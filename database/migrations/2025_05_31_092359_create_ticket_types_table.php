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
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('icon_id')->nullable();
            $table->string('ticket_type_name', 100)->nullable();
            $table->integer('display_order')->nullable();
            $table->integer('status')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('updated_at', 6)->useCurrent();

            $table->foreign('icon_id')->references('id')->on('media')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
    }
};
