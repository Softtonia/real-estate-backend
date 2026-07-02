<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\PageBuilder\Services\TemplateListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TemplateListController extends Controller
{
    public function __construct(
        protected TemplateListService $templateListService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->all();

        $validator = Validator::make($filters, [
            'search' => ['nullable', 'string', 'max:255'],
            'template_type' => ['nullable', 'string', 'max:100'],
            'post_type_id' => ['nullable', 'integer'],
            'post_type_slug' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable'],
            'created_by' => ['nullable', 'integer'],

            'has_layout' => ['nullable'],
            'has_conditions' => ['nullable'],

            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'updated_from' => ['nullable', 'date'],
            'updated_to' => ['nullable', 'date'],

            'trash' => ['nullable', 'in:without,with,only'],

            'sort_by' => ['nullable', 'string'],
            'sort_dir' => ['nullable', 'in:asc,desc'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $paginator = $this->templateListService->paginate($filters);
        $paginator = $this->templateListService->transformPaginator($paginator);

        return response()->json([
            'status' => true,
            'message' => 'Templates fetched successfully.',
            'data' => $paginator,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $filters = $request->all();

        return response()->json([
            'status' => true,
            'message' => 'Template stats fetched successfully.',
            'data' => $this->templateListService->stats($filters),
        ]);
    }
}