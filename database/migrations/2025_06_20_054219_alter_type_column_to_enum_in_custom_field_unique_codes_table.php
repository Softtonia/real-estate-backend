<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('custom_field_unique_codes', function (Blueprint $table) {

            // Rename column and change to ENUM
            // Rename column using raw SQL (MariaDB-compatible)
            DB::statement("ALTER TABLE custom_field_unique_codes CHANGE `type` `post_type` ENUM('project_list', 'property_list', 'developer_list') DEFAULT 'project_list'");

            DB::statement("ALTER TABLE custom_field_unique_codes
                       MODIFY post_type ENUM('project_list', 'property_list', 'developer_list')
                       DEFAULT 'project_list'");

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_field_unique_codes', function (Blueprint $table) {


            // Revert ENUM to VARCHAR and rename back
            DB::statement("ALTER TABLE custom_field_unique_codes
                       MODIFY post_type VARCHAR(30)");


        });
    }
};
