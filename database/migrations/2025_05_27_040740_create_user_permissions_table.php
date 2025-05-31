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
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('model_name'); // varchar(255)
            $table->unsignedBigInteger('role_id'); // bigint unsigned

            // Permissions as tinyint (boolean style)
            $table->tinyInteger('create')->default(0);
            $table->tinyInteger('edit')->default(0);
            $table->tinyInteger('read')->default(1);
            $table->tinyInteger('delete')->default(0);
            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
