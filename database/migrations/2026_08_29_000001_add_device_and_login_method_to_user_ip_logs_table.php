<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('user_ip_logs')) {
            Schema::table('user_ip_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('user_ip_logs', 'user_agent')) {
                    $table->text('user_agent')->nullable()->after('query');
                }
                if (!Schema::hasColumn('user_ip_logs', 'device')) {
                    $table->string('device', 100)->nullable()->after('user_agent');
                }
                if (!Schema::hasColumn('user_ip_logs', 'browser')) {
                    $table->string('browser', 100)->nullable()->after('device');
                }
                if (!Schema::hasColumn('user_ip_logs', 'os')) {
                    $table->string('os', 100)->nullable()->after('browser');
                }
                if (!Schema::hasColumn('user_ip_logs', 'login_method')) {
                    $table->string('login_method', 100)->nullable()->after('os');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_ip_logs')) {
            Schema::table('user_ip_logs', function (Blueprint $table) {
                $columns = ['user_agent', 'device', 'browser', 'os', 'login_method'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('user_ip_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
