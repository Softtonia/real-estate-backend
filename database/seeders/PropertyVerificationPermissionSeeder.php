<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PropertyVerificationPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guardName = config('permission_modules.guard', 'sanctum');

        foreach ([
            'property_verifications.read',
            'property_verifications.assign',
            'property_verifications.review',
            'property_verifications.approve',
            'property_verifications.reject',
        ] as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guardName,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
