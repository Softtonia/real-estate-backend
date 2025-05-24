<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Media;
use App\Models\AgentUniqueId;
use File;
use DB;
use Illuminate\Support\Facades\Validator;

class MediaController extends Controller
{
    // public function addMedia(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'icon_name' => 'required|string|unique:media',
    //         'media_icon' => 'nullable', // Expecting base64 encoded string
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['error' => $validator->errors()], 400);
    //     }

    //     // Decode the base64 string to get the binary image data
    //     $mediaIconData = base64_decode($request->media_icon);

    //     // Generate a unique file name
    //     $fileName = time() . '.png'; // Assuming the media icon is always a PNG file

    //     // Specify the directory where you want to save the file
    //     $directory = 'custom_location/';

    //     // Ensure that the directory exists, create it if it doesn't
    //     $directoryPath = public_path($directory);
    //     File::makeDirectory($directoryPath, $mode = 0777, true, true);

    //     // Save the image to the specified directory
    //     $path = $directoryPath . $fileName;
    //     file_put_contents($path, $mediaIconData);

    //     // Save the media to the database
    //     $media = new Media();
    //     $media->icon_name = $request->icon_name;
    //     $media->icon_css_id = $request->icon_name.'-'.'ur';
    //     $media->media_icon = $directory . $fileName.'png'; // Store the file path instead of binary data
       
    //     $media->save();

    //     $agentUniqueId = new AgentUniqueId();
    //     $agentUniqueId->agents_unique_id = 'URA' . str_pad($media->id, 3, '0', STR_PAD_LEFT); // Assuming URA prefix followed by padded media id
    //     $agentUniqueId->agent_id = $media->id;
    //     $agentUniqueId->save();

    //     return response()->json(['message' => 'Media added successfully', 
    //     'id' => $media->id,
    //     'icon_name' => $media->icon_name,
    //     'icon_css_id' => $media->icon_css_id,
    //     'media_icon' => $media->media_icon,
    //     'created_at' => $media->created_at,
    //     'updated_at' => $media->updated_at
    // ], 200);
    // }

    public function addMedia(Request $request)
{
    // Validate the input
    $validator = Validator::make($request->all(), [
        'icon_name' => 'nullable|string|unique:media',
        'media_icon' => 'nullable|file|mimes:png,jpg,jpeg,gif,svg|max:2048', // Assuming maximum file size is 2MB
    ]);

    if ($validator->fails()) {
        return response()->json(['error' => $validator->errors()], 400);
    }

    // Retrieve the media icon file from the request
    $mediaIconFile = $request->file('media_icon');

    // Generate a unique file name using time and the file's original extension
    $fileName = time() . '_' . str_replace(' ', '_', $mediaIconFile->getClientOriginalName());

    // Specify the directory where you want to save the file
    $directory = 'uploads/media_icons/';

    // Move the media icon file to the specified directory
    $filePath = $mediaIconFile->move(public_path($directory), $fileName);

    // Save the media to the database
    $media = new Media();
    $media->icon_name = $request->icon_name;
    $media->icon_css_id = $request->icon_name . '-' . 'ur';
    $media->media_icon = $directory . $fileName; // Store the relative path in the database

    // Save the record in the database
    $media->save();

    // Return the response with the media data
    return response()->json([
        'message' => 'Media added successfully', 
        'id' => $media->id,
        'icon_name' => $media->icon_name,
        'icon_css_id' => $media->icon_css_id,
        'media_icon' => $media->media_icon,
        'created_at' => $media->created_at,
        'updated_at' => $media->updated_at
    ], 200);
}




public function updateMedia(Request $request)
{
    // Find the media record to update
    $id = $request->id;
    $media = Media::find($id);

    // If the media record doesn't exist, return an error response
    if (!$media) {
        return response()->json(['error' => 'Media not found'], 404);
    }

    // Validate the request data
    $validator = Validator::make($request->all(), [
        'icon_name' => 'required|string|unique:media,icon_name,' . $id, // Ensure icon_name is unique except for the current record
        'media_icon' => 'nullable|file|mimes:png,jpg,jpeg,gif,svg|max:2048', // Max file size of 2MB
    ]);

    if ($validator->fails()) {
        return response()->json(['error' => $validator->errors()], 400);
    }

    // Update the icon name in the database
    $media->icon_name = $request->icon_name;

    // Handle the media icon update if a new file is uploaded
    if ($request->hasFile('media_icon')) {
        $mediaIconFile = $request->file('media_icon');

        // Generate a unique file name using the current timestamp and the original file extension
        $fileName = time() . '_' . str_replace(' ', '_', $mediaIconFile->getClientOriginalName());
        
        // Specify the directory where you want to save the new file
        $directory = 'uploads/media_icons/';

        // Move the new file to the directory
        $mediaIconFile->move(public_path($directory), $fileName);

        // Delete the old media icon file if it exists
        if (File::exists(public_path($media->media_icon))) {
            File::delete(public_path($media->media_icon));
        }

        // Update the media icon's file path to the new one in the database
        $media->media_icon = $directory . $fileName; // Store the relative path
    }

    // Save the updated media record
    $media->save();

    // Return the updated media details as a response
    return response()->json([
        'message' => 'Media updated successfully',
        'id' => $media->id,
        'icon_name' => $media->icon_name,
        'icon_css_id' => $media->icon_css_id,
        'media_icon' => $media->media_icon,
        'created_at' => $media->created_at,
        'updated_at' => $media->updated_at
    ], 200);
}


public function deleteMedia(Request $request)
{
   
    $id = $request->input('id');
    $media = Media::find($id);

    // If the media record doesn't exist, return an error response
    if (!$media) {
        return response()->json(['error' => 'Media not found'], 404);
    }

    // Delete the media record from the database
    $media->delete();

    return response()->json(['message' => 'Media deleted successfully'], 200);
}

public function index(Request $request)
{
    // Fetch all media records from the database
    $data = DB::table('media')->get();
    $mediaList = [];

    foreach ($data as $media) {
        $mediaList[] = [
            'id' => $media->id,
            'icon_css_id' => $media->icon_css_id,
            'icon_name' => $media->icon_name,
            'media_icon' => url($media->media_icon), // Prepend the base URL to the media icon path
            'created_at' => $media->created_at,
            'updated_at' => $media->updated_at
        ];
    }

    // Return the response with the list of media items
    return response()->json($mediaList);
}


}
