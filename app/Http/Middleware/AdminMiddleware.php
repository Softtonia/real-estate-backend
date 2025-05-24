<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
       
            // Check if the user is authenticated and has the "admin" role
            if ($request->user() && $request->user()->role !== 'admin') {
                
                return response()->json(['error' => 'Unauthorized'], 403);
            }
    
            return $next($request);
        }
}
