<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        User::updateOrCreate(
            ['email' => 'darinel.nieto@darinelnieto.com'],
            [
                'name'              => 'Super Admin',
                'username'          => 'superadmin',
                'password'          => Hash::make('Admin@2024!'),
                'email_verified_at' => now(),
            ]
        )->assignRole('super_admin');

        $this->call(FamilySeeder::class);
    }
}
