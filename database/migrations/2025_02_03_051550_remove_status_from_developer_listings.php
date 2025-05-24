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
        Schema::table('developer_listings', function (Blueprint $table) {
            $table->dropColumn('status'); // Remove the status column
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('developer_listings', function (Blueprint $table) {
            $table->string('status')->nullable(); // Restore the status column if rollback is needed
        });
    }
};
