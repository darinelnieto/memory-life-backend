<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // --- Permisos del sistema ---
        $permissions = [
            // Memorias
            'memories.view',
            'memories.create',
            'memories.edit',
            'memories.delete',
            // Funciones premium
            'memories.export',
            'memories.share',
            'memories.unlimited',
            // Administración
            'users.manage',
            'roles.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // --- Rol: super_admin (acceso total al dashboard de Filament) ---
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdmin->givePermissionTo(Permission::all());

        // --- Roles de suscripción frontend ---

        // Capa gratuita: solo ver memorias y crear limitado
        $free = Role::firstOrCreate(['name' => 'free']);
        $free->givePermissionTo([
            'memories.view',
            'memories.create',
        ]);

        // Capa media: crear, editar, compartir
        $medium = Role::firstOrCreate(['name' => 'medium']);
        $medium->givePermissionTo([
            'memories.view',
            'memories.create',
            'memories.edit',
            'memories.share',
        ]);

        // Capa premium: acceso completo sin límites
        $premium = Role::firstOrCreate(['name' => 'premium']);
        $premium->givePermissionTo([
            'memories.view',
            'memories.create',
            'memories.edit',
            'memories.delete',
            'memories.export',
            'memories.share',
            'memories.unlimited',
        ]);
    }
}
