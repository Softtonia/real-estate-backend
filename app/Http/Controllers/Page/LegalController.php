<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Page;
use App\Models\User;
use DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class LegalController extends Controller
{

public function update(Request $request)
{
    // Check if API token is provided in the header
    if ($request->header('api-token') == '') {
        return response()->json(['error' => 'Please enter api token first.'], 422);
    }

    $requestToken = $request->header('api-token');
    $expectedToken = config('constants.API_TOKEN');

    // Validate API token
    if ($requestToken !== $expectedToken) {
        return response()->json(['error' => 'Unauthorized. Invalid api token.'], 401);
    }

    try {
        // Find the page by pagename
        $page = Page::where('page', 'Legal')->first();

        if (!$page) {
            // If page doesn't exist, create a new one
            $page = new Page();
            $page->page = 'Legal';
        }

        // Update the page title
        $page->title = $request->input('title');

        // Update the page content
        $page->content = $request->input('content');

        // Save the changes
        $page->save();

        return response()->json(['success' => 'Page ' . ($page->wasRecentlyCreated ? 'created' : 'updated') . ' successfully']);
    } catch (\Throwable $th) {
        // Log the specific error message for debugging purposes
        \Log::error('Error updating page: ' . $th->getMessage());
        return response()->json(['error' => 'An error occurred while updating the page'], 500);
    }
}


public function index(Request $request)
{
    try {
        // Fetch pages with the name
        $pages = Page::where('page', 'Legal')->get();

        // Check if there are any pages
        if ($pages->isEmpty()) {
            return response()->json(['error' => 'No pages found with the name "Legal"'], 404);
        }

        // Return the list of pages
        return response()->json(['pages' => $pages]);
    } catch (\Throwable $th) {
        // Log the specific error message for debugging purposes
        \Log::error('Error listing Legal pages: ' . $th->getMessage());
        return response()->json(['error' => 'An error occurred while listing Legal pages'], 500);
    }
}


    
}
