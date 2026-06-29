<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_revisions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('template_id');

            $table->json('layout_json')->nullable();
            $table->json('conditions_json')->nullable();

            $table->string('revision_type')->default('layout_save');
            $table->string('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('template_id');
            $table->index('revision_type');
            $table->index('created_by');

            $table->foreign('template_id')
                ->references('id')
                ->on('templates')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_revisions');
    }
};