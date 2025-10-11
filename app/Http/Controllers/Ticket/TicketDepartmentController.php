<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use App\Models\TicketDepartment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TicketDepartmentController extends Controller
{
    // Get all departments
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // Default to 10 if not provided
        $query = TicketDepartment::with('media')->orderBy('display_order');
        $departments = $query->paginate($perPage);
        return response()->json([
            'data' => $departments->items(),
            'meta' => [
                'current_page' => $departments->currentPage(),
                'per_page' => $departments->perPage(),
                'total' => $departments->total(),
                'last_page' => $departments->lastPage(),
            ],
            'links' => [
                'first' => $departments->url(1),
                'last' => $departments->url($departments->lastPage()),
                'prev' => $departments->previousPageUrl(),
                'next' => $departments->nextPageUrl(),
            ],
         
        ],200);
    }

    public function searchDepartment(Request $request)
    {
        // Get search keyword from query param (?search=xyz)
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10); // Default to 10 if not provided

        // Query builder
        $query = TicketDepartment::with('media')->orderBy('display_order');

        // If search keyword provided, filter results
        if (!empty($search)) {
            $query->where('ticket_department_name', 'LIKE', "%{$search}%");
        }

        // Fetch results
        $departments = $query->paginate($perPage);

        return response()->json([ 'data' => $departments->items(),
            'meta' => [
                'current_page' => $departments->currentPage(),
                'per_page' => $departments->perPage(),
                'total' => $departments->total(),
                'last_page' => $departments->lastPage(),
            ],
            'links' => [
                'first' => $departments->url(1),
                'last' => $departments->url($departments->lastPage()),
                'prev' => $departments->previousPageUrl(),
                'next' => $departments->nextPageUrl(),
            ],],200);
    }


    // Store a new department
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'icon_id' => 'nullable|integer',
            'ticket_department_name' => 'required|string|max:100|unique:ticket_departments,ticket_department_name',
            'display_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Set or validate display_order
        if ($request->filled('display_order')) {
            $exists = TicketDepartment::where('display_order', $request->display_order)->exists();
            if ($exists) {
                return response()->json([
                    'status' => false,
                    'errors' => ['display_order' => ['The display order has already been taken.']]
                ], 422);
            }
            $displayOrder = $request->display_order;
        } else {
            $maxOrder = TicketDepartment::max('display_order');
            $displayOrder = $maxOrder !== null ? $maxOrder + 1 : 1;
        }

        //  Now safely create
        $department = TicketDepartment::create([
            'icon_id' => $request->icon_id,
            'ticket_department_name' => $request->ticket_department_name,
            'display_order' => $displayOrder,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Department created successfully.',
            'data' => $department
        ], 201);
    }



    // Show a single department
    public function show(Request $request)
    {
        $id = $request->id;
        $department = TicketDepartment::with('media')->find($id);

        if (!$department) {
            return response()->json([
                'status' => false,
                'message' => 'Department not found.'
            ], 404);
        }

        return response()->json($department);
    }

    // Update department
    public function update(Request $request)
    {
        $id = $request->id;
        $department = TicketDepartment::find($id);

        if (!$department) {
            return response()->json([
                'status' => false,
                'message' => 'Department not found.'
            ], 404);
        }

        //  Strict validation: display_order is required now
        $validator = Validator::make($request->all(), [
            'icon_id' => 'nullable|integer',
            'ticket_department_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('ticket_departments', 'ticket_department_name')->ignore($id)
            ],
            'display_order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        //  Allow only same display_order OR new unique one
        $requestedOrder = $request->display_order;


        $conflict = TicketDepartment::where('id', '!=', $department->id)
            ->where('display_order', $requestedOrder)
            ->exists();

        if ($conflict) {
            return response()->json([
                'status' => false,
                'errors' => ['display_order' => ['The display order has already been taken by another department.']]
            ], 422);
        }

        //  Safe to update
        $department->update([
            'icon_id' => $request->icon_id,
            'ticket_department_name' => $request->ticket_department_name,
            'display_order' => $requestedOrder,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Department updated successfully.',
            'data' => $department
        ]);
    }


    // Delete department
    public function destroy(Request $request)
    {
        $id = $request->id;
        $department = TicketDepartment::find($id);

        if (!$department) {
            return response()->json([
                'status' => false,
                'message' => 'Department not found.'
            ], 404);
        }

        $department->delete();

        return response()->json([
            'status' => true,
            'message' => 'Department deleted successfully.'
        ]);
    }

    // Bulk Delete

    public function bulkDestroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:ticket_departments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Perform bulk delete
        $deleted = TicketDepartment::whereIn('id', $request->ids)->delete();

        return response()->json([
            'status' => true,
            'message' => "$deleted department(s) deleted successfully."
        ]);
    }

}
