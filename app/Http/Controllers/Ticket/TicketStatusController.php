<?php

namespace App\Http\Controllers\Ticket;

use App\Models\TicketStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketStatusController extends AbstractTicketLookupController
{
    protected function modelClass(): string
    {
        return TicketStatus::class;
    }

    protected function nameColumn(): string
    {
        return 'ticket_status_name';
    }

    protected function ticketForeignKey(): string
    {
        return 'status_id';
    }

    public function searchTicketStatusName(Request $request): JsonResponse
    {
        return $this->search($request);
    }
}
