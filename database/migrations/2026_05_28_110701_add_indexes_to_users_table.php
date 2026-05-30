<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Modify columns
        DB::statement("ALTER TABLE users MODIFY phone VARCHAR(20) NULL");
        DB::statement("ALTER TABLE users MODIFY api_token VARCHAR(255) NULL"); // changed from TEXT
        DB::statement("ALTER TABLE users MODIFY remember_token VARCHAR(100) NULL");
        DB::statement("ALTER TABLE users MODIFY unique_id VARCHAR(50) NULL");

        // Add indexes
        Schema::table('users', function (Blueprint $table) {

            // Authentication / login
            $table->index('email', 'users_email_index');
            $table->index('phone', 'users_phone_index');
            $table->index('google_id', 'users_google_id_index');
            $table->index('token_created_at', 'users_token_created_at_index');
            $table->index('api_token', 'users_api_token_index'); // works now because VARCHAR(255)

            // User status / KYC
            $table->index('isapproved', 'users_isapproved_index');
            $table->index('kyc', 'users_kyc_index');
            $table->index('is_otp_verified', 'users_is_otp_verified_index');

            // Relationship / admin filters
            $table->index('created_by', 'users_created_by_index');
            $table->index('role_id', 'users_role_id_index');
            $table->index('unique_id', 'users_unique_id_index');

            // Date based filters
            $table->index('created_at', 'users_created_at_index');

            // Composite indexes
            $table->index(['role_id', 'isapproved'], 'users_role_isapproved_index');
            $table->index(['kyc', 'isapproved'], 'users_kyc_isapproved_index');
            $table->index(['isapproved', 'created_at'], 'users_isapproved_created_at_index');
            $table->index(['created_by', 'isapproved'], 'users_created_by_isapproved_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_email_index');
            $table->dropIndex('users_phone_index');
            $table->dropIndex('users_google_id_index');
            $table->dropIndex('users_token_created_at_index');
            $table->dropIndex('users_api_token_index');

            $table->dropIndex('users_isapproved_index');
            $table->dropIndex('users_kyc_index');
            $table->dropIndex('users_is_otp_verified_index');

            $table->dropIndex('users_created_by_index');
            $table->dropIndex('users_role_id_index');
            $table->dropIndex('users_unique_id_index');

            $table->dropIndex('users_created_at_index');

            $table->dropIndex('users_role_isapproved_index');
            $table->dropIndex('users_kyc_isapproved_index');
            $table->dropIndex('users_isapproved_created_at_index');
            $table->dropIndex('users_created_by_isapproved_index');

            // revert column types
            DB::statement("ALTER TABLE users MODIFY phone VARCHAR(200) NULL");
            DB::statement("ALTER TABLE users MODIFY api_token TEXT NULL"); // revert if needed
            DB::statement("ALTER TABLE users MODIFY remember_token TEXT NULL");
            DB::statement("ALTER TABLE users MODIFY unique_id VARCHAR(200) NULL");
        });
    }
};