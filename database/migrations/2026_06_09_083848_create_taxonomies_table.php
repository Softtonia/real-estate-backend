<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomies', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();

            $table->boolean('is_default')
                ->default(false)
                ->comment('1 = default system taxonomy, 0 = custom admin-created taxonomy');

            $table->boolean('hierarchical')
                ->default(false)
                ->comment('1 = hierarchical taxonomy with parent-child terms, 0 = flat taxonomy');

            $table->boolean('status')
                ->default(true)
                ->comment('1 = active, 0 = inactive');

            $table->unsignedBigInteger('created_by')->nullable();

            $table->unsignedInteger('sort_order')
                ->default(0)
                ->comment('Controls display ordering in admin panel');

            $table->unsignedInteger('menu_order')
                ->nullable()
                ->comment('1-5 reserved for system/admin default taxonomies');

            $table->timestamps();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['slug', 'status']);
            $table->index(['is_default', 'status']);
            $table->index(['sort_order', 'status']);
            $table->index(['menu_order', 'status']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomies');
    }
};