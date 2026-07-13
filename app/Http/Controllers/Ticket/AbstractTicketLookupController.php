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

abstract class AbstractTicketLookupController extends Controller
{
    abstract protected function modelClass(): string;

    abstract protected function nameColumn(): string;

    abstract protected function ticketForeignKey(): string;

    protected function iconRequired(): bool
    {
        return true;
    }

    protected function extraRules(bool $updating): array
    {
        return [];
    }

    protected function defaultExtraData(): array
    {
        return [];
    }

    public function index(Request $request): JsonResponse
    {
        $modelClass = $this->modelClass();
        $nameColumn = $this->nameColumn();

        $query = $modelClass::query()->with('media');

        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $query->where($nameColumn, 'like', "%{$search}%");
        }

        $results = $query
            ->orderBy('display_order')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paginatedResponse($results);
    }

    public function store(Request $request): JsonResponse
    {
        $modelClass = $this->modelClass();
        $model = new $modelClass();
        $table = $model->getTable();
        $nameColumn = $this->nameColumn();

        $validator = Validator::make(
            $request->all(),
            array_merge([
                $nameColumn => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique($table, $nameColumn),
                ],
                'icon_id' => [
                    $this->iconRequired() ? 'required' : 'nullable',
                    'integer',
                    'exists:media,id',
                ],
                'display_order' => [
                    'nullable',
                    'integer',
                    'min:1',
                    Rule::unique($table, 'display_order'),
                ],
            ], $this->extraRules(false))
        );

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $validated = $validator->validated();

        try {
            $record = DB::transaction(function () use (
                $modelClass,
                $validated
            ): Model {
                $displayOrder = $validated['display_order']
                    ?? $this->nextDisplayOrder($modelClass);

                return $modelClass::create(array_merge(
                    $this->defaultExtraData(),
                    $validated,
                    ['display_order' => $displayOrder]
                ));
            });
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(
                'Failed to create record. Check for duplicate name or display order.',
                500
            );
        }

        $record->load('media');

        return response()->json([
            'status' => true,
            'message' => 'Record created successfully.',
            'data' => $record,
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $modelClass = $this->modelClass();

        $record = $modelClass::with('media')
            ->find($request->integer('id'));

        if (!$record) {
            return $this->error('Record not found.', 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Record fetched successfully.',
            'data' => $record,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $idValidator = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($idValidator->fails()) {
            return $this->validationError(
                $idValidator->errors()->toArray()
            );
        }

        $modelClass = $this->modelClass();
        $record = $modelClass::find($request->integer('id'));

        if (!$record) {
            return $this->error('Record not found.', 404);
        }

        $table = $record->getTable();
        $nameColumn = $this->nameColumn();

        $validator = Validator::make(
            $request->all(),
            array_merge([
                'id' => 'required|integer',
                $nameColumn => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique($table, $nameColumn)
                        ->ignore($record->id),
                ],
                'icon_id' => [
                    'sometimes',
                    $this->iconRequired() ? 'required' : 'nullable',
                    'integer',
                    'exists:media,id',
                ],
                'display_order' => [
                    'sometimes',
                    'required',
                    'integer',
                    'min:1',
                    Rule::unique($table, 'display_order')
                        ->ignore($record->id),
                ],
            ], $this->extraRules(true))
        );

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $validated = $validator->validated();
        unset($validated['id']);

        try {
            if ($validated !== []) {
                $record->update($validated);
            }
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(
                'Failed to update record.',
                500
            );
        }

        $record->refresh()->load('media');

        return response()->json([
            'status' => true,
            'message' => 'Record updated successfully.',
            'data' => $record,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $modelClass = $this->modelClass();
        $record = $modelClass::find($request->integer('id'));

        if (!$record) {
            return $this->error('Record not found.', 404);
        }

        if ($this->recordIsInUse($record->id)) {
            return response()->json([
                'status' => false,
                'message' => 'This record is assigned to existing tickets and cannot be deleted.',
            ], 409);
        }

        $record->delete();

        return response()->json([
            'status' => true,
            'message' => 'Record deleted successfully.',
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required|integer|distinct|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $requestedIds = array_values($validator->validated()['ids']);
        $modelClass = $this->modelClass();

        $existingIds = $modelClass::whereIn('id', $requestedIds)
            ->pluck('id')
            ->all();

        $notFoundIds = array_values(
            array_diff($requestedIds, $existingIds)
        );

        $inUseIds = Ticket::whereIn(
            $this->ticketForeignKey(),
            $existingIds
        )
            ->pluck($this->ticketForeignKey())
            ->unique()
            ->values()
            ->all();

        $deletableIds = array_values(
            array_diff($existingIds, $inUseIds)
        );

        $deletedCount = $modelClass::whereIn('id', $deletableIds)
            ->delete();

        return response()->json([
            'status' => true,
            'message' => 'Bulk delete completed.',
            'requested_ids' => $requestedIds,
            'deleted_ids' => $deletableIds,
            'deleted_count' => $deletedCount,
            'in_use_ids' => $inUseIds,
            'not_found_ids' => $notFoundIds,
        ]);
    }

    protected function search(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    private function nextDisplayOrder(string $modelClass): int
    {
        $lastRecord = $modelClass::query()
            ->whereNotNull('display_order')
            ->orderByDesc('display_order')
            ->lockForUpdate()
            ->first();

        return ((int) ($lastRecord?->display_order ?? 0)) + 1;
    }

    private function recordIsInUse(int $id): bool
    {
        return Ticket::where(
            $this->ticketForeignKey(),
            $id
        )->exists();
    }

    private function perPage(Request $request): int
    {
        return min(
            max((int) $request->input('per_page', 10), 1),
            100
        );
    }

    private function paginatedResponse($paginator): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Records fetched successfully.',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $errors,
        ], 422);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], $status);
    }
}
