<?php

namespace App\Http\Controllers\Ticket;

use App\Models\TicketDepartment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketDepartmentController extends AbstractTicketLookupController
{
    protected function modelClass(): string
    {
        return TicketDepartment::class;
    }

    protected function nameColumn(): string
    {
        return 'ticket_department_name';
    }

    protected function ticketForeignKey(): string
    {
        return 'ticket_department_id';
    }

    protected function iconRequired(): bool
    {
        return false;
    }

    public function searchDepartment(Request $request): JsonResponse
    {
        return $this->search($request);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        return $this->bulkDelete($request);
    }
}
