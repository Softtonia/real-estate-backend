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
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('google_id')->nullable();
            $table->string('phone', 200)->nullable();
            $table->string('password')->nullable();
            $table->text('remember_token')->nullable();
            $table->text('api_token')->nullable();

            $table->string('role_id', 200)->nullable();
            $table->string('unique_id', 200)->nullable();
            $table->integer('isapproved')->nullable()
                ->comment('Active=1, Deactive=2, UnderReview=3, Reject=4');
            $table->text('reject_reason')->nullable()
                ->comment('Reason for rejection if user is rejected');
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamp('email_otp_expires_at')->useCurrent()->nullable();
            $table->timestamp('token_created_at')->nullable();
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
