<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {       
        // Define the roles to be checked and created if they don't exist
        $roles = [
            'admin' => [
                'is_default' => 1,  // Admin role should be default
                'created_by' => null,
                'is_admin_login_permission' => 0, // Admin not allowed login
                'prefix' => 'URA',
                'guard_name' => 'sanctum'
            ],
            'owner' => [
                'is_default' => 1,  // Owner role should be default
                'created_by' => null,
                'is_admin_login_permission' => 0, // Not allowed to login
                'prefix' => 'OWN',
                'guard_name' => 'sanctum'
            ],
            'agent' => [
                'is_default' => 1,  // Agent role should be default
                'created_by' => null,
                'is_admin_login_permission' => 0, // Not allowed to login
                'prefix' => 'UROA',
                'guard_name' => 'sanctum'
            ],
            'company' => [
                'is_default' => 1,  // Company role should be default
                'created_by' => null,
                'is_admin_login_permission' => 0, // Not allowed to login
                'prefix' => 'UROB',
                'guard_name' => 'sanctum'
            ],
            'consultancy' => [
                'is_default' => 1,  // Consultancy role should be default
                'created_by' => null,
                'is_admin_login_permission' => 0, // Not allowed to login
                'prefix' => 'UROC',
                'guard_name' => 'sanctum'
            ],
            'developer' => [
                'is_default' => 1,  // Developer role should be default
                'created_by' => null,
                'is_admin_login_permission' => 0, // Not allowed to login
                'prefix' => 'URD',
                'guard_name' => 'web'
            ],
        ];

        // Loop through each role and check if it exists before creating
        foreach ($roles as $roleName => $attributes) {
            // Check if role already exists by name and also check that 'is_default' is 1
            $role = Role::where('name', $roleName)->first();
            
            if (!$role) {
                // If role doesn't exist, create it
                Role::create([
                    'name' => $roleName,
                    'is_default' => $attributes['is_default'],
                    'created_by' => $attributes['created_by'],
                    'is_admin_login_permission' => $attributes['is_admin_login_permission'],
                    'prefix' => $attributes['prefix'],
                    'guard_name' => $attributes['guard_name']
                ]);
            } else {
                // If role exists and the is_default is not 1, update it
                if ($role->is_default != 1 || $role->created_by !== null) {
                    $role->update([
                        'is_default' => $attributes['is_default'],
                        'created_by' => $attributes['created_by'],
                        'is_admin_login_permission' => $attributes['is_admin_login_permission'],
                        'prefix' => $attributes['prefix'],
                        'guard_name' => $attributes['guard_name']
                    ]);
                }
            }
        }
    }
}
