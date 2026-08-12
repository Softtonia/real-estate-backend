<?php

namespace App\Http\Controllers\Api;

use App\Enums\PropertyWorkflowStatus;
use App\Http\Controllers\Controller;
use App\Models\DynamicPost;
use App\Models\PropertyListingRevision;
use App\Models\User;
use App\Services\PropertyVerification\PropertyWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PropertyVerificationController extends Controller
{
    public function __construct(
        private readonly PropertyWorkflowService $workflow
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'status' => [
                    'nullable',
                    'string',
                    Rule::in([
                        'all',
                        PropertyWorkflowStatus::UNDER_REVIEW,
                        PropertyWorkflowStatus::RESUBMISSION,
                        PropertyWorkflowStatus::ASSIGNED,
                        PropertyWorkflowStatus::IN_VERIFICATION,
                        PropertyWorkflowStatus::APPROVED,
                        PropertyWorkflowStatus::REJECTED,
                    ]),
                ],
                'assigned_to' => ['nullable', 'string'],
                'search' => ['nullable', 'string', 'max:255'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);

            $actor = $this->actor($request);

            $latestRevisionIds = PropertyListingRevision::query()
                ->selectRaw('MAX(id)')
                ->groupBy('dynamic_post_id');

            $query = PropertyListingRevision::query()
                ->with([
                    'property',
                    'submitter:id,first_name,last_name,email',
                    'assignedVerifier:id,first_name,last_name,email',
                    'assigner:id,first_name,last_name,email',
                    'decider:id,first_name,last_name,email',
                ])
                ->whereIn('id', $latestRevisionIds);

            if (
                $request->filled('status')
                && $request->status !== 'all'
            ) {
                $query->where('status', $request->status);
            } else {
                $query->whereIn(
                    'status',
                    PropertyWorkflowStatus::OPEN_STATUSES
                );
            }

            $canAssign = $this->userHasPermission(
                $actor,
                'property_verifications.assign'
            );

            if (!$canAssign) {
                // A reviewer can only see listings assigned to that exact user.
                $query->where('assigned_to', (int) $actor->id);
            } elseif ($request->filled('assigned_to')) {
                if ($request->assigned_to === 'me') {
                    $query->where('assigned_to', (int) $actor->id);
                } elseif (is_numeric($request->assigned_to)) {
                    $query->where(
                        'assigned_to',
                        (int) $request->assigned_to
                    );
                }
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->whereHas('property', function ($propertyQuery) use ($search) {
                    $propertyQuery->where(function ($subQuery) use ($search) {
                        if (Schema::hasColumn('dynamic_posts', 'title')) {
                            $subQuery->where(
                                'title',
                                'like',
                                "%{$search}%"
                            );
                        }

                        if (Schema::hasColumn('dynamic_posts', 'listing_code')) {
                            $subQuery->orWhere(
                                'listing_code',
                                'like',
                                "%{$search}%"
                            );
                        }

                        if (Schema::hasColumn('dynamic_posts', 'slug')) {
                            $subQuery->orWhere(
                                'slug',
                                'like',
                                "%{$search}%"
                            );
                        }
                    });
                });
            }

            $items = $query
                ->latest('submitted_at')
                ->paginate((int) $request->get('per_page', 20));

            $items->getCollection()->transform(
                fn(PropertyListingRevision $revision) =>
                $this->formatRevision($revision)
            );

            return response()->json([
                'status' => true,
                'message' => 'Property verification requests fetched successfully.',
                'data' => $items,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to fetch property verification requests.',
                $e
            );
        }
    }

    public function verifiers(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'role_id' => [
                    'nullable',
                    'integer',
                    'exists:roles,id',
                ],
                'search' => ['nullable', 'string', 'max:255'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            ]);

            $query = $this->eligibleVerifierQuery();

            if ($request->filled('role_id')) {
                $query->where(
                    'users.role_id',
                    (int) $request->role_id
                );
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);

                $query->where(function ($userQuery) use ($search) {
                    $userQuery
                        ->where('users.first_name', 'like', "%{$search}%")
                        ->orWhere('users.last_name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('roles.name', 'like', "%{$search}%");
                });
            }

            $options = $query
                ->orderBy('users.first_name')
                ->limit((int) $request->get('limit', 100))
                ->get()
                ->map(function ($user) {
                    $name = trim(
                        ($user->first_name ?? '')
                            . ' '
                            . ($user->last_name ?? '')
                    );

                    return [
                        'id' => (int) $user->id,
                        'value' => (int) $user->id,
                        'label' => $name ?: $user->email,
                        'email' => $user->email,
                        'role_id' => (int) $user->role_id,
                        'role_name' => $user->role_name,
                    ];
                })
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Eligible property verifiers fetched successfully.',
                'data' => [
                    'count' => $options->count(),
                    'selected_role_id' => $request->filled('role_id')
                        ? (int) $request->role_id
                        : null,
                    'options' => $options,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to fetch eligible property verifiers.',
                $e
            );
        }
    }

    public function verifierRoles(Request $request): JsonResponse
    {
        try {
            $this->actor($request);

            $requiredPermissions = $this->requiredVerifierPermissions();
            $guardName = config('permission_modules.guard', 'sanctum');

            $query = DB::table('roles')
                ->join(
                    'role_has_permissions as rhp',
                    'rhp.role_id',
                    '=',
                    'roles.id'
                )
                ->join(
                    'permissions as p',
                    'p.id',
                    '=',
                    'rhp.permission_id'
                )
                ->join('users', 'users.role_id', '=', 'roles.id')
                ->where('p.guard_name', $guardName)
                ->whereIn('p.name', $requiredPermissions);

            if (Schema::hasColumn('users', 'isapproved')) {
                $query->where('users.isapproved', 1);
            }

            if (Schema::hasColumn('roles', 'is_admin_login_permission')) {
                $query->where('roles.is_admin_login_permission', 1);
            }

            $roleNameColumn = Schema::hasColumn('roles', 'name')
                ? 'roles.name'
                : (
                    Schema::hasColumn('roles', 'role_name')
                    ? 'roles.role_name'
                    : 'roles.id'
                );

            $roles = $query
                ->selectRaw(
                    'roles.id as role_id, '
                        . $roleNameColumn
                        . ' as role_name, '
                        . 'COUNT(DISTINCT users.id) as eligible_users_count'
                )
                ->groupBy('roles.id', $roleNameColumn)
                ->havingRaw(
                    'COUNT(DISTINCT p.name) = ?',
                    [count($requiredPermissions)]
                )
                ->orderBy($roleNameColumn)
                ->get()
                ->map(fn($role) => [
                    'id' => (int) $role->role_id,
                    'value' => (int) $role->role_id,
                    'label' => (string) $role->role_name,
                    'eligible_users_count' => (int) $role->eligible_users_count,
                ])
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Eligible verifier roles fetched successfully.',
                'data' => [
                    'count' => $roles->count(),
                    'options' => $roles,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to fetch eligible verifier roles.',
                $e
            );
        }
    }

    public function myAssigned(Request $request): JsonResponse
    {
        $request->merge([
            'assigned_to' => 'me',
        ]);

        return $this->index($request);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'property_ids' => ['required', 'array', 'min:1', 'max:200'],
                'property_ids.*' => [
                    'required',
                    'integer',
                    'distinct',
                    'exists:dynamic_posts,id',
                ],
                'verifier_id' => [
                    'required',
                    'integer',
                    'exists:users,id',
                ],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            $actor = $this->actor($request);
            $verifier = User::findOrFail((int) $validated['verifier_id']);

            $revisions = DB::transaction(function () use (
                $validated,
                $actor,
                $verifier
            ) {
                return collect($validated['property_ids'])
                    ->map(function ($propertyId) use (
                        $validated,
                        $actor,
                        $verifier
                    ) {
                        $property = DynamicPost::findOrFail((int) $propertyId);

                        return $this->workflow->assign(
                            property: $property,
                            actor: $actor,
                            verifier: $verifier,
                            notes: $validated['notes'] ?? null
                        );
                    });
            });

            return response()->json([
                'status' => true,
                'message' => 'Selected properties assigned successfully.',
                'data' => [
                    'assigned_count' => $revisions->count(),
                    'verifier_id' => (int) $verifier->id,
                    'properties' => $revisions
                        ->map(fn(PropertyListingRevision $revision) => [
                            'property_id' => (int) $revision->dynamic_post_id,
                            'revision_id' => (int) $revision->id,
                            'assigned_to' => (int) $revision->assigned_to,
                            'status' => $revision->status,
                        ])
                        ->values(),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to bulk assign properties.',
                $e
            );
        }
    }

    public function assignAllOpen(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'verifier_id' => [
                    'required',
                    'integer',
                    'exists:users,id',
                ],
                'only_unassigned' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            $actor = $this->actor($request);
            $verifier = User::findOrFail((int) $validated['verifier_id']);

            $latestRevisionIds = PropertyListingRevision::query()
                ->selectRaw('MAX(id)')
                ->groupBy('dynamic_post_id');

            $revisionQuery = PropertyListingRevision::query()
                ->whereIn('id', $latestRevisionIds)
                ->whereIn('status', [
                    PropertyWorkflowStatus::UNDER_REVIEW,
                    PropertyWorkflowStatus::RESUBMISSION,
                    PropertyWorkflowStatus::ASSIGNED,
                ]);

            if ((bool) ($validated['only_unassigned'] ?? true)) {
                $revisionQuery->whereNull('assigned_to');
            }

            $propertyIds = $revisionQuery
                ->orderBy('id')
                ->pluck('dynamic_post_id')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values();

            if ($propertyIds->isEmpty()) {
                return response()->json([
                    'status' => true,
                    'message' => 'No open properties were available for assignment.',
                    'data' => [
                        'assigned_count' => 0,
                        'verifier_id' => (int) $verifier->id,
                    ],
                ]);
            }

            DB::transaction(function () use (
                $propertyIds,
                $validated,
                $actor,
                $verifier
            ) {
                foreach ($propertyIds as $propertyId) {
                    $property = DynamicPost::findOrFail($propertyId);

                    $this->workflow->assign(
                        property: $property,
                        actor: $actor,
                        verifier: $verifier,
                        notes: $validated['notes'] ?? 'All open properties assigned.'
                    );
                }
            });

            return response()->json([
                'status' => true,
                'message' => 'All open properties assigned successfully.',
                'data' => [
                    'assigned_count' => $propertyIds->count(),
                    'verifier_id' => (int) $verifier->id,
                    'property_ids' => $propertyIds,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to assign all open properties.',
                $e
            );
        }
    }

    public function show(
        Request $request,
        DynamicPost $property
    ): JsonResponse {
        try {
            $actor = $this->actor($request);

            $revision = $this->workflow->latestRevision($property);

            if (!$revision) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property verification request not found.',
                ], 404);
            }

            $this->assertCanAccessRevision($revision, $actor);

            return response()->json([
                'status' => true,
                'message' => 'Property verification request fetched successfully.',
                'data' => [
                    'property' => $this->formatProperty($property),
                    'revision' => $this->formatRevision($revision),
                    'submitted_payload' => $revision->submitted_payload,
                    'timeline' => $this->formatTimeline(
                        $this->workflow->timeline($property)
                    ),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to fetch property verification request.',
                $e
            );
        }
    }

    public function assign(
        Request $request,
        DynamicPost $property
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'verifier_id' => [
                    'required',
                    'integer',
                    'exists:users,id',
                ],
                'notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ]);

            $actor = $this->actor($request);
            $verifier = User::findOrFail(
                (int) $validated['verifier_id']
            );

            $revision = $this->workflow->assign(
                property: $property,
                actor: $actor,
                verifier: $verifier,
                notes: $validated['notes'] ?? null
            );

            return response()->json([
                'status' => true,
                'message' => 'Property assigned successfully.',
                'data' => $this->formatRevision($revision),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error('Unable to assign property.', $e);
        }
    }

    public function startVerification(
        Request $request,
        DynamicPost $property
    ): JsonResponse {
        try {
            $revision = $this->workflow->startVerification(
                property: $property,
                actor: $this->actor($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Property verification started.',
                'data' => $this->formatRevision($revision),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to start property verification.',
                $e
            );
        }
    }

    public function approve(
        Request $request,
        DynamicPost $property
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ]);

            $revision = $this->workflow->approve(
                property: $property,
                actor: $this->actor($request),
                notes: $validated['notes'] ?? null
            );

            return response()->json([
                'status' => true,
                'message' => 'Property approved and published successfully.',
                'data' => [
                    'property' => $this->formatProperty(
                        $property->fresh()
                    ),
                    'revision' => $this->formatRevision($revision),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to approve property.',
                $e
            );
        }
    }

    public function reject(
        Request $request,
        DynamicPost $property
    ): JsonResponse {
        try {
            $validated = $request->validate([
                'reason' => [
                    'nullable',
                    'string',
                    'min:5',
                    'max:5000',
                ],
                'rejection_reason' => [
                    'nullable',
                    'string',
                    'min:5',
                    'max:5000',
                ],
            ]);

            $reason = trim((string) (
                $validated['rejection_reason']
                ?? $validated['reason']
                ?? ''
            ));

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'rejection_reason' => [
                        'Rejection reason is required.',
                    ],
                ]);
            }

            $revision = $this->workflow->reject(
                property: $property,
                actor: $this->actor($request),
                reason: $reason
            );

            return response()->json([
                'status' => true,
                'message' => 'Property rejected successfully.',
                'data' => [
                    'property' => $this->formatProperty(
                        $property->fresh()
                    ),
                    'revision' => $this->formatRevision($revision),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to reject property.',
                $e
            );
        }
    }

    public function timeline(
        Request $request,
        DynamicPost $property
    ): JsonResponse {
        try {
            $actor = $this->actor($request);
            $revision = $this->workflow->latestRevision($property);

            if (!$revision) {
                return response()->json([
                    'status' => false,
                    'message' => 'Property verification request not found.',
                ], 404);
            }

            $this->assertCanAccessRevision($revision, $actor);

            return response()->json([
                'status' => true,
                'message' => 'Property verification timeline fetched successfully.',
                'data' => $this->formatTimeline(
                    $this->workflow->timeline($property)
                ),
            ]);
        } catch (Throwable $e) {
            return $this->error(
                'Unable to fetch property verification timeline.',
                $e
            );
        }
    }

    private function eligibleVerifierQuery(): Builder
    {
        if (
            !Schema::hasTable('permissions')
            || !Schema::hasTable('role_has_permissions')
        ) {
            throw new \RuntimeException(
                'Spatie permission tables are required for dynamic verifier selection.'
            );
        }

        $guardName = config('permission_modules.guard', 'sanctum');
        $requiredPermissions = $this->requiredVerifierPermissions();

        $roleNameColumn = Schema::hasColumn('roles', 'name')
            ? 'roles.name'
            : (
                Schema::hasColumn('roles', 'role_name')
                ? 'roles.role_name'
                : 'roles.id'
            );

        $query = User::query()
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join(
                'role_has_permissions as rhp',
                'rhp.role_id',
                '=',
                'roles.id'
            )
            ->join(
                'permissions as p',
                'p.id',
                '=',
                'rhp.permission_id'
            )
            ->where('p.guard_name', $guardName)
            ->whereIn('p.name', $requiredPermissions)
            ->select([
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.role_id',
                DB::raw($roleNameColumn . ' as role_name'),
            ]);

        if (Schema::hasColumn('users', 'isapproved')) {
            $query->where('users.isapproved', 1);
        }

        if (Schema::hasColumn('roles', 'is_admin_login_permission')) {
            $query->where('roles.is_admin_login_permission', 1);
        }

        return $query
            ->groupBy(
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.role_id',
                $roleNameColumn
            )
            ->havingRaw(
                'COUNT(DISTINCT p.name) = ?',
                [count($requiredPermissions)]
            );
    }

    private function requiredVerifierPermissions(): array
    {
        return collect(config(
            'property_verification.required_verifier_permissions',
            [
                'property_verifications.review',
                'property_verifications.approve',
                'property_verifications.reject',
            ]
        ))
            ->map(fn($permission) => strtolower(trim((string) $permission)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function userHasPermission(
        User $user,
        string $permissionName
    ): bool {
        if (
            !Schema::hasTable('permissions')
            || !Schema::hasTable('role_has_permissions')
        ) {
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

    private function assertCanAccessRevision(
        PropertyListingRevision $revision,
        User $actor
    ): void {
        if ($this->isSystemAdmin($actor)) {
            return;
        }

        if ((int) $revision->assigned_to !== (int) $actor->id) {
            throw new AuthorizationException(
                'This property is assigned to another verifier.'
            );
        }
    }

    private function isSystemAdmin(User $user): bool
    {
        if (
            empty($user->role_id)
            || !Schema::hasTable('roles')
        ) {
            return false;
        }

        $role = DB::table('roles')
            ->where('id', (int) $user->role_id)
            ->first();

        if (!$role) {
            return false;
        }

        $roleValues = collect([
            $role->name ?? null,
            $role->slug ?? null,
            $role->role_name ?? null,
        ])
            ->filter()
            ->map(fn($value) => Str::slug((string) $value))
            ->values()
            ->toArray();

        return (bool) array_intersect($roleValues, [
            'admin',
            'administrator',
            'super-admin',
            'superadmin',
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user() ?? Auth::user();

        if (!$actor instanceof User) {
            abort(401, 'Unauthenticated admin user.');
        }

        return $actor;
    }

    private function formatRevision(
        PropertyListingRevision $revision
    ): array {
        return [
            'id' => (int) $revision->id,
            'property_id' => (int) $revision->dynamic_post_id,
            'version' => (int) $revision->version,
            'source' => $revision->source,
            'status' => $revision->status,

            'property' => $revision->relationLoaded('property')
                ? $this->formatProperty($revision->property)
                : null,

            'submitted_by' => $this->formatUser(
                $revision->submitter
            ),
            'submitted_at' => optional(
                $revision->submitted_at
            )->toDateTimeString(),

            'assigned_to' => $this->formatUser(
                $revision->assignedVerifier
            ),
            'assigned_by' => $this->formatUser(
                $revision->assigner
            ),
            'assigned_at' => optional(
                $revision->assigned_at
            )->toDateTimeString(),

            'verification_started_at' => optional(
                $revision->verification_started_at
            )->toDateTimeString(),

            'decided_by' => $this->formatUser(
                $revision->decider
            ),
            'decided_at' => optional(
                $revision->decided_at
            )->toDateTimeString(),

            'rejection_reason' => $revision->rejection_reason,
        ];
    }

    private function formatProperty(?DynamicPost $property): ?array
    {
        if (!$property) {
            return null;
        }

        return [
            'id' => (int) $property->id,
            'title' => $property->title ?? null,
            'slug' => $property->slug ?? null,
            'listing_code' => $property->listing_code ?? null,
            'author_id' => isset($property->author_id)
                ? (int) $property->author_id
                : null,
            'status' => $property->status ?? null,
            'live_status' => $property->live_status ?? null,
            'published_at' => optional(
                $property->published_at ?? null
            )->toDateTimeString(),
            'created_at' => optional(
                $property->created_at ?? null
            )->toDateTimeString(),
            'updated_at' => optional(
                $property->updated_at ?? null
            )->toDateTimeString(),
        ];
    }

    private function formatTimeline($events): array
    {
        return collect($events)
            ->map(function ($event) {
                return [
                    'id' => (int) $event->id,
                    'revision_id' => $event->revision_id
                        ? (int) $event->revision_id
                        : null,
                    'event' => $event->event,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'message' => $event->message,
                    'metadata' => $event->metadata,
                    'actor' => $this->formatUser($event->actor),
                    'created_at' => optional(
                        $event->created_at
                    )->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    private function formatUser($user): ?array
    {
        if (!$user) {
            return null;
        }

        $name = trim(
            ($user->first_name ?? '')
                . ' '
                . ($user->last_name ?? '')
        );

        return [
            'id' => (int) $user->id,
            'name' => $name ?: ($user->email ?? null),
            'email' => $user->email ?? null,
        ];
    }

    private function validationError(
        ValidationException $e
    ): JsonResponse {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $e->errors(),
        ], 422);
    }

    private function error(
        string $message,
        Throwable $e
    ): JsonResponse {
        report($e);

        $statusCode = match (true) {
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };

        return response()->json([
            'status' => false,
            'message' => $statusCode < 500
                ? ($e->getMessage() ?: $message)
                : $message,
            'error' => $e->getMessage(),
        ], $statusCode);
    }
}
