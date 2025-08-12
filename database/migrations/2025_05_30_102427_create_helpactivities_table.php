<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('helpactivities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('help_article_id');
            $table->integer('like')->default(0);
            $table->integer('dislike')->default(0);
            $table->enum('type', ['blog', 'help'])->nullable();
            $table->timestamps();

            $table->foreign('help_article_id')->references('id')->on('help_articles')->onDelete('cascade');


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('helpactivities');
    }
};
