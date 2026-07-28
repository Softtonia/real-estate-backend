<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MembershipAuditLogService
{
    public function adminLogs(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return MembershipAuditLog::query()
            ->with([
                'user:id,first_name,last_name,email,phone,role_id',
                'performer:id,first_name,last_name,email,phone,role_id',
            ])
            ->when(!empty($filters['user_id']), function ($query) use ($filters) {
                $query->where('user_id', (int) $filters['user_id']);
            })
            ->when(!empty($filters['performed_by']), function ($query) use ($filters) {
                $query->where('performed_by', (int) $filters['performed_by']);
            })
            ->when(!empty($filters['action']), function ($query) use ($filters) {
                $query->where('action', $filters['action']);
            })
            ->when(!empty($filters['auditable_type']), function ($query) use ($filters) {
                $query->where('auditable_type', $filters['auditable_type']);
            })
            ->when(!empty($filters['auditable_id']), function ($query) use ($filters) {
                $query->where('auditable_id', (int) $filters['auditable_id']);
            })
            ->when(!empty($filters['date_from']), function ($query) use ($filters) {
                $query->where('created_at', '>=', $filters['date_from']);
            })
            ->when(!empty($filters['date_to']), function ($query) use ($filters) {
                $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
            })
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('auditable_type', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                        ->orWhereHas('performer', function ($performerQuery) use ($search) {
                            $performerQuery->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function detail(MembershipAuditLog $auditLog): MembershipAuditLog
    {
        return $auditLog->loadMissing([
            'user:id,first_name,last_name,email,phone,role_id',
            'performer:id,first_name,last_name,email,phone,role_id',
        ]);
    }
}