<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_post_relationships', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('dynamic_post_id');
            $table->unsignedBigInteger('related_post_type_id');
            $table->unsignedBigInteger('related_post_id');

            $table->timestamps();

            $table->unique(
                ['dynamic_post_id', 'related_post_type_id', 'related_post_id'],
                'dpr_unique_relation'
            );

            $table->index(['dynamic_post_id'], 'dpr_post_idx');
            $table->index(['related_post_type_id'], 'dpr_type_idx');
            $table->index(['related_post_id'], 'dpr_related_post_idx');

            $table->foreign('dynamic_post_id', 'dpr_post_fk')
                ->references('id')
                ->on('dynamic_posts')
                ->cascadeOnDelete();

            $table->foreign('related_post_type_id', 'dpr_type_fk')
                ->references('id')
                ->on('post_types')
                ->cascadeOnDelete();

            $table->foreign('related_post_id', 'dpr_related_post_fk')
                ->references('id')
                ->on('dynamic_posts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_post_relationships');
    }
};
