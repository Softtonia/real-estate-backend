<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('display_conditions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('template_id')
                ->constrained('templates')
                ->cascadeOnDelete();

            $table->enum('show_type', ['include', 'exclude']);

            $table->enum('post_type', [
                'property-listing',
                'project-listing',
                'developer-listing'
            ]);

            $table->enum('condition_type', [
                'all',
                'purpose',
                'property',
                'property-type',
                'property-status'
            ]);

            $table->string('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('display_conditions');
    }
};