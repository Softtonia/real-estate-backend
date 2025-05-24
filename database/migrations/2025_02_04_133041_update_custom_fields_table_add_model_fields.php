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
        Schema::table('custom_fields', function (Blueprint $table) {
            // Remove the old 'model' and 'condition' columns
            $table->dropColumn(['model', 'condition']);
    
            // Add the 'model_fields' column to store the model data as a JSON array
            $table->json('model_fields')->nullable(); // Store as JSON array
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            // Add 'model' and 'condition' columns back (in case of rollback)
            $table->string('model')->nullable();
            $table->json('condition')->nullable();
    
            // Remove the 'model_fields' column
            $table->dropColumn('model_fields');
        });
    }
};
