<?php

namespace App\Http\Controllers\Api\Membership;

use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\Admin\MembershipBulkDeleteRequest;
use App\Http\Requests\Membership\Admin\MembershipCategoryRequest;
use App\Http\Requests\Membership\Admin\MembershipFeatureRequest;
use App\Http\Requests\Membership\Admin\MembershipImportRequest;
use App\Http\Requests\Membership\Admin\MembershipPlanRequest;
use App\Http\Requests\Membership\Admin\SyncPlanFeaturesRequest;
use App\Http\Requests\Membership\Admin\SyncPlanRolesRequest;
use App\Http\Resources\Membership\MembershipPlanResource;
use App\Models\Membership\MembershipCategory;
use App\Models\Membership\MembershipFeature;
use App\Models\Membership\MembershipPlan;
use App\Models\User;
use App\Services\Membership\MembershipAccessService;
use App\Services\Membership\MembershipCatalogImportExportService;
use App\Services\Membership\MembershipPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;


class AdminMembershipCatalogController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        try {
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $categories = MembershipCategory::query()
                ->select(['id', 'name', 'slug', 'description', 'status', 'sort_order', 'created_at', 'updated_at'])
                ->when($request->filled('status'), function ($query) use ($request) {
                    $query->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = trim((string) $request->search);

                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
                })
                ->withCount('plans')
                ->ordered()
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Membership categories fetched successfully.',
                'data' => $categories,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership categories.', $e);
        }
    }

    public function storeCategory(MembershipCategoryRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $category = MembershipCategory::query()->create([
                'name' => $data['name'],
                'slug' => Str::slug($data['slug'] ?? $data['name']),
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership category created successfully.',
                'data' => $category,
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership category.', $e);
        }
    }

    public function updateCategory(MembershipCategoryRequest $request, MembershipCategory $category): JsonResponse
    {
        try {
            $data = $request->validated();

            $payload = [];

            if (array_key_exists('name', $data)) {
                $payload['name'] = $data['name'];
            }

            if (array_key_exists('slug', $data)) {
                $payload['slug'] = \Illuminate\Support\Str::slug(
                    $data['slug'] ?: ($data['name'] ?? $category->name)
                );
            }

            if (array_key_exists('description', $data)) {
                $payload['description'] = $data['description'];
            }

            if (array_key_exists('status', $data)) {
                $payload['status'] = (bool) $data['status'];
            }

            if (array_key_exists('sort_order', $data)) {
                $payload['sort_order'] = (int) $data['sort_order'];
            }

            if (! empty($payload)) {
                $category->update($payload);
            }

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership category updated successfully.',
                'data' => $category->fresh(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationError($e);
        } catch (\Throwable $e) {
            return $this->serverError('Unable to update membership category.', $e);
        }
    }

    public function deleteCategory(MembershipCategory $category): JsonResponse
    {
        try {
            if ($category->plans()->exists()) {
                $category->update(['status' => false]);

                $this->clearCatalogCaches();

                return response()->json([
                    'status' => true,
                    'message' => 'Category has plans, so it was deactivated instead of deleted.',
                    'data' => $category->fresh(),
                ]);
            }

            $category->delete();

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership category deleted successfully.',
                'data' => null,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to delete membership category.', $e);
        }
    }

    public function features(Request $request): JsonResponse
    {
        try {
            $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

            $features = MembershipFeature::query()
                ->select(['id', 'name', 'slug', 'description', 'feature_type', 'status', 'sort_order', 'created_at', 'updated_at'])
                ->when($request->filled('status'), function ($query) use ($request) {
                    $query->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
                })
                ->when($request->filled('feature_type'), function ($query) use ($request) {
                    $query->where('feature_type', $request->feature_type);
                })
                ->when($request->filled('search'), function ($query) use ($request) {
                    $search = trim((string) $request->search);

                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
                })
                ->withCount('planFeatures')
                ->ordered()
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Membership features fetched successfully.',
                'data' => $features,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership features.', $e);
        }
    }

    public function showFeature(MembershipFeature $feature): JsonResponse
    {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership feature fetched successfully.',
                'data' => $feature->loadCount('planFeatures'),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership feature.', $e);
        }
    }

    public function storeFeature(MembershipFeatureRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $feature = new MembershipFeature();

            $feature->forceFill([
                'name' => $data['name'],
                'slug' => Str::slug($data['slug'] ?? $data['name']),
                'description' => $data['description'] ?? null,
                'feature_type' => $data['feature_type'],
                'status' => array_key_exists('status', $data) ? (bool) $data['status'] : true,
                'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : 0,
            ]);

            $feature->save();

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership feature created successfully.',
                'data' => $feature->fresh(),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership feature.', $e);
        }
    }

    public function updateFeature(MembershipFeatureRequest $request, MembershipFeature $feature): JsonResponse
    {
        try {
            $data = $request->validated();

            $payload = [];

            if (array_key_exists('name', $data)) {
                $payload['name'] = $data['name'];
            }

            if (array_key_exists('slug', $data)) {
                $payload['slug'] = Str::slug(
                    $data['slug'] ?: ($data['name'] ?? $feature->name)
                );
            }

            if (array_key_exists('description', $data)) {
                $payload['description'] = $data['description'];
            }

            if (array_key_exists('feature_type', $data)) {
                $payload['feature_type'] = $data['feature_type'];
            }

            if (array_key_exists('status', $data)) {
                $payload['status'] = (bool) $data['status'];
            }

            if (array_key_exists('sort_order', $data)) {
                $payload['sort_order'] = (int) $data['sort_order'];
            }

            if (! empty($payload)) {
                $feature->forceFill($payload);
                $feature->save();
            }

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership feature updated successfully.',
                'data' => $feature->fresh()->loadCount('planFeatures'),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to update membership feature.', $e);
        }
    }

    public function deleteFeature(MembershipFeature $feature): JsonResponse
    {
        try {
            if ($feature->planFeatures()->exists()) {
                $feature->update(['status' => false]);

                $this->clearCatalogCaches();

                return response()->json([
                    'status' => true,
                    'message' => 'Feature is used by plans, so it was deactivated instead of deleted.',
                    'data' => $feature->fresh(),
                ]);
            }

            $feature->delete();

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership feature deleted successfully.',
                'data' => null,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to delete membership feature.', $e);
        }
    }

    public function plans(Request $request, MembershipPlanService $planService): JsonResponse
    {
        try {
            $plans = $planService->adminPaginatedPlans($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership plans fetched successfully.',
                'data' => MembershipPlanResource::collection($plans),
                'meta' => [
                    'current_page' => $plans->currentPage(),
                    'last_page' => $plans->lastPage(),
                    'per_page' => $plans->perPage(),
                    'total' => $plans->total(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership plans.', $e);
        }
    }

    public function showPlan(MembershipPlan $plan, MembershipPlanService $planService): JsonResponse
    {
        try {
            $plan = $planService->planDetail($plan);

            return response()->json([
                'status' => true,
                'message' => 'Membership plan fetched successfully.',
                'data' => new MembershipPlanResource($plan),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership plan.', $e);
        }
    }

    public function storePlan(
        MembershipPlanRequest $request,
        MembershipPlanService $planService
    ): JsonResponse {
        try {
            $plan = $planService->createPlan(
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership plan created successfully.',
                'data' => new MembershipPlanResource($plan),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership plan.', $e);
        }
    }

    public function updatePlan(
        MembershipPlanRequest $request,
        MembershipPlan $plan,
        MembershipPlanService $planService
    ): JsonResponse {
        try {
            $plan = $planService->updatePlan(
                plan: $plan,
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership plan updated successfully.',
                'data' => new MembershipPlanResource($plan),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to update membership plan.', $e);
        }
    }

    public function deletePlan(
        MembershipPlan $plan,
        MembershipPlanService $planService
    ): JsonResponse {
        try {
            $planService->deletePlan(
                plan: $plan,
                admin: request()->user()
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership plan deleted or deactivated successfully.',
                'data' => null,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to delete membership plan.', $e);
        }
    }

    public function syncPlanFeatures(
        SyncPlanFeaturesRequest $request,
        MembershipPlan $plan,
        MembershipPlanService $planService
    ): JsonResponse {
        try {
            $plan = $planService->syncFeatures(
                plan: $plan,
                features: $request->validated()['features'],
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Plan features synced successfully.',
                'data' => new MembershipPlanResource($plan),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to sync plan features.', $e);
        }
    }

    public function syncPlanRoles(
        SyncPlanRolesRequest $request,
        MembershipPlan $plan,
        MembershipPlanService $planService
    ): JsonResponse {
        try {
            $plan = $planService->syncRoleRules(
                plan: $plan,
                roleIds: $request->validated()['role_ids'],
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Plan role rules synced successfully.',
                'data' => new MembershipPlanResource($plan),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to sync plan roles.', $e);
        }
    }

    private function clearCatalogCaches(): void
    {
        Cache::store('redis')->forget('membership:plans:active');
        Cache::store('redis')->forget('membership:admin:stats');

        app(MembershipAccessService::class)->forgetGlobalPlanCaches();
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

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'error' => $e->errors(),
        ], 422);
    }

    private function serverError(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
    public function exportCategories(
        Request $request,
        MembershipCatalogImportExportService $service
    ): StreamedResponse {
        return $service->export('categories', $request->all());
    }

    public function importCategories(
        MembershipImportRequest $request,
        MembershipCatalogImportExportService $service
    ): JsonResponse {
        try {
            $result = $service->import(
                type: 'categories',
                file: $request->file('file'),
                mode: $request->input('mode', 'upsert')
            );

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership categories imported successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to import membership categories.', $e);
        }
    }

    public function bulkDeleteCategories(
        MembershipBulkDeleteRequest $request,
        MembershipCatalogImportExportService $service
    ): JsonResponse {
        try {
            $result = $service->bulkDelete('categories', $request->validated()['ids']);

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership categories bulk delete completed.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to bulk delete membership categories.', $e);
        }
    }

    public function exportFeatures(
        Request $request,
        MembershipCatalogImportExportService $service
    ): StreamedResponse {
        return $service->export('features', $request->all());
    }

    public function importFeatures(
        MembershipImportRequest $request,
        MembershipCatalogImportExportService $service
    ): JsonResponse {
        try {
            $result = $service->import(
                type: 'features',
                file: $request->file('file'),
                mode: $request->input('mode', 'upsert')
            );

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership features imported successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to import membership features.', $e);
        }
    }

    public function bulkDeleteFeatures(
        MembershipBulkDeleteRequest $request,
        MembershipCatalogImportExportService $service
    ): JsonResponse {
        try {
            $result = $service->bulkDelete('features', $request->validated()['ids']);

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership features bulk delete completed.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to bulk delete membership features.', $e);
        }
    }

    public function exportPlans(
        Request $request,
        MembershipCatalogImportExportService $service
    ): StreamedResponse {
        return $service->export('plans', $request->all());
    }

    public function importPlans(
        MembershipImportRequest $request,
        MembershipCatalogImportExportService $service
    ): JsonResponse {
        try {
            $result = $service->import(
                type: 'plans',
                file: $request->file('file'),
                mode: $request->input('mode', 'upsert')
            );

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership plans imported successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to import membership plans.', $e);
        }
    }

    public function bulkDeletePlans(
        MembershipBulkDeleteRequest $request,
        MembershipCatalogImportExportService $service
    ): JsonResponse {
        try {
            $result = $service->bulkDelete('plans', $request->validated()['ids']);

            $this->clearCatalogCaches();

            return response()->json([
                'status' => true,
                'message' => 'Membership plans bulk delete completed.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to bulk delete membership plans.', $e);
        }
    }
}
