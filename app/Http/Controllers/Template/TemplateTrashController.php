<?php

declare(strict_types=1);

namespace App\Http\Controllers\Template;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\PageBuilder\Services\TemplateTrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TemplateTrashController extends Controller
{
    public function __construct(
        protected TemplateTrashService $templateTrashService
    ) {
    }

    public function trashed(Request $request): JsonResponse
    {
        $query = Template::onlyTrashed()
            ->with(['layout', 'conditions', 'postType'])
            ->latest('deleted_at');

        if ($request->filled('template_type')) {
            $query->where('template_type', $request->template_type);
        }

        if ($request->filled('post_type_id')) {
            $query->where('post_type_id', $request->post_type_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('template_name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('shortcode', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'status' => true,
            'message' => 'Trashed templates fetched successfully.',
            'data' => $query->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    public function trash(int $id): JsonResponse
    {
        $template = Template::withTrashed()->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        try {
            $template = $this->templateTrashService->trash($template);

            return response()->json([
                'status' => true,
                'message' => 'Template moved to trash successfully.',
                'data' => $template,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to move template to trash.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $template = Template::withTrashed()->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        $status = $request->input('status', 'draft');

        if (! in_array($status, ['draft', 'inactive'], true)) {
            $status = 'draft';
        }

        try {
            $template = $this->templateTrashService->restore($template, $status);

            return response()->json([
                'status' => true,
                'message' => 'Template restored successfully.',
                'data' => $template,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to restore template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function forceDelete(int $id): JsonResponse
    {
        $template = Template::withTrashed()->find($id);

        if (! $template) {
            return response()->json([
                'status' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        try {
            $this->templateTrashService->forceDelete($template);

            return response()->json([
                'status' => true,
                'message' => 'Template permanently deleted successfully.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to permanently delete template.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkTrash(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->templateTrashService->bulkTrash($request->ids);

        return response()->json([
            'status' => true,
            'message' => 'Templates moved to trash successfully.',
            'data' => $result,
        ]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $status = $request->input('status', 'draft');

        if (! in_array($status, ['draft', 'inactive'], true)) {
            $status = 'draft';
        }

        $result = $this->templateTrashService->bulkRestore($request->ids, $status);

        return response()->json([
            'status' => true,
            'message' => 'Templates restored successfully.',
            'data' => $result,
        ]);
    }

    public function bulkForceDelete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->templateTrashService->bulkForceDelete($request->ids);

            return response()->json([
                'status' => true,
                'message' => 'Templates permanently deleted successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to permanently delete templates.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'older_than_days' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->templateTrashService->emptyTrash(
                $request->filled('older_than_days')
                    ? (int) $request->older_than_days
                    : null,
                (int) $request->get('limit', 500)
            );

            return response()->json([
                'status' => true,
                'message' => 'Trash emptied successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to empty trash.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}