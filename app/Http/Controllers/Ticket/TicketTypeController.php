<?php

namespace App\Http\Controllers\Ticket;

use App\Models\TicketType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketTypeController extends AbstractTicketLookupController
{
    protected function modelClass(): string
    {
        return TicketType::class;
    }

    protected function nameColumn(): string
    {
        return 'ticket_type_name';
    }

    protected function ticketForeignKey(): string
    {
        return 'ticket_type_id';
    }

    protected function extraRules(bool $updating): array
    {
        return [
            'status' => $updating
                ? 'sometimes|boolean'
                : 'nullable|boolean',
        ];
    }

    protected function defaultExtraData(): array
    {
        return [
            'status' => true,
        ];
    }

    public function searchTicketType(Request $request): JsonResponse
    {
        return $this->search($request);
    }
}
