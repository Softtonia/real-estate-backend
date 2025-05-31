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
        Schema::create('project_analytics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('type', 30)->default('view');
            $table->timestamp('created_at', 6)->nullable()->default(DB::raw('CURRENT_TIMESTAMP(6)'));
            $table->timestamp('updated_at', 6)->nullable()->default(DB::raw('CURRENT_TIMESTAMP(6)'));

             $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
             $table->foreign('project_id')->references('id')->on('project_listings')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_analytics');
    }
};
