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
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id(); // Auto-incrementing ID
            $table->string('model_name'); // The model name (Post, Comment, etc.)
            $table->unsignedBigInteger('role_id'); // The user this permission belongs to
            $table->tinyInteger('create')->default(0); // 0 = not allowed, 1 = allowed
            $table->tinyInteger('edit')->default(0); // 0 = not allowed, 1 = allowed
            $table->tinyInteger('read')->default(1); // 0 = not allowed, 1 = allowed
            $table->tinyInteger('delete')->default(0); // 0 = not allowed, 1 = allowed
            $table->timestamps();

            // Foreign key constraint (ensuring the user_id column references the 'id' column of 'users' table)
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onDelete('cascade'); // Ensures deletion of user will cascade to user permissions
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
