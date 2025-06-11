<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HelpCategory;
use App\Models\HelpSubcategory;
use App\Models\HelpChildcategory;
use App\Models\HelpArticle;
use DB;
use Illuminate\Support\Facades\Log;
use Storage;
use Illuminate\Support\Facades\Validator;
class HelpArticleController extends Controller
{

    // this is for store the data
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'required|string|max:255|unique:help_articles,title',
                'description' => 'required',
                'help_category_id' => 'nullable|exists:help_categories,id',
                'help_subcategory_id' => 'nullable|exists:help_subcategories,id',
                'help_childcategory_id' => 'nullable|exists:help_childcategories,id',
            ]);

            $data = [
                'title' => $validatedData['title'],
                'description' => $validatedData['description'],
                'help_category_id' => $validatedData['help_category_id'] ?? null,
                'help_subcategory_id' => $validatedData['help_subcategory_id'] ?? null,
                'help_childcategory_id' => $validatedData['help_childcategory_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];


            $result = HelpArticle::create($data);


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

            $data = HelpArticle::with('category','subcategory','childcategory')->get();

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
            $clientReview = HelpArticle::findOrFail($id);

            // Validate the request data
            $request->validate([
                'title' => 'required|string|max:255|unique:help_articles,title,' . $id,
                'description' => 'required',
                'help_category_id' => 'nullable|exists:help_categories,id',
                'help_subcategory_id' => 'nullable|exists:help_subcategories,id',
                'help_childcategory_id' => 'nullable|exists:help_childcategories,id',
            ]);

            // Prepare the data for updating
            $data = $request->only(['title','description','help_category_id','help_subcategory_id','help_childcategory_id']);



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
            $client = HelpArticle::find($id);

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

            $data = HelpArticle::with('category','subcategory','childcategory')->where('id',$request->id)->first();

            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // Bulk delete
    public function bulkDelete(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:help_articles,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Delete in one transaction
            DB::transaction(function () use ($request) {
                HelpArticle::whereIn('id', $request->ids)->delete();
            });


            return response()->json([
                'status' => true,
                'message' => 'Selected articles deleted successfully.',
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'error' => $th->getMessage(),
            ], 500);
        }
    }

}
