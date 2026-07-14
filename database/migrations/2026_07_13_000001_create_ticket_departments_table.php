<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('icon_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('ticket_department_name', 255)->unique();
            $table->unsignedInteger('display_order')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_departments');
    }
};
