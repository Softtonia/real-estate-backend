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
        Schema::table('join_requests', function (Blueprint $table) {
            // Drop foreign keys if they exist
            $foreignKeys = DB::select("SELECT CONSTRAINT_NAME 
                                       FROM information_schema.KEY_COLUMN_USAGE 
                                       WHERE TABLE_NAME = 'join_requests' 
                                       AND TABLE_SCHEMA = DATABASE() 
                                       AND REFERENCED_TABLE_NAME IS NOT NULL");

            foreach ($foreignKeys as $foreignKey) {
                $table->dropForeign([$foreignKey->CONSTRAINT_NAME]);
            }

            // Drop the columns
            if (Schema::hasColumn('join_requests', 'agent_id')) {
                $table->dropColumn('agent_id');
            }
            if (Schema::hasColumn('join_requests', 'consultancy_id')) {
                $table->dropColumn('consultancy_id');
            }
            if (Schema::hasColumn('join_requests', 'company_id')) {
                $table->dropColumn('company_id');
            }

            // Add user_id column with correct data type
            if (!Schema::hasColumn('join_requests', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('join_requests', function (Blueprint $table) {
            // Remove user_id column
            if (Schema::hasColumn('join_requests', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }

            // Re-add the columns
            if (!Schema::hasColumn('join_requests', 'agent_id')) {
                $table->unsignedBigInteger('agent_id')->nullable();
            }
            if (!Schema::hasColumn('join_requests', 'consultancy_id')) {
                $table->unsignedBigInteger('consultancy_id')->nullable();
            }
            if (!Schema::hasColumn('join_requests', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable();
            }
        });
    }};
