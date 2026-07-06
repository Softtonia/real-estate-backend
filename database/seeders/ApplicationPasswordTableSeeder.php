<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplicationPasswordTableSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('api_clients') || !Schema::hasTable('application_passwords')) {
            $this->command?->error('api_clients or application_passwords table does not exist.');
            return;
        }

        $now = Carbon::now();

        $passwords = [
            'fixed_admin_panel' => [
                'api_client_slug' => 'admin-panel',
                'name' => 'Fixed Admin Panel Application Password',
                'env_key' => 'FIXED_ADMIN_PANEL_TOKEN',
                'abilities' => ['*'],
            ],

            'fixed_local_react_admin' => [
                'api_client_slug' => 'api-key-port-5173',
                'name' => 'Fixed Local React Admin Application Password',
                'env_key' => 'FIXED_LOCAL_REACT_ADMIN_TOKEN',
                'abilities' => ['*'],
            ],

            'fixed_business_live' => [
                'api_client_slug' => 'businesscom',
                'name' => 'Fixed Business Live Application Password',
                'env_key' => 'FIXED_BUSINESS_LIVE_TOKEN',
                'abilities' => ['*'],
            ],

            'fixed_business_local' => [
                'api_client_slug' => 'business-localhost-api-key-port-5173',
                'name' => 'Fixed Business Local Application Password',
                'env_key' => 'FIXED_BUSINESS_LOCAL_TOKEN',
                'abilities' => ['*'],
            ],

            'fixed_local_nextjs' => [
                'api_client_slug' => 'local-sagar',
                'name' => 'Fixed Local Next.js Website Application Password',
                'env_key' => 'FIXED_LOCAL_NEXTJS_TOKEN',
                'abilities' => ['*'],
            ],

            'fixed_holiplaces_website' => [
                'api_client_slug' => 'holiplacescom',
                'name' => 'Fixed Holiplaces Website Application Password',
                'env_key' => 'FIXED_HOLIPLACES_WEBSITE_TOKEN',
                'abilities' => ['*'],
            ],

            'fixed_mobile_application' => [
                'api_client_slug' => 'mobile-application',
                'name' => 'Fixed Mobile Application Password',
                'env_key' => 'FIXED_MOBILE_APPLICATION_TOKEN',
                'abilities' => ['*'],
            ],
        ];

        foreach ($passwords as $fixedKeyName => $passwordData) {
            $plainToken = env($passwordData['env_key']);

            if (empty($plainToken)) {
                $this->command?->warn("Token missing for {$fixedKeyName}. Env key: {$passwordData['env_key']}");
                continue;
            }

            $apiClient = DB::table('api_clients')
                ->where('slug', $passwordData['api_client_slug'])
                ->first();

            if (!$apiClient) {
                $this->command?->warn("API client not found for {$fixedKeyName}: {$passwordData['api_client_slug']}");
                continue;
            }

            $payload = [
                'api_client_id' => $apiClient->id,
                'name' => $passwordData['name'],
                'token_prefix' => substr($plainToken, 0, 24),
                'token_hash' => hash('sha256', $plainToken),
                'abilities' => json_encode($passwordData['abilities'], JSON_UNESCAPED_SLASHES),
                'expires_at' => null,
                'revoked_at' => null,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('application_passwords', 'last_used_at')) {
                $payload['last_used_at'] = null;
            }

            if (Schema::hasColumn('application_passwords', 'last_used_ip')) {
                $payload['last_used_ip'] = null;
            }

            if (Schema::hasColumn('application_passwords', 'last_user_agent')) {
                $payload['last_user_agent'] = null;
            }

            $existing = DB::table('application_passwords')
                ->where('api_client_id', $apiClient->id)
                ->where('name', $passwordData['name'])
                ->first();

            if ($existing) {
                DB::table('application_passwords')
                    ->where('id', $existing->id)
                    ->update($payload);

                $this->command?->info("Updated {$fixedKeyName}: {$passwordData['name']}");
            } else {
                $payload['created_at'] = $now;

                DB::table('application_passwords')->insert($payload);

                $this->command?->info("Created {$fixedKeyName}: {$passwordData['name']}");
            }

            $this->command?->line("Fixed Key Name: {$fixedKeyName}");
            $this->command?->line("Client Slug: {$passwordData['api_client_slug']}");
            $this->command?->line("Token Prefix: " . substr($plainToken, 0, 24));
            $this->command?->newLine();
        }
    }
}