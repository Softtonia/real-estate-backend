<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Modify columns first
        DB::statement("ALTER TABLE users MODIFY phone VARCHAR(20) NULL");
        DB::statement("ALTER TABLE users MODIFY api_token VARCHAR(255) NULL");
        DB::statement("ALTER TABLE users MODIFY remember_token VARCHAR(100) NULL");
        DB::statement("ALTER TABLE users MODIFY unique_id VARCHAR(50) NULL");

        // Add indexes only if they do not exist
        Schema::table('users', function (Blueprint $table) {
            if (!$this->indexExists('users', 'users_email_index')) {
                $table->index('email', 'users_email_index');
            }

            if (!$this->indexExists('users', 'users_phone_index')) {
                $table->index('phone', 'users_phone_index');
            }

            if (!$this->indexExists('users', 'users_google_id_index')) {
                $table->index('google_id', 'users_google_id_index');
            }

            if (!$this->indexExists('users', 'users_token_created_at_index')) {
                $table->index('token_created_at', 'users_token_created_at_index');
            }

            if (!$this->indexExists('users', 'users_api_token_index')) {
                $table->index('api_token', 'users_api_token_index');
            }

            if (!$this->indexExists('users', 'users_isapproved_index')) {
                $table->index('isapproved', 'users_isapproved_index');
            }

            if (!$this->indexExists('users', 'users_kyc_index')) {
                $table->index('kyc', 'users_kyc_index');
            }

            if (!$this->indexExists('users', 'users_is_otp_verified_index')) {
                $table->index('is_otp_verified', 'users_is_otp_verified_index');
            }

            if (!$this->indexExists('users', 'users_created_by_index')) {
                $table->index('created_by', 'users_created_by_index');
            }

            if (!$this->indexExists('users', 'users_role_id_index')) {
                $table->index('role_id', 'users_role_id_index');
            }

            if (!$this->indexExists('users', 'users_unique_id_index')) {
                $table->index('unique_id', 'users_unique_id_index');
            }

            if (!$this->indexExists('users', 'users_created_at_index')) {
                $table->index('created_at', 'users_created_at_index');
            }

            if (!$this->indexExists('users', 'users_role_isapproved_index')) {
                $table->index(['role_id', 'isapproved'], 'users_role_isapproved_index');
            }

            if (!$this->indexExists('users', 'users_kyc_isapproved_index')) {
                $table->index(['kyc', 'isapproved'], 'users_kyc_isapproved_index');
            }

            if (!$this->indexExists('users', 'users_isapproved_created_at_index')) {
                $table->index(['isapproved', 'created_at'], 'users_isapproved_created_at_index');
            }

            if (!$this->indexExists('users', 'users_created_by_isapproved_index')) {
                $table->index(['created_by', 'isapproved'], 'users_created_by_isapproved_index');
            }
        });
    }

    public function down(): void
    {
        // Drop indexes first
        Schema::table('users', function (Blueprint $table) {
            if ($this->indexExists('users', 'users_email_index')) {
                $table->dropIndex('users_email_index');
            }

            if ($this->indexExists('users', 'users_phone_index')) {
                $table->dropIndex('users_phone_index');
            }

            if ($this->indexExists('users', 'users_google_id_index')) {
                $table->dropIndex('users_google_id_index');
            }

            if ($this->indexExists('users', 'users_token_created_at_index')) {
                $table->dropIndex('users_token_created_at_index');
            }

            if ($this->indexExists('users', 'users_api_token_index')) {
                $table->dropIndex('users_api_token_index');
            }

            if ($this->indexExists('users', 'users_isapproved_index')) {
                $table->dropIndex('users_isapproved_index');
            }

            if ($this->indexExists('users', 'users_kyc_index')) {
                $table->dropIndex('users_kyc_index');
            }

            if ($this->indexExists('users', 'users_is_otp_verified_index')) {
                $table->dropIndex('users_is_otp_verified_index');
            }

            if ($this->indexExists('users', 'users_created_by_index')) {
                $table->dropIndex('users_created_by_index');
            }

            if ($this->indexExists('users', 'users_role_id_index')) {
                $table->dropIndex('users_role_id_index');
            }

            if ($this->indexExists('users', 'users_unique_id_index')) {
                $table->dropIndex('users_unique_id_index');
            }

            if ($this->indexExists('users', 'users_created_at_index')) {
                $table->dropIndex('users_created_at_index');
            }

            if ($this->indexExists('users', 'users_role_isapproved_index')) {
                $table->dropIndex('users_role_isapproved_index');
            }

            if ($this->indexExists('users', 'users_kyc_isapproved_index')) {
                $table->dropIndex('users_kyc_isapproved_index');
            }

            if ($this->indexExists('users', 'users_isapproved_created_at_index')) {
                $table->dropIndex('users_isapproved_created_at_index');
            }

            if ($this->indexExists('users', 'users_created_by_isapproved_index')) {
                $table->dropIndex('users_created_by_isapproved_index');
            }
        });

        // Then revert column types
        DB::statement("ALTER TABLE users MODIFY phone VARCHAR(200) NULL");
        DB::statement("ALTER TABLE users MODIFY api_token TEXT NULL");
        DB::statement("ALTER TABLE users MODIFY remember_token TEXT NULL");
        DB::statement("ALTER TABLE users MODIFY unique_id VARCHAR(200) NULL");
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        $result = DB::select(
            "SELECT COUNT(1) as count 
             FROM information_schema.statistics 
             WHERE table_schema = ? 
             AND table_name = ? 
             AND index_name = ?",
            [$database, $table, $index]
        );

        return $result[0]->count > 0;
    }
};