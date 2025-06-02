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
        Schema::table('status', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['property_type_id']);
        });

        Schema::table('status', function (Blueprint $table) {
            $table->string('property_type_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('status', function (Blueprint $table) {
            // Change back to unsignedBigInteger
            $table->unsignedBigInteger('property_type_id')->change();
        });

        Schema::table('status', function (Blueprint $table) {
            // Recreate foreign key constraint if needed
            $table->foreign('property_type_id')->references('id')->on('property_types')->onDelete('cascade');
        });
    }
};
