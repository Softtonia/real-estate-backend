<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Otp;
use App\Mail\OTPMail;
use App\Jobs\SendOtpMailJob;

class OtpController extends Controller
{
    /**
     * Verify email OTP
     */
    public function emailVerifyOtp(Request $request)
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not authenticated.',
            ], 401);
        }

        $request->validate([
            'email_otp' => 'required|numeric',
        ]);

        // Use Eloquent for OTP
        $otpRecord = Otp::where('user_id', $authUser->id)->latest()->first();

        if (!$otpRecord) {
            return response()->json([
                'status' => 'error',
                'message' => 'No OTP record found for the user.',
            ], 200);
        }

        if ($otpRecord->otp != $request->email_otp) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid OTP.',
            ], 400);
        }

        if (Carbon::now()->greaterThan($otpRecord->expire_date_time)) {
            return response()->json([
                'status' => 'error',
                'message' => 'OTP has expired.',
            ], 400);
        }

        // Atomic update using transaction
        DB::transaction(function () use ($authUser) {
            Otp::where('user_id', $authUser->id)->update(['isOTPVerified' => true]);
            User::where('id', $authUser->id)->update([
                'is_otp_verified' => true,
                'isapproved' => 1,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'OTP verified successfully and user approved.',
            'user_id' => $authUser->id,
            'role' => $authUser->role->name ?? null,
            'api_token' => $authUser->api_token,
        ], 200);
    }

    /**
     * Resend OTP to user email
     */
    public function resendOtp(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $latestOtp = Otp::where('user_id', $user->id)->latest()->first();

        if ($latestOtp && $latestOtp->created_at->diffInSeconds(now()) < 60) {
            return response()->json([
                'status' => false,
                'message' => 'You can resend OTP after 1 minute.',
            ], 429);
        }

        $otp = rand(1000, 9999);
        $expiryTime = now()->addMinutes(10);

        if ($latestOtp) {
            $latestOtp->update([
                'otp' => $otp,
                'isOTPVerified' => false,
                'expire_date_time' => $expiryTime,
            ]);
        } else {
            Otp::create([
                'user_id' => $user->id,
                'otp' => $otp,
                'isOTPVerified' => false,
                'expire_date_time' => $expiryTime,
            ]);
        }

        // Cache OTP request for throttling
        Cache::put("otp_request_{$user->id}", true, 60);

        $fullName = trim($user->first_name . ' ' . $user->last_name);

        // Dynamic mail config
        $settings = DB::table('mail_configs')->where('status', 1)->first();
        if (!$settings) {
            return response()->json([
                'message' => 'Mail settings are not configured.',
            ], 500);
        }

        config([
            'mail.mailers.smtp.host' => $settings->host,
            'mail.mailers.smtp.port' => $settings->port,
            'mail.mailers.smtp.username' => $settings->username,
            'mail.mailers.smtp.password' => $settings->password,
            'mail.mailers.smtp.encryption' => $settings->encryption,
            'mail.from.address' => $settings->from_address,
            'mail.from.name' => $settings->from_name,
        ]);

        // Async email dispatch
        try {
            SendOtpMailJob::dispatch($otp, $user->email, $fullName);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP email.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'OTP resent successfully. Please check your email.',
        ], 200);
    }
}