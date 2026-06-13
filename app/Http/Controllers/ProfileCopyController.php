<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\Journey;
use App\Models\Post;
use App\Models\TreeMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileCopyController extends Controller
{
    public function preview(Request $request, Family $family): JsonResponse
    {
        $userId = $request->user()->id;
        $this->ensureFamilyAccess($family, $userId);

        $data = $request->validate([
            'source_family_id' => 'required|integer|exists:families,id',
        ]);

        $sourceFamily = Family::findOrFail($data['source_family_id']);
        $this->ensureFamilyAccess($sourceFamily, $userId);

        abort_if($sourceFamily->id === $family->id, 422, 'La familia origen y la destino no pueden ser la misma.');

        $postCount = Post::query()
            ->where('family_id', $sourceFamily->id)
            ->where('user_id', $userId)
            ->where('show_on_profile', true)
            ->count();

        $journeyCount = Journey::query()
            ->where('family_id', $sourceFamily->id)
            ->where('user_id', $userId)
            ->count();

        return response()->json([
            'data' => [
                'from_family' => [
                    'id' => $sourceFamily->id,
                    'name' => '',
                    'surname' => $sourceFamily->surname,
                ],
                'to_family' => [
                    'id' => $family->id,
                    'name' => '',
                    'surname' => $family->surname,
                ],
                'posts_count' => $postCount,
                'journeys_count' => $journeyCount,
            ],
        ]);
    }

    public function copy(Request $request, Family $family): JsonResponse
    {
        $userId = $request->user()->id;
        $this->ensureFamilyAccess($family, $userId);

        $data = $request->validate([
            'source_family_id' => 'required|integer|exists:families,id',
            'copy_posts' => 'sometimes|boolean',
            'copy_journeys' => 'sometimes|boolean',
        ]);

        $sourceFamily = Family::findOrFail($data['source_family_id']);
        $this->ensureFamilyAccess($sourceFamily, $userId);

        abort_if($sourceFamily->id === $family->id, 422, 'La familia origen y la destino no pueden ser la misma.');

        $copyPosts = array_key_exists('copy_posts', $data) ? (bool) $data['copy_posts'] : true;
        $copyJourneys = array_key_exists('copy_journeys', $data) ? (bool) $data['copy_journeys'] : true;

        $result = DB::transaction(function () use ($sourceFamily, $family, $userId, $copyPosts, $copyJourneys) {
            $copiedPostCount = 0;
            $skippedPostCount = 0;
            $copiedJourneyCount = 0;
            $skippedJourneyCount = 0;
            $postIdMap = [];

            if ($copyPosts) {
                $sourcePosts = Post::query()
                    ->where('family_id', $sourceFamily->id)
                    ->where('user_id', $userId)
                    ->where('show_on_profile', true)
                    ->orderBy('created_at')
                    ->get();

                foreach ($sourcePosts as $sourcePost) {
                    $existingCopy = Post::query()
                        ->where('family_id', $family->id)
                        ->where('copied_from_post_id', $sourcePost->id)
                        ->first();

                    if ($existingCopy) {
                        $postIdMap[$sourcePost->id] = $existingCopy->id;
                        $skippedPostCount++;
                        continue;
                    }

                    $copiedPost = $this->copyPost($sourcePost, $family, $userId);
                    $postIdMap[$sourcePost->id] = $copiedPost->id;
                    $copiedPostCount++;
                }
            }

            if ($copyJourneys) {
                $sourceJourneys = Journey::query()
                    ->where('family_id', $sourceFamily->id)
                    ->where('user_id', $userId)
                    ->with(['items' => fn ($query) => $query->orderBy('sort_order')])
                    ->orderBy('created_at')
                    ->get();

                foreach ($sourceJourneys as $sourceJourney) {
                    $existingCopy = Journey::query()
                        ->where('family_id', $family->id)
                        ->where('copied_from_journey_id', $sourceJourney->id)
                        ->first();

                    if ($existingCopy) {
                        $skippedJourneyCount++;
                        continue;
                    }

                    $copiedJourney = $this->copyJourney($sourceJourney, $family, $userId, $postIdMap);

                    foreach ($sourceJourney->items as $sourceItem) {
                        $this->copyJourneyItem($sourceItem, $copiedJourney, $postIdMap);
                    }

                    $copiedJourneyCount++;
                }
            }

            return [
                'copied_posts_count' => $copiedPostCount,
                'skipped_posts_count' => $skippedPostCount,
                'copied_journeys_count' => $copiedJourneyCount,
                'skipped_journeys_count' => $skippedJourneyCount,
            ];
        });

        return response()->json([
            'message' => 'Contenido copiado correctamente.',
            'data' => $result,
        ]);
    }

    private function copyPost(Post $source, Family $targetFamily, int $userId): Post
    {
        $mediaPath = $this->copyStoragePath($source->media_path);
        $mediaPaths = null;

        if ($source->type === 'video') {
            $mediaPaths = $mediaPath ? [$mediaPath] : null;
        } elseif (is_array($source->media_paths)) {
            $mediaPaths = array_values(array_filter(array_map(fn ($path) => $this->copyStoragePath($path), $source->media_paths)));
        }

        return Post::create([
            'family_id' => $targetFamily->id,
            'user_id' => $userId,
            'content' => $source->content,
            'type' => $source->type,
            'media_path' => $mediaPath,
            'media_paths' => $mediaPaths,
            'allow_comments' => $source->allow_comments,
            'allow_likes' => $source->allow_likes,
            'allow_reposts' => $source->allow_reposts,
            'show_on_profile' => true,
            'scheduled_at' => null,
            'repost_of_post_id' => null,
            'copied_from_post_id' => $source->id,
            'copied_at' => now(),
        ]);
    }

    private function copyJourney(Journey $source, Family $targetFamily, int $userId, array $postIdMap): Journey
    {
        $coverPath = $this->copyStoragePath($source->cover_path);
        $treeMemberId = $this->resolveTargetTreeMemberId($targetFamily, $source->tree_member_id);

        return Journey::create([
            'family_id' => $targetFamily->id,
            'user_id' => $userId,
            'tree_member_id' => $treeMemberId,
            'title' => $source->title,
            'description' => $source->description,
            'cover_path' => $coverPath,
            'published_at' => $source->published_at,
            'copied_from_journey_id' => $source->id,
            'copied_at' => now(),
        ]);
    }

    private function copyJourneyItem($sourceItem, Journey $targetJourney, array $postIdMap): void
    {
        $filePath = $this->copyStoragePath($sourceItem->file_path);
        $sourcePostId = $sourceItem->source_post_id ? ($postIdMap[$sourceItem->source_post_id] ?? null) : null;

        $targetJourney->items()->create([
            'type' => $sourceItem->type,
            'content' => $sourceItem->content,
            'file_path' => $filePath,
            'caption' => $sourceItem->caption,
            'sort_order' => $sourceItem->sort_order,
            'source_post_id' => $sourcePostId,
        ]);
    }

    private function resolveTargetTreeMemberId(Family $targetFamily, ?int $sourceTreeMemberId): ?int
    {
        if (!$sourceTreeMemberId) {
            return null;
        }

        $sourceTreeMember = TreeMember::query()->find($sourceTreeMemberId);
        if (!$sourceTreeMember?->user_id) {
            return null;
        }

        return TreeMember::query()
            ->where('family_id', $targetFamily->id)
            ->where('user_id', $sourceTreeMember->user_id)
            ->value('id');
    }

    private function copyStoragePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $disk = Storage::disk('public');

        if (!$disk->exists($path)) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $folder = Str::startsWith($path, 'journeys/')
            ? 'journeys'
            : (Str::startsWith($path, 'posts/') ? 'posts' : 'copies');
        $newPath = $folder . '/' . Str::uuid() . ($extension ? '.' . $extension : '');

        try {
            $copied = $disk->copy($path, $newPath);

            if (!$copied || !$disk->exists($newPath)) {
                return $path;
            }

            return $newPath;
        } catch (\Throwable) {
            return $path;
        }
    }

    private function ensureFamilyAccess(Family $family, int $userId): void
    {
        abort_unless(
            DB::table('family_members')
                ->where('family_id', $family->id)
                ->where('user_id', $userId)
                ->exists(),
            403,
            'No tienes acceso a esta familia'
        );
    }
}
