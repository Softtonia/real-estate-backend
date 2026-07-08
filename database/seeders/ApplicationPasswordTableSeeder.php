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
        if (! Schema::hasTable('application_passwords')) {
            $this->command?->warn('application_passwords table not found.');
            return;
        }

        if (! Schema::hasTable('api_clients')) {
            $this->command?->warn('api_clients table not found.');
            return;
        }

        $now = Carbon::now();

        /*
         * Important:
         * api_client_slug must match api_clients.slug exactly.
         * fixed_key_name keeps your old fixed names unchanged.
         */

        $passwords = [
            [
                'fixed_key_name' => 'fixed_admin_panel',
                'api_client_slug' => 'admin-panel',
                'name' => 'Fixed Admin Panel Application Password',
                'env_key' => 'FIXED_ADMIN_PANEL_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_local_react_admin',
                'api_client_slug' => 'api-key-port-5173',
                'name' => 'Fixed Local React Admin Application Password',
                'env_key' => 'FIXED_LOCAL_REACT_ADMIN_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_local_api',
                'api_client_slug' => 'api-key-port-8000',
                'name' => 'Fixed Local API Application Password',
                'env_key' => 'FIXED_LOCAL_API_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_business_live',
                'api_client_slug' => 'businesscom',
                'name' => 'Fixed Business Live Application Password',
                'env_key' => 'FIXED_BUSINESS_LIVE_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_business_local',
                'api_client_slug' => 'business-localhost-api-key-port-5173',
                'name' => 'Fixed Business Local Application Password',
                'env_key' => 'FIXED_BUSINESS_LOCAL_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_local_nextjs',
                'api_client_slug' => 'local-sagar',
                'name' => 'Fixed Local Next.js Application Password',
                'env_key' => 'FIXED_LOCAL_NEXTJS_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_holiplaces_website',
                'api_client_slug' => 'holiplacescom',
                'name' => 'Fixed Holiplaces Website Application Password',
                'env_key' => 'FIXED_HOLIPLACES_WEBSITE_TOKEN',
                'abilities' => ['*'],
            ],
            [
                'fixed_key_name' => 'fixed_mobile_application',
                'api_client_slug' => 'mobile-application',
                'name' => 'Fixed Mobile Application Password',
                'env_key' => 'FIXED_MOBILE_APPLICATION_TOKEN',
                'abilities' => ['*'],
            ],
        ];

        foreach ($passwords as $password) {
            $client = DB::table('api_clients')
                ->where('slug', $password['api_client_slug'])
                ->whereNull('deleted_at')
                ->first();

            if (! $client) {
                $this->command?->warn(
                    "API client not found for {$password['fixed_key_name']}: {$password['api_client_slug']}"
                );
                continue;
            }

            $plainToken = $this->getTokenFromEnv($password['env_key']);

            if ($plainToken === '') {
                $this->command?->warn(
                    "Token missing for {$password['fixed_key_name']}. Env key: {$password['env_key']}"
                );
                continue;
            }

            $data = [
                'api_client_id' => $client->id,
                'name' => $password['name'],
                'token_hash' => hash('sha256', $plainToken),
                'token_prefix' => $this->tokenPrefix($plainToken),
                'abilities' => json_encode($password['abilities'], JSON_UNESCAPED_SLASHES),
                'expires_at' => null,
                'revoked_at' => null,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('application_passwords', 'fixed_key_name')) {
                $data['fixed_key_name'] = $password['fixed_key_name'];
            }

            if (Schema::hasColumn('application_passwords', 'last_used_at')) {
                $data['last_used_at'] = null;
            }

            if (Schema::hasColumn('application_passwords', 'last_used_ip')) {
                $data['last_used_ip'] = null;
            }

            if (Schema::hasColumn('application_passwords', 'last_user_agent')) {
                $data['last_user_agent'] = null;
            }

            $data = $this->onlyExistingColumns('application_passwords', $data);

            $match = Schema::hasColumn('application_passwords', 'fixed_key_name')
                ? ['fixed_key_name' => $password['fixed_key_name']]
                : [
                    'api_client_id' => $client->id,
                    'name' => $password['name'],
                ];

            $existing = DB::table('application_passwords')
                ->where($match)
                ->first();

            if ($existing) {
                DB::table('application_passwords')
                    ->where('id', $existing->id)
                    ->update($data);

                $action = 'Updated';
            } else {
                $data['created_at'] = $now;

                DB::table('application_passwords')
                    ->insert($this->onlyExistingColumns('application_passwords', $data));

                $action = 'Created';
            }

            $this->command?->info("{$action} {$password['fixed_key_name']}: {$password['name']}");
            $this->command?->line("Fixed Key Name: {$password['fixed_key_name']}");
            $this->command?->line("Client Slug: {$password['api_client_slug']}");
            $this->command?->line("Token Prefix: " . $this->tokenPrefix($plainToken));
            $this->command?->newLine();
        }
    }

    private function getTokenFromEnv(string $envKey): string
    {
        $fromConfig = config('api_security.fixed_tokens.' . $envKey);

        if (! empty($fromConfig)) {
            return trim((string) $fromConfig);
        }

        return trim((string) env($envKey, ''));
    }

    private function tokenPrefix(string $plainToken): string
    {
        return substr($plainToken, 0, 24);
    }

    private function onlyExistingColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(function ($value, $column) use ($table) {
                return Schema::hasColumn($table, $column);
            })
            ->all();
    }
}