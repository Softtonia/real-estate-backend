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
        Schema::table('subscribed_emails', function (Blueprint $table) {
            DB::statement("ALTER TABLE subscribed_emails CHANGE email subscribe_email VARCHAR(255)");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribed_emails', function (Blueprint $table) {
            // 
        });
    }
};
