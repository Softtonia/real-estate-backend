<?php

// namespace App\Http\Controllers;

// use Illuminate\Support\Facades\Hash;
// use Laravel\Socialite\Facades\Socialite;
// use App\Http\Controllers\Controller;
// use Illuminate\Http\Request;
// use App\Models\User;
// use Illuminate\Support\Facades\Auth;

// class GoogleAuthController extends Controller
// {
//     public function redirectToGoogle()
//     {
//         try {
//             $googleUrl = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();

//             return response()->json([
//                 'status' => true,
//                 'message' => 'Google login URL generated successfully.',
//                 'url' => $googleUrl,
//             ], 200);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'status' => false,
//                 'message' => 'Failed to generate Google login URL.',
//                 'error' => $e->getMessage(),
//             ], 500);
//         }
//     }

//     public function handleGoogleCallback()
//     {
//         try {
//             $googleUser = Socialite::driver('google')->stateless()->user();

//             // \Log::info('Google User Data:',  [$googleUser]);
//             // dd($googleUser);

//             // Check if user exists
//             $user = User::where('email', $googleUser->email)->first();

//             if (!$user) {
//                 // Create new user
//                 $user = User::create([
//                     'name' => $googleUser->name,
//                     'email' => $googleUser->email,
//                     'google_id' => $googleUser->id,
//                     'password' => bcrypt(uniqid()), // Dummy password
//                 ]);
//             }

//             // Generate token
//             $token = $user->createToken('auth_token')->plainTextToken;

//             return response()->json([
//                 'status' => true,
//                 'message' => 'Login Successful',
//                 'user' => $user,
//                 'token' => $token
//             ], 200);

//         } catch (\Exception $e) {
//             return response()->json(['error' => 'Google login failed'], 500);
//         }
//     }
// }




namespace App\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class GoogleAuthController extends Controller
{
    public function redirectToGoogle()
    {
        try {
            $googleUrl = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();

            return response()->json([
                'status' => true,
                'message' => 'Google login URL generated successfully.',
                'url' => $googleUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to generate Google login URL.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            $user = User::firstOrCreate(
                ['email' => $googleUser->getEmail()],
                [
                    'first_name' => $googleUser->getName(),
                    'google_id' => $googleUser->getId(),
                    'password' => Hash::make(uniqid()), // dummy password
                ]
            );

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => true,
                'message' => 'Login Successful',
                'user' => $user,
                'token' => $token
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Google login failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

