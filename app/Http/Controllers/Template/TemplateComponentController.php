<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\TemplateComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TemplateComponentController extends Controller
{
    public function index()
    {
        $components = TemplateComponent::orderBy('id', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Template components fetched successfully.',
            'data' => $components,
        ]);
    }

    public function create(Request $request)
    {
        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'component_name' => 'required|string|max:255',
            'component_key' => 'nullable|string|max:255|unique:template_components,component_key',
            'component_type' => 'required|in:static,dynamic',
            'icon' => 'nullable|string|max:255',
            'config_json' => 'nullable',
            'status' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $configJson = $this->prepareJsonField($payload['config_json'] ?? null);

        if ($configJson['error']) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid config JSON.',
            ], 422);
        }

        $componentKey = $payload['component_key'] ?? null;

        if (!$componentKey) {
            $componentKey = $this->generateUniqueComponentKey($payload['component_name']);
        }

        $component = TemplateComponent::create([
            'component_name' => $payload['component_name'],
            'component_key' => $componentKey,
            'component_type' => $payload['component_type'],
            'icon' => $payload['icon'] ?? null,
            'config_json' => $configJson['value'],
            'status' => $this->toBoolean($payload['status'] ?? true),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template component created successfully.',
            'data' => $component,
        ], 201);
    }

    public function show($id)
    {
        $component = TemplateComponent::find($id);

        if (!$component) {
            return response()->json([
                'status' => false,
                'message' => 'Template component not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Template component fetched successfully.',
            'data' => $component,
        ]);
    }

    public function update(Request $request, $id)
    {
        $component = TemplateComponent::find($id);

        if (!$component) {
            return response()->json([
                'status' => false,
                'message' => 'Template component not found.',
            ], 404);
        }

        $payload = $this->getPayload($request);

        $validator = Validator::make($payload, [
            'component_name' => 'required|string|max:255',
            'component_key' => 'nullable|string|max:255|unique:template_components,component_key,' . $component->id,
            'component_type' => 'required|in:static,dynamic',
            'icon' => 'nullable|string|max:255',
            'config_json' => 'nullable',
            'status' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $configJson = $this->prepareJsonField($payload['config_json'] ?? null);

        if ($configJson['error']) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid config JSON.',
            ], 422);
        }

        $componentKey = $payload['component_key'] ?? $component->component_key;

        $component->update([
            'component_name' => $payload['component_name'],
            'component_key' => $componentKey,
            'component_type' => $payload['component_type'],
            'icon' => $payload['icon'] ?? null,
            'config_json' => $configJson['value'],
            'status' => array_key_exists('status', $payload)
                ? $this->toBoolean($payload['status'])
                : $component->status,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template component updated successfully.',
            'data' => $component->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $component = TemplateComponent::find($id);

        if (!$component) {
            return response()->json([
                'status' => false,
                'message' => 'Template component not found.',
            ], 404);
        }

        $component->delete();

        return response()->json([
            'status' => true,
            'message' => 'Template component deleted successfully.',
        ]);
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

    private function prepareJsonField($value): array
    {
        if ($value === null || $value === '') {
            return [
                'error' => false,
                'value' => null,
            ];
        }

        if (is_array($value)) {
            return [
                'error' => false,
                'value' => $value,
            ];
        }

        if (is_object($value)) {
            return [
                'error' => false,
                'value' => json_decode(json_encode($value), true),
            ];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'error' => true,
                    'value' => null,
                ];
            }

            return [
                'error' => false,
                'value' => $decoded,
            ];
        }

        return [
            'error' => true,
            'value' => null,
        ];
    }

    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        return false;
    }

    private function generateUniqueComponentKey(string $componentName, $ignoreId = null): string
    {
        $baseKey = Str::slug($componentName, '_');

        if (!$baseKey) {
            $baseKey = 'component';
        }

        $componentKey = $baseKey;
        $counter = 1;

        while (
            TemplateComponent::where('component_key', $componentKey)
                ->when($ignoreId, function ($query) use ($ignoreId) {
                    $query->where('id', '!=', $ignoreId);
                })
                ->exists()
        ) {
            $componentKey = $baseKey . '_' . $counter;
            $counter++;
        }

        return $componentKey;
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