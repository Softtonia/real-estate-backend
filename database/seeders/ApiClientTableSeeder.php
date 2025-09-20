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
                'client_id'       => 'OPUVVNR3XNCXPDL',
                'client_secret'   => '6CNWWJNWAQFO95D',
                'app_type'        => 'admin',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "https://admin.urbanrealities.com",
                    "http://admin.urbanrealities.com",
                ]),
            ],
            [
                'client_name'     => 'API Key Port :5173',
                'client_id'       => '8CTPOXSMXTWDJIK',
                'client_secret'   => 'EYOY2ERCWHJ4KW7',
                'app_type'        => 'admin',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "http://localhost:5173"
                ]),
            ],
            [
                'client_name'     => 'API Key Port : 8000',
                'client_id'       => 'PX3DUI1NBRQCTGS',
                'client_secret'   => 'R6LAWNCAACQP27R',
                'app_type'        => 'admin',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "http://127.0.0.1:8000",
                ]),
            ],
            [
                'client_name'     => 'Business.com',
                 'client_id'       => 'IFMODQP8ZZOAUU2',   // 15 chars
                'client_secret'   => 'E9GUFBVPLCTTZRL',
                'app_type'        => 'business',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "https://business.urbanrealities.com",
                "http://business.urbanrealities.com"
                ]),

            ],
            [
                'client_name'     => 'Business Localhost API Key Port :5173',
                 'client_id'       => 'ZAYYL8IQDWCRAAZ',   // 15 chars
                'client_secret'   => 'EGFFBPEYXGDHP5R',
                'app_type'        => 'business',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                   "http://localhost:5173"
                ]),

            ],
            [
                'client_name'     => 'API Key : 3000',
                'client_id'       => 'LD5TNUNOKREYBI2',
                'client_secret'   => '8UJIIPRSUJK4NNI',
                'app_type'        => 'website',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "http://localhost:3000"
                ]),
                'nextjs_internal_key' => "XS7N2XMQNAXKMM0XYYWF1EGGZEWHHWXAYQPGX8RV1YNXHHLR1D",

            ],
            [
                'client_name'     => 'Urbanrealities.com',
                'client_id'       => 'KWTWGGSBIZGD7GZ',
                'client_secret'   => 'DLERJBYZ6QZCW0U',
                'app_type'        => 'website',
                'status'          => '1',
                'allowed_domain'  => json_encode([
                    "https://www.urbanrealities.com",
                    "https://urbanrealities.com",
                    "http://urbanrealities.com"
                ]),
                'nextjs_internal_key' =>"PMCVGOEQZQQUNZODZTNKXAQC10QYLW04HAF316DEDXD7YWD5VR",
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
