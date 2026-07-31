<?php

namespace App\Http\Controllers\Api\Kyc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\KycRoleRuleUpdateRequest;
use App\Http\Requests\Kyc\KycUserExemptionRequest;
use App\Http\Requests\Kyc\KycUserExemptionRevokeRequest;
use App\Http\Resources\Kyc\KycRoleRuleResource;
use App\Http\Resources\Kyc\KycUserExemptionResource;
use App\Models\KycActivity;
use App\Models\KycDocument;
use App\Models\KycRoleRule;
use App\Models\KycUserExemption;
use App\Models\Role;
use App\Models\User;
use App\Services\Kyc\KycAccessService;
use App\Services\Kyc\KycActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class KycSettingsController extends Controller
{
    public function __construct(
        private readonly KycAccessService $accessService,
        private readonly KycActivityService $activityService
    ) {}

    public function roleRules(Request $request): JsonResponse
    {
        $admin = $this->resolveCurrentAdmin($request);

        if (!$admin) {
            return $this->unauthenticatedResponse();
        }

        try {
            $perPage = max(1, min((int) $request->input('per_page', 50), 100));

            $query = KycRoleRule::query()
                ->with('role:id,name')
                ->latest('id');

            if ($request->filled('role_id')) {
                $query->where('role_id', (int) $request->input('role_id'));
            }

            if ($request->filled('requires_kyc')) {
                $query->where('requires_kyc', $this->booleanValue($request->input('requires_kyc')));
            }

            if ($request->filled('can_publish_without_kyc')) {
                $query->where('can_publish_without_kyc', $this->booleanValue($request->input('can_publish_without_kyc')));
            }

            if ($request->filled('is_active')) {
                $query->where('is_active', $this->booleanValue($request->input('is_active')));
            }

            return response()->json([
                'status' => true,
                'message' => 'KYC role rules fetched successfully.',
                'data' => KycRoleRuleResource::collection($query->paginate($perPage)),
                'available_document_types' => KycDocument::documentTypes(),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC role rules.', $e);
        }
    }

    public function availableRoles(Request $request): JsonResponse
    {
        $admin = $this->resolveCurrentAdmin($request);

        if (!$admin) {
            return $this->unauthenticatedResponse();
        }

        try {
            $roles = Role::query()
                ->select(['id', 'name'])
                ->whereRaw("LOWER(TRIM(name)) NOT IN ('admin', 'administrator', 'superadmin', 'super admin')")
                ->orderBy('name')
                ->get()
                ->map(function (Role $role) {
                    $rule = KycRoleRule::query()
                        ->where('role_id', $role->id)
                        ->first();

                    return [
                        'id' => (int) $role->id,
                        'name' => $role->name,
                        'has_rule' => $rule !== null,
                        'rule' => $rule ? [
                            'requires_kyc' => (bool) $rule->requires_kyc,
                            'can_publish_without_kyc' => (bool) $rule->can_publish_without_kyc,
                            'required_documents' => $rule->required_documents ?? [],
                            'is_active' => (bool) $rule->is_active,
                        ] : [
                            'requires_kyc' => !$this->isOwnerRole($role->name),
                            'can_publish_without_kyc' => $this->isOwnerRole($role->name),
                            'required_documents' => KycRoleRule::defaultRequiredDocumentsForRoleName($role->name),
                            'is_active' => true,
                        ],
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'KYC available roles fetched successfully.',
                'data' => $roles,
                'available_document_types' => KycDocument::documentTypes(),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC available roles.', $e);
        }
    }

    public function updateRoleRule(KycRoleRuleUpdateRequest $request): JsonResponse
    {
        $admin = $this->resolveCurrentAdmin($request);

        if (!$admin) {
            return $this->unauthenticatedResponse();
        }

        try {
            $role = Role::query()
                ->select(['id', 'name'])
                ->findOrFail((int) $request->input('role_id'));

            if ($this->isAdminRole($role->name)) {
                return response()->json([
                    'status' => false,
                    'message' => 'KYC rule cannot be changed for admin role.',
                ], 422);
            }

            $rule = KycRoleRule::query()->updateOrCreate(
                [
                    'role_id' => $role->id,
                ],
                [
                    'requires_kyc' => (bool) $request->boolean('requires_kyc'),
                    'can_publish_without_kyc' => (bool) $request->boolean('can_publish_without_kyc'),
                    'required_documents' => $request->input('required_documents', []),
                    'is_active' => (bool) $request->boolean('is_active'),
                    'notes' => $request->input('notes'),
                ]
            );

            $this->clearUsersAccessCacheByRole((int) $role->id);

            Cache::store('redis')->forget('kyc:admin:stats');
            Cache::store('redis')->forget('kyc:pending-count');

            return response()->json([
                'status' => true,
                'message' => 'KYC role rule updated successfully.',
                'data' => new KycRoleRuleResource($rule->fresh('role')),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to update KYC role rule.', $e);
        }
    }

    public function userExemptions(Request $request): JsonResponse
    {
        $admin = $this->resolveCurrentAdmin($request);

        if (!$admin) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:active,revoked,expired,all'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $perPage = max(1, min((int) $request->input('per_page', 20), 100));

            $query = KycUserExemption::query()
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id,unique_id',
                    'user.role:id,name',

                    'creator:id,first_name,last_name,email,phone,role_id',
                    'creator.role:id,name',

                    'revoker:id,first_name,last_name,email,phone,role_id',
                    'revoker.role:id,name',
                ])
                ->latest('id');

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->input('user_id'));
            }

            $status = $request->input('status', 'active');

            if ($status === 'active') {
                $query->active();
            }

            if ($status === 'revoked') {
                $query->whereNotNull('revoked_at');
            }

            if ($status === 'expired') {
                $query
                    ->whereNull('revoked_at')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now());
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));

                $query->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery
                        ->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%')
                        ->orWhere('unique_id', 'like', '%' . $search . '%');
                });
            }

            return response()->json([
                'status' => true,
                'message' => 'KYC user exemptions fetched successfully.',
                'data' => KycUserExemptionResource::collection($query->paginate($perPage)),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC user exemptions.', $e);
        }
    }
    public function createUserExemption(KycUserExemptionRequest $request): JsonResponse
    {
        $admin = $this->resolveCurrentAdmin($request);

        if (!$admin) {
            return $this->unauthenticatedResponse();
        }

        try {
            $user = User::query()
                ->select(['id', 'first_name', 'last_name', 'email', 'phone', 'role_id', 'kyc'])
                ->findOrFail((int) $request->input('user_id'));

            if ((int) $user->id === 1 || (int) $user->role_id === 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'KYC exemption is not required for admin user.',
                ], 422);
            }

            $exemption = KycUserExemption::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                ],
                [
                    'created_by' => $admin->id,
                    'revoked_by' => null,
                    'reason' => $request->input('reason'),
                    'expires_at' => $request->input('expires_at'),
                    'revoked_at' => null,
                ]
            );

            $this->activityService->record(
                kycRequest: null,
                user: $user,
                performedBy: $admin,
                action: KycActivity::ACTION_EXEMPTION_CREATED,
                remarks: $request->input('reason') ?: 'User KYC exemption created.',
                metadata: [
                    'exemption_id' => $exemption->id,
                    'expires_at' => optional($exemption->expires_at)->toDateTimeString(),
                ],
                request: $request
            );

            $this->accessService->forgetUserCache($user);

            Cache::store('redis')->forget('kyc:user:' . $user->id . ':status');

            return response()->json([
                'status' => true,
                'message' => 'KYC user exemption created successfully.',
                'data' => new KycUserExemptionResource(
                    $exemption->fresh(['user', 'creator', 'revoker'])
                ),
                'access' => $this->accessService->userKycStatus($user),
            ], 201);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to create KYC user exemption.', $e);
        }
    }

    public function revokeUserExemption(
        KycUserExemptionRevokeRequest $request,
        KycUserExemption $exemption
    ): JsonResponse {
        $admin = $this->resolveCurrentAdmin($request);

        if (!$admin) {
            return $this->unauthenticatedResponse();
        }

        try {
            if ($exemption->revoked_at) {
                return response()->json([
                    'status' => false,
                    'message' => 'KYC exemption is already revoked.',
                ], 422);
            }

            $user = $exemption->user;

            $exemption->update([
                'revoked_by' => $admin->id,
                'revoked_at' => now(),
            ]);

            $this->activityService->record(
                kycRequest: null,
                user: $user,
                performedBy: $admin,
                action: KycActivity::ACTION_EXEMPTION_REVOKED,
                remarks: $request->input('reason') ?: 'User KYC exemption revoked.',
                metadata: [
                    'exemption_id' => $exemption->id,
                ],
                request: $request
            );

            if ($user) {
                $this->accessService->forgetUserCache($user);
                Cache::store('redis')->forget('kyc:user:' . $user->id . ':status');
            }

            return response()->json([
                'status' => true,
                'message' => 'KYC user exemption revoked successfully.',
                'data' => new KycUserExemptionResource(
                    $exemption->fresh(['user', 'creator', 'revoker'])
                ),
                'access' => $user ? $this->accessService->userKycStatus($user) : null,
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to revoke KYC user exemption.', $e);
        }
    }

    public function userAccessStatus(Request $request, int $userId): JsonResponse
    {
        $admin = $this->resolveCurrentAdmin($request);

        if (!$admin) {
            return $this->unauthenticatedResponse();
        }

        try {
            $user = User::query()->findOrFail($userId);

            return response()->json([
                'status' => true,
                'message' => 'User KYC access status fetched successfully.',
                'data' => $this->accessService->userKycStatus($user),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch user KYC access status.', $e);
        }
    }

    private function clearUsersAccessCacheByRole(int $roleId): void
    {
        User::query()
            ->where('role_id', $roleId)
            ->select(['id'])
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    $this->accessService->forgetUserCache((int) $user->id);
                    Cache::store('redis')->forget('kyc:user:' . $user->id . ':status');
                }
            });
    }

    private function resolveCurrentAdmin(Request $request): ?User
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

    private function booleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function isOwnerRole(?string $roleName): bool
    {
        $roleName = strtolower(trim((string) $roleName));
        $roleName = str_replace([' ', '_', '-'], '', $roleName);

        return in_array($roleName, [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
    }

    private function isAdminRole(?string $roleName): bool
    {
        $roleName = strtolower(trim((string) $roleName));
        $roleName = str_replace([' ', '_', '-'], '', $roleName);

        return in_array($roleName, [
            'admin',
            'administrator',
            'superadmin',
            'superadministrator',
        ], true);
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Invalid or expired admin token.',
        ], 401);
    }

    private function validationResponse($validator): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function serverErrorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }
}
