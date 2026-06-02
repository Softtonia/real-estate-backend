<?php

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    /**
     * Template listing
     */
    public function index()
    {
        try {
            $templates = Template::with('displayConditions')
                ->latest()
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Templates fetched successfully.',
                'data' => $templates,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while fetching templates.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store template with multiple display conditions
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => ['required', 'string', 'max:255'],

                'display_conditions' => ['nullable', 'array'],

                'display_conditions.*.show_type' => [
                    'required',
                    Rule::in(['include', 'exclude']),
                ],

                'display_conditions.*.post_type' => [
                    'required',
                    Rule::in([
                        'property-listing',
                        'project-listing',
                        'developer-listing',
                    ]),
                ],

                'display_conditions.*.condition_type' => [
                    'required',
                    Rule::in([
                        'all',
                        'purpose',
                        'property',
                        'property-type',
                        'property-status',
                    ]),
                ],

                'display_conditions.*.value' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
            ]);

            $template = Template::create([
                'name' => $request->name,
                'slug' => $this->generateUniqueSlug($request->name),
                'created_by' => Auth::id() ?? $request->user_id ?? null,
            ]);

            if ($request->has('display_conditions') && is_array($request->display_conditions)) {
                foreach ($request->display_conditions as $condition) {
                    $template->displayConditions()->create([
                        'show_type' => $condition['show_type'],
                        'post_type' => $condition['post_type'],
                        'condition_type' => $condition['condition_type'],
                        'value' => $condition['condition_type'] === 'all'
                            ? null
                            : ($condition['value'] ?? null),
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Template created successfully.',
                'data' => $template->load('displayConditions'),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while creating template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Single template detail
     */
    public function show($id)
    {
        try {
            $template = Template::with('displayConditions')->find($id);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found.',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Template fetched successfully.',
                'data' => $template,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while fetching template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update template with multiple display conditions
     */
    public function update(Request $request, $id)
    {
        try {
            $template = Template::find($id);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found.',
                ], 404);
            }

            $request->validate([
                'name' => ['required', 'string', 'max:255'],

                'display_conditions' => ['nullable', 'array'],

                'display_conditions.*.show_type' => [
                    'required',
                    Rule::in(['include', 'exclude']),
                ],

                'display_conditions.*.post_type' => [
                    'required',
                    Rule::in([
                        'property-listing',
                        'project-listing',
                        'developer-listing',
                    ]),
                ],

                'display_conditions.*.condition_type' => [
                    'required',
                    Rule::in([
                        'all',
                        'purpose',
                        'property',
                        'property-type',
                        'property-status',
                    ]),
                ],

                'display_conditions.*.value' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
            ]);

            $template->update([
                'name' => $request->name,
                'slug' => $this->generateUniqueSlug($request->name, $template->id),
            ]);

            if ($request->has('display_conditions') && is_array($request->display_conditions)) {
                $template->displayConditions()->delete();

                foreach ($request->display_conditions as $condition) {
                    $template->displayConditions()->create([
                        'show_type' => $condition['show_type'],
                        'post_type' => $condition['post_type'],
                        'condition_type' => $condition['condition_type'],
                        'value' => $condition['condition_type'] === 'all'
                            ? null
                            : ($condition['value'] ?? null),
                    ]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Template updated successfully.',
                'data' => $template->load('displayConditions'),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while updating template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete template
     */
    public function destroy($id)
    {
        try {
            $template = Template::find($id);

            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found.',
                ], 404);
            }

            $template->delete();

            return response()->json([
                'status' => true,
                'message' => 'Template deleted successfully.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while deleting template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto generate unique slug from template name
     *
     * Example:
     * Single property template
     * single-property-template
     * single-property-template-1
     * single-property-template-2
     */
    private function generateUniqueSlug($name, $ignoreId = null)
    {
        $baseSlug = Str::slug($name);

        if (empty($baseSlug)) {
            $baseSlug = 'template';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            Template::where('slug', $slug)
                ->when($ignoreId, function ($query) use ($ignoreId) {
                    $query->where('id', '!=', $ignoreId);
                })
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}