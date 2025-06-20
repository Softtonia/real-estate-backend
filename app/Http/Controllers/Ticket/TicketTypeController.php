<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TicketType;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TicketTypeController extends Controller
{

    public function index()
    {
        $TicketType = TicketType::with('media')->get();

        return $TicketType;
    }

    public function store(Request $request)
    {
        try {

            // if ($request->header('api-token') == '') {
            //     return response()->json(['error' => 'Please enter api token first.'], 422);
            // }

            // $requestToken = $request->header('api-token');
            // $expectedToken = config('constants.API_TOKEN');

            // if ($requestToken !== $expectedToken) {
            //     return response()->json(['error' => 'Unauthorized. Invalid api token.'], 401);
            // }

            $request->validate([
                'ticket_type_name' => 'required|string|unique:ticket_types,ticket_type_name',
                'icon_id' => 'required',
                'display_order' => 'required|unique:ticket_types',
            ]);


            $checkMedia = Media::where('id', $request->icon_id)->first();

            if (!$checkMedia) {
                return response()->json(['error' => 'Invalid Icon Id.'], 401);
            }

            $ticketType = TicketType::create($request->all());
            $id = $ticketType->id;
            $ticketType = TicketType::where('id', $id)->first();
            return response()->json([
                'id' => $ticketType->id,
                'ticket_type_name' => $ticketType->ticket_type_name,
                'display_order' => $ticketType->display_order,
                'icon_id' => $ticketType->icon_id,
                'created_at' => $ticketType->created_at,
                'updated_at' => $ticketType->updated_at,
            ], 201);

            // return response()->json($response, 201); // 201 Created status code
        } catch (\Illuminate\Database\QueryException $e) {
            $errorCode = $e->errorInfo[1];

            if ($errorCode == 1062) { // MySQL error code for duplicate entry
                return response()->json(['message' => 'The provided ticket type name is already taken.'], 422);
            }

            // Handle other database-related errors here if needed
            return response()->json(['message' => 'Failed to store ticket type.'], 500);
        }
    }


    public function update(Request $request)
    {
        // if ($request->header('api-token') == '') {
        //     return response()->json(['error' => 'Please enter api token first.'], 422);
        // }

        // $requestToken = $request->header('api-token');
        // $expectedToken = config('constants.API_TOKEN');

        // if ($requestToken !== $expectedToken) {
        //     return response()->json(['error' => 'Unauthorized. Invalid api token.'], 401);
        // }

        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:ticket_types,id',
            'ticket_type_name' => 'required|unique:ticket_types,ticket_type_name,' . $request->id,
            'display_order' => 'required|unique:ticket_types,display_order,' . $request->id . ',id',
            'icon_id' => 'required|integer|exists:media,id',
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $checkMedia = Media::find($request->icon_id);

        if (!$checkMedia) {
            return response()->json(['error' => 'Invalid Icon Id.'], 401);
        }

        $ticketType = TicketType::find($request->id);

        if (!$ticketType) {
            return response()->json(['error' => 'Invalid Ticket Type ID'], 404);
        }

        $ticketType->update([
            'ticket_type_name' => $request->ticket_type_name,
            'icon_id' => $request->icon_id,
            'display_order' => $request->display_order,
        ]);

        return $ticketType;
    }


    public function destroy(Request $request)
    {
        // if ($request->header('api-token') == '') {
        //     return response()->json(['error' => 'Please enter api token first.'], 422);
        // }

        // $requestToken = $request->header('api-token');
        // $expectedToken = config('constants.API_TOKEN');

        // if ($requestToken !== $expectedToken) {
        //     return response()->json(['error' => 'Unauthorized. Invalid api token.'], 401);
        // }

        // Validate incoming request data
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:ticket_types,id',
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            // Find the ticket status based on id
            $ticketype = TicketType::findOrFail($request->id);

            if (!$ticketype) {
                return response()->json(['error' => 'Invalid Ticket Type Id'], 404);
            }

            // Delete the ticket status
            $ticketype->delete();

            return response()->json(['message' => 'Ticket type deleted successfully'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Ticket type not found'], 404);
        }
    }

    public function show(Request $request)
    {

        try {

            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:ticket_types,id',
            ]);

            // Check if validation fails
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $TicketType = TicketType::where('id', $request->id)->with('media')->get();

            return $TicketType;

        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'The provided ticket type id is invalid.'], 422);
        }
    }


    // bulk delete

    public function bulkDelete(Request $request)
    {
        //  Validate input
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:ticket_types,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $ids = $request->input('ids');

        DB::beginTransaction();
        try {
            //  Model-level delete
            $deletedCount = TicketType::whereIn('id', $ids)->delete();

            DB::commit();

            return response()->json([
                'message' => 'Ticket types deleted successfully.',
                'deletedCount' => $deletedCount,
                'deletedIds' => $ids,
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'error' => 'Something went wrong while deleting ticket types.',
            ], 500);
        }
    }

    // search by ticket_type_name

    public function searchTicketType(Request $request)
    {
        $search = $request->input('search');

        if (!$search) {
            return response()->json(['error' => 'Search term is required.'], 422);
        }

        $results = TicketType::with('media')
            ->where('ticket_type_name', 'like', '%' . $search . '%')
            ->orderBy('display_order', 'asc')
            ->paginate(10);

        return response()->json([
            'count' => $results->count(),
            'data' => $results
        ]);
    }

}
