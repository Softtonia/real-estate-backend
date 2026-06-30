<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApiClientTableSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $clients = [
            [
                'name' => 'Admin Panel',
                'slug' => 'admin-panel',
                'type' => 'admin',
                'status' => true,
                'allowed_origins' => [
                    'https://admin.holiplaces.com',
                    'http://admin.holiplaces.com',
                    'http://localhost:5173',
                    'http://127.0.0.1:5173',
                ],
                'permissions' => ['*'],
                'rate_limit_per_minute' => 300,
                'requires_signature' => false,
                'description' => 'React admin panel client.',
            ],
            [
                'name' => 'API Key Port :5173',
                'slug' => 'api-key-port-5173',
                'type' => 'admin',
                'status' => true,
                'allowed_origins' => [
                    'http://localhost:5173',
                ],
                'permissions' => ['*'],
                'rate_limit_per_minute' => 300,
                'requires_signature' => false,
                'description' => 'Local React admin client.',
            ],
            [
                'name' => 'API Key Port :8000',
                'slug' => 'api-key-port-8000',
                'type' => 'custom',
                'status' => true,
                'allowed_origins' => [
                    'http://127.0.0.1:8000',
                ],
                'permissions' => ['*'],
                'rate_limit_per_minute' => 300,
                'requires_signature' => false,
                'description' => 'Local API testing client.',
            ],
            [
                'name' => 'Business.com',
                'slug' => 'businesscom',
                'type' => 'business',
                'status' => true,
                'allowed_origins' => [
                    'https://business.holiplaces.com',
                    'http://business.holiplaces.com',
                ],
                'permissions' => ['*'],
                'rate_limit_per_minute' => 300,
                'requires_signature' => false,
                'description' => 'Business production client.',
            ],
            [
                'name' => 'Business Localhost API Key Port :5173',
                'slug' => 'business-localhost-api-key-port-5173',
                'type' => 'business',
                'status' => true,
                'allowed_origins' => [
                    'http://localhost:5173',
                    'http://localhost:5175',
                ],
                'permissions' => ['*'],
                'rate_limit_per_minute' => 300,
                'requires_signature' => false,
                'description' => 'Business localhost client.',
            ],
            [
                'name' => 'Local Next.js Website',
                'slug' => 'local-sagar',
                'type' => 'website',
                'status' => true,
                'allowed_origins' => [
                    'http://localhost:3000',
                    'http://localhost:3000/',
                    'http://127.0.0.1:3000',
                ],
                'permissions' => ['*'],
                'rate_limit_per_minute' => 300,
                'requires_signature' => false,
                'description' => 'Local Next.js website client.',
            ],
            [
                'name' => 'holiplaces.com',
                'slug' => 'holiplacescom',
                'type' => 'website',
                'status' => true,
                'allowed_origins' => [
                    'https://www.holiplaces.com',
                    'https://holiplaces.com',
                ],
                'permissions' => [
                    'post_types.*.read',
                ],
                'rate_limit_per_minute' => 300,
                'requires_signature' => true,
                'description' => 'Production Next.js website client.',
            ],
            [
                'name' => 'Mobile Application',
                'slug' => 'mobile-application',
                'type' => 'mobile-app',
                'status' => true,
                'allowed_origins' => [],
                'permissions' => [
                    'post_types.*.read',
                ],
                'rate_limit_per_minute' => 300,
                'requires_signature' => false,
                'description' => 'Mobile application client.',
            ],
            [
                'name' => 'Custom Integration',
                'slug' => 'custom-integration',
                'type' => 'custom',
                'status' => true,
                'allowed_origins' => [
                    'https://partner1.com',
                    'https://partner2.com',
                ],
                'permissions' => [
                    'post_types.*.read',
                    'post_types.*.write',
                ],
                'rate_limit_per_minute' => 300,
                'requires_signature' => true,
                'description' => 'External custom integration client.',
            ],
        ];

        foreach ($clients as $client) {
            $existing = DB::table('api_clients')
                ->where('slug', $client['slug'])
                ->first();

            $data = [
                'name' => $client['name'],
                'slug' => $client['slug'],
                'type' => $client['type'],
                'status' => $client['status'] ? 1 : 0,
                'allowed_origins' => json_encode($client['allowed_origins'], JSON_UNESCAPED_SLASHES),
                'permissions' => json_encode($client['permissions'], JSON_UNESCAPED_SLASHES),
                'rate_limit_per_minute' => $client['rate_limit_per_minute'],
                'requires_signature' => $client['requires_signature'] ? 1 : 0,
                'description' => $client['description'],
                'last_used_at' => null,
                'deleted_at' => null,
                'updated_at' => $now,
            ];

            if (!$existing) {
                $data['created_at'] = $now;
            }

            if (Schema::hasColumn('api_clients', 'client_name')) {
                $data['client_name'] = $client['name'];
            }

            if (Schema::hasColumn('api_clients', 'app_type')) {
                $data['app_type'] = $client['type'];
            }

            if (Schema::hasColumn('api_clients', 'allowed_domain')) {
                $data['allowed_domain'] = implode(',', $client['allowed_origins']);
            }

            if (!$existing && Schema::hasColumn('api_clients', 'client_id')) {
                $data['client_id'] = Str::upper(Str::random(16));
            }

            if (!$existing && Schema::hasColumn('api_clients', 'client_secret')) {
                $data['client_secret'] = Str::upper(Str::random(32));
            }

            if (!$existing && Schema::hasColumn('api_clients', 'nextjs_internal_key')) {
                $data['nextjs_internal_key'] = Str::random(48);
            }

            if ($existing) {
                DB::table('api_clients')
                    ->where('id', $existing->id)
                    ->update($data);
            } else {
                DB::table('api_clients')->insert($data);
            }
        }
    }
}