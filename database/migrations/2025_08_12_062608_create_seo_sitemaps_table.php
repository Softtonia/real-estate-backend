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
        Schema::create('seo_sitemaps', function (Blueprint $table) {
            $table->id();
            $table->string('url')->unique();
            $table->enum('frequency', ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'])->default('monthly');
            $table->decimal('priority', 2, 1)->default(0.5);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_modified')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_sitemaps');
    }
};
