<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomWidgetRequest;
use App\Http\Requests\UpdateCustomWidgetRequest;
use App\Http\Requests\SaveWidgetConfigurationRequest;
use App\Models\CustomWidget;
use App\Models\WidgetConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomWidgetController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomWidget::with([
            'createdBy:id,first_name,last_name,email',
            'configurations'
        ])->latest();

        if ($request->filled('search')) {
            $query->where('widget_name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('post_type')) {
            if (!CustomWidget::isValidPostType($request->post_type)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid post type selected.',
                ], 422);
            }

            $query->where('post_type', $request->post_type);
        }

        $perPage = (int) $request->get('per_page', 20);

        $widgets = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Custom widgets fetched successfully.',
            'data' => $widgets,
        ]);
    }

    public function store(StoreCustomWidgetRequest $request)
    {
        $payload = $request->validated();

        DB::beginTransaction();

        try {
            $widget = CustomWidget::create([
                'widget_name' => $payload['widget_name'],
                'slug' => $payload['slug'] ?? CustomWidget::generateUniqueSlug($payload['widget_name']),
                'post_type' => $payload['post_type'],
                'created_by' => $this->getAuthenticatedUserId(),
            ]);

            $this->syncConfigurations($widget, $payload['configurations'] ?? []);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Custom widget created successfully.',
                'data' => $widget->load([
                    'createdBy:id,first_name,last_name,email',
                    'configurations'
                ]),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to create custom widget.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $widget = CustomWidget::with([
            'createdBy:id,first_name,last_name,email',
            'configurations'
        ])->find($id);

        if (!$widget) {
            return response()->json([
                'status' => false,
                'message' => 'Custom widget not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Custom widget fetched successfully.',
            'data' => $widget,
        ]);
    }

    public function update(UpdateCustomWidgetRequest $request, $id)
    {
        $widget = CustomWidget::find($id);

        if (!$widget) {
            return response()->json([
                'status' => false,
                'message' => 'Custom widget not found.',
            ], 404);
        }

        if (!$this->canModifyWidget($widget)) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to update this widget.',
            ], 403);
        }

        $payload = $request->validated();

        DB::beginTransaction();

        try {
            $updateData = [
                'widget_name' => $payload['widget_name'],
                'post_type' => $payload['post_type'],
            ];

            if (!empty($payload['slug'])) {
                $updateData['slug'] = $payload['slug'];
            } elseif ($widget->widget_name !== $payload['widget_name']) {
                $updateData['slug'] = CustomWidget::generateUniqueSlug(
                    $payload['widget_name'],
                    $widget->id
                );
            }

            $widget->update($updateData);

            if (array_key_exists('configurations', $payload)) {
                $this->syncConfigurations($widget, $payload['configurations'] ?? []);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Custom widget updated successfully.',
                'data' => $widget->fresh([
                    'createdBy:id,first_name,last_name,email',
                    'configurations'
                ]),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to update custom widget.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $widget = CustomWidget::find($id);

        if (!$widget) {
            return response()->json([
                'status' => false,
                'message' => 'Custom widget not found.',
            ], 404);
        }

        if (!$this->canModifyWidget($widget)) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to delete this widget.',
            ], 403);
        }

        if ($request->boolean('force_delete')) {
            $widget->forceDelete();

            return response()->json([
                'status' => true,
                'message' => 'Custom widget permanently deleted successfully.',
            ]);
        }

        $widget->delete();

        return response()->json([
            'status' => true,
            'message' => 'Custom widget deleted successfully.',
        ]);
    }

    public function fields($post_type)
    {
        $post_type = strtolower(trim($post_type));

        $fields = match ($post_type) {
            CustomWidget::POST_TYPE_PROPERTY_LISTING => [
                'title',
                'excerpt',
                'featured_image',
                'gallery',
                'property_id',
                'amenities',
                'owner_name',
                'owner_phone',
            ],

            CustomWidget::POST_TYPE_PROJECT_LISTING => [
                'project_name',
                'project_gallery',
                'project_location',
                'project_description',
            ],

            CustomWidget::POST_TYPE_DEVELOPER_LISTING => [
                'developer_name',
                'developer_logo',
                'developer_projects',
                'developer_description',
            ],

            CustomWidget::POST_TYPE_BASIC => [
                'text',
                'image',
                'button',
                'html',
            ],

            default => null,
        };

        if ($fields === null) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid post type selected.',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Available fields fetched successfully.',
            'data' => [
                'post_type' => $post_type,
                'fields' => $fields,
            ],
        ]);
    }

    public function saveConfiguration(SaveWidgetConfigurationRequest $request)
    {
        $payload = $request->validated();

        $widget = CustomWidget::find($payload['widget_id']);

        if (!$widget) {
            return response()->json([
                'status' => false,
                'message' => 'Custom widget not found.',
            ], 404);
        }

        if (!$this->canModifyWidget($widget)) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to update this widget configuration.',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $this->syncConfigurations($widget, $payload['configurations']);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Widget configuration saved successfully.',
                'data' => $widget->fresh([
                    'createdBy:id,first_name,last_name,email',
                    'configurations'
                ]),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Unable to save widget configuration.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function widgetsByPostType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'post_type' => 'required|in:basic,property-listing,project-listing,developer-listing',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $widgets = CustomWidget::with('configurations')
            ->where(function ($query) use ($request) {
                $query->where('post_type', $request->post_type)
                    ->orWhere('post_type', CustomWidget::POST_TYPE_BASIC);
            })
            ->latest()
            ->get()
            ->map(function ($widget) {
                $config = $widget->configurations->pluck('field_value', 'field_key');

                return [
                    'id' => $widget->id,
                    'name' => $widget->widget_name,
                    'slug' => $widget->slug,
                    'post_type' => $widget->post_type,
                    'key' => $config['component']['key'] ?? $widget->slug,
                    'type' => $config['component']['type'] ?? 'dynamic',
                    'binding' => $config['binding'] ?? null,
                    'settings' => $config['settings'] ?? new \stdClass(),
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Custom widgets fetched successfully.',
            'data' => $widgets,
        ]);
    }

    private function syncConfigurations(CustomWidget $widget, array $configurations): void
    {
        $widget->configurations()->delete();

        foreach ($configurations as $configuration) {
            if (empty($configuration['field_key'])) {
                continue;
            }

            WidgetConfiguration::create([
                'widget_id' => $widget->id,
                'field_key' => $configuration['field_key'],
                'field_value' => $this->prepareJsonValue($configuration['field_value'] ?? null),
            ]);
        }
    }

    private function prepareJsonValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return $value;
        }

        return $value;
    }

    private function canModifyWidget(CustomWidget $widget): bool
    {
        /*
         * Your routes are protected by admin.token.
         * If this API reaches controller, admin token is already validated.
         * This prevents false 403 when auth()->user() is null due to custom token middleware.
         */
        if ($this->routeHasMiddleware('admin.token')) {
            return true;
        }

        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (isset($user->role) && strtolower((string) $user->role) === 'admin') {
            return true;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        return (int) $widget->created_by === (int) $user->id;
    }

    private function routeHasMiddleware(string $middleware): bool
    {
        $route = request()->route();

        if (!$route) {
            return false;
        }

        return in_array($middleware, $route->middleware(), true);
    }

    private function getAuthenticatedUserId()
    {
        if (auth()->id()) {
            return auth()->id();
        }

        if (request()->user()) {
            return request()->user()->id;
        }

        return null;
    }

    private function validationErrorResponse($validator)
    {
        return response()->json([
            'status' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }
}