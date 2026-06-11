<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_groups', function (Blueprint $table) {
            $table->id();

            $table->string('group_name', 200);
            $table->string('group_slug', 200)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('created_by', 'idx_cfg_created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_groups');
    }
};