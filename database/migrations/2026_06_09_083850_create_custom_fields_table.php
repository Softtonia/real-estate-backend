<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('group_id')->nullable();

            $table->enum('entity_type', [
                'post',
                'taxonomy'
            ])->default('post');

            $table->foreignId('post_type_id')
                ->nullable()
                ->constrained('post_types')
                ->nullOnDelete();

            $table->foreignId('taxonomy_id')
                ->nullable()
                ->constrained('taxonomies')
                ->nullOnDelete();

            $table->string('field_label', 255);
            $table->string('field_name_slug', 255);
            $table->string('field_placeholder', 255)->nullable();

            $table->enum('field_type', [
                'text',
                'texteditor',
                'textarea',
                'number',
                'email',
                'url',
                'date',
                'datetime',
                'boolean',
                'checkbox',
                'radio',
                'select',
                'repeater',
                'media',
                'file'
            ]);

            $table->enum('required', [
                'yes',
                'no'
            ])->default('no');

            $table->string('checkbox_type', 100)->nullable();

            $table->text('default_value')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('conditional_rules')->nullable();

            $table->unsignedBigInteger('template_id')->nullable();

            $table->integer('media_limit')->nullable();
            $table->string('media_size', 100)->nullable();
            $table->string('media_format', 255)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->unique(
                ['entity_type', 'post_type_id', 'field_name_slug'],
                'uq_custom_field_post_type_slug'
            );

            $table->unique(
                ['entity_type', 'taxonomy_id', 'field_name_slug'],
                'uq_custom_field_taxonomy_slug'
            );

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['entity_type', 'post_type_id', 'status'], 'idx_custom_fields_post_type_status');
            $table->index(['entity_type', 'taxonomy_id', 'status'], 'idx_custom_fields_taxonomy_status');
            $table->index(['group_id', 'status'], 'idx_custom_fields_group_status');
            $table->index(['sort_order', 'status'], 'idx_custom_fields_sort_order_status');
            $table->index('template_id', 'idx_custom_fields_template_id');
            $table->index('created_by', 'idx_custom_fields_created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};