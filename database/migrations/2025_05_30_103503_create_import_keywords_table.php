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
        Schema::create('import_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword_name', 100)->nullable();
            $table->string('slug', 255)->unique()->nullable();
            $table->enum('keyword_type', ['property_keyword', 'project_keyword', 'developer_keyword'])->default('property_keyword');
            $table->timestamp('created_at', 6)->nullable()->useCurrent();
            $table->timestamp('updated_at', 6)->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_keywords');
    }
};
