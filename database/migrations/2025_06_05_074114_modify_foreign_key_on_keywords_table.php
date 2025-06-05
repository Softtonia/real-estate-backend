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
        Schema::table('keywords', function (Blueprint $table) {
            // Drop existing foreign key constraint
            $table->dropForeign(['property_id']);

            $table->foreign('property_id')
                ->references('id')
                ->on('properties_listing')  // change this to new table name
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            // Drop the modified foreign key
            $table->dropForeign(['property_id']);


            $table->foreign('property_id')
                ->references('id')
                ->on('properties_listing')
                ->onDelete('cascade');
        });
    }
};
