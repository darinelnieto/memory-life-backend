<?php

namespace Tests\Feature\Family;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FamilyCoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_family_with_cover_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->post('/api/families', [
            'name' => 'Familia',
            'surname' => 'PEREZ',
            'cover' => UploadedFile::fake()->image('cover.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.cover_url', fn ($value) => is_string($value) && $value !== '');
        $response->assertJsonPath('data.avatar_url', fn ($value) => is_string($value) && $value !== '');

        $family = Family::query()->firstOrFail();

        $this->assertNotNull($family->cover_photo);
        Storage::disk('public')->assertExists($family->cover_photo);
    }

    public function test_family_manager_can_update_family_cover(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $family = Family::create([
            'owner_id' => $owner->id,
            'surname' => 'PEREZ',
            'name' => 'Familia',
        ]);

        FamilyMember::create([
            'family_id' => $family->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->post("/api/families/{$family->id}/cover", [
            'cover' => UploadedFile::fake()->image('new-cover.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertJsonPath('data.cover_url', fn ($value) => is_string($value) && $value !== '');
        $response->assertJsonPath('data.avatar_url', fn ($value) => is_string($value) && $value !== '');

        $family->refresh();

        $this->assertNotNull($family->cover_photo);
        Storage::disk('public')->assertExists($family->cover_photo);
    }
}
