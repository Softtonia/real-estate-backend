<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE api_clients MODIFY status TINYINT(1) NOT NULL DEFAULT 1");
        DB::statement("ALTER TABLE api_clients MODIFY requires_signature TINYINT(1) NOT NULL DEFAULT 0");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE api_clients MODIFY status ENUM('0','1') NOT NULL DEFAULT '1'");
        DB::statement("ALTER TABLE api_clients MODIFY requires_signature TINYINT(1) NOT NULL DEFAULT 0");
    }
};