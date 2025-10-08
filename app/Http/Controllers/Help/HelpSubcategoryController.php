<?php


namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\HelpCategory;
use App\Models\HelpSubcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DB;

class HelpSubcategoryController extends Controller
{

    // this is for store the data
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:help_categories,name',
                'help_category_id' => 'required|exists:help_categories,id',
            ]);

            $data = [
                'name' => $request->name,
                'help_category_id' =>  $request->help_category_id
            ];

            $result = HelpSubcategory::create($data);

            $returnRes = [
                'status' => true,
                'message' => 'Data added successfully.',
                'data' => $result,
            ];

            return response()->json($returnRes, 201);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is for listing
    public function index(Request $request)
    {
        try {
            $baseURL = config('app.url');

            $data = HelpSubcategory::with('category')->get();

            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function searchByName(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $search = $request->input('search'); // search keyword from query or body

            $query = HelpSubcategory::with('category');

            // Apply search filter agar keyword diya gaya ho
            if (!empty($search)) {
                $query->where('name', 'like', "%{$search}%");
            }

            $data = $query->get();

            return response()->json($data);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }




    // this is for update the record
    public function update(Request $request)
    {
        try {
            $id =  $request->id;
            // Find the existing review by ID
            $clientReview = HelpSubcategory::findOrFail($id);

            // Validate the request data
            $request->validate([
                'name' => 'required|string|max:255|unique:help_categories,name,' . $id,
                'help_category_id' => 'required|exists:help_categories,id',
            ]);

            // Prepare the data for updating
            $data = $request->only(['name','help_category_id']);

            // Update the review
            $clientReview->update($data);

            // Prepare the response
            $returnRes = [
                'status' => true,
                'message' => 'Data updated successfully.',
                'data' => $clientReview,
            ];

            return response()->json($returnRes, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // this is for delete the record
    public function delete(Request $request)
    {
        try {

            $id = $request->id;
            $client = HelpSubcategory::find($id);

            if (!$client) {
            return response()->json(['message' => 'Data not found'], 404);
            }

            // Delete the client
            $client->delete();

            $returnRes = [
            'status' => true,
            'message' => 'Data deleted successfully.'
            ];

            return response()->json($returnRes,200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is foe get data by id
    public function getdatabyId(Request $request)
    {
        try {
            $baseURL = config('app.url');

            $data = HelpSubcategory::with('category')->where('id',$request->id)->first();

            if($data){
                    $data->image = $data->image ? url('uploads/help/' . $data->image) : null;
            }

            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // this is for fetch the data
    public function getHelpSubcategoryByCategoryId(Request $request)
    {
        try {

            $data = HelpSubcategory::where('help_category_id',$request->help_category_id)->with('category')->get();

            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // bulk delete

    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'integer|exists:help_subcategories,id',
            ]);

            $deleted = HelpSubcategory::whereIn('id', $request->ids)->delete();

            if ($deleted > 0) {
                return response()->json([
                    'status' => true,
                    'message' => "$deleted record(s) deleted successfully."
                ], 200);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'No matching records found to delete.'
                ], 404);
            }
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


}
