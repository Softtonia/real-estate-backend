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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_admin_login_permission')->default(0)->comment('0 = Not Allowed, 1 = Allowed');
            $table->string('prefix')->nullable();
            $table->string('guard_name')->default('web');
            $table->boolean('is_default')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();

            // Foreign key (optional, if created_by references users table)
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
