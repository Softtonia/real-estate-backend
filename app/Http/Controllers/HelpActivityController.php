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

        // Create a new activity entry for each action
        $activity = HelpActivity::create([
            'help_article_id' => $request->help_article_id,
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
