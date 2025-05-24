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
            // Remove columns first
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['consultancy_id']);
            $table->dropForeign(['company_id']);

            $table->dropColumn(['agent_id', 'consultancy_id', 'company_id']);

            // Add user_id with correct foreign key reference
            $table->unsignedBigInteger('user_id')->after('id'); 
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('join_requests', function (Blueprint $table) {
            // Rollback changes
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            // Re-add previous columns if needed
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('consultancy_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
        });
    }
};
