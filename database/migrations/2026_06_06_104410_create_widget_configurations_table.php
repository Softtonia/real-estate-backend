<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_configurations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('widget_id')
                ->constrained('custom_widgets')
                ->cascadeOnDelete();

            $table->string('field_key');
            $table->longText('field_value')->nullable();

            $table->timestamps();

            $table->index('widget_id');
            $table->index('field_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_configurations');
    }
};