<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_post_form_step_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_type_id')
                ->constrained('post_types')
                ->cascadeOnDelete();

            $table->foreignId('dynamic_post_form_step_id')
                ->constrained('dynamic_post_form_steps')
                ->cascadeOnDelete();

            $table->foreignId('custom_field_id')
                ->constrained('custom_fields')
                ->cascadeOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One custom field can be mapped only once for one post type
            $table->unique(['post_type_id', 'custom_field_id'], 'dp_form_step_field_unique');

            $table->index(
                ['post_type_id', 'dynamic_post_form_step_id'],
                'dp_form_step_field_step_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_post_form_step_fields');
    }
};