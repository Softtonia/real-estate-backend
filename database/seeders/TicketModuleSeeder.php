<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TicketModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TicketDepartmentsSeeder::class,
            TicketPrioritiesTableSeeder::class,
            TicketStatusTableSeeder::class,
            TicketTypesTableSeeder::class,
        ]);
    }
}