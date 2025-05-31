<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;
use App\Models\Role;

class CompanyApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
        //     return response()->json(['error' => 'Please provide an API token.'], 422);
        // }

        // // Retrieve and validate the token format
        // $authorizationHeader = $request->header('Authorization');
        // if (!str_starts_with($authorizationHeader, 'Bearer ')) {
        //     return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        // }

        // // Extract the token
        // $requestToken = substr($authorizationHeader, 7);
        // if (empty($requestToken)) {
        //     return response()->json(['error' => 'Token is missing.'], 422);
        // }

        // // Verify the token against the database
        // $user = User::where('api_token', $requestToken)->first();
        // if (!$user) {
        //     return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        // }

        // // Check user role
        // $role = Role::find($user->role_id);
        // if (!$role || $role->name !== 'company') {
        //     return response()->json(['error' => 'User does not have the required role.'], 400);
        // }

        // // Attach the user to the request for downstream use
        // $request->merge(['user' => $user]);

        // return $next($request);





    // Check if the user is authenticated
    if (!auth()->check()) {
        return response()->json(['status' => false, 'error' => 'Invalid token'], 400);
    }

    // Get the user's role from the roles table using the user's role_id
    $userRole = Role::where('id', auth()->user()->role_id)->first();

    // If the role is admin, allow the action without permission checks
    if ($userRole && $userRole->name === 'admin') {
        return $next($request);
    }

    // Get the user's role ID
    $roleId = auth()->user()->role_id;

    // Get the model name from the cleaned URI
    $modelName = $request->route()->uri();
    $cleanedUri = str_replace('api/', '', $modelName);
    $cleanedUri = ltrim($cleanedUri, '/');

    // Assuming you want to log or debug the cleaned URI
    dd($cleanedUri); // This will output the cleaned URI

    // Here, you would match $cleanedUri to a model name. For instance, a model mapping array:
    $modelMappings = [
        'posts' => Post::class,
        'users' => User::class,
        // Add more mappings here
    ];

    // Now, resolve the model name from the cleaned URI
    if (array_key_exists($cleanedUri, $modelMappings)) {
        $modelClass = $modelMappings[$cleanedUri];

        // You can now get the model's name (e.g., Post, User)
        $modelName = class_basename($modelClass);
        dd($modelName); // Output the model name (Post, User, etc.)
    } else {
        // If no model is found, handle the case accordingly
        return response()->json([
            'status' => false,
            'message' => 'Model not found for the given URI.'
        ], 404);
    }

    // Proceed with permission checks (You can keep your logic for permission checks here)
    $permission = UserPermission::where('role_id', $roleId)
        ->where('model_name', $cleanedUri)
        ->first();

    if ($permission) {
        return $next($request);
    } else {
        return response()->json([
            'status' => false,
            'message' => 'You are not authorized to perform this action.'
        ], 403);
    }

    return $next($request);
}

}

