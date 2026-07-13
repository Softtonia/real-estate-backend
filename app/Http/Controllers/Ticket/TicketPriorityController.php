<?php

namespace App\Http\Controllers\Ticket;

use App\Models\TicketPriority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketPriorityController extends AbstractTicketLookupController
{
    protected function modelClass(): string
    {
        return TicketPriority::class;
    }

    protected function nameColumn(): string
    {
        return 'ticket_priority';
    }

    protected function ticketForeignKey(): string
    {
        return 'priority_id';
    }

    public function searchTicketPriority(Request $request): JsonResponse
    {
        return $this->search($request);
    }
}
