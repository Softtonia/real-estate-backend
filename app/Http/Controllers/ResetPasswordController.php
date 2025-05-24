<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Hash;
class ResetPasswordController extends Controller
{
    public function showResetForm($token)
    {
        return view('auth.passwords.reset')->with(['token' => $token]);
    }

    public function reset(Request $request)
    {
        // Validate the request data
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ]);

        // Find the user by email
        $user = User::where('email', $request->email)->first();

        // Check if the user exists
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Update the user's password
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['success' => 'Password updated successfully']);
    }
}
