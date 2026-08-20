<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClientReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClientReviewController extends Controller
{
    
    
    // this is for store the data
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
	            'title' => 'required|string|max:255',
	            'review' => 'required|string',
	            'short_description' => 'required|string',
	            'client_photo' => 'nullable',
	            'rating' => 'nullable|integer|min:1|max:5',
	        ]);

            $data = [
                'title' => $request->title,
	            'review' =>  $request->review,
	            'short_description' => $request->short_description,
	            'rating' => $request->input('rating', 5),
            ];
    
            // Handle file uploads client photo
	        if ($request->hasFile('client_photo')) {
	            $file = $request->file('client_photo');
	            $fileName = time() . '_' . $file->getClientOriginalName();
	            $file->move(public_path('uploads/client_photo'), $fileName);
	            $data['client_photo'] = $fileName;
	        }
        
            $result = ClientReview::create($data);
     
        
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
    
    
    // this is for listing (Frontend Guest API)
	public function index(Request $request)
	{
	    try {
	        $query = ClientReview::query()
	            ->where(function ($q) {
	                $q->where('status', '1')->orWhereNull('status');
	            })
	            ->latest();

	        if ($request->has('limit')) {
	            $query->limit((int) $request->input('limit'));
	        }

	        $data = $query->get();

	        foreach ($data as $row) {
	            $photo = $row->client_photo;
	            if ($photo && is_string($photo) && trim($photo) !== '') {
	                $trimmed = trim($photo);
	                if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
	                    $row->client_photo = $trimmed;
	                } else {
	                    $row->client_photo = url('uploads/client_photo/' . ltrim($trimmed, '/'));
	                }
	            } else {
	                $row->client_photo = url('images/default.png');
	            }
	            $row->client_photo_url = $row->client_photo;
	            $row->rating = $row->rating ? (int) $row->rating : 5;
	        }

	        return response()->json([
	            'status' => true,
	            'message' => 'Client reviews fetched successfully.',
	            'data' => $data,
	        ]);
	    } catch (\Throwable $th) {
	        return response()->json([
	            'status' => false,
	            'message' => 'Unable to fetch client reviews.',
	            'error' => $th->getMessage(),
	        ], 500);
	    }
	}

    

    // this is for update the record 
    public function update(Request $request)
	{
	    try {
	    	$id =  $request->id;
	        // Find the existing review by ID
	        $clientReview = ClientReview::findOrFail($id);

	        // Validate the request data
	        $request->validate([
	            'title' => 'required|string|max:255',
	            'review' => 'required|string',
	            'short_description' => 'required|string',
	            'client_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
	            'rating' => 'nullable|integer|min:1|max:5',
	        ]);

	        // Prepare the data for updating
	        $data = $request->only(['title', 'review', 'short_description', 'status', 'rating']);

	        // Handle file upload if a new file is uploaded
	        if ($request->hasFile('client_photo')) {
	            // Delete the old photo if it exists
	            if ($clientReview->client_photo) {
	                $oldFilePath = public_path('uploads/client_photo/' . $clientReview->client_photo);
	                if (file_exists($oldFilePath)) {
	                    unlink($oldFilePath);
	                }
	            }

	            // Upload the new photo
	            $file = $request->file('client_photo');
	            $fileName = time() . '_' . $file->getClientOriginalName();
	            $file->move(public_path('uploads/client_photo'), $fileName);
	            $data['client_photo'] = $fileName;
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
    public function destroy(Request $request)
    {
        try {

            $id = $request->id;
            $client = ClientReview::find($id);
            
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

	public function bulkDelete(Request $request)
	{
		try {
			$ids = $request->input('ids'); // array of IDs

			if (empty($ids) || !is_array($ids)) {
				return response()->json([
					'status' => false,
					'message' => 'Please provide an array of IDs to delete.'
				], 400);
			}

			// Find all records that match IDs
			$clients = ClientReview::whereIn('id', $ids)->get();

			if ($clients->isEmpty()) {
				return response()->json([
					'status' => false,
					'message' => 'No matching data found.'
				], 200);
			}

			// Delete all matching records
			ClientReview::whereIn('id', $ids)->delete();

			return response()->json([
				'status' => true,
				'message' => 'Selected data deleted successfully.',
				'deleted_ids' => $ids
			], 200);

		} catch (\Throwable $th) {
			return response()->json(['error' => $th->getMessage()], 500);
		}
	}

    
    
    // this is foe get data by id
    public function getdatabyId(Request $request)
    {
	    try {
	        $baseURL = config('app.url');

	        $data = ClientReview::where('id',$request->id)->first();
	        
	        if($data){
	                $data->client_photo = $data->client_photo ? url('uploads/client_photo/' . $data->client_photo) : null;
	        }
	        
	        return response()->json($data);
	    } catch (\Throwable $th) {
	        return response()->json(['error' => $th->getMessage()], 500);
	    }
	}


	public function searchByTitle(Request $request)
	{
		try {
			$baseURL = config('app.url');
			$search = $request->input('search'); // Search keyword from query

			$query = ClientReview::query();

			// Apply search if keyword provided
			if (!empty($search)) {
				$query->where('title', 'like', "%{$search}%");
			}

			$data = $query->get();

			// Append image full URL
			if ($data->count()) {
				foreach ($data as $row) {
					$row->client_photo = $row->client_photo ? url('uploads/client_photo/' . $row->client_photo) : null;
				}
			}

			return response()->json($data);

		} catch (\Throwable $th) {
			return response()->json(['error' => $th->getMessage()], 500);
		}
	}

    
}