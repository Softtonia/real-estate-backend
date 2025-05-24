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
            // Add purpose_id column as foreign key
            $table->unsignedBigInteger('purpose_id')->nullable();
            $table->foreign('purpose_id')->references('id')->on('purpose');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties_listing', function (Blueprint $table) {
            // Drop the foreign key constraint and the purpose_id column
            $table->dropForeign(['purpose_id']);
            $table->dropColumn('purpose_id');
        });
    }
};
