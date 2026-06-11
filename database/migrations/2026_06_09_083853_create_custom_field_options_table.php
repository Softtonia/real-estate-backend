<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('custom_field_id')
                ->constrained('custom_fields')
                ->cascadeOnDelete();

            $table->string('type', 50)->nullable();
            $table->string('name', 150);
            $table->string('value', 150);

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->timestamps();

            $table->index(['custom_field_id', 'status'], 'idx_cfo_field_status');
            $table->index(['sort_order', 'status'], 'idx_cfo_sort_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_options');
    }
};