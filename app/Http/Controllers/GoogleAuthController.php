<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\UniqueID;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Generate Google login URL.
     */
    public function redirectToGoogle(): JsonResponse
    {
        try {
            $googleUrl = Socialite::driver('google')
                ->stateless()
                ->redirect()
                ->getTargetUrl();

            return response()->json([
                'status' => true,
                'message' => 'Google login URL generated successfully.',
                'url' => $googleUrl,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to generate Google login URL.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Google authentication callback.
     */
    public function handleGoogleCallback(Request $request): JsonResponse
    {
        try {
            /*
             * Get Google user information.
             */
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            $googleEmail = $googleUser->getEmail();

            if (empty($googleEmail)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Google account email was not received.',
                ], 422);
            }

            /*
             * Check user before registration.
             */
            $user = User::where('email', $googleEmail)->first();

            /*
             * This will be true only when the email is not registered.
             */
            $isNewUser = $user === null;

            if ($isNewUser) {
                /*
                 * Default role is used only while creating a new user.
                 */
                $defaultRoleId = 2;

                $roleId = (int) $request->input(
                    'role_id',
                    $defaultRoleId
                );

                /*
                 * Role 1 should not be selectable.
                 */
                if ($roleId === 1) {
                    return response()->json([
                        'status' => false,
                        'message' => 'You are not allowed to select this role.',
                        'error' => 'Unauthorized Role',
                    ], 403);
                }

                $role = Role::find($roleId);

                if (!$role) {
                    return response()->json([
                        'status' => false,
                        'message' => 'The selected role does not exist.',
                        'error' => 'Invalid Role',
                    ], 422);
                }

                if (empty($role->prefix)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Prefix is not configured for the selected role.',
                    ], 422);
                }

                /*
                 * Create user, unique ID and user details together.
                 */
                $user = DB::transaction(function () use (
                    $googleUser,
                    $googleEmail,
                    $role,
                    $roleId
                ) {
                    $username = $this->generateUsername(
                        $googleUser->getName(),
                        $googleEmail
                    );

                    $uniqueId = $this->generateUniqueId($role);

                    /*
                     * Save generated unique ID.
                     */
                    $uniqueIDModel = new UniqueID();
                    $uniqueIDModel->unique_id = $uniqueId;
                    $uniqueIDModel->save();

                    /*
                     * Create new user.
                     */
                    $createdUser = User::create([
                        'first_name' => $googleUser->getName(),
                        'email' => $googleEmail,
                        'user_name' => $username,
                        'google_id' => $googleUser->getId(),
                        'role_id' => $roleId,
                        'password' => Hash::make(Str::random(40)),
                        'isapproved' => 1,
                        'is_otp_verified' => true,
                        'kyc' => 0,
                        'unique_id' => $uniqueId,
                    ]);

                    /*
                     * Create user detail record.
                     */
                    UserDetail::create([
                        'user_id' => $createdUser->id,
                        'role_id' => $roleId,
                    ]);

                    return $createdUser;
                });
            } else {
                /*
                 * Existing user:
                 * Only update Google ID.
                 *
                 * Do not update role_id here because an existing user's
                 * role should not change on every Google login.
                 */
                $user->update([
                    'google_id' => $googleUser->getId(),
                ]);
            }

            /*
             * Generate a new token when:
             * 1. Token does not exist
             * 2. Token creation time does not exist
             * 3. Token is older than 24 hours
             */
            $tokenExpired = empty($user->api_token)
                || empty($user->token_created_at)
                || Carbon::parse($user->token_created_at)
                    ->lt(now()->subHours(24));

            if ($tokenExpired) {
                $user->api_token = Str::random(80);
                $user->token_created_at = now();
                $user->save();
            }

            /*
             * Load role relationship for response.
             */
            $user->load('role');

            return response()->json([
                'status' => true,
                'message' => $isNewUser
                    ? 'Registration successful. Please select your role.'
                    : 'Login successful.',
                'data' => [
                    'user_id' => $user->id,
                    'first_name' => $user->first_name,
                    'email' => $user->email,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role?->name,
                    'token' => $user->api_token,

                    /*
                     * Frontend must check these fields.
                     */
                    'is_new_user' => $isNewUser,
                    'show_role_selection' => $isNewUser,

                    /*
                     * You can directly use this for redirect.
                     */
                    'redirect_to' => $isNewUser
                        ? '/set-role'
                        : '/dashboard',
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Google login failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate unique username.
     */
    private function generateUsername(
        ?string $googleName,
        string $googleEmail
    ): string {
        $baseUsername = Str::lower(
            preg_replace('/[^a-zA-Z0-9]/', '', $googleName ?? '')
        );

        /*
         * Use email name when Google name produces an empty username.
         */
        if (empty($baseUsername)) {
            $emailUsername = explode('@', $googleEmail)[0];

            $baseUsername = Str::lower(
                preg_replace('/[^a-zA-Z0-9]/', '', $emailUsername)
            );
        }

        if (empty($baseUsername)) {
            $baseUsername = 'user';
        }

        $username = $baseUsername;
        $counter = 1;

        while (User::where('user_name', $username)->exists()) {
            $username = $baseUsername . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Generate role-prefix-based unique ID.
     *
     * Example:
     * OWN001
     * OWN002
     */
    private function generateUniqueId(Role $role): string
    {
        $prefix = trim($role->prefix);

        $lastUniqueID = UniqueID::where(
            'unique_id',
            'like',
            $prefix . '%'
        )
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $lastNumber = 0;

        if ($lastUniqueID) {
            $numberPart = substr(
                $lastUniqueID->unique_id,
                strlen($prefix)
            );

            $lastNumber = is_numeric($numberPart)
                ? (int) $numberPart
                : 0;
        }

        return $prefix . str_pad(
            $lastNumber + 1,
            3,
            '0',
            STR_PAD_LEFT
        );
    }
}