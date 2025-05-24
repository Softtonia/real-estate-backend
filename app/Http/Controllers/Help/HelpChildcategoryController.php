<?php


namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\HelpCategory;
use App\Models\HelpSubcategory;
use App\Models\HelpChildcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DB;

class HelpChildcategoryController extends Controller
{
    
    // this is for store the data
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:help_categories,name',
                'help_category_id' => 'required|exists:help_categories,id',
                'help_subcategory_id' => 'required|exists:help_subcategories,id',
            ]);

            $data = [
                'name' => $request->name,
                'help_category_id' => $request->help_category_id,
                'help_subcategory_id' => $request->help_subcategory_id,
            ];
        
            $result = HelpChildcategory::create($data);
        
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

            $data = HelpChildcategory::with('category','subcategory')->get();
            
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
            $clientReview = HelpChildcategory::findOrFail($id);

            // Validate the request data
            $request->validate([
                'name' => 'required|string|max:255|unique:help_categories,name,' . $id,
                'help_category_id' => 'required|exists:help_categories,id',
                'help_subcategory_id' => 'required|exists:help_subcategories,id',
            ]);

            // Prepare the data for updating
            $data = $request->only(['name','help_category_id','help_subcategory_id']);

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
            $client = HelpChildcategory::find($id);
            
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

            $data = HelpChildcategory::with('category','subcategory')->where('id',$request->id)->first();
            
            if($data){
                    $data->image = $data->image ? url('uploads/help/' . $data->image) : null;
            }
            
            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


     // this is for fetch the data
    public function getHelpChildcategoryBySubcategoryId(Request $request)
    {
        try {

            $data = HelpChildcategory::where('help_subcategory_id',$request->help_category_id)->with('subcategory')->get();
            
            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }
    
}