<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_repeater_values', function (Blueprint $table) {
            $table->id();

            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');

            $table->unsignedBigInteger('custom_field_id');
            $table->unsignedBigInteger('custom_field_repeater_id');
            $table->unsignedBigInteger('custom_field_repeater_option_id')->nullable();

            $table->string('field_label')->nullable();
            $table->string('field_name_slug')->nullable();
            $table->string('field_type')->nullable();

            $table->unsignedInteger('row_index')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('unique_id')->nullable();

            $table->longText('field_meta_value')->nullable();
            $table->string('value_string')->nullable();
            $table->longText('value_text')->nullable();
            $table->decimal('value_number', 15, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->json('value_json')->nullable();

            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'cf_rv_entity_idx');
            $table->index(['custom_field_id'], 'cf_rv_cf_idx');
            $table->index(['custom_field_repeater_id'], 'cf_rv_rep_idx');
            $table->index(['custom_field_repeater_option_id'], 'cf_rv_opt_idx');
            $table->index(['entity_type', 'entity_id', 'custom_field_id', 'row_index'], 'cf_rv_main_idx');

            $table->foreign('custom_field_id', 'cf_rv_cf_fk')
                ->references('id')
                ->on('custom_fields')
                ->cascadeOnDelete();

            $table->foreign('custom_field_repeater_id', 'cf_rv_rep_fk')
                ->references('id')
                ->on('custom_field_repeaters')
                ->cascadeOnDelete();

            $table->foreign('custom_field_repeater_option_id', 'cf_rv_opt_fk')
                ->references('id')
                ->on('custom_field_repeater_options')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_repeater_values');
    }
};