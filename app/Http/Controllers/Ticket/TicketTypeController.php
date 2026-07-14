<?php

namespace App\Http\Controllers\Ticket;

use App\Models\TicketType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketTypeController extends BaseTicketLookupController
{
    protected string $modelClass = TicketType::class;
    protected string $table = 'ticket_types';
    protected string $nameColumn = 'ticket_type_name';
    protected string $resourceName = 'ticket type';
    protected string $ticketForeignKey = 'ticket_type_id';
    protected bool $supportsStatus = true;

    public function searchTicketType(Request $request): JsonResponse
    {
        return $this->search($request);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        return $this->bulkDestroy($request);
    }
}
