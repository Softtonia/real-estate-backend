<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Jobs\SendPasswordResetEmail;

class ForgotPasswordController extends Controller
{
    /**
     * Send password reset link
     */
    public function forgetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid email format',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = $request->input('email');

        // Fetch user once
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Email not found',
            ], 200);
        }

        // Cache mail config for 5 minutes
        $mailConfig = Cache::remember('mail_config', 300, function () {
            return \DB::table('mail_configs')->where('status', 1)->first();
        });

        if (!$mailConfig) {
            return response()->json([
                'status' => false,
                'message' => 'Mail configuration not found in database.',
            ], 500);
        }

        // Generate token
        $token = Str::random(40);

        // Save or update token
        PasswordReset::updateOrCreate(
            ['email' => $email],
            [
                'token' => $token,
                'created_at' => Carbon::now(),
            ]
        );

        $resetUrl = url("/api/reset-password-form?token={$token}");

        $mailData = [
            'url' => $resetUrl,
            'email' => $email,
            'title' => 'Password Reset',
            'body' => 'Click here to reset your password',
            'from_address' => $mailConfig->from_address,
            'from_name' => $mailConfig->from_name,
        ];

        try {
            SendPasswordResetEmail::dispatch($mailData)->onQueue('emails');
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to dispatch email job',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'Password reset link sent to your email',
            'reset_token' => $token,
            'reset_url' => $resetUrl,
        ], 200);
    }

    /**
     * Reset password using token
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $request->input('token');

        // Validate token and check expiry (1 hour)
        $passwordReset = PasswordReset::where('token', $token)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->first();

        if (!$passwordReset) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token',
            ], 400);
        }

        // Fetch user once
        $user = User::where('email', $passwordReset->email)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        $passwordReset->delete();

        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully',
        ], 200);
    }

    /**
     * Validate reset token without changing password
     */
    public function validateResetToken(Request $request)
    {
        $token = $request->input('token');

        $resetData = PasswordReset::where('token', $token)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->first();

        if (!$resetData) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token',
            ], 400);
        }

        return response()->json([
            'status' => true,
            'message' => 'Valid token',
            'email' => $resetData->email,
            'token' => $resetData->token,
        ], 200);
    }
}