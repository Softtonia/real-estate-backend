<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Jobs\SendOtpMailJob;
use Carbon\Carbon;

class EmailOtpController extends Controller
{
    /**
     * Generate OTP and send via queued email
     */
    public function generateOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = $request->input('email');

        // Fetch user once
        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'Email not found in users table.',
            ], 200);
        }

        // Cache key to limit OTP generation frequency (1 min)
        $cacheKey = "otp_request_{$user->id}";
        if (Cache::has($cacheKey)) {
            return response()->json([
                'message' => 'OTP was recently sent. Please wait before requesting again.',
            ], 429);
        }

        // Generate 4-digit OTP and expiry
        $otp = rand(1000, 9999);
        $expiryTime = Carbon::now()->addMinutes(10);

        // Insert or update OTP in DB
        DB::table('otps')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'otp' => $otp,
                'expire_date_time' => $expiryTime,
                'isOTPVerified' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        // Store OTP request in cache for 1 minute to prevent spam
        Cache::put($cacheKey, true, 60);

        // Load dynamic mail config once and cache for 5 minutes
        $settings = Cache::remember('mail_config', 300, function () {
            return DB::table('mail_configs')->where('status', 1)->first();
        });

        if (!$settings) {
            return response()->json([
                'message' => 'Mail settings are not configured.',
            ], 500);
        }

        // Configure mail dynamically
        config([
            'mail.mailers.smtp.host' => $settings->host,
            'mail.mailers.smtp.port' => $settings->port,
            'mail.mailers.smtp.username' => $settings->username,
            'mail.mailers.smtp.password' => $settings->password,
            'mail.mailers.smtp.encryption' => $settings->encryption,
            'mail.from.address' => $settings->from_address,
            'mail.from.name' => $settings->from_name,
        ]);

        // Dispatch OTP email asynchronously
        SendOtpMailJob::dispatch($otp, $user->email, $user->first_name . ' ' . $user->last_name);

        return response()->json([
            'message' => 'OTP sent successfully.',
        ]);
    }

    /**
     * Reset password using OTP
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|numeric',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        // Fetch user once
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'Email not found in users table.',
            ], 200);
        }

        // Fetch OTP record with expiry check
        $otpRecord = DB::table('otps')
            ->where('user_id', $user->id)
            ->where('otp', $request->otp)
            ->where('expire_date_time', '>', Carbon::now())
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'message' => 'OTP expired or invalid.',
            ], 400);
        }

        // Update user password
        $user->password = Hash::make($request->new_password);
        $user->save();

        // Delete OTP record
        DB::table('otps')->where('id', $otpRecord->id)->delete();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}