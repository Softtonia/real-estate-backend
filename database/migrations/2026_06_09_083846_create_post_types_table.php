<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_types', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();

            $table->boolean('is_default')
                ->default(false)
                ->comment('1 = default system post type, 0 = custom admin-created post type');

            $table->boolean('status')
                ->default(true)
                ->comment('1 = active, 0 = inactive');

            $table->json('supports')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['slug', 'status']);
            $table->index(['is_default', 'status']);
            $table->index(['sort_order', 'status']);
            $table->index('created_by');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_types');
    }
};