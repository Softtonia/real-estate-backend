<?php

namespace App\Http\Controllers\Ticket;

use App\Models\TicketDepartment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketDepartmentController extends BaseTicketLookupController
{
    protected string $modelClass = TicketDepartment::class;
    protected string $table = 'ticket_departments';
    protected string $nameColumn = 'ticket_department_name';
    protected string $resourceName = 'department';
    protected string $ticketForeignKey = 'ticket_department_id';

    public function searchDepartment(Request $request): JsonResponse
    {
        return $this->search($request);
    }
}
