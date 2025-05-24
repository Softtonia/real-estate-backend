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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone', 200)->nullable()->after('email');
            $table->integer('country_code')->nullable()->after('phone');
            $table->text('api_token')->nullable()->after('password');
            $table->string('role_id', 200)->nullable()->after('api_token');
            $table->string('verification_code')->nullable()->after('role_id');
            $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code');
            $table->string('unique_id', 200)->nullable()->after('verification_code_expires_at');
            $table->string('requestId', 255)->nullable()->after('unique_id');
            $table->integer('isapproved')->nullable()->after('requestId')->comment('Active=1, Deactive=2, UnderReview=3');
            $table->bigInteger('created_by')->unsigned()->default(0)->after('isapproved');
            $table->integer('email_otp')->nullable()->after('created_by');
            $table->timestamp('email_otp_expires_at')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'))->after('email_otp');
            $table->text('deactive_reason')->nullable()->after('email_otp_expires_at');
            $table->timestamp('token_created_at')->nullable()->after('deactive_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name', 'last_name', 'phone', 'country_code', 'api_token',
                'role_id', 'verification_code', 'verification_code_expires_at',
                'unique_id', 'requestId', 'isapproved', 'created_by',
                'email_otp', 'email_otp_expires_at', 'deactive_reason', 'token_created_at'
            ]);
        });
    }
};
