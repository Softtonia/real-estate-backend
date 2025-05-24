<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHelpActivitiesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('helpactivities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('help_article_id'); // Remove foreign key constraint
            $table->integer('like')->default(0);
            $table->integer('dislike')->default(0);
            $table->enum('type', ['blog', 'help'])->nullable();
            $table->unsignedBigInteger('user_id')->nullable(); // Remove foreign key constraint
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('helpactivities');
    }
}