<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\UniqueID;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Failed to generate Google login URL.',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Handle Google callback.
     */
    public function handleGoogleCallback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->user();

            $googleId = trim((string) $googleUser->getId());

            $email = strtolower(
                trim((string) $googleUser->getEmail())
            );

            if ($googleId === '' || $email === '') {
                return $this->frontendRedirect('/auth/login', [
                    'google_error' => 'missing_information',
                ]);
            }

            $googleNames = $this->extractGoogleNames(
                $googleUser,
                $email
            );

            $user = User::query()
                ->where(function ($query) use (
                    $googleId,
                    $email
                ) {
                    $query
                        ->where('google_id', $googleId)
                        ->orWhereRaw(
                            'LOWER(TRIM(email)) = ?',
                            [$email]
                        );
                })
                ->first();

            logger()->info('Google user lookup completed', [
                'google_id' => $googleId,
                'google_email' => $email,
                'matched_user_id' => $user?->id,
                'is_registered' => (bool) $user,
            ]);

            /*
             * Existing registered user.
             */
            if ($user) {
                DB::transaction(function () use (
                    $user,
                    $googleId,
                    $googleNames
                ) {
                    /*
                     * Lock the user's role to safely create
                     * a missing unique ID.
                     */
                    $role = null;

                    if ($user->role_id) {
                        $role = Role::query()
                            ->whereKey($user->role_id)
                            ->lockForUpdate()
                            ->first();
                    }

                    $this->updateExistingGoogleUser(
                        $user,
                        $googleId,
                        $googleNames,
                        $role
                    );
                });

                $loginCode = Str::random(64);

                Cache::store('redis')->put(
                    'google_login:' . $loginCode,
                    $user->id,
                    now()->addMinutes(5)
                );

                return $this->frontendRedirect(
                    '/auth/login/googlecallback',
                    [
                        'is_registered' => 'true',
                        'code' => $loginCode,
                    ]
                );
            }

            /*
             * New unregistered user.
             */
            $registrationToken = Str::random(64);

            Cache::store('redis')->put(
                'google_registration:' . $registrationToken,
                [
                    'google_id' => $googleId,
                    'email' => $email,
                    'first_name' => $googleNames['first_name'],
                    'last_name' => $googleNames['last_name'],
                ],
                now()->addMinutes(15)
            );

            return $this->frontendRedirect(
                '/auth/login/googlecallback',
                [
                    'is_registered' => 'false',
                    'registration_token' => $registrationToken,
                ]
            );
        } catch (Throwable $e) {
            report($e);

            logger()->error('Google callback failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->frontendRedirect('/auth/login', [
                'google_error' => 'login_failed',
            ]);
        }
    }

    /**
     * Exchange the existing-user login code.
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

        $userId = Cache::store('redis')->pull(
            'google_login:' . $validated['code']
        );

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'Google login code is invalid or expired.',
                'is_registered' => false,
                'requires_role_selection' => false,
            ], 401);
        }

        $user = User::with('role')->find($userId);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User account was not found.',
                'is_registered' => false,
                'requires_role_selection' => false,
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
     * Register new Google user after role selection.
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
                'requires_role_selection' => true,
            ], 401);
        }

        if ((int) $validated['role_id'] === 1) {
            return response()->json([
                'status' => false,
                'message' => 'You are not allowed to select this role.',
                'is_registered' => false,
                'requires_role_selection' => true,
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
                'requires_role_selection' => true,
            ], 422);
        }

        try {
            $result = DB::transaction(function () use (
                $googleData,
                $role
            ) {
                /*
                 * Check again to prevent duplicate registration.
                 */
                $existingUser = User::query()
                    ->where(function ($query) use ($googleData) {
                        $query
                            ->where(
                                'google_id',
                                $googleData['google_id']
                            )
                            ->orWhereRaw(
                                'LOWER(TRIM(email)) = ?',
                                [
                                    strtolower(
                                        trim($googleData['email'])
                                    ),
                                ]
                            );
                    })
                    ->lockForUpdate()
                    ->first();

                if ($existingUser) {
                    $existingRole = Role::query()
                        ->whereKey($existingUser->role_id)
                        ->lockForUpdate()
                        ->first();

                    $this->updateExistingGoogleUser(
                        $existingUser,
                        $googleData['google_id'],
                        [
                            'first_name' =>
                                $googleData['first_name'] ?? null,

                            'last_name' =>
                                $googleData['last_name'] ?? null,
                        ],
                        $existingRole
                    );

                    return [
                        'user' => $existingUser,
                        'created' => false,
                    ];
                }

                /*
                 * Lock role row. This prevents two users under
                 * the same role from getting the same unique ID.
                 */
                $lockedRole = Role::query()
                    ->whereKey($role->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $uniqueId = $this->generateUniqueId(
                    $lockedRole
                );

                $username = $this->generateUniqueUsername(
                    $googleData['first_name'] ?? '',
                    $googleData['last_name'] ?? null,
                    $googleData['email']
                );

                $user = new User();

                $user->first_name =
                    $googleData['first_name'] ?? null;

                $user->last_name =
                    $googleData['last_name'] ?? null;

                $user->user_name = $username;

                $user->email = strtolower(
                    trim($googleData['email'])
                );

                $user->google_id =
                    $googleData['google_id'];

                $user->role_id = $lockedRole->id;

                $user->password = Hash::make(
                    Str::random(40)
                );

                $user->isapproved = 1;
                $user->is_otp_verified = true;
                $user->kyc = 0;
                $user->created_by = 0;
                $user->unique_id = $uniqueId;

                $user->save();

                $userDetail = new UserDetail();
                $userDetail->user_id = $user->id;
                $userDetail->role_id = $lockedRole->id;
                $userDetail->save();

                return [
                    'user' => $user,
                    'created' => true,
                ];
            });

            /** @var User $user */
            $user = $result['user'];
            $created = $result['created'];

            Cache::store('redis')->forget(
                'google_registration:' . $registrationToken
            );

            $this->createOrRefreshApiToken($user);

            $user->loadMissing('role');

            return response()->json([
                'status' => true,
                'message' => $created
                    ? 'Registration and login successful.'
                    : 'User was already registered. Login successful.',

                'is_registered' => true,
                'requires_role_selection' => false,
                'data' => $this->userResponse($user),
            ], $created ? 201 : 200);
        } catch (Throwable $e) {
            report($e);

            logger()->error('Google registration failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'role_id' => $validated['role_id'],
                'google_email' => $googleData['email'] ?? null,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Google registration failed.',
                'is_registered' => false,
                'requires_role_selection' => true,
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Update existing Google user.
     *
     * Existing role is never changed.
     * Missing username and unique ID are generated.
     */
    private function updateExistingGoogleUser(
        User $user,
        string $googleId,
        array $googleNames,
        ?Role $role = null
    ): void {
        $user->google_id = $googleId;
        $user->is_otp_verified = true;

        if (
            empty($user->first_name) &&
            !empty($googleNames['first_name'])
        ) {
            $user->first_name =
                $googleNames['first_name'];
        }

        if (
            empty($user->last_name) &&
            !empty($googleNames['last_name'])
        ) {
            $user->last_name =
                $googleNames['last_name'];
        }

        /*
         * Fix missing username.
         */
        if (empty($user->user_name)) {
            $user->user_name =
                $this->generateUniqueUsername(
                    $user->first_name ?? '',
                    $user->last_name ?? null,
                    $user->email,
                    $user->id
                );
        }

        /*
         * Fix missing unique ID only.
         * Existing unique IDs are preserved.
         */
        if (
            empty($user->unique_id) &&
            $role
        ) {
            $user->unique_id =
                $this->generateUniqueId($role);
        }

        /*
         * Do not change role_id.
         */
        $user->save();
    }

    /**
     * Generate unique username.
     *
     * Vijay Kumar => vijaykumar
     * Duplicate   => vijaykumar1
     */
    private function generateUniqueUsername(
        string $firstName,
        ?string $lastName,
        string $email,
        ?int $ignoreUserId = null
    ): string {
        $fullName = trim(
            $firstName . ' ' . ($lastName ?? '')
        );

        $baseUsername = Str::lower(
            Str::ascii($fullName)
        );

        $baseUsername = preg_replace(
            '/[^a-z0-9]/',
            '',
            $baseUsername
        );

        if (!$baseUsername) {
            $emailName = Str::before($email, '@');

            $baseUsername = preg_replace(
                '/[^a-z0-9]/',
                '',
                Str::lower(Str::ascii($emailName))
            );
        }

        $baseUsername = $baseUsername ?: 'user';

        /*
         * Limit base so counter can be safely added.
         */
        $baseUsername = Str::limit(
            $baseUsername,
            180,
            ''
        );

        $username = $baseUsername;
        $counter = 1;

        while (
            User::query()
                ->when(
                    $ignoreUserId,
                    fn ($query) => $query->where(
                        'id',
                        '!=',
                        $ignoreUserId
                    )
                )
                ->where('user_name', $username)
                ->exists()
        ) {
            $username = $baseUsername . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Generate the next role-based unique ID.
     *
     * It checks:
     * - users.unique_id
     * - unique_ids.unique_id
     *
     * Example:
     * Existing OWN002 and OWN005
     * New ID becomes OWN006.
     */
    private function generateUniqueId(
        Role $role
    ): string {
        $prefix = strtoupper(
            trim((string) $role->prefix)
        );

        if ($prefix === '') {
            throw new \RuntimeException(
                'The selected role does not have a prefix.'
            );
        }

        /*
         * Get all IDs from users table.
         */
        $userUniqueIds = User::query()
            ->where(
                'unique_id',
                'like',
                $prefix . '%'
            )
            ->pluck('unique_id');

        /*
         * Get all IDs from unique_ids table.
         */
        $masterUniqueIds = UniqueID::query()
            ->where(
                'unique_id',
                'like',
                $prefix . '%'
            )
            ->pluck('unique_id');

        $allUniqueIds = $userUniqueIds
            ->merge($masterUniqueIds)
            ->filter()
            ->unique();

        $highestNumber = 0;

        foreach ($allUniqueIds as $existingUniqueId) {
            $existingUniqueId = strtoupper(
                trim((string) $existingUniqueId)
            );

            if (!str_starts_with(
                $existingUniqueId,
                $prefix
            )) {
                continue;
            }

            $numberPart = substr(
                $existingUniqueId,
                strlen($prefix)
            );

            if (
                $numberPart !== '' &&
                ctype_digit($numberPart)
            ) {
                $highestNumber = max(
                    $highestNumber,
                    (int) $numberPart
                );
            }
        }

        do {
            $highestNumber++;

            $newUniqueId = $prefix . str_pad(
                $highestNumber,
                3,
                '0',
                STR_PAD_LEFT
            );

            $alreadyExistsInUsers = User::query()
                ->where('unique_id', $newUniqueId)
                ->exists();

            $alreadyExistsInMaster = UniqueID::query()
                ->where('unique_id', $newUniqueId)
                ->exists();
        } while (
            $alreadyExistsInUsers ||
            $alreadyExistsInMaster
        );

        $uniqueIdModel = new UniqueID();
        $uniqueIdModel->unique_id = $newUniqueId;
        $uniqueIdModel->save();

        return $newUniqueId;
    }

    /**
     * Extract first and last names.
     */
    private function extractGoogleNames(
        object $googleUser,
        string $email
    ): array {
        $rawGoogleUser = is_array(
            $googleUser->user ?? null
        )
            ? $googleUser->user
            : [];

        $firstName = Str::squish(
            (string) (
                $rawGoogleUser['given_name'] ?? ''
            )
        );

        $lastName = Str::squish(
            (string) (
                $rawGoogleUser['family_name'] ?? ''
            )
        );

        $fullName = Str::squish(
            (string) (
                $googleUser->getName()
                ?: $googleUser->getNickname()
                ?: Str::before($email, '@')
            )
        );

        $nameParts = preg_split(
            '/\s+/u',
            $fullName,
            2,
            PREG_SPLIT_NO_EMPTY
        );

        if ($firstName === '') {
            $firstName = $nameParts[0]
                ?? Str::before($email, '@');
        }

        if (
            $lastName === '' &&
            isset($nameParts[1])
        ) {
            $lastName = $nameParts[1];
        }

        return [
            'first_name' => $firstName ?: null,
            'last_name' => $lastName ?: null,
        ];
    }

    /**
     * Create or refresh API token.
     */
    private function createOrRefreshApiToken(
        User $user
    ): void {
        $tokenExpired = empty(
            $user->token_created_at
        );

        if ($user->token_created_at) {
            $tokenExpired = Carbon::parse(
                $user->token_created_at
            )->lte(now()->subHours(24));
        }

        if (
            empty($user->api_token) ||
            $tokenExpired
        ) {
            $user->api_token = Str::random(80);
            $user->token_created_at = now();
            $user->save();
        }

        try {
            app(\App\Services\Auth\LoginHistoryService::class)->recordLogin($user, request(), 'Google OAuth');
        } catch (\Throwable $e) {
            \Log::error('Google login history recording failed: ' . $e->getMessage());
        }
    }

    /**
     * Redirect to frontend.
     */
    private function frontendRedirect(
        string $path,
        array $parameters = []
    ): RedirectResponse {
        $frontendUrl = rtrim(
            (string) config(
                'app.frontend_url',
                'https://holiplaces.com'
            ),
            '/'
        );

        $url = $frontendUrl
            . '/'
            . ltrim($path, '/');

        if ($parameters !== []) {
            $url .= '?'
                . http_build_query($parameters);
        }

        return redirect()->away($url);
    }

    /**
     * Authenticated-user response.
     */
    private function userResponse(
        User $user
    ): array {
        $fullName = trim(
            implode(' ', array_filter([
                $user->first_name,
                $user->last_name,
            ]))
        );

        return [
            'user_id' => $user->id,
            'user_name' => $user->user_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $fullName,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'role_name' => $user->role?->name,
            'unique_id' => $user->unique_id,
            'token' => $user->api_token,
        ];
    }
}