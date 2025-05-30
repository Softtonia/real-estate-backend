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
        Schema::create('help_childcategories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('help_category_id')->nullable();
            $table->unsignedBigInteger('help_subcategory_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamp('created_at')->useCurrent()->nullable();
            $table->timestamp('updated_at')->useCurrent()->nullable();

            $table->foreign('help_category_id')->references('id')->on('help_categories')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('help_subcategory_id')->references('id')->on('help_subcategories')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('help_childcategories');
    }
};
