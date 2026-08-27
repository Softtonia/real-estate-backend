<?php

namespace App\Services\Kyc;

use App\Models\KycActivity;
use App\Models\KycRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class KycAssignmentService
{
    public function __construct(
        private readonly KycActivityService $activityService,
        private readonly KycAccessService $accessService
    ) {
    }

    /**
     * Required permissions for a role to be eligible for KYC verification.
     */
    public function requiredVerifierPermissions(): array
    {
        return [
            'kyc_requests.read',
            'kyc_requests.edit',
            'kyc_requests.approve',
            'kyc_requests.reject',
        ];
    }

    /**
     * Get all roles eligible to review/verify KYC requests.
     * Only returns roles that have been explicitly granted KYC permissions (e.g. kyc_requests.*),
     * excluding the main admin role.
     */
    public function getEligibleRoles(): Collection
    {
        $roleNameColumn = Schema::hasColumn('roles', 'name')
            ? 'roles.name'
            : (Schema::hasColumn('roles', 'role_name') ? 'roles.role_name' : 'roles.id');

        $hasPermissionsTable = Schema::hasTable('permissions') && Schema::hasTable('role_has_permissions');

        $query = DB::table('roles');

        if ($hasPermissionsTable) {
            $query->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'roles.id')
                ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
                ->where(function ($pQuery) {
                    $pQuery->where('p.name', 'like', 'kyc_requests.%')
                        ->orWhereIn('p.name', [
                            'kyc_requests.read',
                            'kyc_requests.edit',
                            'kyc_requests.approve',
                            'kyc_requests.reject',
                            'kyc_requests.assign',
                        ]);
                });
        }

        $query->leftJoin('users', function ($join) {
            $join->on('users.role_id', '=', 'roles.id');
            if (Schema::hasColumn('users', 'isapproved')) {
                $join->where('users.isapproved', 1);
            }
        });

        // Filter: only roles with admin login permission
        if (Schema::hasColumn('roles', 'is_admin_login_permission')) {
            $query->where('roles.is_admin_login_permission', 1);
        }

        // Exclude the main Admin role (role id 1 or names like admin/super-admin)
        $query->where('roles.id', '!=', 1);
        $query->whereNotIn(DB::raw('LOWER(' . $roleNameColumn . ')'), [
            'admin',
            'administrator',
            'super-admin',
            'superadmin',
            'super admin',
        ]);

        $roles = $query
            ->selectRaw('roles.id as role_id, ' . $roleNameColumn . ' as role_name, COUNT(DISTINCT users.id) as eligible_users_count')
            ->groupBy('roles.id', $roleNameColumn)
            ->orderBy($roleNameColumn)
            ->get();

        return $roles->map(fn($role) => [
            'id' => (int) $role->role_id,
            'value' => (int) $role->role_id,
            'label' => (string) $role->role_name,
            'role_name' => (string) $role->role_name,
            'eligible_users_count' => (int) $role->eligible_users_count,
        ])->values();
    }

    /**
     * Get eligible verifier users for KYC assignment.
     * Only returns approved users belonging to roles that have KYC permissions.
     */
    public function getEligibleVerifiers(?int $roleId = null, ?string $search = null, int $limit = 100): Collection
    {
        $roleNameColumn = Schema::hasColumn('roles', 'name')
            ? 'roles.name'
            : (Schema::hasColumn('roles', 'role_name') ? 'roles.role_name' : 'roles.id');

        $hasPermissionsTable = Schema::hasTable('permissions') && Schema::hasTable('role_has_permissions');

        $eligibleRoleIdsQuery = DB::table('roles');

        if ($hasPermissionsTable) {
            $eligibleRoleIdsQuery->join('role_has_permissions as rhp', 'rhp.role_id', '=', 'roles.id')
                ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
                ->where(function ($pQuery) {
                    $pQuery->where('p.name', 'like', 'kyc_requests.%')
                        ->orWhereIn('p.name', [
                            'kyc_requests.read',
                            'kyc_requests.edit',
                            'kyc_requests.approve',
                            'kyc_requests.reject',
                            'kyc_requests.assign',
                        ]);
                });
        }

        if (Schema::hasColumn('roles', 'is_admin_login_permission')) {
            $eligibleRoleIdsQuery->where('roles.is_admin_login_permission', 1);
        }

        $eligibleRoleIdsQuery->where('roles.id', '!=', 1);
        $eligibleRoleIdsQuery->whereNotIn(DB::raw('LOWER(' . $roleNameColumn . ')'), [
            'admin',
            'administrator',
            'super-admin',
            'superadmin',
            'super admin',
        ]);

        $eligibleRoleIds = $eligibleRoleIdsQuery->pluck('roles.id')->unique()->all();

        if (empty($eligibleRoleIds)) {
            return collect();
        }

        $query = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereIn('users.role_id', $eligibleRoleIds)
            ->select([
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.phone',
                'users.role_id',
                DB::raw($roleNameColumn . ' as role_name'),
            ]);

        if (Schema::hasColumn('users', 'isapproved')) {
            $query->where('users.isapproved', 1);
        }

        if ($roleId) {
            $query->where('users.role_id', $roleId);
        }

        if (!empty($search)) {
            $search = trim($search);
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('users.first_name', 'like', "%{$search}%")
                    ->orWhere('users.last_name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%");

                if (Schema::hasColumn('users', 'unique_id')) {
                    $subQuery->orWhere('users.unique_id', 'like', "%{$search}%");
                }
            });
        }

        return $query
            ->orderBy('users.first_name')
            ->limit($limit)
            ->get()
            ->map(function ($user) {
                $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

                return [
                    'id' => (int) $user->id,
                    'value' => (int) $user->id,
                    'label' => $fullName ? "{$fullName} ({$user->email})" : $user->email,
                    'full_name' => $fullName ?: null,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone ?? null,
                    'role_id' => (int) $user->role_id,
                    'role_name' => $user->role_name,
                ];
            })
            ->values();
    }

    /**
     * Assign a single KYC request to a verifier or unassign it.
     */
    public function assign(
        KycRequest $kycRequest,
        ?User $verifier,
        User $assigner,
        ?string $notes = null
    ): KycRequest {
        return DB::transaction(function () use ($kycRequest, $verifier, $assigner, $notes) {
            $lockedRequest = KycRequest::query()
                ->lockForUpdate()
                ->findOrFail($kycRequest->id);

            $previousAssignedTo = $lockedRequest->assigned_to;
            $oldStatus = $lockedRequest->status;

            if ($verifier) {
                $isReassignment = !empty($previousAssignedTo) && (int) $previousAssignedTo !== (int) $verifier->id;
                $action = $isReassignment ? KycActivity::ACTION_REASSIGNED : KycActivity::ACTION_ASSIGNED;

                $lockedRequest->update([
                    'assigned_to' => (int) $verifier->id,
                    'assigned_by' => (int) $assigner->id,
                    'assigned_at' => now(),
                    'assign_notes' => $notes,
                ]);

                $verifierName = trim(($verifier->first_name ?? '') . ' ' . ($verifier->last_name ?? '')) ?: $verifier->email;
                $remarks = $notes ?: ($isReassignment ? "Reassigned to {$verifierName}" : "Assigned to {$verifierName}");

                $this->activityService->record(
                    kycRequest: $lockedRequest->fresh(['user']),
                    user: $lockedRequest->user,
                    performedBy: $assigner,
                    action: $action,
                    oldStatus: $oldStatus,
                    newStatus: $lockedRequest->status,
                    remarks: $remarks
                );
            } else {
                $lockedRequest->update([
                    'assigned_to' => null,
                    'assigned_by' => (int) $assigner->id,
                    'assigned_at' => null,
                    'assign_notes' => $notes,
                ]);

                $this->activityService->record(
                    kycRequest: $lockedRequest->fresh(['user']),
                    user: $lockedRequest->user,
                    performedBy: $assigner,
                    action: KycActivity::ACTION_UNASSIGNED,
                    oldStatus: $oldStatus,
                    newStatus: $lockedRequest->status,
                    remarks: $notes ?: 'KYC assignment removed.'
                );
            }

            $this->clearCaches($lockedRequest);

            return $lockedRequest->fresh([
                'user:id,first_name,last_name,email,phone,role_id,kyc,reject_reason',
                'role:id,name',
                'reviewer:id,first_name,last_name,email',
                'assignedVerifier:id,first_name,last_name,email',
                'assigner:id,first_name,last_name,email',
                'documents',
                'activities.performer:id,first_name,last_name,email',
            ]);
        });
    }

    /**
     * Bulk assign multiple KYC requests to a verifier.
     */
    public function bulkAssign(
        array $kycRequestIds,
        User $verifier,
        User $assigner,
        ?string $notes = null
    ): EloquentCollection {
        return DB::transaction(function () use ($kycRequestIds, $verifier, $assigner, $notes) {
            $requests = KycRequest::query()
                ->whereIn('id', $kycRequestIds)
                ->lockForUpdate()
                ->get();

            $verifierName = trim(($verifier->first_name ?? '') . ' ' . ($verifier->last_name ?? '')) ?: $verifier->email;

            foreach ($requests as $request) {
                $previousAssignedTo = $request->assigned_to;
                $isReassignment = !empty($previousAssignedTo) && (int) $previousAssignedTo !== (int) $verifier->id;
                $action = $isReassignment ? KycActivity::ACTION_REASSIGNED : KycActivity::ACTION_ASSIGNED;

                $request->update([
                    'assigned_to' => (int) $verifier->id,
                    'assigned_by' => (int) $assigner->id,
                    'assigned_at' => now(),
                    'assign_notes' => $notes,
                ]);

                $remarks = $notes ?: ($isReassignment ? "Reassigned to {$verifierName}" : "Assigned to {$verifierName}");

                $this->activityService->record(
                    kycRequest: $request->fresh(['user']),
                    user: $request->user,
                    performedBy: $assigner,
                    action: $action,
                    oldStatus: $request->status,
                    newStatus: $request->status,
                    remarks: $remarks
                );

                $this->clearCaches($request);
            }

            return KycRequest::query()
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id,kyc,reject_reason',
                    'role:id,name',
                    'reviewer:id,first_name,last_name,email',
                    'assignedVerifier:id,first_name,last_name,email',
                    'assigner:id,first_name,last_name,email',
                ])
                ->whereIn('id', $kycRequestIds)
                ->get();
        });
    }

    /**
     * Assign all open unassigned KYC requests matching filters.
     */
    public function assignAllOpen(
        User $verifier,
        User $assigner,
        array $filters = [],
        ?string $notes = null
    ): EloquentCollection {
        return DB::transaction(function () use ($verifier, $assigner, $filters, $notes) {
            $query = KycRequest::query()
                ->whereNull('assigned_to')
                ->whereIn('status', [
                    KycRequest::STATUS_SUBMITTED,
                    KycRequest::STATUS_RESUBMITTED,
                    KycRequest::STATUS_UNDER_REVIEW,
                ])
                ->lockForUpdate();

            if (!empty($filters['role_id'])) {
                $query->where('role_id', (int) $filters['role_id']);
            }

            if (!empty($filters['status'])) {
                $query->where('status', (string) $filters['status']);
            }

            $requestIds = $query->pluck('id')->all();

            if (empty($requestIds)) {
                return new EloquentCollection();
            }

            return $this->bulkAssign($requestIds, $verifier, $assigner, $notes);
        });
    }

    /**
     * Check if actor has permission to review the KYC request.
     * Admin has unrestricted access without needing assignment.
     * All other users can ONLY review requests assigned to themselves.
     */
    public function assertCanReview(KycRequest $kycRequest, User $actor): void
    {
        // Only main system admin has unrestricted bypass
        if ($this->isSystemAdmin($actor)) {
            return;
        }

        // Assigned verifier can review their own assigned request
        if (!empty($kycRequest->assigned_to) && (int) $kycRequest->assigned_to === (int) $actor->id) {
            return;
        }

        throw new AuthorizationException(
            empty($kycRequest->assigned_to)
            ? 'This KYC request is not assigned to you for review.'
            : 'This KYC request is assigned to another verifier.'
        );
    }

    /**
     * Check if actor has permission to view the KYC request details.
     * Admin has unrestricted access.
     * All other users can ONLY view requests assigned to themselves.
     */
    public function assertCanView(KycRequest $kycRequest, User $actor): void
    {
        // Only main system admin has unrestricted bypass
        if ($this->isSystemAdmin($actor)) {
            return;
        }

        // Assigned verifier can view their own assigned request
        if (!empty($kycRequest->assigned_to) && (int) $kycRequest->assigned_to === (int) $actor->id) {
            return;
        }

        throw new AuthorizationException(
            empty($kycRequest->assigned_to)
            ? 'This KYC request is not assigned to you.'
            : 'This KYC request is assigned to another verifier.'
        );
    }

    /**
     * Determine if a user is a super admin / unrestricted admin.
     */
    public function isSystemAdmin(User $user): bool
    {
        if ((int) $user->id === 1 || (int) $user->role_id === 1) {
            return true;
        }

        if (empty($user->role_id) || !Schema::hasTable('roles')) {
            return false;
        }

        $role = DB::table('roles')->where('id', (int) $user->role_id)->first();

        if (!$role) {
            return false;
        }

        $roleValues = collect([
            $role->name ?? null,
            $role->slug ?? null,
            $role->role_name ?? null,
        ])
            ->filter()
            ->map(fn($v) => Str::slug((string) $v))
            ->values()
            ->toArray();

        return (bool) array_intersect($roleValues, [
            'admin',
            'administrator',
            'super-admin',
            'superadmin',
        ]);
    }

    /**
     * Check if user has KYC assign permission.
     */
    public function canAssign(User $user): bool
    {
        if ($this->isSystemAdmin($user)) {
            return true;
        }

        return $this->userHasPermission($user, 'kyc_requests.assign');
    }

    /**
     * Check specific permission for a user.
     */
    public function userHasPermission(User $user, string $permissionName): bool
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_has_permissions')) {
            return false;
        }

        $guardName = config('permission_modules.guard', 'sanctum');
        $permissionName = strtolower(trim($permissionName));

        $hasRolePermission = DB::table('role_has_permissions as rhp')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('rhp.role_id', (int) $user->role_id)
            ->where('p.guard_name', $guardName)
            ->where('p.name', $permissionName)
            ->exists();

        if ($hasRolePermission) {
            return true;
        }

        if (!Schema::hasTable('model_has_permissions')) {
            return false;
        }

        return DB::table('model_has_permissions as mhp')
            ->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
            ->where('mhp.model_id', (int) $user->id)
            ->where('mhp.model_type', User::class)
            ->where('p.guard_name', $guardName)
            ->where('p.name', $permissionName)
            ->exists();
    }

    private function clearCaches(KycRequest $kycRequest): void
    {
        Cache::store('redis')->forget('kyc:admin:stats');
        Cache::store('redis')->forget('kyc:pending-count');

        if ($kycRequest->user_id) {
            Cache::store('redis')->forget('kyc:user:' . $kycRequest->user_id . ':status');
        }
    }
}
