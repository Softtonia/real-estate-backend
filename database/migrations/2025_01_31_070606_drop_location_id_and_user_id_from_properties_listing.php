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
        Schema::table('properties_listing', function (Blueprint $table) {
             // Drop the location_id and user_id columns
             $table->dropColumn('location_id');
             $table->dropColumn('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties_listing', function (Blueprint $table) {
            // Restore the location_id and user_id columns in case of rollback
            $table->string('location_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
        });
    }
};
