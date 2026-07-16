<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\UniqueID;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Default role assigned only to newly registered Google users.
     */
    private const DEFAULT_ROLE_ID = 2;

    /**
     * Generate Google authentication URL.
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

                // Frontend should directly open the Google URL.
                'requires_role_selection' => false,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Failed to generate Google login URL.',
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Handle Google callback.
     */
    public function handleGoogleCallback(): JsonResponse
    {
        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            $googleId = $googleUser->getId();
            $email = $googleUser->getEmail();
            $name = trim(
                $googleUser->getName()
                ?: $googleUser->getNickname()
                ?: Str::before((string) $email, '@')
            );

            if (!$googleId || !$email) {
                return response()->json([
                    'status' => false,
                    'message' => 'Google did not provide the required user information.',
                ], 422);
            }

            /*
             * First find by Google ID.
             */
            $user = User::where('google_id', $googleId)->first();

            /*
             * If Google ID is not linked yet, find the existing account
             * using the verified Google email address.
             */
            if (!$user) {
                $user = User::where('email', $email)->first();
            }

            $isNewUser = false;

            /*
             * Existing user:
             * Preserve the existing role_id.
             */
            if ($user) {
                $user->update([
                    'google_id' => $googleId,
                    'is_otp_verified' => true,

                    // Do not update role_id here.
                ]);
            } else {
                /*
                 * New user:
                 * Assign the default role completely from the backend.
                 */
                $result = DB::transaction(function () use (
                    $googleId,
                    $email,
                    $name
                ) {
                    /*
                     * Locking the role row helps prevent duplicate unique IDs
                     * when multiple users register at the same time.
                     */
                    $role = Role::whereKey(self::DEFAULT_ROLE_ID)
                        ->lockForUpdate()
                        ->first();

                    if (!$role) {
                        throw new \RuntimeException(
                            'Default registration role is not configured.'
                        );
                    }

                    if ((int) $role->id === 1) {
                        throw new \RuntimeException(
                            'Admin role cannot be used as the default registration role.'
                        );
                    }

                    if (empty($role->prefix)) {
                        throw new \RuntimeException(
                            'The default role does not have a unique ID prefix.'
                        );
                    }

                    $uniqueId = $this->generateUniqueId($role);
                    $username = $this->generateUniqueUsername($name, $email);

                    $user = User::create([
                        'first_name' => $name,
                        'email' => $email,
                        'user_name' => $username,
                        'google_id' => $googleId,
                        'role_id' => $role->id,
                        'password' => Hash::make(Str::random(40)),
                        'isapproved' => 1,
                        'is_otp_verified' => true,
                        'kyc' => 0,
                        'unique_id' => $uniqueId,
                    ]);

                    UserDetail::create([
                        'user_id' => $user->id,
                        'role_id' => $role->id,
                    ]);

                    return $user;
                });

                $user = $result;
                $isNewUser = true;
            }

            /*
             * Generate or refresh the API token after 24 hours.
             */
            $tokenExpired = !$user->token_created_at;

            if ($user->token_created_at) {
                $tokenExpired = Carbon::parse($user->token_created_at)
                    ->lte(now()->subHours(24));
            }

            if (!$user->api_token || $tokenExpired) {
                $user->forceFill([
                    'api_token' => Str::random(80),
                    'token_created_at' => now(),
                ])->save();
            }

            $user->loadMissing('role');

            return response()->json([
                'status' => true,
                'message' => $isNewUser
                    ? 'Registration and login successful.'
                    : 'Login successful.',

                /*
                 * This is always false because role selection is now
                 * completely handled by the backend.
                 */
                'requires_role_selection' => false,
                'is_new_user' => $isNewUser,

                'data' => [
                    'user_id' => $user->id,
                    'first_name' => $user->first_name,
                    'email' => $user->email,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role?->name,
                    'unique_id' => $user->unique_id,
                    'token' => $user->api_token,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Google login failed.',
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Generate a unique username.
     */
    private function generateUniqueUsername(
        string $name,
        string $email
    ): string {
        $baseUsername = Str::lower($name);

        $baseUsername = preg_replace(
            '/[^a-z0-9_]/',
            '',
            str_replace(' ', '', $baseUsername)
        );

        if (empty($baseUsername)) {
            $baseUsername = Str::lower(Str::before($email, '@'));

            $baseUsername = preg_replace(
                '/[^a-z0-9_]/',
                '',
                $baseUsername
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
     * Generate the next unique ID for the selected role.
     */
    private function generateUniqueId(Role $role): string
    {
        $prefix = (string) $role->prefix;

        $lastNumber = UniqueID::where(
            'unique_id',
            'like',
            $prefix . '%'
        )
            ->get(['unique_id'])
            ->map(function (UniqueID $uniqueId) use ($prefix) {
                return (int) substr(
                    $uniqueId->unique_id,
                    strlen($prefix)
                );
            })
            ->max() ?? 0;

        $newUniqueId = $prefix
            . str_pad(
                $lastNumber + 1,
                3,
                '0',
                STR_PAD_LEFT
            );

        $uniqueIdModel = new UniqueID();
        $uniqueIdModel->unique_id = $newUniqueId;
        $uniqueIdModel->save();

        return $newUniqueId;
    }
}