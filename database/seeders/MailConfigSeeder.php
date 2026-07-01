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
            'host' => 'smtp.zoho.in',
            'port' => 465,
            'username' => 'vijay.kumar@softtonia.com',
            'password' => 'sRVURao1',
            'encryption' => 'ssl',
            'from_address' => 'vijay.kumar@softtonia.com',
            'from_name' => 'Holiplaces',
            'status' => 1, // Set to 1 for active status
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
