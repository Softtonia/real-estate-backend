<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            /*
            |--------------------------------------------------------------------------
            | Critical Authentication Indexes
            |--------------------------------------------------------------------------
            */

            // Login with email
            $table->index('email', 'users_email_index');

            // Login with phone
            $table->index('phone', 'users_phone_index');

            // Google login
            $table->index('google_id', 'users_google_id_index');

            // API token based authentication
            $table->index('token_created_at', 'users_token_created_at_index');

            /*
            |--------------------------------------------------------------------------
            | User Status / Approval / KYC Indexes
            |--------------------------------------------------------------------------
            */

            // Active / inactive user filter
            $table->index('isapproved', 'users_isapproved_index');

            // KYC status filter
            $table->index('kyc', 'users_kyc_index');

            // OTP verified filter
            $table->index('is_otp_verified', 'users_is_otp_verified_index');

            /*
            |--------------------------------------------------------------------------
            | Relationship / Admin Filters
            |--------------------------------------------------------------------------
            */

            // Created by admin / sub admin filter
            $table->index('created_by', 'users_created_by_index');

            // Role based user filter
            $table->index('role_id', 'users_role_id_index');

            // Unique user id lookup
            $table->index('unique_id', 'users_unique_id_index');

            /*
            |--------------------------------------------------------------------------
            | Date Based Filters
            |--------------------------------------------------------------------------
            */

            // Recently created users
            $table->index('created_at', 'users_created_at_index');

            // Updated users
            $table->index('updated_at', 'users_updated_at_index');

            /*
            |--------------------------------------------------------------------------
            | Composite Indexes for Common API Queries
            |--------------------------------------------------------------------------
            */

            // Login APIs usually check email + status
            $table->index(['email', 'isapproved'], 'users_email_isapproved_index');

            // Phone login + status
            $table->index(['phone', 'isapproved'], 'users_phone_isapproved_index');

            // Admin user listing filters
            $table->index(['role_id', 'isapproved'], 'users_role_isapproved_index');

            // KYC listing filters
            $table->index(['kyc', 'isapproved'], 'users_kyc_isapproved_index');

            // Latest active users
            $table->index(['isapproved', 'created_at'], 'users_isapproved_created_at_index');

            // Created by admin with status
            $table->index(['created_by', 'isapproved'], 'users_created_by_isapproved_index');
                // API token lookup
            $table->index('api_token', 'users_api_token_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            $table->dropIndex('users_email_index');
            $table->dropIndex('users_phone_index');
            $table->dropIndex('users_google_id_index');
            $table->dropIndex('users_token_created_at_index');

            $table->dropIndex('users_isapproved_index');
            $table->dropIndex('users_kyc_index');
            $table->dropIndex('users_is_otp_verified_index');

            $table->dropIndex('users_created_by_index');
            $table->dropIndex('users_role_id_index');
            $table->dropIndex('users_unique_id_index');

            $table->dropIndex('users_created_at_index');
            $table->dropIndex('users_updated_at_index');

            $table->dropIndex('users_email_isapproved_index');
            $table->dropIndex('users_phone_isapproved_index');
            $table->dropIndex('users_role_isapproved_index');
            $table->dropIndex('users_kyc_isapproved_index');
            $table->dropIndex('users_isapproved_created_at_index');
            $table->dropIndex('users_created_by_isapproved_index');
             $table->dropIndex('users_api_token_index');
        });
    }
};