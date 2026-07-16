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
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Failed to generate Google login URL.',
                'error' => app()->isLocal()
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Google callback.
     *
     * Existing user:
     * Redirect directly to google-success.
     *
     * New user:
     * Redirect to role-selection page.
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            $googleId = $googleUser->getId();
            $email = strtolower(trim((string) $googleUser->getEmail()));

            if (!$googleId || !$email) {
                return $this->frontendRedirect(
                    '/auth/login',
                    [
                        'google_error' => 'missing_information',
                    ]
                );
            }

            /*
             * Correctly separate Google first name and last name.
             *
             * Example:
             * Google name: Vijay Kumar
             * first_name: Vijay
             * last_name: Kumar
             */
            $googleNames = $this->extractGoogleNames(
                $googleUser,
                $email
            );

            /*
             * Find existing user using Google ID or email.
             */
            $user = User::query()
                ->where('google_id', $googleId)
                ->orWhereRaw('LOWER(email) = ?', [$email])
                ->first();

            /*
             * Existing registered user.
             */
            if ($user) {
                $updateData = [
                    'google_id' => $googleId,
                    'is_otp_verified' => true,
                ];

                /*
                 * Do not overwrite names manually updated by the user.
                 * Only fill them when missing.
                 */
                if (empty($user->first_name)) {
                    $updateData['first_name'] =
                        $googleNames['first_name'];
                }

                if (
                    empty($user->last_name) &&
                    !empty($googleNames['last_name'])
                ) {
                    $updateData['last_name'] =
                        $googleNames['last_name'];
                }

                /*
                 * Never update role_id for an existing user.
                 */
                $user->forceFill($updateData)->save();

                /*
                 * Create one-time login code in Redis.
                 */
                $loginCode = Str::random(64);

                Cache::store('redis')->put(
                    'google_login:' . $loginCode,
                    $user->id,
                    now()->addMinutes(5)
                );

                /*
                 * Existing user does not visit the role-selection page.
                 */
                return $this->frontendRedirect(
                    '/auth/google-success',
                    [
                        'is_registered' => 'true',
                        'code' => $loginCode,
                    ]
                );
            }

            /*
             * User is not registered.
             * Store Google details temporarily in Redis.
             */
            $registrationToken = Str::random(64);

            Cache::store('redis')->put(
                'google_registration:' . $registrationToken,
                [
                    'google_id' => $googleId,
                    'email' => $email,
                    'first_name' => $googleNames['first_name'],
                    'last_name' => $googleNames['last_name'],
                    'full_name' => $googleNames['full_name'],
                ],
                now()->addMinutes(15)
            );

            /*
             * Only a new user is sent to role selection.
             */
            return $this->frontendRedirect(
                '/auth/login/googlecallback',
                [
                    'is_registered' => 'false',
                    'registration_token' => $registrationToken,
                ]
            );
        } catch (Throwable $e) {
            report($e);

            return $this->frontendRedirect(
                '/auth/login',
                [
                    'google_error' => 'login_failed',
                ]
            );
        }
    }

    /**
     * Existing registered user exchanges one-time code
     * for the actual API token.
     */
    public function exchangeGoogleLoginCode(
        Request $request
    ): JsonResponse {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'min:20',
                'max:255',
            ],
        ]);

        /*
         * Pull reads and deletes the Redis key.
         * The code can only be used once.
         */
        $userId = Cache::store('redis')->pull(
            'google_login:' . $validated['code']
        );

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'Google login code is invalid or expired.',
                'is_registered' => false,
            ], 401);
        }

        $user = User::with('role')->find($userId);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User account was not found.',
                'is_registered' => false,
            ], 404);
        }

        $this->createOrRefreshApiToken($user);

        $user->loadMissing('role');

        return response()->json([
            'status' => true,
            'message' => 'Login successful.',
            'is_registered' => true,
            'requires_role_selection' => false,
            'data' => $this->userResponse($user),
        ]);
    }

    /**
     * Register a new Google user after role selection.
     */
    public function completeGoogleRegistration(
        Request $request
    ): JsonResponse {
        $validated = $request->validate([
            'registration_token' => [
                'required',
                'string',
                'min:20',
                'max:255',
            ],
            'role_id' => [
                'required',
                'integer',
            ],
        ]);

        $registrationToken =
            $validated['registration_token'];

        $googleData = Cache::store('redis')->get(
            'google_registration:' . $registrationToken
        );

        if (!$googleData) {
            return response()->json([
                'status' => false,
                'message' => 'Registration session has expired. Please log in with Google again.',
                'is_registered' => false,
            ], 401);
        }

        /*
         * Prevent the user from selecting admin role.
         */
        if ((int) $validated['role_id'] === 1) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to select this role.',
                'is_registered' => false,
            ], 403);
        }

        $role = Role::query()
            ->whereKey($validated['role_id'])
            ->where('id', '!=', 1)
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'The selected role is invalid.',
                'is_registered' => false,
            ], 422);
        }

        try {
            /*
             * Check again before registration.
             *
             * This prevents duplicate users when the same request
             * is submitted more than once.
             */
            $existingUser = User::query()
                ->where(
                    'google_id',
                    $googleData['google_id']
                )
                ->orWhereRaw(
                    'LOWER(email) = ?',
                    [strtolower($googleData['email'])]
                )
                ->first();

            if ($existingUser) {
                /*
                 * Do not overwrite the existing user's role.
                 */
                $existingUser->forceFill([
                    'google_id' =>
                        $googleData['google_id'],
                    'is_otp_verified' => true,
                ])->save();

                Cache::store('redis')->forget(
                    'google_registration:'
                    . $registrationToken
                );

                $this->createOrRefreshApiToken(
                    $existingUser
                );

                $existingUser->loadMissing('role');

                return response()->json([
                    'status' => true,
                    'message' => 'User was already registered. Login successful.',
                    'is_registered' => true,
                    'requires_role_selection' => false,
                    'data' => $this->userResponse(
                        $existingUser
                    ),
                ]);
            }

            $user = DB::transaction(function () use (
                $googleData,
                $role
            ) {
                /*
                 * Lock the role row to prevent duplicate
                 * role-based unique IDs.
                 */
                $lockedRole = Role::query()
                    ->whereKey($role->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $uniqueId = $this->generateUniqueId(
                    $lockedRole
                );

                $username = $this->generateUniqueUsername(
                    $googleData['first_name'],
                    $googleData['last_name'] ?? null,
                    $googleData['email']
                );

                $user = User::create([
                    'first_name' =>
                        $googleData['first_name'],

                    'last_name' =>
                        $googleData['last_name'] ?: null,

                    'email' => strtolower(
                        $googleData['email']
                    ),

                    'user_name' => $username,
                    'google_id' =>
                        $googleData['google_id'],

                    'role_id' => $lockedRole->id,

                    'password' => Hash::make(
                        Str::random(40)
                    ),

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

            /*
             * Remove the temporary registration data.
             */
            Cache::store('redis')->forget(
                'google_registration:' . $registrationToken
            );

            $this->createOrRefreshApiToken($user);

            $user->loadMissing('role');

            return response()->json([
                'status' => true,
                'message' => 'Registration and login successful.',
                'is_registered' => true,
                'requires_role_selection' => false,
                'data' => $this->userResponse($user),
            ], 201);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Google registration failed.',
                'is_registered' => false,
                'error' => app()->isLocal()
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Correctly separate first and last names.
     *
     * Vijay Kumar:
     * first_name = Vijay
     * last_name  = Kumar
     *
     * Vijay Kumar Sharma:
     * first_name = Vijay
     * last_name  = Kumar Sharma
     */
    private function extractGoogleNames(
        object $googleUser,
        string $email
    ): array {
        $rawGoogleUser = $googleUser->user ?? [];

        $firstName = trim(
            (string) (
                $rawGoogleUser['given_name'] ?? ''
            )
        );

        $lastName = trim(
            (string) (
                $rawGoogleUser['family_name'] ?? ''
            )
        );

        $fullName = trim(
            (string) (
                $googleUser->getName()
                ?: $googleUser->getNickname()
                ?: Str::before($email, '@')
            )
        );

        /*
         * Fallback when Google does not provide given_name
         * or family_name separately.
         */
        $nameParts = preg_split(
            '/\s+/u',
            $fullName,
            2,
            PREG_SPLIT_NO_EMPTY
        );

        if (!$firstName) {
            $firstName = $nameParts[0]
                ?? Str::before($email, '@');
        }

        if (!$lastName && isset($nameParts[1])) {
            $lastName = $nameParts[1];
        }

        $firstName = Str::title(
            Str::lower(trim($firstName))
        );

        $lastName = $lastName
            ? Str::title(Str::lower(trim($lastName)))
            : null;

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim(
                $firstName . ' ' . ($lastName ?? '')
            ),
        ];
    }

    /**
     * Generate or refresh API token after 24 hours.
     */
    private function createOrRefreshApiToken(
        User $user
    ): void {
        $tokenExpired = empty($user->token_created_at);

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
     * Generate a unique username.
     */
    private function generateUniqueUsername(
        string $firstName,
        ?string $lastName,
        string $email
    ): string {
        $name = trim(
            $firstName . ' ' . ($lastName ?? '')
        );

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
     *
     * The role row is already locked in the transaction.
     */
    private function generateUniqueId(
        Role $role
    ): string {
        $prefix = trim((string) $role->prefix);

        if (!$prefix) {
            throw new \RuntimeException(
                'The selected role does not have a prefix.'
            );
        }

        $lastUniqueId = UniqueID::query()
            ->where(
                'unique_id',
                'like',
                $prefix . '%'
            )
            ->orderByDesc('id')
            ->first();

        $lastNumber = 0;

        if ($lastUniqueId) {
            $numberPart = substr(
                $lastUniqueId->unique_id,
                strlen($prefix)
            );

            $lastNumber = is_numeric($numberPart)
                ? (int) $numberPart
                : 0;
        }

        $newUniqueId = $prefix . str_pad(
            $lastNumber + 1,
            3,
            '0',
            STR_PAD_LEFT
        );

        UniqueID::create([
            'unique_id' => $newUniqueId,
        ]);

        return $newUniqueId;
    }

    /**
     * Generate frontend redirect URL.
     */
    private function frontendRedirect(
        string $path,
        array $parameters = []
    ) {
        $frontendUrl = rtrim(
            (string) config('app.frontend_url'),
            '/'
        );

        $url = $frontendUrl . '/' . ltrim(
            $path,
            '/'
        );

        if (!empty($parameters)) {
            $url .= '?' . http_build_query($parameters);
        }

        return redirect()->away($url);
    }

    /**
     * Standard user response.
     */
    private function userResponse(
        User $user
    ): array {
        return [
            'user_id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => trim(
                $user->first_name
                . ' '
                . ($user->last_name ?? '')
            ),
            'email' => $user->email,
            'role_id' => $user->role_id,
            'role_name' => $user->role?->name,
            'unique_id' => $user->unique_id,
            'token' => $user->api_token,
        ];
    }
}