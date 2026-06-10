<?php

namespace App\Http\Resources;

use App\Models\FamilyInvitation;
use App\Models\FamilyMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TreeMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'family_id'    => $this->family_id,
            'user_id'      => $this->user_id,
            'app_user_email' => $this->app_user_email,
            'invite_status' => $this->invite_status,
            'parent_id'    => $this->parent_id,
            'spouse_id'    => $this->spouse_id,
            'first_name'   => $this->first_name,
            'last_name'    => $this->last_name,
            'full_name'    => $this->first_name . ' ' . $this->last_name,
            'relationship' => $this->relationship,
            'gender'       => $this->gender,
            'avatar_url'   => $this->avatar_url,
            'cover_url'    => $this->cover_url,
            'account_status' => $this->resolveAccountStatus($this->user_id, $this->app_user_email, $this->family_id),
            'photos_urls'  => $this->media_photos_urls,
            'video_url'    => $this->media_video_url,
            'birth_date'   => $this->birth_date?->toDateString(),
            'death_date'   => $this->death_date?->toDateString(),
            'bio'          => $this->bio,
            'is_deceased'  => $this->is_deceased,
            // Spouse inline (without further nesting to avoid circular)
            'spouse'       => $this->whenLoaded('spouse', fn () => [
                'id'           => $this->spouse->id,
                'first_name'   => $this->spouse->first_name,
                'last_name'    => $this->spouse->last_name,
                'full_name'    => $this->spouse->first_name . ' ' . $this->spouse->last_name,
                'relationship' => $this->spouse->relationship,
                'gender'       => $this->spouse->gender,
                'avatar_url'   => $this->spouse->avatar_url,
                'cover_url'    => $this->spouse->cover_url,
                'account_status' => $this->resolveAccountStatus($this->spouse->user_id, $this->spouse->app_user_email, $this->family_id),
                'photos_urls'  => $this->spouse->media_photos_urls,
                'video_url'    => $this->spouse->media_video_url,
                'birth_date'   => $this->spouse->birth_date?->toDateString(),
                'death_date'   => $this->spouse->death_date?->toDateString(),
                'bio'          => $this->spouse->bio,
                'is_deceased'  => $this->spouse->is_deceased,
                'spouse_id'    => $this->spouse->spouse_id,
                'parent_id'    => $this->spouse->parent_id,
                'user_id'      => $this->spouse->user_id,
                'app_user_email' => $this->spouse->app_user_email,
                'invite_status' => $this->spouse->invite_status,
            ]),
            'children'     => TreeMemberResource::collection($this->whenLoaded('children')),
        ];
    }

    private function resolveAccountStatus(?int $userId, ?string $email, int $familyId): string
    {
        $normalizedEmail = $email ? strtolower(trim($email)) : null;

        if (!$normalizedEmail && !$userId) {
            return 'none';
        }

        if ($userId) {
            $isFamilyMember = FamilyMember::query()
                ->where('family_id', $familyId)
                ->where('user_id', $userId)
                ->exists();

            if ($isFamilyMember) {
                return 'linked';
            }
        }

        if ($normalizedEmail) {
            $hasPendingInvite = FamilyInvitation::query()
                ->where('family_id', $familyId)
                ->where('email', $normalizedEmail)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->exists();

            if ($hasPendingInvite) {
                return 'pending';
            }
        }

        return $userId ? 'account' : 'none';
    }
}
