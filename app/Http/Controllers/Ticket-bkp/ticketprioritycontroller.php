<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\TicketPriority;
use App\Models\Media;
use Illuminate\Validation\Rule;
use Log;

class ticketprioritycontroller extends Controller
{

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // Default to 10 if not provided
        $query = TicketPriority::orderByRaw("FIELD(ticket_priority, 'low', 'medium', 'high')")->with('media');

        $ticketPriorities = $query->paginate($perPage);

        return response()->json([
            'data' => $ticketPriorities->items(),
            'meta' => [
                'current_page' => $ticketPriorities->currentPage(),
                'last_page' => $ticketPriorities->lastPage(),
                'per_page' => $ticketPriorities->perPage(),
                'total' => $ticketPriorities->total(),
            ],
            'links' => [
                'first' => $ticketPriorities->url(1),
                'last' => $ticketPriorities->url($ticketPriorities->lastPage()),
                'prev' => $ticketPriorities->previousPageUrl(),
                'next' => $ticketPriorities->nextPageUrl(),
            ],
        ],200);
    }


    /**
     * Store a newly created ticket priority in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */

    public function store(Request $request)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        $authorizationHeader = $request->header('Authorization');
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();
        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        // Validate the request data
        try {
            $validatedData = $request->validate([
                'ticket_priority' => 'required|string|max:255|unique:ticket_priorities',
                'icon_id' => 'required|integer|exists:media,id',
                'display_order' => 'nullable|integer|unique:ticket_priorities',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }

        // Determine `display_properties_order` if not provided
        $displayTicketPriorityOrder = $request->input('display_order');

        if ($displayTicketPriorityOrder === null) {
            // Find the greatest number in `display_properties_order`
            $maxOrder = DB::table('ticket_priorities')
                ->selectRaw('MAX(CAST(display_order AS UNSIGNED)) as max_order')
                ->value('max_order');

            // If no records exist, set to 1, else increment by 1
            $displayTicketPriorityOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

            // Ensure unique value (optional, can skip if `unique` validation suffices)
            while (TicketPriority::where('display_order', $displayTicketPriorityOrder)->exists()) {
                $displayTicketPriorityOrder++;
            }
        }

        // Check if the icon exists
        $checkMedia = Media::find($validatedData['icon_id']);
        if (!$checkMedia) {
            return response()->json(['error' => 'Invalid Icon Id.'], 401);
        }

        try {

            // Create the ticket priority
            $ticketPriority = TicketPriority::create([
                'ticket_priority' => (string) $validatedData['ticket_priority'],
                'icon_id' => $validatedData['icon_id'],
                'display_order' => $displayTicketPriorityOrder,
            ]);

            // Return a success response
            return response()->json([
                'id' => $ticketPriority->id,
                'ticket_priority' => $ticketPriority->ticket_priority,
                'display_order' => $ticketPriority->display_order,
                'icon_id' => $ticketPriority->icon_id,
                'created_at' => $ticketPriority->created_at,
                'updated_at' => $ticketPriority->updated_at,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Something went wrong. Please try again.'], 500);
        }
    }


    /**
     * Update the specified ticket priority in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */


    public function update(Request $request)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        $authorizationHeader = $request->header('Authorization');
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();
        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $id = $request->id;
        $ticketPriority = TicketPriority::find($id);

        if (!$ticketPriority) {
            return response()->json(['error' => 'Ticket priority not found'], 404);
        }

        // Validate the request data
        try {
            $validatedData = $request->validate([
                'id' => [
                    'required',
                    Rule::exists('ticket_priorities', 'id'),
                ],
                'ticket_priority' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'icon_id' => [
                    'required',
                    'integer',
                    Rule::exists('media', 'id'),
                ],
                'display_order' => [
                    'nullable',
                    'integer',
                    Rule::unique('ticket_priorities', 'display_order')->ignore($id),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
        // Determine `display_properties_order` if not provided
        $displayTicketPriorityOrder = $request->input('display_order');

        if ($displayTicketPriorityOrder === null) {
            // Find the greatest number in `display_properties_order`
            $maxOrder = DB::table('ticket_priorities')
                ->selectRaw('MAX(CAST(display_order AS UNSIGNED)) as max_order')
                ->value('max_order');

            // If no records exist, set to 1, else increment by 1
            $displayTicketPriorityOrder = ($maxOrder !== null) ? $maxOrder + 1 : 1;

            // Ensure unique value (optional, can skip if `unique` validation suffices)
            while (TicketPriority::where('display_order', $displayTicketPriorityOrder)->exists()) {
                $displayTicketPriorityOrder++;
            }
        }
        $checkMedia = Media::find($validatedData['icon_id']);
        if (!$checkMedia) {
            return response()->json(['error' => 'Invalid Icon Id.'], 401);
        }

        $ticketPriority->update([
            'ticket_priority' => $validatedData['ticket_priority'],
            'icon_id' => $validatedData['icon_id'],
            'display_order' => $displayTicketPriorityOrder,
        ]);

        return response()->json($ticketPriority, 200);
    }


    /**
     * Remove the specified ticket priority from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract the token by removing the "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Check if the token is empty after removing "Bearer "
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
        try {
            $id = $request->id;
            $amenityCategory = TicketPriority::findOrFail($id); // Find the Ticket Priority by ID

            $amenityCategory->delete(); // Delete the Ticket Priority

            return response()->json(['message' => 'Ticket Priority deleted successfully']);
        } catch (ModelNotFoundException $e) {
            // Handle the case where the ID is not found
            return response()->json(['error' => 'Ticket Priority not found.'], 404);
        } catch (\Throwable $th) {
            // Handle any other exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function show(Request $request)
    {

        try {

            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|exists:ticket_priorities,id',
            ]);

            // Check if validation fails
            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $ticketPriority = TicketPriority::where('id', $request->id)->with('media')->get();

            return $ticketPriority;

        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'The provided ticket priority id is invalid.'], 422);
        }
    }


    // Bulk Delete
    public function bulkDelete(Request $request)
    {

        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract the token by removing the "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Check if the token is empty after removing "Bearer "
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
        // Verify the token dynamically (e.g., check in the database)
        $tokenExists = DB::table('users')->where('api_token', $requestToken)->exists();

        if (!$tokenExists) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }


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
            $existingIds = TicketPriority::whereIn('id', $ids)->pluck('id')->toArray();

            if (empty($existingIds)) {
                return response()->json([
                    'error' => 'No matching Ticket Priority records found for given IDs.'
                ], 404);
            }

            $notFoundIds = array_values(array_diff($ids, $existingIds));

            $deletedCount = TicketPriority::whereIn('id', $existingIds)->delete();

            return response()->json([
                'message' => 'Ticket Priorities deleted successfully.',
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



    // Search ticket priorities by ticket_priority
    public function searchTicketPriority(Request $request)
    {
        try {
            $searchTerm = $request->input('search');
            $perPage = $request->input('per_page', 10); // Default to 10 if not provided

            $query = TicketPriority::query();

            if (!empty($searchTerm)) {
                $query->where('ticket_priority', 'like', '%' . $searchTerm . '%');
            }

            $results = $query->orderBy('display_order', 'asc')->paginate($perPage); // 10 results per page

            return response()->json([
                'status' => true,
                'message' => 'Ticket priorities fetched successfully.',
                'data' => $results->items(),
                'meta' => [
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                ],
                'links' => [
                    'first' => $results->url(1),
                    'last' => $results->url($results->lastPage()),
                    'prev' => $results->previousPageUrl(),
                    'next' => $results->nextPageUrl(),
                ],
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
