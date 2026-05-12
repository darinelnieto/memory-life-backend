<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Seeder;

class FamilySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first();

        if (!$admin) {
            return;
        }

        $family = Family::firstOrCreate(
            ['owner_id' => $admin->id, 'surname' => 'NIETO'],
            ['name' => 'Nieto García']
        );

        if (!$family->familyMembers()->where('user_id', $admin->id)->exists()) {
            $family->members()->attach($admin->id, [
                'role'      => 'owner',
                'joined_at' => now(),
            ]);
        }
    }
}
