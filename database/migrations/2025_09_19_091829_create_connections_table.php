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
        Schema::create('connections', function (Blueprint $table) {
             $table->bigIncrements('id');

            $table->unsignedBigInteger('requester_id')->index();
            $table->unsignedBigInteger('receiver_id')->index();

            $table->enum('state', ['pending', 'accepted', 'rejected', 'left'])->default('pending')->index();

            $table->string('note', 1000)->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('rejected_at')->nullable()->index();
            $table->timestamp('left_at')->nullable()->index();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('requester_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index(['requester_id', 'state'], 'conn_requester_state_idx');
            $table->index(['receiver_id', 'state'], 'conn_receiver_state_idx');
            $table->index(['requester_id', 'receiver_id'], 'conn_requester_receiver_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
