<?php

namespace App\Http\Controllers;

use App\Models\Family;
use App\Models\Journey;
use App\Models\MemoryLeafShare;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostRepost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalNotificationController extends Controller
{
    public function index(Request $request, Family $family): JsonResponse
    {
        $this->assertMember($family, $request);

        $userId = (int) $request->user()->id;
        $items = [];

        $posts = Post::query()
            ->with('user')
            ->where('family_id', $family->id)
            ->where('user_id', '!=', $userId)
            ->whereNull('repost_of_post_id')
            ->where(function ($q) {
                $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->latest()
            ->limit(30)
            ->get();

        foreach ($posts as $post) {
            $items[] = [
                'id' => "post-{$post->id}",
                'userName' => $post->user?->name ?? 'Familiar',
                'userAvatar' => $post->user?->avatar_url,
                'action' => 'Nueva publicacion',
                'message' => $post->content ?: 'Publico contenido en el feed',
                'mediaThumb' => null,
                'createdAt' => $post->created_at?->toISOString() ?? now()->toISOString(),
                'target' => [
                    'type' => 'feed-post',
                    'postId' => $post->id,
                ],
            ];
        }

        $comments = PostComment::query()
            ->with(['user', 'post'])
            ->where('user_id', '!=', $userId)
            ->whereHas('post', function ($q) use ($family) {
                $q->where('family_id', $family->id);
            })
            ->latest()
            ->limit(30)
            ->get();

        foreach ($comments as $comment) {
            $items[] = [
                'id' => "comment-{$comment->id}",
                'userName' => $comment->user?->name ?? 'Familiar',
                'userAvatar' => $comment->user?->avatar_url,
                'action' => 'Comento una publicacion',
                'message' => $comment->content,
                'mediaThumb' => null,
                'createdAt' => $comment->created_at?->toISOString() ?? now()->toISOString(),
                'target' => [
                    'type' => 'feed-comment',
                    'postId' => $comment->post_id,
                    'commentId' => $comment->id,
                ],
            ];
        }

        $reposts = PostRepost::query()
            ->with(['user', 'post'])
            ->where('user_id', '!=', $userId)
            ->whereHas('post', function ($q) use ($family, $userId) {
                $q->where('family_id', $family->id)->where('user_id', $userId);
            })
            ->latest()
            ->limit(30)
            ->get();

        foreach ($reposts as $repost) {
            $items[] = [
                'id' => "repost-{$repost->id}",
                'userName' => $repost->user?->name ?? 'Familiar',
                'userAvatar' => $repost->user?->avatar_url,
                'action' => 'Reposteo tu publicacion',
                'message' => $repost->post?->content ?: 'Tu publicacion fue reposteada',
                'mediaThumb' => null,
                'createdAt' => $repost->created_at?->toISOString() ?? now()->toISOString(),
                'target' => [
                    'type' => 'feed-repost',
                    'postId' => $repost->post_id,
                ],
            ];
        }

        $journeys = Journey::query()
            ->with('user')
            ->where('family_id', $family->id)
            ->where('user_id', '!=', $userId)
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->latest('published_at')
            ->limit(20)
            ->get();

        foreach ($journeys as $journey) {
            $createdAt = $journey->published_at?->toISOString() ?? $journey->created_at?->toISOString() ?? now()->toISOString();
            $items[] = [
                'id' => "journey-{$journey->id}",
                'userName' => $journey->user?->name ?? 'Familiar',
                'userAvatar' => $journey->user?->avatar_url,
                'action' => 'Publico un journey',
                'message' => $journey->title,
                'mediaThumb' => null,
                'createdAt' => $createdAt,
                'target' => [
                    'type' => 'journey',
                    'journeyId' => $journey->id,
                ],
            ];
        }

        $incomingShares = MemoryLeafShare::query()
            ->with(['sender', 'memoryLeaf'])
            ->where('recipient_id', $userId)
            ->where('status', 'pending')
            ->whereHas('memoryLeaf', fn ($q) => $q->where('family_id', $family->id))
            ->latest()
            ->limit(20)
            ->get();

        foreach ($incomingShares as $share) {
            $items[] = [
                'id' => "ml-share-{$share->id}",
                'userName' => $share->sender?->name ?? 'Familiar',
                'userAvatar' => $share->sender?->avatar_url,
                'action' => 'Te compartio un item de Memory Leaf',
                'message' => $share->memoryLeaf?->full_name ?? 'Memory Leaf item',
                'mediaThumb' => null,
                'createdAt' => $share->created_at?->toISOString() ?? now()->toISOString(),
                'target' => [
                    'type' => 'memory-leaf-share-request',
                    'shareId' => $share->id,
                    'memoryLeafId' => $share->memory_leaf_id,
                ],
            ];
        }

        $shareStatus = MemoryLeafShare::query()
            ->with(['recipient', 'memoryLeaf', 'copiedMemoryLeaf'])
            ->where('sender_id', $userId)
            ->whereIn('status', ['accepted', 'rejected'])
            ->whereNotNull('responded_at')
            ->whereHas('memoryLeaf', fn ($q) => $q->where('family_id', $family->id))
            ->latest('responded_at')
            ->limit(20)
            ->get();

        foreach ($shareStatus as $share) {
            $accepted = $share->status === 'accepted';
            $items[] = [
                'id' => "ml-share-status-{$share->id}",
                'userName' => $share->recipient?->name ?? 'Familiar',
                'userAvatar' => $share->recipient?->avatar_url,
                'action' => $accepted ? 'Acepto tu compartido de Memory Leaf' : 'Rechazo tu compartido de Memory Leaf',
                'message' => $share->memoryLeaf?->full_name ?? 'Memory Leaf item',
                'mediaThumb' => null,
                'createdAt' => $share->responded_at?->toISOString() ?? now()->toISOString(),
                'target' => [
                    'type' => 'memory-leaf-share-status',
                    'shareId' => $share->id,
                    'memoryLeafId' => $share->memory_leaf_id,
                ],
            ];
        }

        usort($items, fn (array $a, array $b) => strtotime($b['createdAt']) <=> strtotime($a['createdAt']));

        return response()->json([
            'data' => array_slice($items, 0, 100),
        ]);
    }

    private function assertMember(Family $family, Request $request): void
    {
        abort_unless(
            $family->familyMembers()->where('user_id', $request->user()->id)->exists(),
            403
        );
    }
}
