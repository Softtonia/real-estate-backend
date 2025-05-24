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
        Schema::table('project_listings', function (Blueprint $table) {
            $table->dropColumn(['location_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_listings', function (Blueprint $table) {
            $table->integer('location_id')->nullable(); // Adjust type and constraints as needed
            $table->string('status')->nullable(); // Adjust type and constraints as needed
        });
    }
};
