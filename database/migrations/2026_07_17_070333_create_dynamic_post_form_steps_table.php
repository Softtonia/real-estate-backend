<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dynamic_post_form_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('post_type_id')
                ->constrained('post_types')
                ->cascadeOnDelete();

            $table->string('step_key', 50); // step-1, step-2
            $table->string('step_label'); // Basic Details
            $table->text('description')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['post_type_id', 'step_key'], 'dp_form_steps_post_step_unique');
            $table->index(['post_type_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_post_form_steps');
    }
};