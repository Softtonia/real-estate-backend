<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use App\Services\Kyc\KycAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class EnsureKycApprovedForListingPublish
{
    public function __construct(
        private readonly KycAccessService $kycAccessService
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'always'): mixed
    {
        /*
         * mode:
         * always         => every create/update listing API requires KYC access
         * published_only => only block when request is trying to publish/live/approve
         */
        if ($mode === 'published_only' && !$this->isPublishAction($request)) {
            return $next($request);
        }

        $currentUser = $this->resolveCurrentUser($request);

        if (!$currentUser) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $subjectUser = $this->resolveListingSubjectUser($request, $currentUser);

        /*
         * Admin can manage listing only when no subject user is available.
         * If request contains assigned_user_id/user_id/owner_id, we check that user's KYC.
         */
        if (
            $this->isAdminUser($currentUser)
            && (int) $subjectUser->id === (int) $currentUser->id
            && !$this->requestHasSubjectUser($request)
        ) {
            return $next($request);
        }

        $kycStatus = $this->kycAccessService->userKycStatus($subjectUser);

        if ((bool) $kycStatus['can_publish_listing']) {
            return $next($request);
        }

        return response()->json([
            'status' => false,
            'message' => 'KYC approval is required before publishing or managing listings.',
            'error' => $kycStatus['message'] ?? 'KYC approval is required.',
            'data' => [
                'user_id' => (int) $subjectUser->id,
                'role_id' => $subjectUser->role_id,
                'role_name' => $kycStatus['role_name'] ?? null,
                'kyc' => $kycStatus['user_kyc_value'] ?? null,
                'requires_kyc' => $kycStatus['requires_kyc'] ?? true,
                'has_approved_kyc' => $kycStatus['has_approved_kyc'] ?? false,
                'has_user_exemption' => $kycStatus['has_user_exemption'] ?? false,
                'can_publish_without_kyc' => $kycStatus['can_publish_without_kyc'] ?? false,
            ],
        ], 403);
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $authUser = Auth::user();

        if ($authUser instanceof User) {
            return $authUser;
        }

        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }

    private function resolveListingSubjectUser(Request $request, User $currentUser): User
    {
        foreach ($this->subjectUserFields() as $field) {
            if (!$request->filled($field)) {
                continue;
            }

            $user = User::query()->find((int) $request->input($field));

            if ($user) {
                return $user;
            }
        }

        return $currentUser;
    }

    private function requestHasSubjectUser(Request $request): bool
    {
        foreach ($this->subjectUserFields() as $field) {
            if ($request->filled($field)) {
                return true;
            }
        }

        return false;
    }

    private function subjectUserFields(): array
    {
        return [
            'assigned_user_id',
            'listing_user_id',
            'user_id',
            'owner_id',
            'agent_id',
            'author_id',
        ];
    }

    private function isPublishAction(Request $request): bool
    {
        foreach (['is_publish', 'publish', 'is_published'] as $field) {
            if ($request->has($field)) {
                return in_array($request->input($field), [1, '1', true, 'true', 'yes', 'on'], true);
            }
        }

        foreach (['status', 'post_status', 'listing_status', 'property_status'] as $field) {
            if (!$request->filled($field)) {
                continue;
            }

            $status = strtolower(trim((string) $request->input($field)));

            if (in_array($status, [
                'publish',
                'published',
                'approved',
                'active',
                'live',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private function isAdminUser(User $user): bool
    {
        if ((int) $user->id === 1 || (int) $user->role_id === 1) {
            return true;
        }

        if (empty($user->role_id) || !Schema::hasTable('roles')) {
            return false;
        }

        $role = Role::query()
            ->select(['id', 'name'])
            ->where('id', $user->role_id)
            ->first();

        if (!$role) {
            return false;
        }

        $roleName = strtolower(trim((string) $role->name));
        $roleName = str_replace([' ', '_', '-'], '', $roleName);

        return in_array($roleName, [
            'admin',
            'administrator',
            'superadmin',
            'superadministrator',
        ], true);
    }
}