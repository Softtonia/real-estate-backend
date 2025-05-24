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
        Schema::table('roles', function (Blueprint $table) {
            // Add the necessary fields to the roles table
           
            $table->tinyInteger('is_default')->default(0); // Default to 0
           
           
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn([
                'is_admin_login_permission',
                'prefix',
                'guard_name',
                'is_default',
                'created_by'
            ]);
        });
    }
};
