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
        Schema::create('ticket_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('icon_id')->nullable();
            $table->string('ticket_status_name', 200)->collation('latin1_swedish_ci');
            $table->integer('display_order')->nullable();
            $table->timestamp('created_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('updated_at')->useCurrent();

            $table->foreign('icon_id')
                ->references('id')->on('media')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_status');
    }
};
