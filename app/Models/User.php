<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password', 'google_id', 'avatar', 'bio', 'cover_photo', 'birth_date', 'phone', 'location', 'gender'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole('super_admin');
    }

    public function families(): BelongsToMany
    {
        return $this->belongsToMany(Family::class, 'family_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function getCompletionPercentageAttribute(): int
    {
        $weights = [
            'name'        => 10,
            'username'    => 10,
            'bio'         => 15,
            'avatar'      => 15,
            'cover_photo' => 15,
            'birth_date'  => 10,
            'phone'       => 10,
            'location'    => 10,
            'gender'      => 5,
        ];

        $total = 0;
        foreach ($weights as $field => $weight) {
            if (!empty($this->$field)) {
                $total += $weight;
            }
        }

        return $total;
    }

    public function getAvatarUrlAttribute(): string|null
    {
        if (!$this->avatar) return null;
        return str_starts_with($this->avatar, 'http')
            ? $this->avatar
            : asset('storage/' . $this->avatar);
    }

    public function getCoverUrlAttribute(): string|null
    {
        if (!$this->cover_photo) return null;
        return asset('storage/' . $this->cover_photo);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date'        => 'date',
            'password'          => 'hashed',
        ];
    }
}

