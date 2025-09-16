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
        // $clients = [
        //     [
        //         'client_name'     => 'Admin Panel',
        //         'client_id'       => 'RHTYTHHQWA614EL',
        //         'client_secret'   => 'JQRCQYYKGT66RQY',
        //         'app_type'        => 'admin',
        //         'status'         => '1',
        //         'allowed_domain'  => '["https://www.urbanrealities.com","https://urbanrealities.com","http://127.0.0.1:8000","https://admin.urbanrealities.com","http://admin.urbanrealities.com","http://localhost:5173","http://localhost:3000","https://api.urbanrealities.com/public"]',
        //     ],


        // ];

        $clients = [
            [
                'client_name'     => 'Admin Panel',
                'client_id'       => 'ADMINCLIENT001',
                'client_secret'   => 'ADMINSECRET001',
                'app_type'        => 'admin',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "https://admin.urbanrealities.com",
                    "http://admin.urbanrealities.com",
                    "http://127.0.0.1:8000",
                    "http://localhost:5173","http://localhost:3000","https://api.urbanrealities.com/public"
                ]),
            ],
            [
                'client_name'     => 'Business App',
                'client_id'       => 'BUSINESSCLI001',
                'client_secret'   => 'BUSINESSSEC001',
                'app_type'        => 'business',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "https://business.urbanrealities.com",
                    "http://127.0.0.1:8000",
                    "http://localhost:5173","http://localhost:3000","https://api.urbanrealities.com/public"
                ]),

            ],
            [
                'client_name'     => 'Website Frontend',
                'client_id'       => 'WEBSITECLI001',
                'client_secret'   => 'WEBSITESEC001',
                'app_type'        => 'website',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "https://www.urbanrealities.com",
                    "https://urbanrealities.com",
                    "http://localhost:5173",
                    "http://localhost:3000",
                    "https://api.urbanrealities.com/public"
                ]),
                'nextjs_internal_key' =>"HJQ7CHRZOX1EO3WRUA0ONESEQLQBDECMG5FNUFOKID4WTZGUMG",
            ],
            [
                'client_name'     => 'Mobile Application',
                'client_id'       => 'MOBILECLI00001',
                'client_secret'   => 'MOBILESEC00001',
                'app_type'        => 'mobile-app',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "*" // mobile apps ke liye usually CORS check skip karte hain
                ]),

            ],
            [
                'client_name'     => 'Custom Integration',
                 'client_id'       => 'CUSTOMCLI00001',   // 15 chars
                'client_secret'   => 'CUSTOMSEC00001',
                'app_type'        => 'custom',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "https://partner1.com",
                    "https://partner2.com"
                ]),

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
