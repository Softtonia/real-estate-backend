<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


class MailConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('mail_configs')->insert([
            'mailer' => 'smtp',
            'host' => 'mail.holiplaces.com',
            'port' => 465,
            'username' => 'developer@holiplaces.com',
            'password' => 'BGlnjr48(mON',
            'encryption' => 'ssl',
            'from_address' => 'developer@holiplaces.com',
            'from_name' => 'Urban Realities',
            'status' => 1, // Set to 1 for active status
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
