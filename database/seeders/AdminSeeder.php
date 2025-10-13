<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Find or create the 'admin' role
        $adminRole = DB::table('roles')->where('name', 'admin')->first();

        if (!$adminRole) {
            $this->command->warn('Admin role not found. Creating the admin role.');

            // Create the admin role
            $adminRoleId = DB::table('roles')->insertGetId([
                'name' => 'admin',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // Ensure the admin role is marked as default
            DB::table('roles')->where('name', 'admin')->update(['is_default' => true]);
            $adminRoleId = $adminRole->id;
        }

        // Get all users with this admin role_id
        $adminUsers = User::where('role_id', $adminRoleId)->get();

        if ($adminUsers->isEmpty()) {
            $this->command->warn('No users found with admin role_id. Creating default admin user.');

            // Create a default admin user with unique_id = 'ADMIN'
            User::create([
                'first_name' => 'Admin',
                'last_name' => 'User',
                'user_name' => 'adminuser',
                'email' => 'sales@softtonia.com',
                'password' => Hash::make('Zen@1234'),
                'role_id' => $adminRoleId,
                'isapproved' => 1,
                'unique_id' => 'ADMIN' // Ensure unique_id is stored as "ADMIN"
            ]);

            $this->command->info('Default admin user created successfully.');
        } else {
            // Update existing admin users to ensure they have 'ADMIN' as unique_id
            foreach ($adminUsers as $user) {
                $user->update([
                    'first_name' => 'Admin',
                    'last_name' => 'Admin',
                    'email' => 'sales@softtonia.com',
                    'password' => Hash::make('Zen@1234'),
                    'isapproved' => 1,
                    'role_id' => $adminRoleId,
                    'unique_id' => 'ADMIN', // Ensure unique_id is updated
                ]);
            }

            $this->command->info('Admin users updated successfully.');
        }
    }
}
