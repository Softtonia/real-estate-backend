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
     * Generate the Google authentication URL.
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
     * Receive Google callback forwarded by the Next.js callback page.
     */
    public function handleGoogleCallback(
        Request $request
    ): JsonResponse {
        try {
            if (!$request->filled('code')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Google authorization code is missing.',
                ], 422);
            }

            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            $googleEmail = $googleUser->getEmail();

            if (!$googleEmail) {
                return response()->json([
                    'status' => false,
                    'message' => 'Google account email was not received.',
                ], 422);
            }

            /*
             * Check before creating the user.
             */
            $user = User::where('email', $googleEmail)->first();

            $isNewUser = $user === null;

            if ($isNewUser) {
                /*
                 * Temporary/default role for new registration.
                 * The user can select their final role on /set-role.
                 */
                $defaultRoleId = 2;

                $role = Role::find($defaultRoleId);

                if (!$role) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Default registration role does not exist.',
                    ], 422);
                }

                if (!$role->prefix) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Default role prefix is not configured.',
                    ], 422);
                }

                $user = DB::transaction(function () use (
                    $googleUser,
                    $googleEmail,
                    $defaultRoleId,
                    $role
                ) {
                    /*
                     * Check again inside transaction.
                     */
                    $existingUser = User::where(
                        'email',
                        $googleEmail
                    )
                        ->lockForUpdate()
                        ->first();

                    if ($existingUser) {
                        return $existingUser;
                    }

                    $username = $this->generateUsername(
                        $googleUser->getName(),
                        $googleEmail
                    );

                    $uniqueId = $this->generateUniqueId($role);

                    UniqueID::create([
                        'unique_id' => $uniqueId,
                    ]);

                    $createdUser = User::create([
                        'first_name' => $googleUser->getName(),
                        'email' => $googleEmail,
                        'user_name' => $username,
                        'google_id' => $googleUser->getId(),
                        'role_id' => $defaultRoleId,
                        'password' => Hash::make(
                            Str::random(40)
                        ),
                        'isapproved' => 1,
                        'is_otp_verified' => true,
                        'kyc' => 0,
                        'unique_id' => $uniqueId,
                    ]);

                    UserDetail::create([
                        'user_id' => $createdUser->id,
                        'role_id' => $defaultRoleId,
                    ]);

                    return $createdUser;
                });
            } else {
                /*
                 * Existing user:
                 * Never overwrite the existing role during login.
                 */
                $user->update([
                    'google_id' => $googleUser->getId(),
                ]);
            }

            $tokenExpired =
                !$user->api_token ||
                !$user->token_created_at ||
                Carbon::parse($user->token_created_at)
                    ->lt(now()->subHours(24));

            if ($tokenExpired) {
                $user->forceFill([
                    'api_token' => Str::random(80),
                    'token_created_at' => now(),
                ])->save();
            }

            $user->load('role');

            return response()->json([
                'status' => true,
                'message' => $isNewUser
                    ? 'Google registration successful.'
                    : 'Google login successful.',
                'data' => [
                    'user_id' => $user->id,
                    'first_name' => $user->first_name,
                    'email' => $user->email,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role?->name,
                    'token' => $user->api_token,

                    /*
                     * True only during first registration.
                     */
                    'is_new_user' => $isNewUser,
                    'show_role_selection' => $isNewUser,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Google login failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a unique username.
     */
    private function generateUsername(
        ?string $googleName,
        string $googleEmail
    ): string {
        $baseUsername = Str::lower(
            preg_replace(
                '/[^a-zA-Z0-9]/',
                '',
                $googleName ?? ''
            )
        );

        if (!$baseUsername) {
            $emailUsername = explode('@', $googleEmail)[0];

            $baseUsername = Str::lower(
                preg_replace(
                    '/[^a-zA-Z0-9]/',
                    '',
                    $emailUsername
                )
            );
        }

        if (!$baseUsername) {
            $baseUsername = 'user';
        }

        $username = $baseUsername;
        $counter = 1;

        while (
            User::where('user_name', $username)->exists()
        ) {
            $username = $baseUsername . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Generate role-based unique ID.
     */
    private function generateUniqueId(Role $role): string
    {
        $prefix = trim($role->prefix);

        $lastUniqueId = UniqueID::where(
            'unique_id',
            'like',
            $prefix . '%'
        )
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $lastNumber = 0;

        if ($lastUniqueId) {
            $numberPart = substr(
                $lastUniqueId->unique_id,
                strlen($prefix)
            );

            if (is_numeric($numberPart)) {
                $lastNumber = (int) $numberPart;
            }
        }

        return $prefix . str_pad(
            $lastNumber + 1,
            3,
            '0',
            STR_PAD_LEFT
        );
    }
}