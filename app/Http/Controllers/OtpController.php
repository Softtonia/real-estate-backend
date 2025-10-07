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
    // Send OTP API
    public function sendOtp(Request $request)
    {
        $phone = $request->input('phoneNumber');
        $userPhoneNumber = User::where('phone', preg_replace('/^\+91/', '', $request->input('phoneNumber')))->first();

        // Ensure the phone number is provided
        if (!$phone) {
            return response()->json(['status' => false, 'message' => 'Phone number is required'], 400);
        }


        if ($userPhoneNumber) {
            // Initialize cURL and configure request options
            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL => "https://auth.otpless.app/auth/v1/initiate/otp",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode([
                    "phoneNumber" => $phone,
                    "expiry" => 30, // OTP expiry in minutes
                    "otpLength" => 4, // OTP length
                    "channels" => ["SMS"], // OTP channels
                    // "metadata" => [
                    //     "key1" => "Data1",
                    // ]
                ]),
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "clientId: " . env('OTPLESS_CLIENT_ID'),
                    "clientSecret: " . env('OTPLESS_CLIENT_SECRET')
                ],
            ]);

            // Execute cURL request and capture response
            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);

            if ($err) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to send OTP',
                    'error' => "cURL Error: $err",
                ], 500);
            } else {
                $responseData = json_decode($response, true);

                // Check if response contains a requestId
                if (isset($responseData['requestId'])) {

                    $userPhoneNumber->update([
                        'requestId' => $responseData['requestId'],
                        'updated_at' => Carbon::now(),
                    ]);
                    // Save OTP request details in the database
                    DB::table('otps')->insert([
                        'phone' => $phone,
                        'requestId' => $responseData['requestId'],
                        'otp' => null,
                        'user_id' => $userPhoneNumber->id ?? null,
                        'isOTPVerified' => false,
                    ]);

                    return response()->json([
                        'status' => true,
                        'message' => 'OTP sent successfully',
                        'data' => $responseData
                    ], 200);
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'Failed to send OTP',
                        'error' => $responseData // Contains the error response from Otpless
                    ], 500);
                }
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Phone number is not registered',
            ], 404);
        }
    }


    public function verifyOtp(Request $request)
    {

        $otp = $request->input('otp');
        $userPhoneNumber = User::where('requestId', $request->requestId)->first();


        if ($userPhoneNumber) {
            // Initialize cURL and configure request options
            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL => "https://auth.otpless.app/auth/v1/verify/otp",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode([
                    "requestId" => $userPhoneNumber->requestId,
                    "otp" => $otp,
                    "channels" => ["SMS"]  // Only SMS channel is specified here
                ]),
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "clientId: " . env('OTPLESS_CLIENT_ID'),
                    "clientSecret: " . env('OTPLESS_CLIENT_SECRET')
                ],
            ]);

            // Execute cURL request and capture response
            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);

            if ($err) {
                return response()->json(['status' => false, 'message' => "cURL Error: $err"], 500);
            }

            $responseData = json_decode($response, true);
            if ($responseData['message'] == 'Expired') {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP expired',
                ], 400);
            }
            if (isset($responseData['errorCode']) == '7118') {
                return response()->json([
                    'status' => false,
                    'message' => 'Incorrect OTP!',
                ], 400);
            }
            // Update OTP as verified if response is valid
            DB::table('otps')->where('requestId', $userPhoneNumber->requestId)->update([
                'isOTPVerified' => true,
                'otp' => $otp, // Store the verified OTP for future reference
            ]);

            return response()->json(['status' => true, 'message' => 'OTP verified successfully', 'data' => $userPhoneNumber], 200);
        } else {
            // Return specific message for incorrect or invalid OTP
            return response()->json([
                'status' => false,
                'message' => 'OTP verification failed. The OTP may be incorrect or expired.',
            ], 400);
        }
    }

    // original
    // public function emailVerifyOtp(Request $request)
    // {
    //     $request->validate([
    //         'user_id' => 'required|exists:users,id',
    //         'email_otp' => 'required|numeric',
    //     ]);

    //     // Fetch the OTP record for the user
    //     $otpRecord = DB::table('otps')->where('user_id', $request->user_id)->first();

    //     if (!$otpRecord) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'No OTP record found for the user.',
    //         ], 404);
    //     }

    //     if ($otpRecord->otp == $request->email_otp) {
    //         // Check if OTP has expired (assuming you store expiration time in `created_at` or a similar column)
    //         $otpExpirationTime = Carbon::parse($otpRecord->created_at)->addMinutes(10); // Example: OTP valid for 10 minutes

    //         if (Carbon::now()->greaterThan($otpExpirationTime)) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'OTP has expired.',
    //             ], 400);
    //         }

    //         // Mark OTP as verified
    //         DB::table('otps')->where('user_id', $request->user_id)->update([
    //             'isOTPVerified' => true,
    //         ]);

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'OTP verified successfully.',
    //         ], 200);
    //     }

    //     return response()->json([
    //         'status' => 'error',
    //         'message' => 'Invalid OTP.',
    //     ], 400);
    // }

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



    // public function emailVerifyOtp(Request $request)
    // {
    //     $user = User::where('id', $request->user_id)->first();
    //     // dd($user);
    //     if ($user && $user->email_otp == $request->email_otp) {

    //         if (Carbon::now()->lessThanOrEqualTo($user->email_otp_expires_at)) {

    //             return response()->json([
    //                 'status' => 'success',
    //                 'message' => 'OTP verified successfully'
    //             ], 200);
    //         } else {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'OTP has expired'
    //             ], 400);
    //         }
    //     }

    //     return response()->json([
    //         'status' => 'error',
    //         'message' => 'Invalid OTP'
    //     ], 400);
    // }


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
