<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

abstract class BaseTicketLookupController extends Controller
{
    /** @var class-string<Model> */
    protected string $modelClass;
    protected string $table;
    protected string $nameColumn;
    protected string $resourceName;
    protected string $ticketForeignKey;
    protected bool $supportsStatus = false;

    public function index(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);

        $items = ($this->modelClass)::query()
            ->with('media')
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate($perPage);

        return $this->paginatedResponse($items);
    }

    public function search(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);
        $search = trim((string) $request->input('search', ''));

        $query = ($this->modelClass)::query()->with('media');

        if ($search !== '') {
            $query->where($this->nameColumn, 'like', '%' . $search . '%');
        }

        $items = $query
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate($perPage);

        return $this->paginatedResponse($items);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            $item = DB::transaction(function () use ($validated) {
                $validated['display_order'] = $validated['display_order']
                    ?? $this->nextDisplayOrder();

                return ($this->modelClass)::create($validated);
            });

            $item->load('media');

            return response()->json([
                'status' => true,
                'message' => ucfirst($this->resourceName) . ' created successfully.',
                'data' => $item,
            ], 201);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Failed to create ' . $this->resourceName . '.',
            ], 500);
        }
    }

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer', Rule::exists($this->table, 'id')],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $item = ($this->modelClass)::with('media')->find((int) $request->id);

        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => ucfirst($this->resourceName) . ' not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $item,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $id = (int) $request->input('id');
        $item = ($this->modelClass)::find($id);

        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => ucfirst($this->resourceName) . ' not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), $this->rules($id));

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $validated['display_order'] = $validated['display_order'] ?? $item->display_order;

        try {
            $item->update($validated);
            $item->load('media');

            return response()->json([
                'status' => true,
                'message' => ucfirst($this->resourceName) . ' updated successfully.',
                'data' => $item,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Failed to update ' . $this->resourceName . '.',
            ], 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer', Rule::exists($this->table, 'id')],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $id = (int) $request->id;

        if ($this->isUsedByTicket($id)) {
            return response()->json([
                'status' => false,
                'message' => ucfirst($this->resourceName) . ' is assigned to one or more tickets and cannot be deleted.',
            ], 409);
        }

        $item = ($this->modelClass)::find($id);

        if (!$item) {
            return response()->json([
                'status' => false,
                'message' => ucfirst($this->resourceName) . ' not found.',
            ], 404);
        }

        $item->delete();

        return response()->json([
            'status' => true,
            'message' => ucfirst($this->resourceName) . ' deleted successfully.',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $requestedIds = array_values(array_unique(array_map('intval', $request->input('ids'))));
        $existingIds = ($this->modelClass)::whereIn('id', $requestedIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $notFoundIds = array_values(array_diff($requestedIds, $existingIds));
        $inUseIds = Ticket::whereIn($this->ticketForeignKey, $existingIds)
            ->pluck($this->ticketForeignKey)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $deletableIds = array_values(array_diff($existingIds, $inUseIds));
        $deletedCount = 0;

        if ($deletableIds !== []) {
            $deletedCount = ($this->modelClass)::whereIn('id', $deletableIds)->delete();
        }

        return response()->json([
            'status' => true,
            'message' => $deletedCount . ' ' . $this->resourceName . '(s) deleted successfully.',
            'requested_ids' => $requestedIds,
            'deleted_ids' => $deletableIds,
            'deleted_count' => $deletedCount,
            'in_use_ids' => $inUseIds,
            'not_found_ids' => $notFoundIds,
        ]);
    }

    protected function rules(?int $id = null): array
    {
        $rules = [
            'id' => $id === null
                ? ['sometimes']
                : ['required', 'integer', Rule::exists($this->table, 'id')],
            'icon_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
            $this->nameColumn => [
                'required',
                'string',
                'max:255',
                Rule::unique($this->table, $this->nameColumn)->ignore($id),
            ],
            'display_order' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique($this->table, 'display_order')->ignore($id),
            ],
        ];

        if ($this->supportsStatus) {
            $rules['status'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    protected function nextDisplayOrder(): int
    {
        $max = ($this->modelClass)::query()->lockForUpdate()->max('display_order');

        return ((int) $max) + 1;
    }

    protected function isUsedByTicket(int $id): bool
    {
        return Ticket::where($this->ticketForeignKey, $id)->exists();
    }

    protected function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 10), 1), 100);
    }

    protected function paginatedResponse($items): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
            'links' => [
                'first' => $items->url(1),
                'last' => $items->url($items->lastPage()),
                'prev' => $items->previousPageUrl(),
                'next' => $items->nextPageUrl(),
            ],
        ]);
    }
}
