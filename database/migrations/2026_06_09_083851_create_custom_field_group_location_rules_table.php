<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('custom_field_group_location_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_group_id')->constrained('custom_field_groups')->cascadeOnDelete();
            $table->enum('show_if', ['post_type', 'taxonomy']);
            $table->enum('match_type', ['all', 'specific'])->default('specific');
            $table->foreignId('post_type_id')->nullable()->constrained('post_types')->nullOnDelete();
            $table->foreignId('taxonomy_id')->nullable()->constrained('taxonomies')->nullOnDelete();
            $table->json('taxonomy_term_ids')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['custom_field_group_id', 'show_if', 'post_type_id', 'taxonomy_id'], 'uq_group_location_rule');
            $table->index(['custom_field_group_id', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('custom_field_group_location_rules');
    }
};