<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HelpCategory;
use DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Storage;
use Illuminate\Support\Facades\Validator;
class HelpCategoryController extends Controller
{


    // this is for store the data
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:help_categories,name',
                'image' => 'nullable|required',
            ]);

            $data = [
                'name' => $request->name,
            ];

            // Handle file uploads client photo
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $file->move(public_path('uploads/help'), $fileName);
                $data['image'] = $fileName;
            }

            $result = HelpCategory::create($data);


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

            $data = HelpCategory::all();

            // if($data){
            //     foreach($data as $row){
            //         $row->image = $row->image ? url('uploads/help/' . $row->image) : null;
            //     }
            // }

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
            $clientReview = HelpCategory::findOrFail($id);

            // Validate the request data
            $request->validate([
                'name' => 'required|string|max:255|unique:help_categories,name,' . $id,
                'image' => 'nullable',
            ]);

            // Prepare the data for updating
            $data = $request->only(['name']);

            // Handle file upload if a new file is uploaded
            if ($request->hasFile('image')) {
                // Delete the old photo if it exists
                if ($clientReview->image) {
                    $oldFilePath = public_path('uploads/help/' . $clientReview->image);
                    if (file_exists($oldFilePath)) {
                        unlink($oldFilePath);
                    }
                }

                // Upload the new photo
                $file = $request->file('image');
                $fileName = time() . '_' . $file->getClientOriginalName();
                $file->move(public_path('uploads/help'), $fileName);
                $data['image'] = $fileName;
            }

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
            $client = HelpCategory::find($id);

            if (!$client) {
            return response()->json(['message' => 'Data not found'], 404);
            }

            $filePath = public_path('uploads/help/'.$client->image);

            // Delete the file if it exists
            if (File::exists($filePath)) {
                File::delete($filePath);
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

            $data = HelpCategory::where('id',$request->id)->first();

            if($data){
                    $data->image = $data->image ? url('uploads/help/' . $data->image) : null;
            }

            return response()->json($data);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // this is for bulk delete

    public function bulkDelete(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:help_categories,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // First get the records that will be deleted
            $categoriesToDelete = HelpCategory::whereIn('id', $request->ids)->get();

            // Delete associated images
            foreach ($categoriesToDelete as $category) {
                if ($category->image) {
                    $filePath = public_path('uploads/help/' . $category->image);
                    if (File::exists($filePath)) {
                        File::delete($filePath);
                    }
                }
            }

            // Now perform the actual deletion
            $deletedCount = HelpCategory::whereIn('id', $request->ids)->delete();

            return response()->json([
                'status' => true,
                'message' => "$deletedCount record(s) deleted successfully."
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'error' => $th->getMessage()
            ], 500);
        }
    }


}




