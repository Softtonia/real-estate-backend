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
        // Step 1: Temporarily change ENUM to VARCHAR to avoid conversion issues
        Schema::table('join_requests', function (Blueprint $table) {
            $table->string('status', 20)->change();
        });

        // Step 2: Update existing ENUM values to integer values
        DB::statement("UPDATE join_requests SET status = '1' WHERE status = 'requested'");
        DB::statement("UPDATE join_requests SET status = '2' WHERE status = 'accepted'");
        DB::statement("UPDATE join_requests SET status = '3' WHERE status = 'rejected'");
        DB::statement("UPDATE join_requests SET status = '4' WHERE status = 'normal'");
        DB::statement("UPDATE join_requests SET status = '5' WHERE status = 'leaved'");

        // Step 3: Change column type from VARCHAR to INTEGER
        Schema::table('join_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')
                  ->default(1)
                  ->comment('1 = Requested, 2 = Accepted, 3 = Rejected, 4 = Normal, 5 = Leaved')
                  ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert column to VARCHAR before changing back to ENUM
        Schema::table('join_requests', function (Blueprint $table) {
            $table->string('status', 20)->change();
        });

        // Convert integer values back to ENUM strings
        DB::statement("UPDATE join_requests SET status = 'requested' WHERE status = '1'");
        DB::statement("UPDATE join_requests SET status = 'accepted' WHERE status = '2'");
        DB::statement("UPDATE join_requests SET status = 'rejected' WHERE status = '3'");
        DB::statement("UPDATE join_requests SET status = 'normal' WHERE status = '4'");
        DB::statement("UPDATE join_requests SET status = 'leaved' WHERE status = '5'");

        // Change column back to ENUM
        Schema::table('join_requests', function (Blueprint $table) {
            $table->enum('status', ['requested', 'accepted', 'rejected', 'normal', 'leaved'])
                  ->default('requested')
                  ->change();
        });
    }

};
