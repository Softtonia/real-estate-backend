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

        /*
         * IMPORTANT:
         * Yaha apne verifier roles ke names daalo.
         * Jo role property verify karega, usko admin login permission bhi chahiye.
         */
        $verifierRoleNames = [
            'admin',
            'manager',
            'company',
            'agent',
            'consultancy',
        ];

        foreach ($verifierRoleNames as $roleName) {
            $role = Role::where('name', $roleName)
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

            $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}