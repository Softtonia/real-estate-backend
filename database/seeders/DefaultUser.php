<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\UserDetail;
use App\Models\UserPersonalDetail;
use App\Models\UserBusinessDetail;
use App\Models\UniqueID;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DefaultUser extends Seeder
{
    public function run(): void
    {
        DB::beginTransaction();

        try {

            User::updateOrCreate(
                ['user_name' => 'anonymous'],
                [
                    'user_name' => 'anonymous',
                    'first_name' => 'Anonymous',
                    'last_name' => null,
                    'email' => null,
                    'phone' => null,
                    'password' => null,
                    'role_id' => null,
                    'isapproved' => 1,
                    'kyc' => 0,
                    'is_otp_verified' => 0,
                    'created_by' => 1,
                ]
            );

            $this->command->info('✅ Anonymous User created successfully.');

            // ✅ Only non-admin roles
            $rolesConfig = [
                'owner' => [
                    'email' => 'owner@softtonia.com',
                    'first_name' => 'Owner',
                    'last_name' => 'User',
                    'user_name' => 'owneruser',
                    'bussiness_required' => false,
                ],
                'agent' => [
                    'email' => 'agent@softtonia.com',
                    'first_name' => 'Agent',
                    'last_name' => 'User',
                    'user_name' => 'agentuser',
                    'bussiness_required' => true,
                ],
                'company' => [
                    'email' => 'company@softtonia.com',
                    'first_name' => 'Company',
                    'last_name' => 'User',
                    'user_name' => 'companyuser',
                    'bussiness_required' => true,
                ],
                'consultancy' => [
                    'email' => 'consultancy@softtonia.com',
                    'first_name' => 'Consultancy',
                    'last_name' => 'User',
                    'user_name' => 'consultancyuser',
                    'bussiness_required' => true,
                ],
                'developer' => [
                    'email' => 'developer@softtonia.com',
                    'first_name' => 'Developer',
                    'last_name' => 'User',
                    'user_name' => 'developeruser',
                    'bussiness_required' => true,
                ],
            ];

            foreach ($rolesConfig as $roleName => $data) {
                $role = Role::where('name', $roleName)->first();

                if (!$role) {
                    $this->command->warn("⚠️ Role '{$roleName}' not found. Skipping...");
                    continue;
                }

                $prefix = $role->prefix ?? strtoupper(substr($roleName, 0, 3));

                $uniqueID = new UniqueID();
                $uniqueID->unique_id = $prefix . str_pad(UniqueID::count() + 1, 3, '0', STR_PAD_LEFT);
                $uniqueID->save();

                $user = User::updateOrCreate(
                    ['email' => $data['email']],
                    [
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'user_name' => $data['user_name'],
                        'email' => $data['email'],
                        'phone' => rand(9000000000, 9999999999),
                        'api_token' => Str::random(60),
                        'remember_token' => Str::random(10),
                        'unique_id' => $uniqueID->unique_id,
                        'role_id' => $role->id,
                        'password' => 'Soft@1234',
                        'isapproved' => 1,
                        'kyc' => 1,
                        'is_otp_verified' => 1,
                        'country_id' => 1,
                        'state_id' => 1,
                        'city_id' => 1,
                        'area_locality' => 'Central Area',
                        'colony' => 'Sector 10',
                        'street_address' => 'Main Street',
                        'pin_code' => '110011',
                        'about' => 'System generated user for role ' . ucfirst($roleName),
                        'created_by' => 1,
                    ]
                );

                DB::table('user_has_unique_ids')->updateOrInsert([
                    'user_id' => $user->id,
                    'unique_id' => $uniqueID->id,
                ]);

                // Always populate personal details
                UserPersonalDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'country_id' => 1,
                        'state_id' => 1,
                        'city_id' => 1,
                        'area_locality' => 'Central Area',
                        'colony' => 'Sector 10',
                        'street_address' => 'Main Street',
                        'address' => 'Address - ' . ucfirst($roleName),
                        'pin_code' => '110011',
                        'alternate_number' => rand(7000000000, 7999999999),
                        'about_us' => 'This is a demo ' . ucfirst($roleName) . ' account.',
                        'created_by' => 1,
                    ]
                );

                if ($data['bussiness_required']) {
                    UserBusinessDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'business_name' => ucfirst($roleName) . ' Business Pvt Ltd',
                            'business_email' => $data['email'],
                            'business_phone' => rand(8000000000, 8999999999),
                            'license_number' => strtoupper($roleName) . '_LIC_' . rand(1000, 9999),
                            'country_id' => 1,
                            'state_id' => 1,
                            'city_id' => 1,
                            'business_address' => '123 ' . ucfirst($roleName) . ' St, Business Park',
                            'area_locality' => 'Business Area',
                            'colony' => 'Sector 20',
                            'street_address' => 'Business Street',
                            'business_pin_code' => '110022',
                            'no_of_employees' => rand(5, 50),
                            'rera_number' => 'RERA' . rand(10000, 99999),
                            'about_business' => 'This is a demo ' . ucfirst($roleName) . ' company account.',
                            'created_by' => 1,
                        ]
                    );

                    $this->command->info("✅ Full user personal & business details inserted for {$roleName}");
                } else {
                    $this->command->info("✅ Basic user personal details inserted for {$roleName}");
                }
            }

            DB::commit();

            $this->command->info('🎉 Anonymous user, roles, users & user_details seeded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            $this->command->error('❌ Seeder failed: ' . $e->getMessage());
        }
    }
}