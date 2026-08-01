<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PropertyVerificationPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guardName = config('permission_modules.guard', 'sanctum');

        $permissions = config('property_verification.permissions', [
            'property_verifications.read',
            'property_verifications.assign',
            'property_verifications.review',
            'property_verifications.approve',
            'property_verifications.reject',
        ]);

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guardName,
            ]);
        }

        $verifierRoleNames = [
            'admin',
            'manager',
            'company',
            'agent',
            'consultancy',
        ];

        foreach ($verifierRoleNames as $roleName) {
            $role = Role::whereRaw('LOWER(name) = ?', [strtolower($roleName)])
                ->where('guard_name', $guardName)
                ->first();

            if (!$role) {
                continue;
            }

            DB::table('roles')
                ->where('id', $role->id)
                ->update([
                    'is_admin_login_permission' => 1,
                    'guard_name' => $guardName,
                ]);

            foreach ($permissions as $permissionName) {
                $permission = Permission::where('name', $permissionName)
                    ->where('guard_name', $guardName)
                    ->first();

                if ($permission && !$role->hasPermissionTo($permissionName, $guardName)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
