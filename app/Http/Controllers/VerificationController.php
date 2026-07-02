<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function verifyEmail(Request $request, $id, $code)
    {
        $user = User::findOrFail($id);

        if ($user->verification_code === $code && Carbon::now()->lt($user->verification_code_expires_at)) {
            $user->email_verified_at = now();
            $user->save();

            return response()->json(['message' => 'Email verified successfully'], 200);
        }

        return response()->json(['message' => 'Invalid verification code or expired'], 400);
    }

    public function sendVerificationEmail(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email is already verified'], 400);
        }

        $verificationCode = Str::random(6);
        $expiresAt = Carbon::now()->addMinutes(10);

        $user->update([
            'verification_code' => $verificationCode,
            'verification_code_expires_at' => $expiresAt,
        ]);

        $verificationUrl = route('api.verify-email', ['id' => $userId, 'code' => $verificationCode]);

        $data = [
            'user' => $user,
            'verificationUrl' => $verificationUrl,
        ];

        Mail::send('emails.verify_email', $data, function ($message) use ($user) {
            $message->to($user->email)->subject('Email Verification');
        });

        return response()->json(['message' => 'Verification email sent successfully'], 200);
    }
}
