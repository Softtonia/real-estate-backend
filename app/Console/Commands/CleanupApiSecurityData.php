<?php

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupApiSecurityData extends Command
{
    protected $signature = 'api-security:cleanup';

    protected $description = 'Clean expired API security data, logs, nonces, and temporary IP blocks.';

    private int $chunkSize;

    public function handle(): int
    {
        $this->chunkSize = (int) config('api_security.cleanup_chunk_size', 1000);

        $deletedNonces = $this->deleteWhere('api_request_nonces', function (Builder $query) {
            $query->where('expires_at', '<', now());
        });

        $deletedAuthFailures = $this->deleteWhere('api_auth_failures', function (Builder $query) {
            $query->where(
                'created_at',
                '<',
                now()->subDays((int) config('api_security.failed_auth_retention_days', 30))
            );
        });

        $deletedRequestLogs = $this->deleteWhere('api_request_logs', function (Builder $query) {
            $query->where(
                'created_at',
                '<',
                now()->subDays((int) config('api_security.request_log_retention_days', 30))
            );
        });

        $deletedExpiredIpBlocks = $this->deleteWhere('blocked_api_ips', function (Builder $query) {
            $query->where('permanent', false)
                ->whereNotNull('blocked_until')
                ->where(
                    'blocked_until',
                    '<',
                    now()->subDays((int) config('api_security.expired_ip_block_retention_days', 7))
                );
        });

        $deletedAuditLogs = $this->deleteWhere('api_security_audit_logs', function (Builder $query) {
            $query->where(
                'created_at',
                '<',
                now()->subDays((int) config('api_security.audit_log_retention_days', 180))
            );
        });

        $this->info('API security cleanup completed.');
        $this->table(
            ['Table', 'Deleted Rows'],
            [
                ['api_request_nonces', $deletedNonces],
                ['api_auth_failures', $deletedAuthFailures],
                ['api_request_logs', $deletedRequestLogs],
                ['blocked_api_ips', $deletedExpiredIpBlocks],
                ['api_security_audit_logs', $deletedAuditLogs],
            ]
        );

        return self::SUCCESS;
    }

    private function deleteWhere(string $table, Closure $callback): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $totalDeleted = 0;

        do {
            $query = DB::table($table)
                ->select('id')
                ->orderBy('id')
                ->limit($this->chunkSize);

            $callback($query);

            $ids = $query->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted = DB::table($table)
                ->whereIn('id', $ids)
                ->delete();

            $totalDeleted += $deleted;
        } while ($deleted > 0);

        return $totalDeleted;
    }
}