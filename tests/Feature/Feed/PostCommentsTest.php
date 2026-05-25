<?php

namespace Tests\Feature\Feed;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostCommentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_comments_endpoint_is_paginated(): void
    {
        [$owner, $member, $family, $post] = $this->createBaseContext();

        for ($i = 1; $i <= 12; $i++) {
            PostComment::create([
                'post_id' => $post->id,
                'user_id' => $member->id,
                'content' => "comment {$i}",
                'created_at' => now()->subMinutes(12 - $i),
                'updated_at' => now()->subMinutes(12 - $i),
            ]);
        }

        Sanctum::actingAs($member);

        $pageOne = $this->getJson("/api/families/{$family->id}/posts/{$post->id}/comments");

        $pageOne->assertOk();
        $pageOne->assertJsonCount(10, 'data');
        $pageOne->assertJsonPath('meta.current_page', 1);
        $pageOne->assertJsonPath('meta.last_page', 2);

        $pageTwo = $this->getJson("/api/families/{$family->id}/posts/{$post->id}/comments?page=2");

        $pageTwo->assertOk();
        $pageTwo->assertJsonCount(2, 'data');
        $pageTwo->assertJsonPath('meta.current_page', 2);
        $pageTwo->assertJsonPath('meta.last_page', 2);
    }

    public function test_comment_author_can_update_own_comment(): void
    {
        [, $member, $family, $post] = $this->createBaseContext();

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $member->id,
            'content' => 'before',
        ]);

        Sanctum::actingAs($member);

        $response = $this->patchJson("/api/families/{$family->id}/posts/{$post->id}/comments/{$comment->id}", [
            'content' => 'after',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.content', 'after');
        $this->assertDatabaseHas('post_comments', [
            'id' => $comment->id,
            'content' => 'after',
        ]);
    }

    public function test_user_cannot_update_comment_from_another_user(): void
    {
        [, $member, $family, $post] = $this->createBaseContext();
        $otherMember = User::factory()->create();
        $this->addMemberToFamily($family, $otherMember, 'member');

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $member->id,
            'content' => 'original',
        ]);

        Sanctum::actingAs($otherMember);

        $response = $this->patchJson("/api/families/{$family->id}/posts/{$post->id}/comments/{$comment->id}", [
            'content' => 'hacked',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('post_comments', [
            'id' => $comment->id,
            'content' => 'original',
        ]);
    }

    public function test_comment_author_can_delete_own_comment(): void
    {
        [, $member, $family, $post] = $this->createBaseContext();

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $member->id,
            'content' => 'delete me',
        ]);

        Sanctum::actingAs($member);

        $response = $this->deleteJson("/api/families/{$family->id}/posts/{$post->id}/comments/{$comment->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('post_comments', [
            'id' => $comment->id,
        ]);
    }

    public function test_post_owner_can_delete_comment_from_other_member(): void
    {
        [$owner, $member, $family, $post] = $this->createBaseContext();

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $member->id,
            'content' => 'owner can delete',
        ]);

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/families/{$family->id}/posts/{$post->id}/comments/{$comment->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('post_comments', [
            'id' => $comment->id,
        ]);
    }

    public function test_unrelated_member_cannot_delete_comment_from_other_member(): void
    {
        [, $member, $family, $post] = $this->createBaseContext();
        $thirdMember = User::factory()->create();
        $this->addMemberToFamily($family, $thirdMember, 'member');

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $member->id,
            'content' => 'should stay',
        ]);

        Sanctum::actingAs($thirdMember);

        $response = $this->deleteJson("/api/families/{$family->id}/posts/{$post->id}/comments/{$comment->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('post_comments', [
            'id' => $comment->id,
            'content' => 'should stay',
        ]);
    }

    private function createBaseContext(): array
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
