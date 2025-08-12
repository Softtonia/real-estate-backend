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
        Schema::create('seo_schema_markups', function (Blueprint $table) {
            $table->id();
             $table->string('page_url')->unique();
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('schema_type'); // e.g., 'Article', 'Organization' $table->json('schema_json');
            $table->json('schema_json');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['model_type', 'model_id'], 'idx_schema_model');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_schema_markups');
    }
};
