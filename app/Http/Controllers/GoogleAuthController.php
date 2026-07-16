<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\UniqueID;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Failed to generate Google login URL.',
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Google will call this backend method directly.
     */
    public function handleGoogleCallback()
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
                return redirect()->away(
                    config('app.frontend_url')
                    . '/auth/login?google_error=missing_information'
                );
            }

            /*
             * Check using both google_id and email.
             */
            $user = User::query()
                ->where('google_id', $googleId)
                ->orWhere('email', $email)
                ->first();

            /*
             * EXISTING USER:
             * Do not show role-selection page.
             */
            if ($user) {
                $user->forceFill([
                    'google_id' => $googleId,
                    'is_otp_verified' => true,

                    // Do not update role_id.
                ])->save();

                /*
                 * Create temporary one-time login code.
                 * Do not put permanent API token directly in the URL.
                 */
                $loginCode = Str::random(64);

                Cache::put(
                    'google_login:' . $loginCode,
                    $user->id,
                    now()->addMinutes(3)
                );

                return redirect()->away(
                    config('app.frontend_url')
                    . '/auth/google-success?code='
                    . urlencode($loginCode)
                );
            }

            /*
             * NEW USER:
             * Store Google details temporarily.
             */
            $registrationToken = Str::random(64);

            Cache::put(
                'google_registration:' . $registrationToken,
                [
                    'google_id' => $googleId,
                    'email' => $email,
                    'name' => $name,
                ],
                now()->addMinutes(15)
            );

            /*
             * Only new users reach the role-selection page.
             */
            return redirect()->away(
                config('app.frontend_url')
                . '/auth/login/googlecallback?registration_token='
                . urlencode($registrationToken)
            );
        } catch (Throwable $e) {
            report($e);

            return redirect()->away(
                config('app.frontend_url')
                . '/auth/login?google_error=login_failed'
            );
        }
    }

    /**
     * Existing user exchanges one-time code for API token.
     */
    public function exchangeGoogleLoginCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        /*
         * pull() reads and removes the code.
         * The code can only be used once.
         */
        $userId = Cache::pull(
            'google_login:' . $request->input('code')
        );

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'Google login code is invalid or expired.',
            ], 401);
        }

        $user = User::with('role')->find($userId);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User account was not found.',
            ], 404);
        }

        $this->createOrRefreshApiToken($user);

        return response()->json([
            'status' => true,
            'message' => 'Login successful.',
            'is_new_user' => false,
            'requires_role_selection' => false,
            'data' => $this->userResponse($user),
        ]);
    }

    /**
     * Complete registration after a new user selects a role.
     */
    public function completeGoogleRegistration(
        Request $request
    ): JsonResponse {
        $request->validate([
            'registration_token' => ['required', 'string'],
            'role_id' => ['required', 'integer'],
        ]);

        $registrationToken = $request->input(
            'registration_token'
        );

        $googleData = Cache::get(
            'google_registration:' . $registrationToken
        );

        if (!$googleData) {
            return response()->json([
                'status' => false,
                'message' => 'Registration session expired. Please login with Google again.',
            ], 401);
        }

        /*
         * Role 1/admin cannot be selected.
         */
        $role = Role::query()
            ->whereKey($request->integer('role_id'))
            ->where('id', '!=', 1)
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'The selected role is invalid.',
            ], 422);
        }

        try {
            /*
             * Check again in case user was created in another request.
             */
            $existingUser = User::query()
                ->where('google_id', $googleData['google_id'])
                ->orWhere('email', $googleData['email'])
                ->first();

            if ($existingUser) {
                /*
                 * Never replace an existing user's role.
                 */
                $existingUser->forceFill([
                    'google_id' => $googleData['google_id'],
                    'is_otp_verified' => true,
                ])->save();

                Cache::forget(
                    'google_registration:' . $registrationToken
                );

                $this->createOrRefreshApiToken($existingUser);

                $existingUser->loadMissing('role');

                return response()->json([
                    'status' => true,
                    'message' => 'Login successful.',
                    'is_new_user' => false,
                    'requires_role_selection' => false,
                    'data' => $this->userResponse($existingUser),
                ]);
            }

            $user = DB::transaction(function () use (
                $googleData,
                $role
            ) {
                /*
                 * Lock role while creating the role-based unique ID.
                 */
                $lockedRole = Role::query()
                    ->whereKey($role->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $uniqueId = $this->generateUniqueId($lockedRole);

                $username = $this->generateUniqueUsername(
                    $googleData['name'],
                    $googleData['email']
                );

                $user = User::create([
                    'first_name' => $googleData['name'],
                    'email' => $googleData['email'],
                    'user_name' => $username,
                    'google_id' => $googleData['google_id'],
                    'role_id' => $lockedRole->id,
                    'password' => Hash::make(Str::random(40)),
                    'isapproved' => 1,
                    'is_otp_verified' => true,
                    'kyc' => 0,
                    'unique_id' => $uniqueId,
                ]);

                UserDetail::create([
                    'user_id' => $user->id,
                    'role_id' => $lockedRole->id,
                ]);

                return $user;
            });

            Cache::forget(
                'google_registration:' . $registrationToken
            );

            $this->createOrRefreshApiToken($user);

            $user->loadMissing('role');

            return response()->json([
                'status' => true,
                'message' => 'Registration and login successful.',
                'is_new_user' => true,
                'requires_role_selection' => false,
                'data' => $this->userResponse($user),
            ], 201);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Google registration failed.',
                'error' => app()->isLocal()
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Create or refresh API token.
     */
    private function createOrRefreshApiToken(User $user): void
    {
        $tokenExpired = !$user->token_created_at;

        if ($user->token_created_at) {
            $tokenExpired = Carbon::parse(
                $user->token_created_at
            )->lte(now()->subHours(24));
        }

        if (!$user->api_token || $tokenExpired) {
            $user->forceFill([
                'api_token' => Str::random(80),
                'token_created_at' => now(),
            ])->save();
        }
    }

    /**
     * Generate unique username.
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

        if (!$baseUsername) {
            $baseUsername = preg_replace(
                '/[^a-z0-9_]/',
                '',
                Str::lower(Str::before($email, '@'))
            );
        }

        $baseUsername = $baseUsername ?: 'user';

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
        if (!$role->prefix) {
            throw new \RuntimeException(
                'Selected role does not have a prefix.'
            );
        }

        $lastUniqueId = UniqueID::query()
            ->where(
                'unique_id',
                'like',
                $role->prefix . '%'
            )
            ->lockForUpdate()
            ->orderBy('unique_id', 'desc')
            ->first();

        $lastNumber = $lastUniqueId
            ? (int) substr(
                $lastUniqueId->unique_id,
                strlen($role->prefix)
            )
            : 0;

        $newUniqueId = $role->prefix
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

    /**
     * Standard login response.
     */
    private function userResponse(User $user): array
    {
        return [
            'user_id' => $user->id,
            'first_name' => $user->first_name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'role_name' => $user->role?->name,
            'unique_id' => $user->unique_id,
            'token' => $user->api_token,
        ];
    }
}