<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\Otp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use Illuminate\Support\Facades\Mail;
use App\Mail\OTPMail;

class OtpController extends Controller
{
   

  

    public function emailVerifyOtp(Request $request)
    {
        $authUser = Auth::user();
        $authUserId = $authUser->id;

        $request->validate([
            'email_otp' => 'required|numeric',
        ]);

        // Fetch the OTP record for the user
        $otpRecord = DB::table('otps')->where('user_id', $authUserId)->first();

        if (!$otpRecord) {
            return response()->json([
                'status' => 'error',
                'message' => 'No OTP record found for the user.',
            ], 200);
        }

        // Compare provided OTP
        if ($otpRecord->otp != $request->email_otp) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid OTP.',
            ], 400);
        }

        // Check expiration using expire_date_time column
        if (Carbon::now()->greaterThan(Carbon::parse($otpRecord->expire_date_time))) {
            return response()->json([
                'status' => 'error',
                'message' => 'OTP has expired.',
            ], 400);
        }

        // Mark OTP as verified
        DB::table('otps')->where('user_id', $authUserId)->update([
            'isOTPVerified' => true,
        ]);

        $isOTPVerifiedUser = DB::table('otps')->where('user_id', $authUserId)->where('isOTPVerified', true)->first();

        if ($isOTPVerifiedUser) {
            // ✅ Also mark user as approved
            DB::table('users')->where('id', $authUserId)->update([
                'is_otp_verified' => true,  // Or 1, depending on your column type
                'isapproved' => 1,          // Approve the user
            ]);
        }
        else {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to verify OTP.',
                ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'OTP verified successfully and user approved.',
            'user_id' => $authUserId,
            'role' => $authUser->role->name,
            'api_token' => $authUser->api_token,
        ], 200);
    }



    


    public function resendOtp(Request $request)
    {
        $user = auth()->user(); // Logged-in user check
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        // Fetch the latest OTP for this user
        $latestOtp = DB::table('otps')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();

        // If OTP exists and was created less than 1 minute ago, restrict resending
        if ($latestOtp && Carbon::parse($latestOtp->created_at)->diffInSeconds(now()) < 60) {
            return response()->json([
                'status' => false,
                'message' => 'You can resend OTP after 1 minute.',
            ], 429);
        }

        // Generate a new OTP
        $otp = rand(1000, 9999);
        $expireTime = Carbon::now()->addMinutes(10);

        // If OTP record exists → update it; otherwise → create new
        if ($latestOtp) {
            DB::table('otps')
                ->where('id', $latestOtp->id)
                ->update([
                    'otp' => $otp,
                    'isOTPVerified' => false,
                    'expire_date_time' => $expireTime,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('otps')->insert([
                'otp' => $otp,
                'user_id' => $user->id,
                'isOTPVerified' => false,
                'expire_date_time' => $expireTime,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $fullName = $user->first_name . ' ' . $user->last_name;

        // ✅ Load mail configuration dynamically
        $settings = DB::table('mail_configs')->where('status', 1)->first();
        if ($settings) {
            config([
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                'mail.mailers.smtp.encryption' => $settings->encryption,
                'mail.from.address' => $settings->from_address,
                'mail.from.name' => $settings->from_name,
            ]);
        }

        // Send OTP via email
        try {
            Mail::to($user->email)->send(new OTPMail($otp, $fullName));

            return response()->json([
                'status' => true,
                'message' => 'OTP resent successfully. Please check your email.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP email.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}
