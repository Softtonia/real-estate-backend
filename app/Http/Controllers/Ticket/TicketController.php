<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketResponse;
use App\Models\TicketStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        /*
     * Stats query: only access scope apply.
     * Search/filter/pagination apply nahi hoga, so top cards always full count show karenge.
     */
        $statsQuery = Ticket::query();
        $this->applyAccessScope($statsQuery, $user);
        $stats = $this->ticketStats($statsQuery);

        /*
     * Listing query: access + filters + pagination.
     */
        $query = Ticket::query()->with($this->relations());

        $this->applyAccessScope($query, $user);
        $this->applyFilters($query, $request);

        $tickets = $query
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginatedTicketsResponse($tickets, $stats);
    }
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->unauthenticatedResponse();
            }

            $this->normalizeTicketRequest($request);

            $validator = Validator::make($request->all(), $this->storeRules());

            if ($validator->fails()) {
                return $this->validationResponse($validator->errors());
            }

            $validated = $validator->validated();

            if ($this->incomingFilesCount($request) > 5) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed.',
                    'errors' => [
                        'attachments' => ['A maximum of 5 files is allowed.'],
                    ],
                ], 422);
            }

            $isAdmin = $this->isAdmin($user);

            $raisedBy = $isAdmin
                ? (int) ($validated['raised_by'] ?? $user->id)
                : (int) $user->id;

            if (
                !$isAdmin &&
                isset($validated['user_id']) &&
                (int) $validated['user_id'] !== (int) $user->id
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot assign a ticket to another user.',
                ], 403);
            }

            $statusId = $validated['status_id'] ?? TicketStatus::query()
                ->orderBy('display_order')
                ->orderBy('id')
                ->value('id');

            if (!$statusId) {
                return response()->json([
                    'status' => false,
                    'message' => 'No ticket status is configured.',
                    'errors' => [
                        'status_id' => ['Create a ticket status before creating tickets.'],
                    ],
                ], 422);
            }

            $ticket = DB::transaction(function () use ($request, $validated, $raisedBy, $statusId) {
                $prefix = optional(SiteSetting::first())->ticket_prefix ?: 'TCKT';

                $ticket = Ticket::create([
                    'ticket_number' => $this->generateUniqueTicketNumber($prefix),
                    'raised_by' => $raisedBy,
                    'user_id' => $validated['user_id'] ?? null,
                    'subject' => $validated['subject'] ?? null,
                    'message' => $validated['message'],
                    'status_id' => $statusId,
                    'priority_id' => $validated['priority_id'],
                    'ticket_type_id' => $validated['ticket_type_id'],
                    'ticket_department_id' => $validated['ticket_department_id'],
                    'due_date' => $validated['due_date'] ?? null,
                    'property_id' => $validated['property_id'] ?? null,
                ]);

                $ticket->ccUsers()->sync($validated['cc_user_ids'] ?? []);

                $this->storeAttachments($ticket, $request);
                $this->syncLegacyAttachmentColumn($ticket);

                return $ticket;
            });

            return response()->json([
                'status' => true,
                'message' => 'Ticket created successfully.',
                'data' => $this->formatTicket($ticket->fresh($this->relations())),
            ], 201);
        } catch (Throwable $exception) {
            Log::error('Ticket creation failed', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'request' => $request->except(['attachments', 'media_attachment']),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to create ticket.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer', Rule::exists('tickets', 'id')],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors());
        }

        $ticket = Ticket::with($this->relations())->find((int) $request->id);

        if (!$ticket) {
            return $this->ticketNotFoundResponse();
        }

        if (!$this->canAccessTicket($user, $ticket)) {
            return $this->forbiddenResponse();
        }

        return response()->json([
            'status' => true,
            'message' => 'Ticket fetched successfully.',
            'data' => $this->formatTicket($ticket),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $this->normalizeTicketRequest($request);

        $validator = Validator::make($request->all(), $this->updateRules());

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors());
        }

        $validated = $validator->validated();

        $ticket = Ticket::with(['attachments', 'ccUsers'])->find((int) $validated['id']);

        if (!$ticket) {
            return $this->ticketNotFoundResponse();
        }

        if (!$this->canAccessTicket($user, $ticket)) {
            return $this->forbiddenResponse();
        }

        $removeIds = array_map('intval', $validated['remove_attachment_ids'] ?? []);

        $remainingAttachments = $ticket->attachments
            ->reject(fn(TicketAttachment $attachment) => in_array((int) $attachment->id, $removeIds, true))
            ->count();

        if ($remainingAttachments + $this->incomingFilesCount($request) > 5) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => [
                    'attachments' => ['A ticket can contain a maximum of 5 files.'],
                ],
            ], 422);
        }

        $isAdmin = $this->isAdmin($user);

        if (!$isAdmin) {
            if (
                isset($validated['raised_by']) &&
                (int) $validated['raised_by'] !== (int) $ticket->raised_by
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot change who raised this ticket.',
                ], 403);
            }

            if (
                isset($validated['user_id']) &&
                (int) $validated['user_id'] !== (int) $user->id
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot assign this ticket to another user.',
                ], 403);
            }
        }

        try {
            DB::transaction(function () use ($request, $validated, $ticket, $isAdmin) {
                $fields = [
                    'subject',
                    'message',
                    'status_id',
                    'priority_id',
                    'ticket_type_id',
                    'ticket_department_id',
                    'due_date',
                    'property_id',
                ];

                if ($isAdmin) {
                    $fields[] = 'raised_by';
                    $fields[] = 'user_id';
                }

                foreach ($fields as $field) {
                    if (array_key_exists($field, $validated)) {
                        $ticket->{$field} = $validated[$field];
                    }
                }

                $ticket->save();

                if (array_key_exists('cc_user_ids', $validated)) {
                    $ticket->ccUsers()->sync($validated['cc_user_ids'] ?? []);
                }

                $this->removeAttachments($ticket, $validated['remove_attachment_ids'] ?? []);
                $this->storeAttachments($ticket, $request);
                $this->syncLegacyAttachmentColumn($ticket);
            });

            return response()->json([
                'status' => true,
                'message' => 'Ticket updated successfully.',
                'data' => $this->formatTicket($ticket->fresh($this->relations())),
            ]);
        } catch (Throwable $exception) {
            Log::error('Ticket update failed', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'ticket_id' => $ticket->id,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to update ticket.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'id' => ['required', 'integer', Rule::exists('tickets', 'id')],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors());
        }

        $ticket = Ticket::with('attachments')->find((int) $request->id);

        if (!$ticket) {
            return $this->ticketNotFoundResponse();
        }

        if (!$this->canAccessTicket($user, $ticket)) {
            return $this->forbiddenResponse();
        }

        try {
            foreach ($ticket->attachments as $attachment) {
                Storage::disk('public')->delete($attachment->file_path);
            }

            $ticket->delete();

            return response()->json([
                'status' => true,
                'message' => 'Ticket deleted successfully.',
            ]);
        } catch (Throwable $exception) {
            Log::error('Ticket delete failed', [
                'message' => $exception->getMessage(),
                'ticket_id' => $ticket->id,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete ticket.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], 500);
        }
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors());
        }

        $requestedIds = array_values(array_unique(array_map('intval', $request->input('ids'))));

        $tickets = Ticket::with('attachments')
            ->whereIn('id', $requestedIds)
            ->get();

        $existingIds = $tickets->pluck('id')->map(fn($id) => (int) $id)->all();
        $notFoundIds = array_values(array_diff($requestedIds, $existingIds));
        $deniedIds = [];
        $deletedIds = [];

        foreach ($tickets as $ticket) {
            if (!$this->canAccessTicket($user, $ticket)) {
                $deniedIds[] = (int) $ticket->id;
                continue;
            }

            foreach ($ticket->attachments as $attachment) {
                Storage::disk('public')->delete($attachment->file_path);
            }

            $ticket->delete();
            $deletedIds[] = (int) $ticket->id;
        }

        return response()->json([
            'status' => true,
            'message' => count($deletedIds) . ' ticket(s) deleted successfully.',
            'deleted_ids' => $deletedIds,
            'deleted_count' => count($deletedIds),
            'forbidden_ids' => $deniedIds,
            'not_found_ids' => $notFoundIds,
        ]);
    }

    public function searchByTicketNumber(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $search = trim((string) $request->input('search', ''));

        $query = Ticket::query()->with($this->relations());

        $this->applyAccessScope($query, $user);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('ticket_number', 'like', '%' . $search . '%')
                    ->orWhere('subject', 'like', '%' . $search . '%')
                    ->orWhere('message', 'like', '%' . $search . '%');
            });
        }

        $tickets = $query
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginatedTicketsResponse($tickets);
    }

    public function getTicketByToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $tickets = Ticket::query()
            ->with($this->relations())
            ->where(function (Builder $query) use ($user) {
                $query
                    ->where('raised_by', $user->id)
                    ->orWhere('user_id', $user->id)
                    ->orWhereHas('ccUsers', function (Builder $ccQuery) use ($user) {
                        $ccQuery->where('users.id', $user->id);
                    });
            })
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginatedTicketsResponse($tickets);
    }

    public function updateTicketStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'ticket_id' => ['required', 'integer', Rule::exists('tickets', 'id')],
            'status_id' => ['required', 'integer', Rule::exists('ticket_status', 'id')],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors());
        }

        $ticket = Ticket::find((int) $request->ticket_id);

        if (!$ticket) {
            return $this->ticketNotFoundResponse();
        }

        if (!$this->canAccessTicket($user, $ticket)) {
            return $this->forbiddenResponse();
        }

        $ticket->update([
            'status_id' => (int) $request->status_id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Status updated successfully.',
            'data' => $this->formatTicket($ticket->fresh($this->relations())),
        ]);
    }

    public function respond(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'ticket_id' => ['required', 'integer', Rule::exists('tickets', 'id')],
            'message' => ['required', 'string'],
            'message_by' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors());
        }

        $ticket = Ticket::find((int) $request->ticket_id);

        if (!$ticket) {
            return $this->ticketNotFoundResponse();
        }

        if (!$this->canAccessTicket($user, $ticket)) {
            return $this->forbiddenResponse();
        }

        $response = TicketResponse::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $request->message,
            'message_by' => $request->input(
                'message_by',
                $this->isAdmin($user) ? 'admin' : 'user'
            ),
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
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'ticket_id' => ['required', 'integer', Rule::exists('tickets', 'id')],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator->errors());
        }

        $ticket = Ticket::find((int) $request->ticket_id);

        if (!$ticket) {
            return $this->ticketNotFoundResponse();
        }

        if (!$this->canAccessTicket($user, $ticket)) {
            return $this->forbiddenResponse();
        }

        $responses = TicketResponse::with('user:id,first_name,last_name,email')
            ->where('ticket_id', $ticket->id)
            ->oldest('created_at')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Ticket responses fetched successfully.',
            'data' => $responses,
        ]);
    }

    public function ticketResponseHistory(Request $request, int $ticketId): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $ticket = Ticket::with(array_merge($this->relations(), [
            'responses.user:id,first_name,last_name,email',
        ]))->find($ticketId);

        if (!$ticket) {
            return $this->ticketNotFoundResponse();
        }

        if (!$this->canAccessTicket($user, $ticket)) {
            return $this->forbiddenResponse();
        }

        $chatHistory = [[
            'id' => null,
            'message' => $ticket->message,
            'message_by' => 'raised_by',
            'user_id' => $ticket->raised_by,
            'user_name' => $this->userName($ticket->raisedBy),
            'attachments' => $ticket->attachments
                ->map(fn($attachment) => $this->formatAttachment($attachment))
                ->values(),
            'created_at' => $ticket->created_at,
        ]];

        foreach ($ticket->responses->sortBy('created_at') as $response) {
            $chatHistory[] = [
                'id' => $response->id,
                'message' => $response->message,
                'message_by' => $response->message_by,
                'user_id' => $response->user_id,
                'user_name' => $this->userName($response->user),
                'attachments' => [],
                'created_at' => $response->created_at,
            ];
        }

        return response()->json([
            'status' => true,
            'message' => 'Ticket response history fetched successfully.',
            'data' => [
                'ticket' => $this->formatTicket($ticket),
                'chat_history' => array_values($chatHistory),
            ],
        ]);
    }

    private function storeRules(): array
    {
        return [
            'raised_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string'],
            'ticket_type_id' => ['required', 'integer', Rule::exists('ticket_types', 'id')],
            'priority_id' => ['required', 'integer', Rule::exists('ticket_priorities', 'id')],
            'status_id' => ['nullable', 'integer', Rule::exists('ticket_status', 'id')],
            'ticket_department_id' => ['required', 'integer', Rule::exists('ticket_departments', 'id')],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'property_id' => ['nullable', 'integer', $this->propertyListingExistsRule()],
            'cc_user_ids' => ['nullable', 'array'],
            'cc_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => $this->fileRules(),
            'media_attachment' => array_merge(['nullable'], $this->fileRules()),
        ];
    }

    private function updateRules(): array
    {
        return [
            'id' => ['required', 'integer', Rule::exists('tickets', 'id')],
            'raised_by' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'message' => ['sometimes', 'required', 'string'],
            'ticket_type_id' => ['sometimes', 'integer', Rule::exists('ticket_types', 'id')],
            'priority_id' => ['sometimes', 'integer', Rule::exists('ticket_priorities', 'id')],
            'status_id' => ['sometimes', 'integer', Rule::exists('ticket_status', 'id')],
            'ticket_department_id' => ['sometimes', 'integer', Rule::exists('ticket_departments', 'id')],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'property_id' => ['sometimes', 'nullable', 'integer', $this->propertyListingExistsRule()],
            'cc_user_ids' => ['sometimes', 'nullable', 'array'],
            'cc_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => $this->fileRules(),
            'media_attachment' => array_merge(['nullable'], $this->fileRules()),
            'remove_attachment_ids' => ['nullable', 'array'],
            'remove_attachment_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('ticket_attachments', 'id'),
            ],
        ];
    }

    private function fileRules(): array
    {
        return [
            'file',
            'mimes:png,jpg,jpeg,pdf,doc,docx,xls,xlsx',
            'max:10240',
        ];
    }

    private function propertyListingExistsRule()
    {
        $propertyPostTypeId = DB::table('post_types')
            ->where('slug', 'property-listing')
            ->value('id');

        return Rule::exists('dynamic_posts', 'id')
            ->where(function ($query) use ($propertyPostTypeId) {
                $query->where('post_type_id', $propertyPostTypeId ?: 0);
            });
    }

    private function normalizeTicketRequest(Request $request): void
    {
        $updates = [];

        if (!$request->has('message') && $request->has('description')) {
            $updates['message'] = $request->input('description');
        }

        if (!$request->has('user_id') && $request->has('assigned_to')) {
            $updates['user_id'] = $this->extractId($request->input('assigned_to'));
        }

        $idAliases = [
            'status_id' => 'status',
            'priority_id' => 'priority',
            'ticket_type_id' => 'ticket_type',
            'ticket_department_id' => 'ticket_department',
            'property_id' => 'related_property',
        ];

        foreach ($idAliases as $target => $alias) {
            if (!$request->has($target) && $request->has($alias)) {
                $updates[$target] = $this->extractId($request->input($alias));
            }
        }

        if (!$request->has('cc_user_ids')) {
            $ccValue = $request->input(
                'cc_user_ids',
                $request->input('cc_users', $request->input('cc'))
            );

            if ($ccValue !== null) {
                $updates['cc_user_ids'] = $this->normalizeIdArray($ccValue);
            }
        } else {
            $updates['cc_user_ids'] = $this->normalizeIdArray($request->input('cc_user_ids'));
        }

        if ($request->filled('due_date')) {
            $updates['due_date'] = $this->normalizeDate((string) $request->input('due_date'));
        }

        if ($updates !== []) {
            $request->merge($updates);
        }
    }

    private function extractId(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value['value'] ?? $value['id'] ?? null;
        }

        if (is_object($value)) {
            return $value->value ?? $value->id ?? null;
        }

        return $value === '' ? null : $value;
    }

    private function normalizeIdArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = array_filter(array_map('trim', explode(',', $value)));
            }
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        return array_values(array_unique(array_filter(array_map(function ($item) {
            $id = $this->extractId($item);

            return is_numeric($id) ? (int) $id : null;
        }, $value))));
    }

    private function normalizeDate(string $value): string
    {
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d');
            } catch (Throwable) {
                //
            }
        }

        return $value;
    }

    private function incomingFilesCount(Request $request): int
    {
        $files = $request->file('attachments', []);
        $count = is_array($files) ? count(array_filter($files)) : ($files ? 1 : 0);

        if ($request->hasFile('media_attachment')) {
            $count++;
        }

        return $count;
    }

    private function storeAttachments(Ticket $ticket, Request $request): void
    {
        $files = $request->file('attachments', []);

        if (!is_array($files)) {
            $files = [$files];
        }

        if ($request->hasFile('media_attachment')) {
            $files[] = $request->file('media_attachment');
        }

        foreach (array_filter($files) as $file) {
            $path = $file->store('ticket-attachments/' . $ticket->id, 'public');

            $ticket->attachments()->create([
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => (int) $file->getSize(),
            ]);
        }
    }

    private function removeAttachments(Ticket $ticket, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $attachments = TicketAttachment::query()
            ->where('ticket_id', $ticket->id)
            ->whereIn('id', array_map('intval', $ids))
            ->get();

        foreach ($attachments as $attachment) {
            Storage::disk('public')->delete($attachment->file_path);
            $attachment->delete();
        }
    }

    private function syncLegacyAttachmentColumn(Ticket $ticket): void
    {
        $firstAttachment = $ticket->attachments()->oldest('id')->first();

        $ticket->media_attachment = $firstAttachment?->file_path;
        $ticket->save();
    }

    private function generateUniqueTicketNumber(string $prefix): string
    {
        $cleanPrefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'TCKT');

        do {
            $number = sprintf(
                '%s%s%06d',
                $cleanPrefix,
                now()->format('Ym'),
                random_int(1, 999999)
            );
        } while (Ticket::where('ticket_number', $number)->exists());

        return $number;
    }

    private function relations(): array
    {
        return [
            'raisedBy:id,first_name,last_name,email',
            'assignedTo:id,first_name,last_name,email',
            'status.media',
            'priority.media',
            'type.media',
            'department.media',
            'property:id,title,slug,status,live_status,author_id,post_type_id',
            'attachments',
            'ccUsers:id,first_name,last_name,email',
        ];
    }

    private function formatTicket(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'message' => $ticket->message,

            'raised_by' => $ticket->raised_by ? (int) $ticket->raised_by : null,
            'raised_by_name' => $this->userName($ticket->raisedBy),

            'user_id' => $ticket->user_id ? (int) $ticket->user_id : null,
            'user_name' => $this->userName($ticket->assignedTo),

            'status_id' => $ticket->status_id ? (int) $ticket->status_id : null,
            'status_name' => $ticket->status?->ticket_status_name,

            'priority_id' => $ticket->priority_id ? (int) $ticket->priority_id : null,
            'priority_name' => $ticket->priority?->ticket_priority,

            'ticket_type_id' => $ticket->ticket_type_id ? (int) $ticket->ticket_type_id : null,
            'ticket_type_name' => $ticket->type?->ticket_type_name,

            'ticket_department_id' => $ticket->ticket_department_id ? (int) $ticket->ticket_department_id : null,
            'ticket_department_name' => $ticket->department?->ticket_department_name,

            'due_date' => $ticket->due_date
                ? Carbon::parse($ticket->due_date)->format('Y-m-d')
                : null,

            'property_id' => $ticket->property_id ? (int) $ticket->property_id : null,
            'property' => $this->formatProperty($ticket->property),

            'cc_users' => $ticket->ccUsers
                ->map(fn($user) => [
                    'id' => (int) $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $this->userName($user),
                    'email' => $user->email,
                ])
                ->values(),

            'attachments' => $ticket->attachments
                ->map(fn($attachment) => $this->formatAttachment($attachment))
                ->values(),

            'media_attachment' => $ticket->media_attachment,
            'media_attachment_url' => $this->attachmentPublicUrl($ticket->media_attachment),

            'created_at' => optional($ticket->created_at)->toDateTimeString(),
            'updated_at' => optional($ticket->updated_at)->toDateTimeString(),
        ];
    }

    private function formatProperty($property): ?array
    {
        if (!$property) {
            return null;
        }

        return [
            'id' => (int) $property->id,
            'title' => $property->title,
            'slug' => $property->slug,
            'status' => $property->status,
            'live_status' => $property->live_status,
            'author_id' => $property->author_id ? (int) $property->author_id : null,
            'post_type_id' => $property->post_type_id ? (int) $property->post_type_id : null,
        ];
    }

    private function formatAttachment(TicketAttachment $attachment): array
    {
        return [
            'id' => (int) $attachment->id,
            'ticket_id' => (int) $attachment->ticket_id,
            'original_name' => $attachment->original_name,
            'file_path' => $attachment->file_path,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size ? (int) $attachment->file_size : null,
            'file_size_human' => $this->humanFileSize((int) ($attachment->file_size ?? 0)),
            'url' => $this->attachmentPublicUrl($attachment->file_path),
            'created_at' => optional($attachment->created_at)->toDateTimeString(),
        ];
    }

    private function attachmentPublicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('ticket_number', 'like', '%' . $search . '%')
                    ->orWhere('subject', 'like', '%' . $search . '%')
                    ->orWhere('message', 'like', '%' . $search . '%');
            });
        }

        foreach (
            [
                'status_id',
                'priority_id',
                'ticket_type_id',
                'ticket_department_id',
                'user_id',
                'raised_by',
                'property_id',
            ] as $filter
        ) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('due_date_from')) {
            $query->whereDate('due_date', '>=', $request->input('due_date_from'));
        }

        if ($request->filled('due_date_to')) {
            $query->whereDate('due_date', '<=', $request->input('due_date_to'));
        }
    }

    private function applyAccessScope(Builder $query, $user): void
    {
        if ($this->isAdmin($user)) {
            return;
        }

        $query->where(function (Builder $builder) use ($user) {
            $builder
                ->where('raised_by', $user->id)
                ->orWhere('user_id', $user->id)
                ->orWhereHas('ccUsers', function (Builder $ccQuery) use ($user) {
                    $ccQuery->where('users.id', $user->id);
                });
        });
    }

    private function canAccessTicket($user, Ticket $ticket): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (
            (int) $ticket->raised_by === (int) $user->id ||
            (int) $ticket->user_id === (int) $user->id
        ) {
            return true;
        }

        if ($ticket->relationLoaded('ccUsers')) {
            return $ticket->ccUsers->contains('id', $user->id);
        }

        return $ticket->ccUsers()->where('users.id', $user->id)->exists();
    }

    private function isAdmin($user): bool
    {
        if (!$user) {
            return false;
        }

        $roleName = optional($user->role)->name;

        return is_string($roleName) && strcasecmp($roleName, 'admin') === 0;
    }

    private function userName($user): ?string
    {
        if (!$user) {
            return null;
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $name !== '' ? $name : ($user->email ?? null);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 10), 1), 100);
    }

    private function paginatedTicketsResponse($tickets, array $stats = []): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'Tickets fetched successfully.',
            'stats' => $stats,
            'data' => collect($tickets->items())
                ->map(fn(Ticket $ticket) => $this->formatTicket($ticket))
                ->values(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
                'last_page' => $tickets->lastPage(),
            ],
            'links' => [
                'first' => $tickets->url(1),
                'last' => $tickets->url($tickets->lastPage()),
                'prev' => $tickets->previousPageUrl(),
                'next' => $tickets->nextPageUrl(),
            ],
        ]);
    }
    private function ticketStats(Builder $baseQuery): array
    {
        $totalTickets = (clone $baseQuery)->count();

        $statusRows = (clone $baseQuery)
            ->leftJoin('ticket_status as ts', 'ts.id', '=', 'tickets.status_id')
            ->select([
                'tickets.status_id',
                'ts.ticket_status_name',
                DB::raw('COUNT(tickets.id) as total'),
            ])
            ->groupBy('tickets.status_id', 'ts.ticket_status_name')
            ->get();

        $statuses = TicketStatus::query()
            ->select(['id', 'ticket_status_name'])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $statusCounts = [];

        foreach ($statuses as $status) {
            $countRow = $statusRows->firstWhere('status_id', $status->id);

            $key = Str::slug((string) $status->ticket_status_name, '_');

            $statusCounts[$key] = [
                'id' => (int) $status->id,
                'name' => $status->ticket_status_name,
                'count' => (int) ($countRow->total ?? 0),
            ];
        }

        $withoutStatusCount = $statusRows
            ->whereNull('status_id')
            ->sum('total');

        return [
            'total_tickets' => (int) $totalTickets,
            'status_counts' => $statusCounts,
            'without_status' => (int) $withoutStatusCount,
        ];
    }

    private function validationResponse($errors): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $errors,
        ], 422);
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'You are not allowed to access this ticket.',
        ], 403);
    }

    private function ticketNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Ticket not found.',
        ], 404);
    }
}
