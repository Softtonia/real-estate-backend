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
                'client_id'       => 'RHTYTHHQWA614EL',
                'client_secret'   => 'JQRCQYYKGT66RQY',
                'app_type'        => 'admin',
                'status'         => '1',
                'allowed_domain'  => '["https://www.urbanrealities.com","https://urbanrealities.com","http://127.0.0.1:8000","https://admin.urbanrealities.com","http://admin.urbanrealities.com","http://localhost:5173","http://localhost:3000","https://api.urbanrealities.com/public"]',
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
