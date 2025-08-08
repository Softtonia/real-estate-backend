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

            // Default role id set karo (maan lo 2 = Normal User)
            $defaultRoleId = 2;

            // Request me role_id aaya toh use le lo, warna default
            $roleId = $request->input('role_id', $defaultRoleId);

            // Check if user already exists
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                // Naya user create karo
                $user = User::create([
                    'first_name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'role_id' => $roleId,
                    'password' => Hash::make(uniqid()), // dummy password
                    'isapproved' => 1, // isapproved = 1
                ]);
            } else {
                // Agar user pehle se hai toh google_id aur role_id update karo
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'role_id' => $roleId ?? $user->role_id,
                ]);
            }

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

