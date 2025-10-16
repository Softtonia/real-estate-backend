<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class EmailOtpController extends Controller
{
    public function generateOtp(Request $request)
    {
        // Validate the email input
        $request->validate([
            'email' => 'required|email', // Ensure a valid email is provided
        ]);

        $email = $request->input('email');

        // Check if the email exists in the users table
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Email not found in users table.',
            ], 200);
        }

        // Generate a 6-digit OTP
        $otp = rand(1000, 9999);

        // Calculate OTP expiry time (e.g., 10 minutes from now)
        $expiryTime = now()->addMinutes(10);

        // Store the OTP in the otps table
        \DB::table('otps')->insert([
            'otp' => $otp,
            'user_id' => $user->id,
            'expire_date_time' => $expiryTime,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fetch mail settings from the database
        
        $settings = \DB::table('mail_configs')->where('status', 1)->first();


        // If the mail settings are found, configure the mail settings dynamically
        if ($settings) {
            // Ensure required mail configuration data is available before applying
            config([
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                'mail.mailers.smtp.encryption' => $settings->encryption,
                'mail.from.address' => $settings->from_address,
                'mail.from.name' => $settings->from_name,
            ]);
        } else {
            return response()->json([
                'message' => 'Mail settings are not configured.',
            ], 500);
        }

        // Send the OTP via email (passing both OTP and email to the constructor)
        try {
            Mail::to($email)->send(new \App\Mail\OTPMail($otp, $email)); // Now sending both OTP and email
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send OTP email.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'OTP generated and sent successfully.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        try {
            // Validate the input manually
            $validator = \Validator::make($request->all(), [
                'email' => 'required|email',
                'otp' => 'required|numeric',
                'new_password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 400);
            }

            // Get the input values
            $email = $request->input('email');
            $otp = $request->input('otp');
            $newPassword = $request->input('new_password');

            // Check if the email exists in the users table
            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'message' => 'Email not found in users table.',
                ], 200);
            }

            // Verify the OTP from the otps table and check if it's expired
            $otpRecord = \DB::table('otps')
                ->where('user_id', $user->id)
                ->where('otp', $otp)
                ->where('expire_date_time', '>', now())
                ->first();

            if (!$otpRecord) {
                return response()->json([
                    'message' => 'Your OTP has expired or is incorrect.',
                ], 400);
            }

            // Update the user's password
            $user->password = \Hash::make($newPassword);
            $user->save();

            // Delete the OTP record after password reset
            \DB::table('otps')->where('id', $otpRecord->id)->delete();

            return response()->json([
                'message' => 'Password updated successfully.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while processing your request.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

}
