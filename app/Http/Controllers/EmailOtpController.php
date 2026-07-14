<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\SendOtpMailJob;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EmailOtpController extends Controller
{
    /**
     * Generate OTP and send it through queued email.
     */
    public function generateOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $email = strtolower(trim($request->input('email')));

            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email not found in users table.',
                ], 404);
            }

            // Prevent repeated OTP requests for 1 minute.
            $cacheKey = "otp_request_{$user->id}";

            if (Cache::has($cacheKey)) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP was recently sent. Please wait before requesting again.',
                ], 429);
            }

            // Generate secure 4-digit OTP.
            $otp = random_int(1000, 9999);
            $expiryTime = Carbon::now()->addMinutes(10);

            DB::table('otps')->updateOrInsert(
                [
                    'user_id' => $user->id,
                ],
                [
                    'otp' => $otp,
                    'expire_date_time' => $expiryTime,
                    'isOTPVerified' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // Limit OTP requests for 60 seconds.
            Cache::put($cacheKey, true, now()->addSeconds(60));

            // Cache active mail configuration for 5 minutes.
            $settings = Cache::remember('mail_config', 300, function () {
                return DB::table('mail_configs')
                    ->where('status', 1)
                    ->first();
            });

            if (!$settings) {
                Cache::forget($cacheKey);

                return response()->json([
                    'status' => false,
                    'message' => 'Mail settings are not configured.',
                ], 500);
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                'mail.mailers.smtp.encryption' => $settings->encryption,
                'mail.from.address' => $settings->from_address,
                'mail.from.name' => $settings->from_name,
            ]);

            $fullName = trim(
                ($user->first_name ?? '') . ' ' . ($user->last_name ?? '')
            );

            SendOtpMailJob::dispatch(
                $otp,
                $user->email,
                $fullName
            );

            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully.',
            ], 200);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Unable to send OTP. Please try again.',
            ], 500);
        }
    }

    /**
     * Reset password using OTP.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:4'],
            'new_password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $email = strtolower(trim($request->input('email')));

            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email not found in users table.',
                ], 404);
            }

            $otpRecord = DB::table('otps')
                ->where('user_id', $user->id)
                ->where('otp', $request->input('otp'))
                ->where('expire_date_time', '>', Carbon::now())
                ->first();

            if (!$otpRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP expired or invalid.',
                ], 400);
            }

            DB::transaction(function () use ($user, $request, $otpRecord) {
                $user->password = Hash::make($request->input('new_password'));
                $user->save();

                DB::table('otps')
                    ->where('id', $otpRecord->id)
                    ->delete();
            });

            // Allow the user to request another OTP after password reset.
            Cache::forget("otp_request_{$user->id}");

            return response()->json([
                'status' => true,
                'message' => 'Password updated successfully.',
            ], 200);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Unable to update password. Please try again.',
            ], 500);
        }
    }
}