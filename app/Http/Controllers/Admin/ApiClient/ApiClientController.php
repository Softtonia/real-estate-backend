<?php

namespace App\Http\Controllers\Admin\ApiClient;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiClientRequest;
use App\Http\Requests\UpdateApiClientRequest;
use App\Http\Resources\ApiClientResource;
use App\Models\ApiClient;
use App\Models\PostType;
use App\Services\ApiClientService;
use App\Support\ApiPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApiClientController extends Controller
{
    public function __construct(
        private readonly ApiClientService $apiClientService
    ) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ApiClient::class);

        $clients = $this->apiClientService->paginate(20);

        $clients->getCollection()->load([
            'applicationPasswords' => function ($query) {
                $query->select([
                    'id',
                    'api_client_id',
                    'name',
                    'token_prefix',
                    'abilities',
                    'last_used_at',
                    'expires_at',
                    'revoked_at',
                    'created_at',
                ])->latest();
            },
        ]);

        $clients->getCollection()->loadCount('applicationPasswords');

        return ApiClientResource::collection($clients);
    }

    public function store(StoreApiClientRequest $request): JsonResponse
    {
        Gate::authorize('create', ApiClient::class);

        $client = $this->apiClientService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'API client created successfully.',
            'data' => new ApiClientResource(
                $client->loadCount('applicationPasswords')
            ),
        ], 201);
    }

    public function show(ApiClient $apiClient): JsonResponse
    {
        Gate::authorize('view', $apiClient);

        $apiClient->load([
            'applicationPasswords' => function ($query) {
                $query->select([
                    'id',
                    'api_client_id',
                    'name',
                    'token_prefix',
                    'abilities',
                    'last_used_at',
                    'expires_at',
                    'revoked_at',
                    'created_at',
                ])->latest();
            },
        ]);

        $apiClient->loadCount('applicationPasswords');

        return response()->json([
            'success' => true,
            'data' => new ApiClientResource($apiClient),
        ]);
    }

    public function update(UpdateApiClientRequest $request, ApiClient $apiClient): JsonResponse
    {
        Gate::authorize('update', $apiClient);

        $client = $this->apiClientService->update(
            $apiClient,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'API client updated successfully.',
            'data' => new ApiClientResource(
                $client->loadCount('applicationPasswords')
            ),
        ]);
    }

    public function destroy(ApiClient $apiClient): JsonResponse
    {
        Gate::authorize('delete', $apiClient);

        $apiClient->load([
            'applicationPasswords' => function ($query) {
                $query->select([
                    'id',
                    'api_client_id',
                    'name',
                    'token_prefix',
                    'abilities',
                    'last_used_at',
                    'expires_at',
                    'revoked_at',
                    'created_at',
                ])->latest();
            },
        ]);

        $deletedData = [
            'api_client_id' => $apiClient->id,
            'api_client_name' => $apiClient->name,
            'api_client_slug' => $apiClient->slug,
            'api_client_type' => $apiClient->type,
            'application_passwords_count' => $apiClient->applicationPasswords->count(),
            'application_passwords' => $apiClient->applicationPasswords->map(function ($password) {
                return [
                    'application_password_id' => $password->id,
                    'application_token_id' => $password->id,
                    'application_password_name' => $password->name,
                    'token_prefix' => $password->token_prefix,
                    'abilities' => $password->abilities ?? [],
                    'is_valid' => $password->isValid(),
                    'last_used_at' => $password->last_used_at,
                    'expires_at' => $password->expires_at,
                    'revoked_at' => $password->revoked_at,
                    'created_at' => $password->created_at,
                ];
            })->values(),
        ];

        $this->apiClientService->delete($apiClient);

        return response()->json([
            'success' => true,
            'message' => 'API client deleted successfully.',
            'data' => $deletedData,
        ]);
    }
    public function availablePermissions(): JsonResponse
    {
        Gate::authorize('viewAny', ApiClient::class);

        if (!Schema::hasTable('post_types')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'global_permissions' => [
                        [
                            'permission' => '*',
                            'label' => 'Full API Access',
                            'description' => 'Allow full access to all post types.',
                        ],
                        [
                            'permission' => 'post_types.*.read',
                            'label' => 'Read All Post Types',
                            'description' => 'Allow read access to all post types.',
                        ],
                        [
                            'permission' => 'post_types.*.write',
                            'label' => 'Write All Post Types',
                            'description' => 'Allow write access to all post types.',
                        ],
                    ],
                    'permissions' => [],
                ],
            ]);
        }

        $query = DB::table('post_types')
            ->select('id', 'name', 'slug')
            ->whereNotNull('slug');

        if (Schema::hasColumn('post_types', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $postTypes = $query
            ->orderBy('name')
            ->get();

        $permissions = [];

        foreach ($postTypes as $postType) {
            $permissions[] = [
                'group' => 'Post Types',
                'post_type_id' => $postType->id,
                'post_type_name' => $postType->name,
                'post_type_slug' => $postType->slug,
                'permission' => 'post_types.' . $postType->slug . '.read',
                'action' => 'read',
                'label' => 'Read ' . $postType->name,
                'description' => 'Allow listing and viewing ' . $postType->name . ' content.',
            ];

            $permissions[] = [
                'group' => 'Post Types',
                'post_type_id' => $postType->id,
                'post_type_name' => $postType->name,
                'post_type_slug' => $postType->slug,
                'permission' => 'post_types.' . $postType->slug . '.write',
                'action' => 'write',
                'label' => 'Write ' . $postType->name,
                'description' => 'Allow creating, updating, and deleting ' . $postType->name . ' content.',
            ];

            $permissions[] = [
                'group' => 'Post Types',
                'post_type_id' => $postType->id,
                'post_type_name' => $postType->name,
                'post_type_slug' => $postType->slug,
                'permission' => 'post_types.' . $postType->slug . '.*',
                'action' => '*',
                'label' => 'Full Access ' . $postType->name,
                'description' => 'Allow read and write access for ' . $postType->name . ' content.',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'global_permissions' => [
                    [
                        'permission' => '*',
                        'label' => 'Full API Access',
                        'description' => 'Allow full access to all post types.',
                    ],
                    [
                        'permission' => 'post_types.*.read',
                        'label' => 'Read All Post Types',
                        'description' => 'Allow read access to all post types.',
                    ],
                    [
                        'permission' => 'post_types.*.write',
                        'label' => 'Write All Post Types',
                        'description' => 'Allow write access to all post types.',
                    ],
                    [
                        'permission' => 'post_types.*',
                        'label' => 'Full Access All Post Types',
                        'description' => 'Allow read and write access to all post types.',
                    ],
                ],
                'permissions' => $permissions,
            ],
        ]);
    }
    private function formatApiClientWithPasswords(ApiClient $apiClient): array
    {
        $apiClient->load([
            'applicationPasswords' => function ($query) {
                $query->select([
                    'id',
                    'api_client_id',
                    'name',
                    'token_prefix',
                    'abilities',
                    'last_used_at',
                    'expires_at',
                    'revoked_at',
                    'created_at',
                ])->latest();
            },
        ]);

        return [
            'api_client_id' => $apiClient->id,
            'api_client_name' => $apiClient->name,
            'api_client_slug' => $apiClient->slug,
            'api_client_type' => $apiClient->type,

            'application_passwords_count' => $apiClient->applicationPasswords->count(),

            'application_passwords' => $apiClient->applicationPasswords->map(function ($password) {
                return [
                    'application_password_id' => $password->id,
                    'application_token_id' => $password->id,
                    'application_password_name' => $password->name,
                    'token_prefix' => $password->token_prefix,
                    'abilities' => $password->abilities ?? [],
                    'is_valid' => $password->isValid(),
                    'last_used_at' => $password->last_used_at,
                    'expires_at' => $password->expires_at,
                    'revoked_at' => $password->revoked_at,
                    'created_at' => $password->created_at,
                ];
            })->values(),
        ];
    }
}
