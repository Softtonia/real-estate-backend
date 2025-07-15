<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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
            ->join('ticket_departments', 'tickets.ticket_department_id', '=', 'ticket_departments.id')
            ->select(
                'raised_users.id as raised_by_id',
                'raised_users.first_name as raised_user_name',
                'assigned_users.id as assigned_to_id',
                'assigned_users.first_name as assigned_user_fullname',
                'tickets.*',
                'ticket_priorities.ticket_priority',
                'ticket_status.ticket_status_name',
                'ticket_types.ticket_type_name',
                'ticket_departments.ticket_department_name'
            )
            ->get();


        // Check if no tickets found
        if ($tickets->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found',
                'data' => []
            ], 200);
        }




        $formattedTickets = [];

        // Loop through each ticket
        foreach ($tickets as $ticket) {

            $mediaUrl = $ticket->media_attachment
                ? url('attachments/' . $ticket->media_attachment)
                : null;
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
                'ticket_department_id' => $ticket->ticket_department_id,
                'ticket_department_name' => $ticket->ticket_department_name,
                'media_attachment' => $ticket->media_attachment,
                'media_attachment_url' => $mediaUrl,
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
            'ticket_department_id' => 'required|exists:ticket_departments,id',
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

        // if ($request->hasFile('media_attachment')) {
        //     $file = $request->file('media_attachment');
        //     $extension = $file->getClientOriginalExtension();
        //     $fileName = time() . '.' . $extension;
        //     $file->storeAs('attachments', $fileName);
        //     $data['media_attachment'] = $fileName;
        // }

        // Store directly inside public/attachments/
        if ($request->hasFile('media_attachment')) {
            $file = $request->file('media_attachment');
            $extension = $file->getClientOriginalExtension();
            $fileName = time() . '.' . $extension;
            $destinationPath = public_path('attachments');

            // Ensure the directory exists
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $file->move($destinationPath, $fileName);
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
            ->join('ticket_departments', 'tickets.ticket_department_id', '=', 'ticket_departments.id')
            ->select(
                'raised_users.id as raised_by_id',
                'raised_users.first_name as raised_user_name',
                'assigned_users.id as assigned_to_id',
                'assigned_users.first_name as assigned_user_fullname',
                'tickets.*',
                'ticket_priorities.ticket_priority',
                'ticket_status.ticket_status_name',
                'ticket_types.ticket_type_name',
                'ticket_departments.ticket_department_name'
            )->where('tickets.id', $id)->get()->first();
        // ->first();

        if (!$ticket) {
            return response()->json(['error' => 'Ticket not found'], 200);
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
            'ticket_department_id' => $ticket->ticket_department_id,
            'ticket_department_name' => $ticket->ticket_department_name,
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
            'ticket_department_id' => 'nullable|exists:ticket_departments,id',
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
        // if ($request->hasFile('media_attachment')) {
        //     $file = $request->file('media_attachment');
        //     $extension = $file->getClientOriginalExtension();
        //     $fileName = time() . '.' . $extension;
        //     $file->storeAs('attachments', $fileName);
        //     $ticket->media_attachment = $fileName;
        // }


        if ($request->hasFile('media_attachment')) {
            $file = $request->file('media_attachment');
            $extension = $file->getClientOriginalExtension();
            $fileName = time() . '.' . $extension;
            $destinationPath = public_path('attachments');

            // Create directory if it doesn't exist
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            // Move the new file
            $file->move($destinationPath, $fileName);

            // Delete old file if exists
            if ($ticket->media_attachment && file_exists($destinationPath . '/' . $ticket->media_attachment)) {
                unlink($destinationPath . '/' . $ticket->media_attachment);
            }

            // Save new file name
            $ticket->media_attachment = $fileName;
        }




        // Save the updated ticket
        $ticket->save();

        // Customize the response
        $response = $ticket;

        return response()->json($response, 200); // 200 OK status code
    }


    // public function destroy(Request $request, Ticket $ticket)
    // {
    //     $user = $request->user();                     // Authenticated user
    //     $isAdmin = $user->role && strcasecmp($user->role->name, 'admin') === 0;


    //     if (
    //         !$isAdmin &&
    //         $ticket->user_id !== $user->id &&
    //         $ticket->raised_by !== $user->id
    //     ) {

    //         return response()->json([
    //             'error' => 'You are only allowed to delete your own tickets.'
    //         ], 403);
    //     }

    //     $ticket->delete();

    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Ticket deleted successfully'
    //     ], 200);
    // }

    ## new delete code by id 15-07-2025 ##

    public function destroy(Request $request)
    {
        $user = $request->user(); // Authenticated user
        $isAdmin = $user->role && strcasecmp($user->role->name, 'admin') === 0;

        // Validate ticket_id
        $request->validate([
            'id' => 'required|integer|exists:tickets,id'
        ]);

        // Find the ticket
        $ticket = Ticket::find($request->id);

        if (!$ticket) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found.'
            ], 200);
        }

        // Authorization check
        if (
            !$isAdmin &&
            $ticket->user_id !== $user->id &&
            $ticket->raised_by !== $user->id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'You are only allowed to delete your own tickets.'
            ], 403);
        }

        // Delete the ticket
        $ticket->delete(); // Use forceDelete() if SoftDeletes is used and you want permanent deletion

        return response()->json([
            'status' => true,
            'message' => 'Ticket deleted successfully.'
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
            ['ticket_id' => 'required|exists:tickets,id'],
            ['ticket_id.exists' => 'Ticket not found']
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

        if ($responses->isEmpty()) {
            return response()->json([
                'error' => 'No responses found for this ticket.'
            ], 200);
        }

        // Return responses as JSON
        return response()->json(['responses' => $responses], 200);
    }



    public function updateTicketStatus(Request $request)
    {

        $authUser = $request->user();


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
        $isAdmin = $authUser->role && strcasecmp($authUser->role->name, 'admin') === 0;


        if (
            !$isAdmin &&
            $ticket->user_id !== $authUser->id &&
            $ticket->raised_by !== $authUser->id
        ) {

            return response()->json([
                'error' => 'You are only allowed to delete your own tickets.'
            ], 403);
        }
        $ticket->status_id = $request->status_id;
        $ticket->update();

        // Customize the response
        $response = ['status' => true, 'message' => 'Status updated successfully.'];

        return response()->json($response, 200);
    }


    // get tickect by user token


    public function getTicketByToken(Request $request)
    {
        $user = $request->user(); // Get user from token

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $userId = $user->id;

        $tickets = DB::table('tickets')
            ->join('users as raised_users', 'tickets.raised_by', '=', 'raised_users.id')
            ->join('users as assigned_users', 'tickets.user_id', '=', 'assigned_users.id')
            ->join('ticket_priorities', 'tickets.priority_id', '=', 'ticket_priorities.id')
            ->join('ticket_status', 'tickets.status_id', '=', 'ticket_status.id')
            ->join('ticket_types', 'tickets.ticket_type_id', '=', 'ticket_types.id')
            ->join('ticket_departments', 'tickets.ticket_department_id', '=', 'ticket_departments.id')
            ->select(
                'raised_users.id as raised_by_id',
                DB::raw("CONCAT_WS(' ', raised_users.first_name, raised_users.last_name) as raised_user_name"),
                'assigned_users.id as assigned_to_id',
                DB::raw("CONCAT_WS(' ', assigned_users.first_name, assigned_users.last_name) as assigned_user_fullname"),
                'tickets.*',
                'ticket_priorities.ticket_priority',
                'ticket_status.ticket_status_name',
                'ticket_types.ticket_type_name',
                'ticket_departments.ticket_department_name'
            )
            ->where('tickets.user_id', $userId)
            ->get();

        if ($tickets->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No tickets found for this user',
                'data' => []
            ], 200);
        }

        $formattedTickets = [];

        foreach ($tickets as $ticket) {
            $formattedTickets[] = [
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
                'ticket_department_id' => $ticket->ticket_department_id,
                'ticket_department_name' => $ticket->ticket_department_name,
                'media_attachment' => $ticket->media_attachment,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->updated_at
            ];
        }

        return response()->json([
            'status' => true,
            'message' => 'Tickets fetched successfully',
            'data' => $formattedTickets
        ], 200);
    }


    // ticket response history

    public function ticketResponseHistory1($ticketId)
    {
        // Step 1: Fetch ticket details (main ticket information)
        $ticket = DB::table('tickets')
            ->join('users as raised_users', 'tickets.raised_by', '=', 'raised_users.id')
            ->join('users as assigned_users', 'tickets.user_id', '=', 'assigned_users.id')
            ->where('tickets.id', $ticketId)
            ->select(
                'tickets.id',
                'tickets.ticket_number',
                'tickets.subject',
                'tickets.message',
                'tickets.status_id',
                'tickets.priority_id',
                'tickets.media_attachment',
                'tickets.ticket_type_id',
                'tickets.ticket_department_id',
                'tickets.created_at',
                'tickets.updated_at',
                DB::raw("CONCAT_WS(' ', raised_users.first_name, raised_users.last_name) as raised_by_name"),
                DB::raw("CONCAT_WS(' ', assigned_users.first_name, assigned_users.last_name) as assigned_user_name")
            )
            ->first();

        if (!$ticket) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found',
                'data' => []
            ], 200);
        }

        // Step 2: Fetch responses for this ticket (history)
        $responses = DB::table('tickets_response')
            ->join('users', 'tickets_response.user_id', '=', 'users.id')
            ->where('tickets_response.ticket_id', $ticketId)
            ->select(
                'tickets_response.id',
                'tickets_response.message',
                'tickets_response.message_by',
                'tickets_response.created_at',
                // 'tickets_response.media_attachment',
                DB::raw("CONCAT_WS(' ', users.first_name, users.last_name) as user_name")
            )
            ->orderBy('tickets_response.created_at', 'asc')
            ->get();

        // Step 3: Format the data into chat history
        $chatHistory = [];

        // Initial message (ticket's main message)
        $chatHistory[] = [
            'message' => $ticket->message,
            'media_attachment' => $ticket->media_attachment,
            'user_name' => $ticket->raised_by_name,
            'message_by' => 'raised_by',
            'created_at' => $ticket->created_at,
        ];

        // Responses (user/admin replies)
        foreach ($responses as $response) {
            $chatHistory[] = [
                'message' => $response->message,
                'media_attachment' => $response->media_attachment ?? null,
                'user_name' => $response->user_name,
                'message_by' => $response->message_by,
                'created_at' => $response->created_at,
            ];
        }

        // Step 4: Return the final response with ticket and chat history
        return response()->json([
            'status' => true,
            'message' => 'Ticket response history fetched successfully',
            'data' => [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'status_id' => $ticket->status_id,
                'priority_id' => $ticket->priority_id,
                'ticket_type_id' => $ticket->ticket_type_id,
                'ticket_department_id' => $ticket->ticket_department_id,
                'media_attachment' => $ticket->media_attachment,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->updated_at,
                'raised_by_name' => $ticket->raised_by_name,
                'assigned_user_name' => $ticket->assigned_user_name,
                'chat_history' => $chatHistory
            ]
        ], 200);
    }

    //  public function ticketResponseHistory($ticketId)
// {
//     //  Fetch ticket details (main ticket information)
//     $ticket = DB::table('tickets')
//         ->join('users as raised_users', 'tickets.raised_by', '=', 'raised_users.id')
//         ->join('users as assigned_users', 'tickets.user_id', '=', 'assigned_users.id')
//         ->where('tickets.id', $ticketId)
//         ->select(
//             'tickets.id',
//             'tickets.ticket_number',
//             'tickets.subject',
//             'tickets.message',
//             'tickets.status_id',
//             'tickets.priority_id',
//             'tickets.media_attachment',
//             'tickets.ticket_type_id',
//             'tickets.ticket_department_id',
//             'tickets.created_at',
//             'tickets.updated_at',
//             DB::raw("CONCAT_WS(' ', raised_users.first_name, raised_users.last_name) as raised_by_name"),
//             DB::raw("CONCAT_WS(' ', assigned_users.first_name, assigned_users.last_name) as assigned_user_name")
//         )
//         ->first();

    //     if (!$ticket) {
//         return response()->json([
//             'status' => false,
//             'message' => 'Ticket not found',
//             'data' => []
//         ], 200);
//     }

    //     // Step 2: Fetch responses for this ticket (history)
//     $responses = DB::table('tickets_response')
//         ->join('users', 'tickets_response.user_id', '=', 'users.id')
//         ->where('tickets_response.ticket_id', $ticketId)
//         ->select(
//             'tickets_response.id',
//             'tickets_response.message',
//             'tickets_response.message_by',
//             'tickets_response.created_at',
//             DB::raw("CONCAT_WS(' ', users.first_name, users.last_name) as user_name")
//         )
//         ->orderBy('tickets_response.created_at', 'asc')
//         ->get();

    //     // Step 3: Format the data into chat history
//     $chatHistory = [];

    //     // Initial message (ticket's main message)
//     $chatHistory[] = [
//         'message' => $ticket->message,
//         'media_attachment' => $ticket->media_attachment
//             ? config('app.url') . Storage::url('attachments/' . $ticket->media_attachment)
//             : null,
//         'user_name' => $ticket->raised_by_name,
//         'message_by' => 'raised_by',
//         'created_at' => $ticket->created_at,
//     ];

    //     // Responses (user/admin replies)
//     foreach ($responses as $response) {
//         $chatHistory[] = [
//             'message' => $response->message,
//             'media_attachment' => $response->media_attachment
//                 ? config('app.url') . Storage::url('attachments/' . $response->media_attachment)
//                 : null,
//             'user_name' => $response->user_name,
//             'message_by' => $response->message_by,
//             'created_at' => $response->created_at,
//         ];
//     }

    //     //  Return the final response with ticket and chat history
//     return response()->json([
//         'status' => true,
//         'message' => 'Ticket response history fetched successfully',
//         'data' => [
//             'ticket_id' => $ticket->id,
//             'ticket_number' => $ticket->ticket_number,
//             'subject' => $ticket->subject,
//             'status_id' => $ticket->status_id,
//             'priority_id' => $ticket->priority_id,
//             'ticket_type_id' => $ticket->ticket_type_id,
//             'ticket_department_id' => $ticket->ticket_department_id,
//             'media_attachment' => $ticket->media_attachment
//                 ? config('app.url') . Storage::url('attachments/' . $ticket->media_attachment)
//                 : null,
//             'created_at' => $ticket->created_at,
//             'updated_at' => $ticket->updated_at,
//             'raised_by_name' => $ticket->raised_by_name,
//             'assigned_user_name' => $ticket->assigned_user_name,
//             'chat_history' => $chatHistory
//         ]
//     ], 200);
// }



    // use Illuminate\Support\Facades\Storage;

    public function ticketResponseHistory($ticketId)
    {
        // Fetch ticket details (main ticket information)
        $ticket = DB::table('tickets')
            ->join('users as raised_users', 'tickets.raised_by', '=', 'raised_users.id')
            ->join('users as assigned_users', 'tickets.user_id', '=', 'assigned_users.id')
            ->where('tickets.id', $ticketId)
            ->select(
                'tickets.id',
                'tickets.ticket_number',
                'tickets.subject',
                'tickets.message',
                'tickets.status_id',
                'tickets.priority_id',
                'tickets.media_attachment',
                'tickets.ticket_type_id',
                'tickets.ticket_department_id',
                'tickets.created_at',
                'tickets.updated_at',
                DB::raw("CONCAT_WS(' ', raised_users.first_name, raised_users.last_name) as raised_by_name"),
                DB::raw("CONCAT_WS(' ', assigned_users.first_name, assigned_users.last_name) as assigned_user_name")
            )
            ->first();

        if (!$ticket) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found',
                'data' => []
            ], 200);
        }

        // Fetch ticket responses
        $responses = DB::table('tickets_response')
            ->join('users', 'tickets_response.user_id', '=', 'users.id')
            ->where('tickets_response.ticket_id', $ticketId)
            ->select(
                'tickets_response.id',
                'tickets_response.message',
                'tickets_response.message_by',
                'tickets_response.created_at',
                DB::raw("CONCAT_WS(' ', users.first_name, users.last_name) as user_name")
            )
            ->orderBy('tickets_response.created_at', 'asc')
            ->get();

        // Build media URL if file exists in public/attachments
        $ticketMediaUrl = null;
        if (!empty($ticket->media_attachment)) {
            $attachmentPath = public_path('attachments/' . $ticket->media_attachment);

            if (file_exists($attachmentPath)) {
                $ticketMediaUrl = config('app.url') . '/attachments/' . $ticket->media_attachment;
            }
        }

        // Build chat history
        $chatHistory = [];

        // Add the original ticket message
        $chatHistory[] = [
            'message' => $ticket->message,
            'media_attachment' => $ticketMediaUrl,
            'user_name' => $ticket->raised_by_name,
            'message_by' => 'raised_by',
            'created_at' => $ticket->created_at,
        ];

        // Add all responses
        foreach ($responses as $response) {
            $chatHistory[] = [
                'message' => $response->message,
                'media_attachment' => null, // No attachment in responses
                'user_name' => $response->user_name,
                'message_by' => $response->message_by,
                'created_at' => $response->created_at,
            ];
        }

        // Return final formatted data
        return response()->json([
            'status' => true,
            'message' => 'Ticket response history fetched successfully',
            'data' => [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'status_id' => $ticket->status_id,
                'priority_id' => $ticket->priority_id,
                'ticket_type_id' => $ticket->ticket_type_id,
                'ticket_department_id' => $ticket->ticket_department_id,
                'media_attachment' => $ticketMediaUrl,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->updated_at,
                'raised_by_name' => $ticket->raised_by_name,
                'assigned_user_name' => $ticket->assigned_user_name,
                'chat_history' => $chatHistory,
            ]
        ], 200);
    }


    ## Bulk Delete Tickets 15-07-2025 ##

    public function bulkDestroy(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->role && strcasecmp($user->role->name, 'admin') === 0;

        // Validate input
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:tickets,id',
        ]);

        $ticketIds = $request->ids;
        $deleted = [];
        $skipped = [];

        foreach ($ticketIds as $ticketId) {
            $ticket = Ticket::find($ticketId);

            if (!$ticket) {
                $skipped[] = ['id' => $ticketId, 'reason' => 'Not found'];
                continue;
            }

            if (
                !$isAdmin &&
                $ticket->user_id !== $user->id &&
                $ticket->raised_by !== $user->id
            ) {
                $skipped[] = ['id' => $ticketId, 'reason' => 'Unauthorized'];
                continue;
            }

            $ticket->delete(); // or $ticket->forceDelete() if using SoftDeletes
            $deleted[] = $ticketId;
        }

        return response()->json([
            'status' => true,
            'message' => 'Bulk delete completed.',
            'deleted_ids' => $deleted,
            'skipped' => $skipped,
        ], 200);
    }


    ## Search By Ticket Number 15-07-2025 ##

    public function searchByTicketNumber(Request $request)
    {
        $request->validate([
            'ticket_number' => 'required|string'
        ]);

        $ticketNumber = $request->ticket_number;

        // Fetch the ticket by ticket number with all related details
        $ticket = DB::table('tickets')
            ->join('users as raised_users', 'tickets.raised_by', '=', 'raised_users.id')
            ->join('users as assigned_users', 'tickets.user_id', '=', 'assigned_users.id')
            ->join('ticket_priorities', 'tickets.priority_id', '=', 'ticket_priorities.id')
            ->join('ticket_status', 'tickets.status_id', '=', 'ticket_status.id')
            ->join('ticket_types', 'tickets.ticket_type_id', '=', 'ticket_types.id')
            ->join('ticket_departments', 'tickets.ticket_department_id', '=', 'ticket_departments.id')
            ->select(
                'raised_users.id as raised_by_id',
                'raised_users.first_name as raised_user_name',
                'assigned_users.id as assigned_to_id',
                'assigned_users.first_name as assigned_user_fullname',
                'tickets.*',
                'ticket_priorities.ticket_priority',
                'ticket_status.ticket_status_name',
                'ticket_types.ticket_type_name',
                'ticket_departments.ticket_department_name'
            )
            ->where('tickets.ticket_number', $ticketNumber)
            ->first();

        // Ticket not found
        if (!$ticket) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found',
                'data' => null
            ], 200);
        }

        $mediaUrl = $ticket->media_attachment
            ? url('attachments/' . $ticket->media_attachment)
            : null;

        // Format the result
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
            'ticket_department_id' => $ticket->ticket_department_id,
            'ticket_department_name' => $ticket->ticket_department_name,
            'media_attachment' => $ticket->media_attachment,
            'media_attachment_url' => $mediaUrl,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at
        ];

        return response()->json([
            'status' => true,
            'message' => 'Ticket found',
            'data' => $formattedTicket
        ], 200);
    }





}
