<?php

namespace App\Http\Middleware;

use App\Models\Membership\MembershipCreditBalance;
use App\Models\Membership\MembershipPlan;
use App\Models\User;
use App\Services\Membership\MembershipAccessService;
use App\Services\Membership\MembershipActivationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureMembershipCanPublishListing
{
    public function __construct(
        private readonly MembershipAccessService $accessService,
        private readonly MembershipActivationService $activationService
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'always'): Response
    {
        if ($mode === 'published_only' && !$this->isPublishAction($request)) {
            return $next($request);
        }

        $currentUser = $this->resolveCurrentUser($request);

        if (!$currentUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
                'error' => 'User token is required.',
            ], 401);
        }

        $subjectUser = $this->resolveListingSubjectUser($request, $currentUser);

        /**
         * Admin creating system listing without assigning any user:
         * allow without membership check.
         *
         * Admin creating listing for user:
         * check assigned user's membership credit.
         */
        if ($this->isAdminUser($currentUser) && !$this->requestHasSubjectUser($request)) {
            return $next($request);
        }

        $this->ensureFreeMembershipIfNeeded($subjectUser);

        $access = $this->accessService->canUseCredit(
            user: $subjectUser,
            creditType: MembershipCreditBalance::TYPE_LISTING,
            quantity: 1
        );

        if (!$access['allowed']) {
            return response()->json([
                'status' => false,
                'message' => 'Membership listing credit required.',
                'error' => $access['message'],
                'data' => [
                    'user_id' => (int) $subjectUser->id,
                    'membership' => $access['status']['membership'] ?? null,
                    'credits' => $access['status']['credits'] ?? [],
                ],
            ], 403);
        }

        $request->attributes->set('membership_subject_user', $subjectUser);
        $request->attributes->set('membership_should_consume_listing_credit', true);
        $request->attributes->set('membership_listing_credit_type', MembershipCreditBalance::TYPE_LISTING);

        return $next($request);
    }

    private function ensureFreeMembershipIfNeeded(User $user): void
    {
        if ($this->accessService->hasActiveMembership($user)) {
            return;
        }

        $freePlan = MembershipPlan::query()
            ->where('slug', 'free')
            ->where('status', true)
            ->first();

        if (!$freePlan) {
            return;
        }

        $this->activationService->manualActivate(
            user: $user,
            plan: $freePlan,
            admin: null,
            options: [
                'reason' => 'Auto assigned free membership before listing publish.',
            ]
        );
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if ($token && Schema::hasColumn('users', 'api_token')) {
            $user = User::query()
                ->where('api_token', $token)
                ->first();

            if ($user) {
                return $user;
            }
        }

        $authUser = $request->user() ?: Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }

    private function resolveListingSubjectUser(Request $request, User $currentUser): User
    {
        foreach ($this->subjectUserFields() as $field) {
            $value = $request->input($field);

            if ($value && is_numeric($value)) {
                $user = User::query()->find((int) $value);

                if ($user) {
                    return $user;
                }
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
            'created_by',
        ];
    }

    private function isPublishAction(Request $request): bool
    {
        foreach (['is_publish', 'is_published', 'publish'] as $field) {
            if ($request->has($field) && filter_var($request->input($field), FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        foreach (['status', 'post_status', 'listing_status', 'property_status'] as $field) {
            $value = strtolower((string) $request->input($field));

            if (in_array($value, ['publish', 'published', 'approved', 'active', 'live'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isAdminUser(User $user): bool
    {
        if ((int) $user->id === 1 || (string) $user->role_id === '1') {
            return true;
        }

        if (!Schema::hasTable('roles') || !$user->role_id || !is_numeric($user->role_id)) {
            return false;
        }

        $role = \App\Models\Role::query()->find((int) $user->role_id);

        if (!$role) {
            return false;
        }

        $roleName = null;

        foreach (['name', 'role_name', 'title'] as $column) {
            if (Schema::hasColumn('roles', $column) && isset($role->{$column})) {
                $roleName = strtolower(str_replace([' ', '_', '-'], '', (string) $role->{$column}));
                break;
            }
        }

        return in_array($roleName, [
            'admin',
            'administrator',
            'superadmin',
            'superadministrator',
        ], true);
    }
}