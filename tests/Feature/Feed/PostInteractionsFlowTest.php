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

class PostInteractionsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_toggle_like_when_likes_are_enabled(): void
    {
        [, $member, $family, $post] = $this->createBaseContext();

        Sanctum::actingAs($member);

        $like = $this->postJson("/api/families/{$family->id}/posts/{$post->id}/like");
        $like->assertOk();
        $like->assertJsonPath('liked', true);
        $like->assertJsonPath('likes_count', 1);
        $this->assertDatabaseHas((new PostLike())->getTable(), [
            'post_id' => $post->id,
            'user_id' => $member->id,
        ]);

        $unlike = $this->postJson("/api/families/{$family->id}/posts/{$post->id}/like");
        $unlike->assertOk();
        $unlike->assertJsonPath('liked', false);
        $unlike->assertJsonPath('likes_count', 0);
        $this->assertDatabaseMissing((new PostLike())->getTable(), [
            'post_id' => $post->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_user_can_toggle_repost_when_reposts_are_enabled(): void
    {
        [, $member, $family, $post] = $this->createBaseContext();

        Sanctum::actingAs($member);

        $repost = $this->postJson("/api/families/{$family->id}/posts/{$post->id}/repost");
        $repost->assertOk();
        $repost->assertJsonPath('reposted', true);
        $repost->assertJsonPath('reposts_count', 1);
        $this->assertDatabaseHas((new PostRepost())->getTable(), [
            'post_id' => $post->id,
            'user_id' => $member->id,
        ]);

        $feed = $this->getJson("/api/families/{$family->id}/posts");
        $feed->assertOk();
        $feed->assertJsonPath('data.0.is_repost', true);
        $feed->assertJsonPath('data.0.repost_of.id', $post->id);
        $feed->assertJsonPath('data.0.author.id', $member->id);

        $undoRepost = $this->postJson("/api/families/{$family->id}/posts/{$post->id}/repost");
        $undoRepost->assertOk();
        $undoRepost->assertJsonPath('reposted', false);
        $undoRepost->assertJsonPath('reposts_count', 0);
        $this->assertDatabaseMissing((new PostRepost())->getTable(), [
            'post_id' => $post->id,
            'user_id' => $member->id,
        ]);

        $feedAfterUndo = $this->getJson("/api/families/{$family->id}/posts");
        $feedAfterUndo->assertOk();
        $feedAfterUndo->assertJsonPath('data.0.id', $post->id);
    }

    public function test_user_can_create_comment_when_comments_are_enabled(): void
    {
        [, $member, $family, $post] = $this->createBaseContext();

        Sanctum::actingAs($member);

        $response = $this->postJson("/api/families/{$family->id}/posts/{$post->id}/comments", [
            'content' => 'Great memory!',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.content', 'Great memory!');
        $response->assertJsonPath('data.author.id', $member->id);

        $this->assertDatabaseHas('post_comments', [
            'post_id' => $post->id,
            'user_id' => $member->id,
            'content' => 'Great memory!',
        ]);
    }

    private function createBaseContext(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $family = Family::create([
            'owner_id' => $owner->id,
            'surname' => 'Flow',
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
