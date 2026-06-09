<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();

            $table->enum('entity_type', [
                'post',
                'taxonomy_term',
                'user'
            ])->default('post');

            $table->unsignedBigInteger('entity_id');

            $table->foreignId('custom_field_id')
                ->constrained('custom_fields')
                ->cascadeOnDelete();

            $table->foreignId('custom_field_option_id')
                ->nullable()
                ->constrained('custom_field_options')
                ->nullOnDelete();

            $table->text('value_text')->nullable();
            $table->string('value_string', 255)->nullable();
            $table->decimal('value_number', 15, 2)->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->json('value_json')->nullable();

            $table->timestamps();

            $table->unique(
                ['entity_type', 'entity_id', 'custom_field_id'],
                'uq_entity_custom_field_value'
            );

            $table->index(['entity_type', 'entity_id'], 'idx_custom_field_values_entity');
            $table->index(['custom_field_id', 'value_string'], 'idx_custom_field_values_string');
            $table->index(['custom_field_id', 'value_number'], 'idx_custom_field_values_number');
            $table->index(['custom_field_id', 'value_date'], 'idx_custom_field_values_date');
            $table->index('custom_field_option_id', 'idx_custom_field_values_option');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
    }
};