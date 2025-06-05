<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TicketStatus;
use App\Models\Media;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ticketstatuscontroller extends Controller
{
    public function index()
    {
        $TicketStatus = TicketStatus::with('media')->get();

        return $TicketStatus;
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'ticket_status_name' => 'required|string|unique:ticket_status,ticket_status_name',
                'icon_id' => 'required',
                'display_order' => 'required|unique:ticket_status',
            ]);


            $checkMedia = Media::where('id', $request->icon_id)->first();

            if (!$checkMedia) {
                return response()->json(['error' => 'Invalid Icon Id.'], 401);
            }

            $ticketStatus = TicketStatus::create($request->all());
            $id = $ticketStatus->id;
            $ticketStatus = TicketStatus::where('id', $id)->first();
            return response()->json([
                'id' => $ticketStatus->id,
                'ticket_status_name' => $ticketStatus->ticket_status_name,
                'display_order' => $ticketStatus->display_order,
                'icon_id' => $ticketStatus->icon_id,
                'created_at' => $ticketStatus->created_at,
                'updated_at' => $ticketStatus->updated_at,
            ], 201);

            // return response()->json($response, 201); // 201 Created status code
        } catch (\Illuminate\Database\QueryException $e) {
            $errorCode = $e->errorInfo[1];

            if ($errorCode == 1062) { // MySQL error code for duplicate entry
                return response()->json(['message' => 'The provided ticket status name is already taken.'], 422);
            }

            // Handle other database-related errors here if needed
            return response()->json(['message' => 'Failed to store ticket status.'], 500);
        }
    }


    public function update(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:ticket_status,id',
            'ticket_status_name' => 'required|unique:ticket_status,ticket_status_name,' . $request->id,
            'icon_id' => 'required|integer|exists:media,id',
            'display_order' => 'required|unique:ticket_status,display_order,' . $request->id . ',id',
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $checkMedia = Media::find($request->icon_id);

        if (!$checkMedia) {
            return response()->json(['error' => 'Invalid Icon Id.'], 401);
        }

        $ticketStatus = TicketStatus::find($request->id);

        if (!$ticketStatus) {
            return response()->json(['error' => 'Invalid Ticket ID'], 404);
        }

        $ticketStatus->update([
            'ticket_status_name' => $request->ticket_status_name,
            'icon_id' => $request->icon_id,
            'display_order' => $request->display_order,
        ]);

        return $ticketStatus;
    }



    public function destroy(Request $request)
    {
        // Validate incoming request data
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:ticket_status,id',
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            // Find the ticket status based on id
            $ticketStatus = TicketStatus::findOrFail($request->id);

            if (!$ticketStatus) {
                return response()->json(['error' => 'Invalid Ticket status Id'], 404);
            }

            // Delete the ticket status
            $ticketStatus->delete();

            return response()->json(['message' => 'Ticket status deleted successfully'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Ticket status not found'], 404);
        }
    }

    public function show(Request $request)
    {

        try {

            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:ticket_status,id',
            ]);

            // Check if validation fails
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $TicketStatus = TicketStatus::where('id', $request->id)->with('media')->get();

            return $TicketStatus;

        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'The provided ticket status id is invalid.'], 422);
        }
    }


    // Bulk Delete
    public function bulkDelete(Request $request)
    {


        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed.',
                'details' => $validator->errors(),
            ], 422);
        }

        $ids = $request->input('ids');


        try {
            // Existing IDs
            $existingIds = TicketStatus::whereIn('id', $ids)->pluck('id')->toArray();

            if (empty($existingIds)) {
                return response()->json([
                    'error' => 'No matching Ticket Status records found for given IDs.'
                ], 404);
            }

            $notFoundIds = array_values(array_diff($ids, $existingIds));

            $deletedCount = TicketStatus::whereIn('id', $existingIds)->delete();

            return response()->json([
                'message' => 'Ticket Status deleted successfully.',
                'requested_ids' => $ids,
                'deleted_count' => $deletedCount,
                'not_found_ids' => $notFoundIds,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'Server error.',
                'details' => $th->getMessage(),
            ], 500);
        }
    }

    public function searchTicketStatusName(Request $request)
    {
        try {
            $searchTerm = $request->input('search');

            $query = TicketStatus::query();

            if (!empty($searchTerm)) {
                $query->where('ticket_status_name', 'like', '%' . $searchTerm . '%');
            }

            $results = $query->orderBy('display_order', 'asc')->paginate(10); // 10 results per page

            return response()->json([
                'status' => true,
                'message' => 'Ticket status fetched successfully.',
                'data' => $results
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Server error.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

}
