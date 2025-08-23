<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail; // Use the correct namespace for Mail
use Illuminate\Support\Facades\URL;  // Add this line to use the URL facade
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str; // Add this line to use the Str facade
use Carbon\Carbon;
use App\Models\User;

class ForgotPasswordController extends Controller
{

    // Forgot Password (Send Reset Link)
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

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Email not found',
            ], 200);
        }

        //  Mail Config from DB
        $mailConfig = DB::table('mail_configs')->where('status', 1)->first();
        if (!$mailConfig) {
            return response()->json([
                'status' => false,
                'message' => 'Mail configuration not found in database.',
            ], 500);
        }

        config([
            'mail.mailers.smtp.transport' => $mailConfig->mailer,
            'mail.mailers.smtp.host' => $mailConfig->host,
            'mail.mailers.smtp.port' => $mailConfig->port,
            'mail.mailers.smtp.username' => $mailConfig->username,
            'mail.mailers.smtp.password' => $mailConfig->password,
            'mail.mailers.smtp.encryption' => $mailConfig->encryption,
            'mail.from.address' => $mailConfig->from_address,
            'mail.from.name' => $mailConfig->from_name,
        ]);

        //  Generate token
        $token = Str::random(40);

        //  Save token
        PasswordReset::updateOrCreate(
            ['email' => $request->email],
            [
                'token' => $token,
                'created_at' => Carbon::now(),
            ]
        );

        $resetUrl = url('/api/reset-password-form?token=' . $token);

        $data = [
            'url' => $resetUrl,
            'email' => $request->email,
            'title' => 'Password Reset',
            'body' => 'Click here to reset your password',
        ];

        try {
            Mail::send('forgetPasswordMail', ['data' => $data], function ($message) use ($data, $mailConfig) {
                $message->to($data['email'])
                    ->from($mailConfig->from_address, $mailConfig->from_name)
                    ->subject($data['title']);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to send email',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'Password reset link sent to your email',
            'reset_token' => $token, // frontend use kar sakta hai direct API ke liye
            'reset_url' => $resetUrl,
        ], 200);
    }

    //  Reset Password
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $passwordReset = PasswordReset::where('token', $request->token)->first();

        if (!$passwordReset) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token',
            ], 400);
        }

        $user = User::where('email', $passwordReset->email)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 200);
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

    // Check if token is valid (Instead of returning Blade view)
    public function validateResetToken(Request $request)
    {
        $resetData = PasswordReset::where('token', $request->token)->first();

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
