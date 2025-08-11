<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
        $this->call(RoleSeeder::class);
        $this->call(AdminSeeder::class);
        $this->call(MailConfigSeeder::class);
        $this->call(PurposeSeeder::class);
        $this->call(PropertySeeder::class);
        $this->call(PropertyTypeSeeder::class);
        $this->call(ModelsTableSeeder::class);
        $this->call(StatusSeeder::class);
        $this->call(MediaSeeder::class);
        $this->call(AmenitiesCategoriesSeeder::class);
        $this->call(AmenitySeeder::class);
        $this->call(ImportKeywordsSeeder::class);
        $this->call(TicketDepartmentsSeeder::class);
        $this->call(TicketTypesTableSeeder::class);
        $this->call(TicketPrioritiesTableSeeder::class);
        $this->call(TicketStatusTableSeeder::class);
        $this->call(HelpCategorySeeder::class);
        $this->call(HelpSubcategorySeeder::class);
         $this->call(HelpChildcategorySeeder::class);
         $this->call([ApiClientTableSeeder::class]);
         $this->call(SiteSettingsSeeder::class);


    }
}
