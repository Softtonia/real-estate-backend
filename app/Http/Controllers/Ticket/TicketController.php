<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketResponse;
use App\Models\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    private const MAX_ATTACHMENTS = 5;
    private const MAX_FILE_SIZE_KB = 10240;

    private const TICKET_RELATIONS = [
        'raisedBy:id,first_name,last_name,email',
        'assignedTo:id,first_name,last_name,email',
        'status.media',
        'priority.media',
        'type.media',
        'department.media',
        'attachments',
        'ccUsers:id,first_name,last_name,email',
        'property',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Unauthenticated.', 401);
        }

        $query = $this->buildTicketQuery($request)
            ->visibleTo($user);

        return $this->paginatedResponse(
            $query->paginate($this->perPage($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Unauthenticated.', 401);
        }

        $validator = Validator::make(
            $request->all(),
            $this->storeRules()
        );

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $validated = $validator->validated();

        if (count($this->requestFiles($request)) > self::MAX_ATTACHMENTS) {
            return $this->validationError([
                'attachments' => [
                    'A ticket can contain a maximum of '
                    . self::MAX_ATTACHMENTS
                    . ' attachments.',
                ],
            ]);
        }

        $isAdmin = Ticket::userIsAdmin($user);

        if (
            !$isAdmin
            && isset($validated['user_id'])
            && $validated['user_id'] !== null
            && (int) $validated['user_id'] !== (int) $user->id
        ) {
            return $this->error(
                'You cannot assign a ticket to another user.',
                403
            );
        }

        $statusId = $validated['status_id']
            ?? $this->defaultStatusId();

        if (!$statusId) {
            return $this->validationError([
                'status_id' => [
                    'No default ticket status exists. Create a New or Open status first.',
                ],
            ]);
        }

        $raisedBy = $isAdmin
            ? ($validated['raised_by'] ?? $user->id)
            : $user->id;

        $uploadedPaths = [];

        try {
            $ticket = DB::transaction(function () use (
                $request,
                $validated,
                $statusId,
                $raisedBy,
                &$uploadedPaths
            ): Ticket {
                $ticket = Ticket::create([
                    'ticket_number' => $this->generateUniqueTicketNumber(),
                    'raised_by' => $raisedBy,
                    'user_id' => $validated['user_id'] ?? null,
                    'subject' => $validated['subject'],
                    'message' => $validated['message'],
                    'status_id' => $statusId,
                    'priority_id' => $validated['priority_id'],
                    'ticket_type_id' => $validated['ticket_type_id'],
                    'ticket_department_id' => $validated['ticket_department_id'],
                    'due_date' => $validated['due_date'] ?? null,
                    'property_id' => $validated['property_id'] ?? null,
                ]);

                $ticket->ccUsers()->sync(
                    $validated['cc_user_ids'] ?? []
                );

                foreach ($this->requestFiles($request) as $file) {
                    $attachment = $this->storeAttachment(
                        $ticket,
                        $file
                    );

                    $uploadedPaths[] = $attachment->file_path;
                }

                return $ticket;
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($uploadedPaths);
            report($exception);

            return $this->error(
                'Failed to create ticket.',
                500
            );
        }

        return response()->json([
            'status' => true,
            'message' => 'Ticket created successfully.',
            'data' => $this->loadTicket($ticket),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:tickets,id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $ticket = Ticket::with(self::TICKET_RELATIONS)
            ->find($request->integer('id'));

        if (!$ticket) {
            return $this->error('Ticket not found.', 404);
        }

        if (!$this->canAccess($request, $ticket)) {
            return $this->error(
                'You are not allowed to view this ticket.',
                403
            );
        }

        return response()->json([
            'status' => true,
            'message' => 'Ticket fetched successfully.',
            'data' => $this->ticketPayload($ticket),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Unauthenticated.', 401);
        }

        $validator = Validator::make(
            $request->all(),
            $this->updateRules()
        );

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $validated = $validator->validated();

        $ticket = Ticket::with(['attachments', 'ccUsers'])
            ->find($validated['id']);

        if (!$ticket) {
            return $this->error('Ticket not found.', 404);
        }

        if (!$ticket->isVisibleTo($user)) {
            return $this->error(
                'You are not allowed to update this ticket.',
                403
            );
        }

        $isAdmin = Ticket::userIsAdmin($user);

        if (!$isAdmin) {
            foreach ([
                'raised_by',
                'user_id',
                'priority_id',
                'ticket_type_id',
                'ticket_department_id',
            ] as $adminField) {
                unset($validated[$adminField]);
            }
        }

        $removeAttachmentIds = array_values(
            array_unique($validated['remove_attachment_ids'] ?? [])
        );

        $removableAttachments = $ticket->attachments()
            ->whereIn('id', $removeAttachmentIds)
            ->get();

        if (count($removeAttachmentIds) !== $removableAttachments->count()) {
            return $this->validationError([
                'remove_attachment_ids' => [
                    'One or more attachment IDs do not belong to this ticket.',
                ],
            ]);
        }

        $newFiles = $this->requestFiles($request);

        $remainingAttachmentCount = $ticket->attachments->count()
            - $removableAttachments->count()
            + count($newFiles);

        if ($remainingAttachmentCount > self::MAX_ATTACHMENTS) {
            return $this->validationError([
                'attachments' => [
                    'A ticket can contain a maximum of '
                    . self::MAX_ATTACHMENTS
                    . ' attachments.',
                ],
            ]);
        }

        $uploadedPaths = [];
        $pathsToDelete = $removableAttachments
            ->pluck('file_path')
            ->all();

        try {
            DB::transaction(function () use (
                $ticket,
                $validated,
                $isAdmin,
                $removableAttachments,
                $newFiles,
                &$uploadedPaths
            ): void {
                $updateData = [];

                foreach ([
                    'subject',
                    'message',
                    'status_id',
                    'priority_id',
                    'ticket_type_id',
                    'ticket_department_id',
                    'due_date',
                    'property_id',
                ] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $updateData[$field] = $validated[$field];
                    }
                }

                if ($isAdmin) {
                    foreach (['raised_by', 'user_id'] as $field) {
                        if (array_key_exists($field, $validated)) {
                            $updateData[$field] = $validated[$field];
                        }
                    }
                }

                if ($updateData !== []) {
                    $ticket->update($updateData);
                }

                if (array_key_exists('cc_user_ids', $validated)) {
                    $ticket->ccUsers()->sync(
                        $validated['cc_user_ids'] ?? []
                    );
                }

                foreach ($removableAttachments as $attachment) {
                    $attachment->delete();
                }

                foreach ($newFiles as $file) {
                    $attachment = $this->storeAttachment(
                        $ticket,
                        $file
                    );

                    $uploadedPaths[] = $attachment->file_path;
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($uploadedPaths);
            report($exception);

            return $this->error(
                'Failed to update ticket.',
                500
            );
        }

        Storage::disk('public')->delete($pathsToDelete);

        return response()->json([
            'status' => true,
            'message' => 'Ticket updated successfully.',
            'data' => $this->loadTicket($ticket),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:tickets,id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $ticket = Ticket::with('attachments')
            ->find($request->integer('id'));

        if (!$ticket) {
            return $this->error('Ticket not found.', 404);
        }

        if (!$this->canAccess($request, $ticket)) {
            return $this->error(
                'You are not allowed to delete this ticket.',
                403
            );
        }

        $paths = $ticket->attachments
            ->pluck('file_path')
            ->all();

        try {
            DB::transaction(function () use ($ticket): void {
                $ticket->delete();
            });
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(
                'Failed to delete ticket.',
                500
            );
        }

        Storage::disk('public')->delete($paths);

        return response()->json([
            'status' => true,
            'message' => 'Ticket deleted successfully.',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Unauthenticated.', 401);
        }

        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required|integer|distinct|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $requestedIds = array_values($validator->validated()['ids']);

        $tickets = Ticket::with(['attachments', 'ccUsers'])
            ->whereIn('id', $requestedIds)
            ->get();

        $notFoundIds = array_values(
            array_diff($requestedIds, $tickets->pluck('id')->all())
        );

        $unauthorizedIds = $tickets
            ->reject(fn (Ticket $ticket): bool => $ticket->isVisibleTo($user))
            ->pluck('id')
            ->values()
            ->all();

        if ($unauthorizedIds !== []) {
            return response()->json([
                'status' => false,
                'message' => 'Some tickets cannot be deleted by this user.',
                'unauthorized_ids' => $unauthorizedIds,
            ], 403);
        }

        $paths = $tickets
            ->flatMap(fn (Ticket $ticket) => $ticket->attachments->pluck('file_path'))
            ->values()
            ->all();

        try {
            DB::transaction(function () use ($tickets): void {
                foreach ($tickets as $ticket) {
                    $ticket->delete();
                }
            });
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(
                'Failed to delete tickets.',
                500
            );
        }

        Storage::disk('public')->delete($paths);

        return response()->json([
            'status' => true,
            'message' => 'Tickets deleted successfully.',
            'requested_ids' => $requestedIds,
            'deleted_ids' => $tickets->pluck('id')->values(),
            'deleted_count' => $tickets->count(),
            'not_found_ids' => $notFoundIds,
        ]);
    }

    public function searchByTicketNumber(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    public function getTicketByToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Unauthenticated.', 401);
        }

        $query = $this->buildTicketQuery($request)
            ->where(function (Builder $builder) use ($user): void {
                $builder
                    ->where('raised_by', $user->id)
                    ->orWhere('user_id', $user->id)
                    ->orWhereHas('ccUsers', function (Builder $ccQuery) use ($user): void {
                        $ccQuery->where('users.id', $user->id);
                    });
            });

        return $this->paginatedResponse(
            $query->paginate($this->perPage($request))
        );
    }

    public function updateTicketStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|integer|exists:tickets,id',
            'status_id' => 'required|integer|exists:ticket_status,id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $ticket = Ticket::with('ccUsers')
            ->find($request->integer('ticket_id'));

        if (!$ticket) {
            return $this->error('Ticket not found.', 404);
        }

        if (!$this->canAccess($request, $ticket)) {
            return $this->error(
                'You are not allowed to update this ticket status.',
                403
            );
        }

        $ticket->update([
            'status_id' => $request->integer('status_id'),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Ticket status updated successfully.',
            'data' => $this->loadTicket($ticket),
        ]);
    }

    public function respond(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|integer|exists:tickets,id',
            'message' => 'required|string|max:10000',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $user = $request->user();

        if (!$user) {
            return $this->error('Unauthenticated.', 401);
        }

        $ticket = Ticket::with('ccUsers')
            ->find($request->integer('ticket_id'));

        if (!$ticket) {
            return $this->error('Ticket not found.', 404);
        }

        if (!$ticket->isVisibleTo($user)) {
            return $this->error(
                'You are not allowed to respond to this ticket.',
                403
            );
        }

        $response = TicketResponse::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => (string) $request->input('message'),
            'message_by' => Ticket::userIsAdmin($user)
                ? 'admin'
                : 'user',
        ]);

        $response->load('user:id,first_name,last_name,email');

        return response()->json([
            'status' => true,
            'message' => 'Response submitted successfully.',
            'data' => $response,
        ], 201);
    }

    public function respondlist(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|integer|exists:tickets,id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $ticket = Ticket::with('ccUsers')
            ->find($request->integer('ticket_id'));

        if (!$ticket) {
            return $this->error('Ticket not found.', 404);
        }

        if (!$this->canAccess($request, $ticket)) {
            return $this->error(
                'You are not allowed to view these responses.',
                403
            );
        }

        $responses = TicketResponse::with(
            'user:id,first_name,last_name,email'
        )
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Ticket responses fetched successfully.',
            'data' => $responses,
        ]);
    }

    public function ticketResponseHistory(
        Request $request,
        int $ticketId
    ): JsonResponse {
        $ticket = Ticket::with(array_merge(
            self::TICKET_RELATIONS,
            ['responses.user:id,first_name,last_name,email']
        ))->find($ticketId);

        if (!$ticket) {
            return $this->error('Ticket not found.', 404);
        }

        if (!$this->canAccess($request, $ticket)) {
            return $this->error(
                'You are not allowed to view this ticket history.',
                403
            );
        }

        $history = collect([
            [
                'id' => null,
                'message' => $ticket->message,
                'message_by' => 'raised_by',
                'user' => $ticket->raisedBy,
                'attachments' => $ticket->attachments,
                'created_at' => $ticket->created_at,
            ],
        ])->concat(
            $ticket->responses->map(
                fn (TicketResponse $response): array => [
                    'id' => $response->id,
                    'message' => $response->message,
                    'message_by' => $response->message_by,
                    'user' => $response->user,
                    'attachments' => [],
                    'created_at' => $response->created_at,
                ]
            )
        )->sortBy('created_at')->values();

        return response()->json([
            'status' => true,
            'message' => 'Ticket response history fetched successfully.',
            'data' => [
                'ticket' => $this->ticketPayload($ticket),
                'chat_history' => $history,
            ],
        ]);
    }

    private function buildTicketQuery(Request $request): Builder
    {
        $query = Ticket::query()
            ->with(self::TICKET_RELATIONS);

        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $filterMap = [
            'status_id' => 'status_id',
            'priority_id' => 'priority_id',
            'ticket_type_id' => 'ticket_type_id',
            'ticket_department_id' => 'ticket_department_id',
            'user_id' => 'user_id',
            'raised_by' => 'raised_by',
            'property_id' => 'property_id',
        ];

        foreach ($filterMap as $requestKey => $column) {
            if ($request->filled($requestKey)) {
                $query->where(
                    $column,
                    $request->integer($requestKey)
                );
            }
        }

        if ($request->filled('due_from')) {
            $query->whereDate('due_date', '>=', $request->input('due_from'));
        }

        if ($request->filled('due_to')) {
            $query->whereDate('due_date', '<=', $request->input('due_to'));
        }

        $allowedSorts = [
            'created_at',
            'updated_at',
            'due_date',
            'ticket_number',
            'status_id',
            'priority_id',
        ];

        $sortBy = in_array(
            $request->input('sort_by'),
            $allowedSorts,
            true
        )
            ? $request->input('sort_by')
            : 'created_at';

        $sortDirection = strtolower(
            (string) $request->input('sort_direction', 'desc')
        ) === 'asc'
            ? 'asc'
            : 'desc';

        return $query->orderBy($sortBy, $sortDirection);
    }

    private function storeRules(): array
    {
        return [
            'raised_by' => 'nullable|integer|exists:users,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'subject' => 'required|string|max:200',
            'message' => 'required|string|max:50000',
            'status_id' => 'nullable|integer|exists:ticket_status,id',
            'priority_id' => 'required|integer|exists:ticket_priorities,id',
            'ticket_type_id' => 'required|integer|exists:ticket_types,id',
            'ticket_department_id' => 'required|integer|exists:ticket_departments,id',
            'due_date' => 'nullable|date_format:Y-m-d',
            'property_id' => [
                'nullable',
                'integer',
                Rule::exists((new Property())->getTable(), 'id'),
            ],
            'cc_user_ids' => 'nullable|array|max:50',
            'cc_user_ids.*' => 'integer|distinct|exists:users,id',
            'attachments' => 'nullable|array|max:' . self::MAX_ATTACHMENTS,
            'attachments.*' => $this->attachmentRule(),
            'media_attachment' => [
                'nullable',
                $this->attachmentRule(),
            ],
        ];
    }

    private function updateRules(): array
    {
        return [
            'id' => 'required|integer|exists:tickets,id',
            'raised_by' => 'sometimes|nullable|integer|exists:users,id',
            'user_id' => 'sometimes|nullable|integer|exists:users,id',
            'subject' => 'sometimes|required|string|max:200',
            'message' => 'sometimes|required|string|max:50000',
            'status_id' => 'sometimes|nullable|integer|exists:ticket_status,id',
            'priority_id' => 'sometimes|nullable|integer|exists:ticket_priorities,id',
            'ticket_type_id' => 'sometimes|nullable|integer|exists:ticket_types,id',
            'ticket_department_id' => 'sometimes|required|integer|exists:ticket_departments,id',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'property_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists((new Property())->getTable(), 'id'),
            ],
            'cc_user_ids' => 'sometimes|nullable|array|max:50',
            'cc_user_ids.*' => 'integer|distinct|exists:users,id',
            'attachments' => 'nullable|array|max:' . self::MAX_ATTACHMENTS,
            'attachments.*' => $this->attachmentRule(),
            'media_attachment' => [
                'nullable',
                $this->attachmentRule(),
            ],
            'remove_attachment_ids' => 'nullable|array|max:' . self::MAX_ATTACHMENTS,
            'remove_attachment_ids.*' => 'integer|distinct|exists:ticket_attachments,id',
        ];
    }

    private function attachmentRule(): string
    {
        return 'file|mimes:png,jpg,jpeg,pdf,doc,docx,xls,xlsx|max:'
            . self::MAX_FILE_SIZE_KB;
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function requestFiles(Request $request): array
    {
        $files = $request->file('attachments', []);

        if (!is_array($files)) {
            $files = [$files];
        }

        if ($request->hasFile('media_attachment')) {
            $files[] = $request->file('media_attachment');
        }

        return array_values(
            array_filter(
                $files,
                fn ($file): bool => $file instanceof UploadedFile
                    && $file->isValid()
            )
        );
    }

    private function storeAttachment(
        Ticket $ticket,
        UploadedFile $file
    ): TicketAttachment {
        $path = $file->store(
            "tickets/{$ticket->id}",
            'public'
        );

        return $ticket->attachments()->create([
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
    }

    private function defaultStatusId(): ?int
    {
        $status = TicketStatus::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereRaw('LOWER(ticket_status_name) = ?', ['new'])
                    ->orWhereRaw('LOWER(ticket_status_name) = ?', ['open']);
            })
            ->orderBy('display_order')
            ->first();

        $status ??= TicketStatus::query()
            ->orderBy('display_order')
            ->first();

        return $status?->id;
    }

    private function generateUniqueTicketNumber(): string
    {
        $prefix = trim(
            (string) (optional(SiteSetting::first())->ticket_prefix ?? 'TCKT')
        );

        $prefix = $prefix !== ''
            ? Str::upper(Str::slug($prefix, ''))
            : 'TCKT';

        do {
            $ticketNumber = sprintf(
                '%s-%s-%s',
                $prefix,
                now()->format('Ym'),
                Str::upper(Str::random(6))
            );
        } while (
            Ticket::where('ticket_number', $ticketNumber)->exists()
        );

        return $ticketNumber;
    }

    private function canAccess(Request $request, Ticket $ticket): bool
    {
        $user = $request->user();

        return $user !== null
            && $ticket->isVisibleTo($user);
    }

    private function loadTicket(Ticket $ticket): array
    {
        $ticket->refresh();
        $ticket->load(self::TICKET_RELATIONS);

        return $this->ticketPayload($ticket);
    }

    private function ticketPayload(Ticket $ticket): array
    {
        $payload = $ticket->toArray();
        $firstAttachment = $ticket->attachments->first();

        // Temporary compatibility fields for the old frontend.
        $payload['media_attachment'] = $firstAttachment?->original_name
            ?? $ticket->media_attachment;

        $payload['media_attachment_url'] = $firstAttachment?->file_url
            ?? (
                $ticket->media_attachment
                    ? url('attachments/' . $ticket->media_attachment)
                    : null
            );

        return $payload;
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
        $items = collect($paginator->items())
            ->map(
                fn (Ticket $ticket): array => $this->ticketPayload($ticket)
            )
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Tickets fetched successfully.',
            'data' => $items,
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
