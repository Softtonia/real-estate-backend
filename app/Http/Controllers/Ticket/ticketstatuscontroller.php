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
        
        
        $checkMedia = Media::where('id',$request->icon_id)->first();
        
        if (!$checkMedia) {
        return response()->json(['error' => 'Invalid Icon Id.'], 401);
        }
        
        $ticketStatus = TicketStatus::create($request->all());
        $id = $ticketStatus->id;
        $ticketStatus = TicketStatus::where('id',$id)->first();
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

            $TicketStatus = TicketStatus::where('id',$request->id)->with('media')->get();
            
            return $TicketStatus;

        }catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message'=>'The provided ticket status id is invalid.'], 422);
        }
    }
    
}