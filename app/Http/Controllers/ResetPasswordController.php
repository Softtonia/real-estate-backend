<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\PasswordReset;
use Carbon\Carbon;

class ResetPasswordController extends Controller
{
    /**
     * Show reset password form (for API, can be skipped if frontend handles it)
     */
    public function showResetForm($token)
    {
        return response()->json([
            'status' => true,
            'token' => $token,
        ]);
    }

    /**
     * Reset user password
     */
    public function reset(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify token
        $passwordReset = PasswordReset::where('email', $request->email)
                                      ->where('token', $request->token)
                                      ->first();

        if (!$passwordReset) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token',
            ], 400);
        }

        // Find user
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete token
        $passwordReset->delete();

        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully',
        ]);
    }
}