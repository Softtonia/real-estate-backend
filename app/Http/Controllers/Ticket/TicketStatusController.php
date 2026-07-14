<?php

namespace App\Http\Controllers\Ticket;

use App\Models\TicketStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketStatusController extends BaseTicketLookupController
{
    protected string $modelClass = TicketStatus::class;
    protected string $table = 'ticket_status';
    protected string $nameColumn = 'ticket_status_name';
    protected string $resourceName = 'ticket status';
    protected string $ticketForeignKey = 'status_id';

    public function searchTicketStatusName(Request $request): JsonResponse
    {
        return $this->search($request);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        return $this->bulkDestroy($request);
    }
}
