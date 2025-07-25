<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ApiClientTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clients = [
            [
                'client_name'     => 'Admin Panel',
                'client_id'       => 'frontend123',
                'client_secret'   => 'secret987',
                'app_type'        => 'admin',
                'status'          => '1',
                'allowed_domain'  => '["http://admin.urbanrealities.com","http://127.0.0.1:8000","http://localhost:5173"]',
            ],

            [
                'client_name'     => 'Business Dashboard',
                'client_id'       => 'business123',
                'client_secret'   => 'secret123',
                'app_type'        => 'business',
                'status'          => '1',
                'allowed_domain'  => '["http://business.urbanrealities.com","http://127.0.0.1:8000","http://localhost:5173"]',
            ],
            [
                'client_name'     => 'Main Website',
                'client_id'       => 'website123',
                'client_secret'   => 'secret456',
                'app_type'        => 'website',
                'status'          => '1',
                'allowed_domain'  => '["http://www.urbanrealities.com","http://127.0.0.1:8000","http://localhost:5173"]',
            ],
            [
                'client_name'     => 'Mobile App',
                'client_id'       => 'mobile123',
                'client_secret'   => 'secret789',
                'app_type'        => 'mobile-app',
                'status'          => '1',
                'allowed_domain'  => '*', // Allow all
            ],

        ];

        foreach ($clients as $client) {
            DB::table('api_clients')->updateOrInsert(
                ['client_id' => $client['client_id']],
                array_merge($client, [
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ])
            );
        }
    }

}
