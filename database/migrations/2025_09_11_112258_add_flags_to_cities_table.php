<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
             // Add is_popular column (0 = not popular, 1 = popular)
            $table->boolean('is_popular')
                  ->default(0)
                  ->comment('Mark city as popular (1 = yes, 0 = no)')
                  ->after('state_id');

            // Add is_nearby column (0 = not nearby, 1 = nearby)
            $table->boolean('is_nearby')
                  ->default(0)
                  ->comment('Mark city as nearby (1 = yes, 0 = no)')
                  ->after('is_popular');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            //
            $table->dropColumn(['is_popular', 'is_nearby']);
        });
    }
};
