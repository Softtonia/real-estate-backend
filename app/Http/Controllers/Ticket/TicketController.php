<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use DB;
use App\Models\TicketStatus;
use App\Models\Property;
use App\Models\Response;
class TicketController extends Controller
{


    public function index()
    {
        // Fetch all tickets with raised_by user details, assigned_to user details, and priority name
        $tickets = DB::table('tickets')
            ->join('users as raised_users', 'tickets.raised_by', '=', 'raised_users.id')
            ->join('users as assigned_users', 'tickets.user_id', '=', 'assigned_users.id')
            ->join('ticket_priorities', 'tickets.priority_id', '=', 'ticket_priorities.id')
            ->join('ticket_status', 'tickets.status_id', '=', 'ticket_status.id')
            ->join('ticket_types', 'tickets.ticket_type_id', '=', 'ticket_types.id')
            ->select(
                'raised_users.id as raised_by_id',
                // 'raised_users.fullname as raised_user_name',
                DB::raw("CONCAT_WS(' ', raised_users.first_name, raised_users.last_name)  as raised_user_name"),
                'assigned_users.id as assigned_to_id',
                // 'assigned_users.fullname as assigned_user_fullname',

                DB::raw("CONCAT_WS(' ', assigned_users.first_name, assigned_users.last_name) as assigned_user_fullname"),
                'tickets.*',
                'ticket_priorities.ticket_priority',
                'ticket_status.ticket_status_name',
                'ticket_types.ticket_type_name'
            )
            ->get();

        $formattedTickets = [];

        // Loop through each ticket
        foreach ($tickets as $ticket) {

            // Create an array to hold formatted ticket data
            $formattedTicket = [
                'id' => $ticket->id,
                'raised_by' => $ticket->raised_by_id,
                'raised_by_name' => $ticket->raised_user_name,
                'user_id' => $ticket->assigned_to_id,
                'user_name' => $ticket->assigned_user_fullname,
                'status_id' => $ticket->status_id,
                'status_name' => $ticket->ticket_status_name,
                'priority_id' => $ticket->priority_id,
                'priority_name' => $ticket->ticket_priority,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'message' => $ticket->message,
                'ticket_type_id' => $ticket->ticket_type_id,
                'ticket_type_name' => $ticket->ticket_type_name,
                'media_attachment' => $ticket->media_attachment,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->updated_at
            ];


            // Add the formatted ticket to the array of formatted tickets
            $formattedTickets[] = $formattedTicket;
        }

        // Return the formatted tickets as JSON response
        return response()->json($formattedTickets, 200);
    }



    public function store(Request $request)
    {

        $authUser = $request->user();

        $validator = Validator::make($request->all(), [
            'raised_by' => 'required|exists:users,id',
            'user_id' => 'required|exists:users,id',
            'subject' => 'nullable|string',
            'message' => 'nullable|string',
            'ticket_type_id' => 'required|',
            'priority_id' => 'required|exists:ticket_priorities,id',
            'status_id' => 'nullable|exists:ticket_status,id',
            'media_attachment' => $request->hasFile('media_attachment') ? 'file|max:10240' : '',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Authorization check

        if (!$authUser->role || strtolower($authUser->role->name) !== 'admin') {
            if ($request->user_id != $authUser->id || $request->raised_by != $authUser->id) {
                return response()->json([
                    'error' => 'You can only create tickets for yourself'
                ], 403);
            }
        }


        // Generate random 4-digit number for ticket_number
        $ticketNumber = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Set default status_id to 'open'
        $data = $request->all();
        $data['ticket_number'] = $ticketNumber;

        if ($request->hasFile('media_attachment')) {
            $file = $request->file('media_attachment');
            $extension = $file->getClientOriginalExtension();
            $fileName = time() . '.' . $extension;
            $file->storeAs('attachments', $fileName);
            $data['media_attachment'] = $fileName;
        }


        // Create the ticket with appropriate ID field
        $ticket = Ticket::create($data);

        // Customize the response to include only specific fields
        $response = $ticket;

        return response()->json($response, 201); // 201 Created status code
    }



    public function show(Request $request)
    {
        // Fetch the ticket with raised_by user details
        $id = $request->id;
        $ticket = DB::table('tickets')
            ->join('users as raised_users', 'tickets.raised_by', '=', 'raised_users.id')
            ->join('users as assigned_users', 'tickets.user_id', '=', 'assigned_users.id')
            ->join('ticket_priorities', 'tickets.priority_id', '=', 'ticket_priorities.id')
            ->join('ticket_status', 'tickets.status_id', '=', 'ticket_status.id')
            ->join('ticket_types', 'tickets.ticket_type_id', '=', 'ticket_types.id')
            ->select(
                'raised_users.id as raised_by_id',
                DB::raw("CONCAT_WS(' ', raised_users.first_name, raised_users.last_name)  as raised_user_name"),
                'assigned_users.id as assigned_to_id',
                DB::raw("CONCAT_WS(' ', assigned_users.first_name, assigned_users.last_name) as assigned_user_fullname"),
                'tickets.*',
                'ticket_priorities.ticket_priority',
                'ticket_status.ticket_status_name',
                'ticket_types.ticket_type_name'
            )
            ->first();

        if (!$ticket) {
            return response()->json(['error' => 'Ticket not found'], 404);
        }

        $formattedTicket = [
            'id' => $ticket->id,
            'raised_by' => $ticket->raised_by_id,
            'raised_by_name' => $ticket->raised_user_name,
            'user_id' => $ticket->assigned_to_id,
            'user_name' => $ticket->assigned_user_fullname,
            'status_id' => $ticket->status_id,
            'status_name' => $ticket->ticket_status_name,
            'priority_id' => $ticket->priority_id,
            'priority_name' => $ticket->ticket_priority,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'message' => $ticket->message,
            'ticket_type_id' => $ticket->ticket_type_id,
            'ticket_type_name' => $ticket->ticket_type_name,
            'media_attachment' => $ticket->media_attachment,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at
        ];

        // Return the formatted ticket as JSON response
        return response()->json($formattedTicket, 200);
    }


    public function update(Request $request)
    {
        $authUser = $request->user();

        $validator = Validator::make($request->all(), [
            'raised_by' => 'exists:users,id',
            'user_id' => 'exists:users,id',
            'subject' => 'nullable|string',
            'message' => 'nullable|string',
            'ticket_type_id' => 'nullable',
            'priority_id' => 'exists:ticket_priorities,id',
            'status_id' => 'nullable|exists:ticket_status,id',
            'media_attachment' => $request->hasFile('media_attachment') ? 'file|max:10240' : '',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Authorization check

        if (!$authUser->role || strtolower($authUser->role->name) !== 'admin') {
            if ($request->user_id != $authUser->id || $request->raised_by != $authUser->id) {
                return response()->json([
                    'error' => 'You can only update tickets for yourself'
                ], 403);
            }
        }

        $id = $request->id;

        if (!Ticket::where('id', $id)->exists()) {
            return response()->json(['error' => 'Invalid Ticket Id'], 404);
        }

        $ticket = Ticket::findOrFail($id);

        // Update ticket data
        $ticket->fill($request->all());

        // If ticket_type_id is services, remove service_id from data
        if ($ticket->ticket_type_id === 'services') {
            $ticket->ticket_type_id = 'services';
        }

        // If ticket_type_id is property, ensure property_id exists
        if ($ticket->ticket_type_id === 'property' && !$ticket->property_id) {
            return response()->json(['errors' => ['property_id' => ['The property_id field is required.']]], 422);
        }

        // Handle media attachment
        if ($request->hasFile('media_attachment')) {
            $file = $request->file('media_attachment');
            $extension = $file->getClientOriginalExtension();
            $fileName = time() . '.' . $extension;
            $file->storeAs('attachments', $fileName);
            $ticket->media_attachment = $fileName;
        }

        // Save the updated ticket
        $ticket->save();

        // Customize the response
        $response = $ticket;

        return response()->json($response, 200); // 200 OK status code
    }


    public function destroy(Request $request, Ticket $ticket)
    {
        $user = $request->user();                     // Authenticated user
        $isAdmin = $user->role && strcasecmp($user->role->name, 'admin') === 0;


        if (
            !$isAdmin &&
            $ticket->user_id !== $user->id &&
            $ticket->raised_by !== $user->id
        ) {

            return response()->json([
                'error' => 'You are only allowed to delete your own tickets.'
            ], 403);
        }

        $ticket->delete();

        return response()->json([
            'status' => true,
            'message' => 'Ticket deleted successfully'
        ], 200);
    }


    public function respond(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|exists:tickets,id',
            'user_id' => 'required|exists:users,id',
            'message' => 'required|string',
            'message_by' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Check if a response already exists for this ticket
        if (!Ticket::where('id', $request->ticket_id)->exists()) {
            return response()->json(['error' => 'Invalid Ticket Id'], 422);
        }

        // Find the ticket by ID
        $ticket = Ticket::findOrFail($request->ticket_id);



        // Create a new response
        $response = new Response();
        $response->ticket_id = $ticket->id;
        $response->user_id = $request->user_id;
        $response->message = $request->input('message');
        $response->message_by = $request->input('message_by');
        $response->save();

        // Return a JSON response indicating success
        return response()->json(['message' => 'Response submitted successfully.', 'data' => $response]);
    }



    public function respondlist(Request $request)
    {
        // Validate request parameters
        $request->validate([
            'ticket_id' => 'required|exists:tickets,id',
        ]);

        // Retrieve responses for the specified ticket
        $ticket_id = $request->ticket_id;



        $responses = DB::table('tickets_response')
            ->where('ticket_id', $ticket_id)
            ->join('users', 'tickets_response.user_id', '=', 'users.id')
            ->join('tickets', 'tickets.id', '=', 'tickets_response.ticket_id')
           ->selectRaw("
            CONCAT_WS(' ', users.first_name, users.last_name)  AS user_name,
            tickets_response.*,
            tickets.subject       AS ticket_subject,
            tickets.message       AS ticket_message,
            tickets.ticket_type_id
        ")->get();

        // Return responses as JSON
        return response()->json(['responses' => $responses]);
    }



    public function updateTicketStatus(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|exists:tickets,id',
            'status_id' => 'required|exists:ticket_status,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Check if a response already exists for this ticket
        if (!Ticket::where('id', $request->ticket_id)->exists()) {
            return response()->json(['error' => 'Invalid Ticket Id'], 422);
        }

        $ticket = Ticket::findOrFail($request->ticket_id);
        $ticket->status_id = $request->status_id;
        $ticket->update();

        // Customize the response
        $response = ['status' => true, 'message' => 'Status updated successfully.'];

        return response()->json($response, 200);
    }




}
