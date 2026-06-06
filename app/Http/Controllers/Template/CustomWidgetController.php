<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\CustomWidget;
use App\Models\WidgetConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomWidgetController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomWidget::with(['createdBy:id,email', 'configurations'])
            ->latest();

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

        $perPage = $request->get('per_page', 20);

        $widgets = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Custom widgets fetched successfully.',
            'data' => $widgets,
        ]);
    }

    public function store(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'widget_name' => 'required|string|max:255',
            'post_type' => 'required|in:basic,property-listing,project-listing,developer-listing',
            'configurations' => 'nullable|array',
            'configurations.*.field_key' => 'required_with:configurations|string|max:255',
            'configurations.*.field_value' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $widget = CustomWidget::create([
                'widget_name' => $payload['widget_name'],
                'slug' => CustomWidget::generateUniqueSlug($payload['widget_name']),
                'post_type' => $payload['post_type'],
                'created_by' => auth()->id(),
            ]);

            $this->syncConfigurations($widget, $payload['configurations'] ?? []);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Custom widget created successfully.',
                'data' => $widget->load(['createdBy:id,email', 'configurations']),
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
        $widget = CustomWidget::with(['createdBy:id,email', 'configurations'])->find($id);

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

    public function update(Request $request, $id)
    {
        $widget = CustomWidget::find($id);

        if (!$widget) {
            return response()->json([
                'status' => false,
                'message' => 'Custom widget not found.',
            ], 404);
        }

        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'widget_name' => 'required|string|max:255',
            'post_type' => 'required|in:basic,property-listing,project-listing,developer-listing',
            'configurations' => 'nullable|array',
            'configurations.*.field_key' => 'required_with:configurations|string|max:255',
            'configurations.*.field_value' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        DB::beginTransaction();

        try {
            $updateData = [
                'widget_name' => $payload['widget_name'],
                'post_type' => $payload['post_type'],
            ];

            if ($widget->widget_name !== $payload['widget_name']) {
                $updateData['slug'] = CustomWidget::generateUniqueSlug($payload['widget_name'], $widget->id);
            }

            $widget->update($updateData);

            if (array_key_exists('configurations', $payload)) {
                $this->syncConfigurations($widget, $payload['configurations'] ?? []);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Custom widget updated successfully.',
                'data' => $widget->fresh(['createdBy:id,email', 'configurations']),
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

        $forceDelete = $request->boolean('force_delete');

        if ($forceDelete) {
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

    private function getPayload(Request $request): array
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            $payload = $request->all();
        }

        if (empty($payload) && $request->getContent()) {
            $decoded = json_decode($request->getContent(), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return is_array($payload) ? $payload : [];
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
