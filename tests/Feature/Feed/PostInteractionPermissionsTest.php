<?php

namespace Tests\Feature\Feed;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostRepost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostInteractionPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_comment_when_comments_are_disabled(): void
    {
        [, $member, $family, $post] = $this->createBaseContext([
            'allow_comments' => false,
            'allow_likes' => true,
            'allow_reposts' => true,
        ]);

        Sanctum::actingAs($member);

        $response = $this->postJson("/api/families/{$family->id}/posts/{$post->id}/comments", [
            'content' => 'trying to comment',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('post_comments', 0);
    }

    public function test_user_cannot_like_when_likes_are_disabled(): void
    {
        [, $member, $family, $post] = $this->createBaseContext([
            'allow_comments' => true,
            'allow_likes' => false,
            'allow_reposts' => true,
        ]);

        Sanctum::actingAs($member);

        $response = $this->postJson("/api/families/{$family->id}/posts/{$post->id}/like");

        $response->assertStatus(422);
        $this->assertDatabaseCount((new PostLike())->getTable(), 0);
    }

    public function test_user_cannot_repost_when_reposts_are_disabled(): void
    {
        [, $member, $family, $post] = $this->createBaseContext([
            'allow_comments' => true,
            'allow_likes' => true,
            'allow_reposts' => false,
        ]);

        Sanctum::actingAs($member);

        $response = $this->postJson("/api/families/{$family->id}/posts/{$post->id}/repost");

        $response->assertStatus(422);
        $this->assertDatabaseCount((new PostRepost())->getTable(), 0);
    }

    private function createBaseContext(array $postOverrides): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $family = Family::create([
            'owner_id' => $owner->id,
            'surname' => 'Test',
            'name' => 'Family',
        ]);

        $this->addMemberToFamily($family, $owner, 'owner');
        $this->addMemberToFamily($family, $member, 'member');

        $post = Post::create(array_merge([
            'family_id' => $family->id,
            'user_id' => $owner->id,
            'type' => 'text',
            'content' => 'post',
            'allow_comments' => true,
            'allow_likes' => true,
            'allow_reposts' => true,
        ], $postOverrides));

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
