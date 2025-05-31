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
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('icon_id')->nullable();
            $table->string('ticket_department_name', 255)->collation('utf8mb4_unicode_ci');
            $table->integer('display_order')->default(0);
            $table->timestamps();


            $table->foreign('icon_id')
                ->references('id')
                ->on('media')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_departments');
    }
};
