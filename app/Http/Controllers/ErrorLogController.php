<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Response;
use Illuminate\Support\Facades\URL;

class ErrorLogController extends Controller
{
    public function listErrorLogs()
    {
        $directoryPath = public_path('uploads/error_log');

        // Check if the directory exists
        if (!File::exists($directoryPath)) {
            return response()->json(['error' => 'Error log directory not found'], 404);
        }

        // Get all CSV files in the directory
        $errorLogs = File::files($directoryPath);

        // Filter only CSV files (in case there are other types of files)
        $csvFiles = collect($errorLogs)->filter(function ($file) {
            return $file->getExtension() === 'csv';
        });

        // Map the file paths to relative URLs for easy access
        $fileUrls = $csvFiles->map(function ($file) {
            return url('uploads/error_log/' . $file->getBasename());
        });

        // Return the list of error log files
        return response()->json([
            'error_logs' => $fileUrls
        ]);
    }

    public function deleteErrorLog($fileName)
{
    // Define the directory path
    $directoryPath = public_path('uploads/error_log');
    $filePath = $directoryPath . '/' . $fileName;

    // Check if the file exists
    if (!File::exists($filePath)) {
        return response()->json(['error' => 'File not found'], 404);
    }

    // Delete the file
    File::delete($filePath);

    // Return a success response
    return response()->json(['success' => 'File deleted successfully']);
}

public function bulkDeleteErrorLogs(Request $request)
{
    // Define the directory path
    $directoryPath = public_path('uploads/error_log');

    // Get the list of file names from the request
    $fileNames = $request->input('files', []);

    // Check if files are provided
    if (empty($fileNames)) {
        return response()->json(['error' => 'No files provided'], 400);
    }

    // Initialize an array to store errors
    $errors = [];

    // Loop through each file name and attempt to delete it
    foreach ($fileNames as $fileName) {
        $filePath = $directoryPath . '/' . $fileName;

        if (File::exists($filePath)) {
            File::delete($filePath);
        } else {
            $errors[] = "File not found: $fileName";
        }
    }

    // Return success or error response
    if (empty($errors)) {
        return response()->json(['success' => 'Files deleted successfully']);
    }

    return response()->json(['error' => $errors], 400);
}

    public function downloadFile($file)
    {
        // Define the file path
        $filePath = public_path('uploads/error_log/' . $file);

        // Check if the file exists
        if (!file_exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Specify the correct content type for CSV files
        $headers = [
            'Content-Type' => 'text/csv',  // For CSV files
            'Content-Disposition' => 'attachment; filename="' . $file . '"',  // Force download
        ];

        // Return the file as a download response
        return response()->download($filePath, $file, $headers);
    }

}
