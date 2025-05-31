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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 200)->nullable()->collation('latin1_swedish_ci');
            $table->integer('raised_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('subject', 200)->nullable()->collation('latin1_swedish_ci');
            $table->longText('message')->nullable()->collation('latin1_swedish_ci');
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('priority_id')->nullable();
            $table->string('media_attachment', 200)->nullable()->collation('latin1_swedish_ci');
            $table->unsignedBigInteger('ticket_type_id')->nullable();
            $table->unsignedBigInteger('ticket_department_id');
            $table->timestamp('created_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('updated_at')->nullable();

           $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('status_id')->references('id')->on('ticket_status')->onDelete('set null');
            $table->foreign('priority_id')->references('id')->on('ticket_priorities')->onDelete('set null');
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types')->onDelete('set null');
            $table->foreign('ticket_department_id')->references('id')->on('ticket_departments')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
