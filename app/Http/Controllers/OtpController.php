<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Otp;
use App\Jobs\SendOtpMailJob;

class OtpController extends Controller
{
    public function emailVerifyOtp(Request $request)
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'User not authenticated.',
            ], 401);
        }

        $request->validate([
            'email_otp' => 'required|numeric',
        ]);

        $otpRecord = Otp::where('user_id', $authUser->id)
            ->latest()
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'status' => false,
                'message' => 'No OTP record found for this user.',
            ], 404);
        }

        if ($otpRecord->isOTPVerified) {
            return response()->json([
                'status' => true,
                'message' => 'OTP already verified.',
            ], 200);
        }

        if ($otpRecord->otp != $request->email_otp) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP.',
            ], 400);
        }

        if (Carbon::now()->greaterThan($otpRecord->expire_date_time)) {
            return response()->json([
                'status' => false,
                'message' => 'OTP has expired.',
            ], 400);
        }

        DB::transaction(function () use ($authUser, $otpRecord) {
            $otpRecord->update([
                'isOTPVerified' => true,
            ]);

            User::where('id', $authUser->id)->update([
                'is_otp_verified' => 1,
                'isapproved' => 1,
            ]);
        });

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully and user approved.',
            'data' => [
                'user_id' => $authUser->id,
                'role' => $authUser->role->name ?? null,
                'api_token' => $authUser->api_token,
            ]
        ], 200);
    }

    public function resendOtp(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not authenticated.',
            ], 401);
        }

        $latestOtp = Otp::where('user_id', $user->id)
            ->latest()
            ->first();

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

        $fullName = trim($user->first_name . ' ' . $user->last_name);

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