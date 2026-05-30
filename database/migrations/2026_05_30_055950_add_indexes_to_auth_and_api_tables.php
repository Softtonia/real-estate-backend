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
        // api_clients table
        Schema::table('api_clients', function (Blueprint $table) {
            $table->index(['client_id', 'status'], 'idx_client_id_status');
            $table->index('client_secret', 'idx_client_secret');
            $table->index('last_used_at', 'idx_last_used_at');
        });

        // user_ip_logs table
        Schema::table('user_ip_logs', function (Blueprint $table) {
            $table->index('user_id', 'idx_user_id');
            $table->index('ip_address', 'idx_ip_address');
            $table->index('status', 'idx_status');
            $table->index('blocked_at', 'idx_blocked_at');
        });

        // otps table
        Schema::table('otps', function (Blueprint $table) {
            $table->index('user_id', 'idx_user_id');
            $table->index('otp', 'idx_otp');
            $table->index('expire_date_time', 'idx_expire_date_time');
        });

        // lead_otps table
        Schema::table('lead_otps', function (Blueprint $table) {
            $table->index('phone', 'idx_phone');
            $table->index('email', 'idx_email');
            $table->index('otp', 'idx_otp');
            $table->index('expires_at', 'idx_expires_at');
        });

        // password_resets table
        Schema::table('password_resets', function (Blueprint $table) {
            $table->index('email', 'idx_email');
        });

        // password_reset_tokens table
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->index('email', 'idx_email');
            $table->index('token', 'idx_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropIndex('idx_client_id_status');
            $table->dropIndex('idx_client_secret');
            $table->dropIndex('idx_last_used_at');
        });

        Schema::table('user_ip_logs', function (Blueprint $table) {
            $table->dropIndex('idx_user_id');
            $table->dropIndex('idx_ip_address');
            $table->dropIndex('idx_status');
            $table->dropIndex('idx_blocked_at');
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex('idx_user_id');
            $table->dropIndex('idx_otp');
            $table->dropIndex('idx_expire_date_time');
        });

        Schema::table('lead_otps', function (Blueprint $table) {
            $table->dropIndex('idx_phone');
            $table->dropIndex('idx_email');
            $table->dropIndex('idx_otp');
            $table->dropIndex('idx_expires_at');
        });

        Schema::table('password_resets', function (Blueprint $table) {
            $table->dropIndex('idx_email');
        });

        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropIndex('idx_email');
            $table->dropIndex('idx_token');
        });
    }
};