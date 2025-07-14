<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use App\Models\User;
use DB;
class termsandconditioncontroller extends Controller
{

public function update(Request $request)
{


    try {
        // Find the page by pagename
        $page = Page::where('page', 'Terms & Conditions')->first();

        if (!$page) {
            // If page doesn't exist, create a new one
            $page = new Page();
            $page->page = 'Terms & Conditions';
        }

        // Update the page title
        $page->title = $request->input('title');

        // Update the page content (text editor)
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
        // Fetch pages with the name "Terms and Conditions"
        $pages = Page::where('page', 'Terms & Conditions')->get();


        // Check if there are any pages
        if ($pages->isEmpty()) {
            return response()->json(['error' => 'No pages found with the name "Terms and Conditions"'], 404);
        }

        // Return the list of pages
        return response()->json(['pages' => $pages]);
    } catch (\Throwable $th) {
        // Log the specific error message for debugging purposes
        \Log::error('Error listing Terms and Conditions pages: ' . $th->getMessage());
        return response()->json(['error' => 'An error occurred while listing Terms and Conditions pages'], 500);
    }
}



}
