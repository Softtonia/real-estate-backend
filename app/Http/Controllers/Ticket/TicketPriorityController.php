<?php

namespace App\Http\Controllers\Ticket;

use App\Models\TicketPriority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketPriorityController extends BaseTicketLookupController
{
    protected string $modelClass = TicketPriority::class;
    protected string $table = 'ticket_priorities';
    protected string $nameColumn = 'ticket_priority';
    protected string $resourceName = 'ticket priority';
    protected string $ticketForeignKey = 'priority_id';

    public function searchTicketPriority(Request $request): JsonResponse
    {
        return $this->search($request);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        return $this->bulkDestroy($request);
    }
}
