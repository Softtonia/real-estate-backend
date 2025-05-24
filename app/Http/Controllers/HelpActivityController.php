<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Models\HelpActivity;
use Illuminate\Support\Facades\Auth;

class HelpActivityController extends Controller
{
    public function manageActivity(Request $request)
{
    $userId = null; // Default user_id is null

    // Check if Authorization header exists and process token if available
    if ($request->hasHeader('Authorization') && !empty($request->header('Authorization'))) {
        $authorizationHeader = $request->header('Authorization');

        // Validate token format
        if (str_starts_with($authorizationHeader, 'Bearer ')) {
            // Extract token from header
            $token = str_replace('Bearer ', '', $authorizationHeader);

            // Find user by token in users table
            $user = User::where('api_token', $token)->first();

            // If user exists, store user_id
            if ($user) {
                $userId = $user->id;
            }
        }
    }

    // Create a new activity entry for each action
    $activity = HelpActivity::create([
        'help_article_id' => $request->help_article_id,
        'user_id' => $userId, // Store user ID if token is valid, otherwise null
        'like' => $request->action === 'like' ? 1 : 0,
        'dislike' => $request->action === 'dislike' ? 1 : 0,
        'type' => $request->type ?? null
    ]);

    // Get total like/dislike counts
    $likeCount = HelpActivity::where('help_article_id', $request->help_article_id)->where('like', 1)->count();
    $dislikeCount = HelpActivity::where('help_article_id', $request->help_article_id)->where('dislike', 1)->count();

    return response()->json([
        'success' => true,
        'message' => "Article {$request->action}d successfully",
        'data' => $activity,
        'like_count' => $likeCount,
        'dislike_count' => $dislikeCount
    ], 200);
}


    }
