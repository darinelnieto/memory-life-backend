<?php

namespace Tests\Feature\Feed;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_owner_can_update_post_content_and_interactions(): void
    {
        [$owner, , $family, $post] = $this->createBaseContext();

        Sanctum::actingAs($owner);

        $response = $this->patchJson("/api/families/{$family->id}/posts/{$post->id}", [
            'content' => 'updated content',
            'allow_comments' => false,
            'allow_likes' => false,
            'allow_reposts' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.content', 'updated content');
        $response->assertJsonPath('data.allow_comments', false);
        $response->assertJsonPath('data.allow_likes', false);
        $response->assertJsonPath('data.allow_reposts', true);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'content' => 'updated content',
            'allow_comments' => false,
            'allow_likes' => false,
            'allow_reposts' => true,
        ]);
    }

    public function test_user_can_create_photo_post_with_multiple_images(): void
    {
        Storage::fake('public');

        [$owner, , $family] = $this->createBaseContext();

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/families/{$family->id}/posts", [
            'type' => 'photo',
            'content' => 'album post',
            'media' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'photo');
        $response->assertJsonCount(2, 'data.media_urls');

        $post = Post::query()->latest('id')->firstOrFail();

        $this->assertIsArray($post->media_paths);
        $this->assertCount(2, $post->media_paths);
        Storage::disk('public')->assertExists($post->media_paths[0]);
        Storage::disk('public')->assertExists($post->media_paths[1]);
    }

    private function createBaseContext(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $family = Family::create([
            'owner_id' => $owner->id,
            'surname' => 'Manage',
            'name' => 'Family',
        ]);

        $this->addMemberToFamily($family, $owner, 'owner');
        $this->addMemberToFamily($family, $member, 'member');

        $post = Post::create([
            'family_id' => $family->id,
            'user_id' => $owner->id,
            'type' => 'text',
            'content' => 'post',
            'allow_comments' => true,
            'allow_likes' => true,
            'allow_reposts' => true,
        ]);

        return [$owner, $member, $family, $post];
    }

    private function addMemberToFamily(Family $family, User $user, string $role): void
    {
        FamilyMember::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }
}
