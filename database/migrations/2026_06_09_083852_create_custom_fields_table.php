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

            $table->foreignId('custom_field_group_id')
                ->constrained('custom_field_groups')
                ->cascadeOnDelete();

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

            $table->integer('media_limit')->nullable();
            $table->string('media_size', 100)->nullable();
            $table->string('media_format', 255)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->unique(
                ['custom_field_group_id', 'field_name_slug'],
                'uq_custom_field_group_slug'
            );

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['custom_field_group_id', 'status'], 'idx_cf_group_status');
            $table->index(['sort_order', 'status'], 'idx_cf_sort_status');
            $table->index('created_by', 'idx_cf_created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};