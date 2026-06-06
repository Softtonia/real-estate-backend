<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_widgets', function (Blueprint $table) {
            $table->id();

            $table->string('widget_name');
            $table->string('slug')->unique();

            $table->enum('post_type', [
                'basic',
                'property-listing',
                'project-listing',
                'developer-listing'
            ])->default('basic');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('post_type');
            $table->index('widget_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_widgets');
    }
};