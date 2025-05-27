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
        Schema::create('mail_configs', function (Blueprint $table) {
            $table->id();
            $table->string('mailer', 200);
            $table->string('host', 200);
            $table->integer('port');
            $table->string('username', 200);
            $table->string('password', 200);
            $table->string('encryption', 200)->nullable();
            $table->string('from_address', 200);
            $table->string('from_name', 200);
            $table->integer('status')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_configs');
    }
};
