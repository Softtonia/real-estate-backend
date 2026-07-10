<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_post_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dynamic_post_id')
                ->constrained('dynamic_posts')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Only one user per listing
            $table->unique('dynamic_post_id', 'dynamic_post_single_user_unique');

            // One user can have many listings
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_post_user');
    }
};