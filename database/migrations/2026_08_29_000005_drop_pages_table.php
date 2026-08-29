<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('pages');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table) {
                $table->id();
                $table->string('title')->nullable();
                $table->string('slug')->nullable();
                $table->string('page')->nullable();
                $table->longText('content')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
    }
};
