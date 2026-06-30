<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('api_clients')) {
            return;
        }

        DB::statement("ALTER TABLE api_clients MODIFY status TINYINT(1) NOT NULL DEFAULT 1");
        DB::statement("ALTER TABLE api_clients MODIFY requires_signature TINYINT(1) NOT NULL DEFAULT 0");

        DB::statement("
            UPDATE api_clients
            SET status = CASE
                WHEN status IN (1, 2) THEN 1
                ELSE 0
            END
        ");

        DB::statement("
            UPDATE api_clients
            SET requires_signature = CASE
                WHEN requires_signature IN (1, 2) THEN 1
                ELSE 0
            END
        ");

        DB::statement("
            UPDATE api_clients
            SET type = 'custom'
            WHERE type IS NULL
            OR type NOT IN ('admin', 'business', 'website', 'mobile-app', 'custom')
        ");

        DB::statement("
            ALTER TABLE api_clients
            MODIFY type ENUM('admin', 'business', 'website', 'mobile-app', 'custom') NOT NULL DEFAULT 'custom'
        ");

        DB::table('api_clients')
            ->whereIn('slug', [
                'admin-panel',
                'api-key-port-5173',
                'local-react-admin',
            ])
            ->update(['type' => 'admin']);

        DB::table('api_clients')
            ->whereIn('slug', [
                'businesscom',
                'business-panel',
                'business-localhost-api-key-port-5173',
            ])
            ->update(['type' => 'business']);

        DB::table('api_clients')
            ->whereIn('slug', [
                'holiplacescom',
                'holiplaces-website',
                'local-sagar',
                'local-nextjs-website',
            ])
            ->update(['type' => 'website']);

        DB::table('api_clients')
            ->whereIn('slug', [
                'mobile-application',
            ])
            ->update(['type' => 'mobile-app']);

        DB::table('api_clients')
            ->whereIn('slug', [
                'api-key-port-8000',
                'custom-integration',
            ])
            ->update(['type' => 'custom']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('api_clients')) {
            return;
        }

        DB::statement("ALTER TABLE api_clients MODIFY type VARCHAR(255) NOT NULL DEFAULT 'custom'");
        DB::statement("ALTER TABLE api_clients MODIFY status TINYINT(1) NOT NULL DEFAULT 1");
        DB::statement("ALTER TABLE api_clients MODIFY requires_signature TINYINT(1) NOT NULL DEFAULT 0");
    }
};