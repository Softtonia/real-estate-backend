<?php

namespace App\Http\Controllers\Subscribe;

use App\Exports\SubscribedEmailsExport;
use App\Http\Controllers\Controller;
use App\Imports\SubscribedEmailsImport;
use App\Models\SubscribedEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Excel;

class SubscribeController extends Controller
{

     public function insertSubscribeEmail(Request $request)
    {
        // Validate the email
        $validator = Validator::make($request->all(), [
            'subscribe_email' => 'required|email|unique:subscribed_emails,subscribe_email',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Save the email to the database
        // Assuming you have a Subscription model and subscriptions table
        $subscription = new SubscribedEmail();
        $subscription->subscribe_email = $request->subscribe_email;
        $subscription->is_subscribed = true;
        $subscription->save();

        return response()->json(['message' => 'Subscription successful'], 200);
    }

      public function listingOfSubscribedEmails(Request $request)
    {

        $subscription = SubscribedEmail::get();

        return response()->json(['data' => $subscription], 200);
    }


    public function importSubscribedEmails(Request $request)
    {
        // Validate the file
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:csv,xlsx,xls|max:2048', // 2MB max size
        ]);

        // If validation fails, return a JSON response with error details
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        try {
            // Get the uploaded file
            $file = $request->file('file');

            // Import the file using the SubscribedEmailsImport class
            Excel::import($import = new SubscribedEmailsImport, $file);

            // Get the import summary (successes, duplicates, invalid emails, etc.)
            $summary = $import->getSummary();

            // Generate an error log if there were any errors during the import
            $errorLogFile = $import->generateErrorLog();
            return response()->json([
                'message' => 'Emails import completed successfully.',
                'summary' => $summary,
                'error_log_url' => $errorLogFile ? url($errorLogFile) : null,
            ], 200);


        } catch (\Exception $e) {
            // Return a JSON response in case of an exception
            return response()->json([
                'error' => 'Error processing the file: ' . $e->getMessage()
            ], 500);
        }
    }


     public function exportSubscribedEmails($format = 'csv', Request $request)
    {
        // Validate the requested format
        if (!in_array($format, ['csv', 'xlsx'])) {
            return response()->json(['error' => 'Invalid format. Only CSV and Excel are supported.'], 400);
        }

        // Get filter parameters from the request
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $tag = $request->query('tag');
        $isSubscribed = $request->query('is_subscribed');

        // Log the filter parameters for debugging
        \Log::info("Filters - Start Date: $startDate, End Date: $endDate, Tag: $tag, Is Subscribed: $isSubscribed");

        // Prepare the filters for export
        $filters = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'tag' => $tag,
            'is_subscribed' => $isSubscribed,
        ];

        // File name and path setup
        $fileName = 'subscribed_emails_' . now()->format('Y_m_d_H_i_s') . '.' . $format;
        $filePath = 'uploads/error_log/' . $fileName;

        // Ensure the directory exists
        if (!Storage::exists('uploads/error_log')) {
            Storage::makeDirectory('uploads/error_log');
        }

        // Export the filtered data and store it
        (new SubscribedEmailsExport($filters))->store($filePath, 'public');

        // Generate the public URL for the file
        $fileUrl = Storage::url($filePath);

        // Return the file URL as a response
        return response()->json([
            'message' => 'File exported successfully!',
            'file_url' => url($fileUrl)
        ]);
    }



}
