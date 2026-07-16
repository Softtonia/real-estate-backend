<?php



namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\UniqueID;
use App\Models\UserDetail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        // Default role id set karo (maan lo 2 = Normal User)
        $defaultRoleId = 2;

        // Request me role_id aaya toh use le lo, warna default
        $roleId = $request->input('role_id', $defaultRoleId);

        if ((int) $roleId === 1) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to select this role.',
                'error' => 'Unauthorized Role',
            ], 200);
        }
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Check if user already exists
            $user = User::where('email', $googleUser->getEmail())->first();



            if (!$user) {

                $role = Role::find($roleId); // Assuming Role model exists

                if ($role) {
                    $userRoleCount = User::where('role_id', $role->id)->count();

                    if ($userRoleCount == 0) {
                        // If no users exist for this role, start from 001
                        $uniqueIDModel = new UniqueID();
                        $uniqueIDModel->unique_id = $role->prefix . str_pad(1, 3, '0', STR_PAD_LEFT);
                        $uniqueIDModel->save();
                    } else {
                        // Fetch last unique_id for this role
                        $lastUniqueID = UniqueID::where('unique_id', 'like', $role->prefix . '%')
                            ->orderBy('unique_id', 'desc')
                            ->first();

                        $lastCount = $lastUniqueID
                            ? (int) substr($lastUniqueID->unique_id, strlen($role->prefix))
                            : 0;

                        $newUniqueID = $role->prefix . str_pad($lastCount + 1, 3, '0', STR_PAD_LEFT);

                        $uniqueIDModel = new UniqueID();
                        $uniqueIDModel->unique_id = $newUniqueID;
                        $uniqueIDModel->save();
                    }
                }

                //  Generate unique username
                    $baseUsername = preg_replace('/\s+/', '', strtolower($googleUser->getName())); // remove spaces
                    $username = $baseUsername;
                    $counter = 1;

                    // Check if username already exists and increment until unique
                    while (User::where('user_name', $username)->exists()) {
                        $username = $baseUsername . $counter;
                        $counter++;
                    }

                // new user create
                $user = User::create([
                    'first_name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'user_name' => $username,
                    'google_id' => $googleUser->getId(),
                    'role_id' => $roleId,
                    'password' => Hash::make(uniqid()), // dummy password
                    'isapproved' => 1, // isapproved = 1
                    'is_otp_verified' => true, // Google se verified email milta hai
                    'kyc' => 0, // Default KYC status to Pending
                    'unique_id' => $uniqueIDModel->unique_id,
                ]);

                if($user){
                    $userDetails = UserDetail::create([
                        'user_id' => $user->id,
                        'role_id' => $roleId,
                    ]);
                }
            } else {
                // Agar user pehle se hai toh google_id aur role_id update karo
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'role_id' => $roleId ?? $user->role_id,
                ]);
            }

            // $token = $user->createToken('auth_token')->plainTextToken;

            if (!$user->api_token || !$user->token_created_at || $user->token_created_at < now()->subHours(24)) {
                $user->api_token = Str::random(80);
                $user->token_created_at = now();
                $user->save();
            }

            return response()->json([
                'status' => true,
                'message' => 'Login Successful',
                // 'user' => $user,
                // 'token' => $user->api_token,
                // 'role' => $user->role->name,
                   'data' => [
                        'user_id'   => $user->id,
                        'first_name'      => $user->first_name,
                        'role_id'   => $user->role_id,
                        'role_name' => $user->role ? $user->role->name : null,
                        'token'     => $user->api_token,
                    ]
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

