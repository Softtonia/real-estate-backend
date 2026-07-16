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
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Google callback.
     *
     * Existing user:
     * Return login token and is_registered = true.
     *
     * New user:
     * Do not create account yet.
     * Return registration token and is_registered = false.
     */
    public function handleGoogleCallback(Request $request): JsonResponse
    {
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

            $email = strtolower(trim((string) $googleUser->getEmail()));

            if (!$email) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email was not received from Google.',
                ], 422);
            }

            /*
             * Vijay Kumar:
             * first_name = Vijay
             * last_name  = Kumar
             *
             * Vijay Kumar Sharma:
             * first_name = Vijay
             * last_name  = Kumar Sharma
             */
            [$firstName, $lastName] = $this->splitFullName(
                $googleUser->getName()
            );

            /*
             * Check whether user is already registered.
             */
            $user = User::where('email', $email)->first();

            if ($user) {
                /*
                 * Existing registered user:
                 * Never change role_id during login.
                 */
                $user->forceFill([
                    'google_id' => $googleUser->getId(),
                ])->save();

                $this->createOrRefreshApiToken($user);

                $user->load('role');

                return response()->json([
                    'status' => true,
                    'message' => 'Login successful.',
                    'data' => [
                        'is_registered' => true,
                        'requires_role_selection' => false,

                        'user_id' => $user->id,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'email_verified' => !empty(
                            $user->email_verified_at
                        ),
                        'unique_id' => $user->unique_id,
                        'role_id' => $user->role_id,
                        'role_name' => $user->role?->name,
                        'token' => $user->api_token,
                    ],
                ]);
            }

            /*
             * New Google user:
             * Store Google profile temporarily.
             * The frontend will show the role-selection screen.
             */
            $registrationToken = Str::random(80);

            Cache::put(
                'google_registration:' . $registrationToken,
                [
                    'google_id' => $googleUser->getId(),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                ],
                now()->addMinutes(15)
            );

            return response()->json([
                'status' => true,
                'message' => 'Please select your role to complete registration.',
                'data' => [
                    'is_registered' => false,
                    'requires_role_selection' => true,

                    'registration_token' => $registrationToken,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'email_verified' => true,

                    'role_id' => null,
                    'unique_id' => null,
                    'token' => null,
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
     * Complete Google signup after user selects a role.
     */
    public function registerGoogleUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registration_token' => [
                'required',
                'string',
            ],
            'role_id' => [
                'required',
                'integer',
                'exists:roles,id',
            ],
        ]);

        try {
            $roleId = (int) $validated['role_id'];
            $registrationToken = $validated['registration_token'];

            /*
             * Prevent registration as admin.
             */
            if ($roleId === 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'You are not allowed to select this role.',
                ], 403);
            }

            $googleProfile = Cache::get(
                'google_registration:' . $registrationToken
            );

            if (!$googleProfile) {
                return response()->json([
                    'status' => false,
                    'message' => 'Registration session expired. Please login with Google again.',
                ], 419);
            }

            $role = Role::find($roleId);

            if (!$role) {
                return response()->json([
                    'status' => false,
                    'message' => 'Selected role does not exist.',
                ], 422);
            }

            if (empty($role->prefix)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unique ID prefix is not configured for this role.',
                ], 422);
            }

            /*
             * Check email again before registration.
             */
            $existingUser = User::where(
                'email',
                $googleProfile['email']
            )->first();

            if ($existingUser) {
                $this->createOrRefreshApiToken($existingUser);

                $existingUser->load('role');

                Cache::forget(
                    'google_registration:' . $registrationToken
                );

                return response()->json([
                    'status' => true,
                    'message' => 'Account is already registered.',
                    'data' => [
                        'is_registered' => true,
                        'requires_role_selection' => false,

                        'user_id' => $existingUser->id,
                        'first_name' => $existingUser->first_name,
                        'last_name' => $existingUser->last_name,
                        'email' => $existingUser->email,
                        'email_verified' => !empty(
                            $existingUser->email_verified_at
                        ),
                        'unique_id' => $existingUser->unique_id,
                        'role_id' => $existingUser->role_id,
                        'role_name' => $existingUser->role?->name,
                        'token' => $existingUser->api_token,
                    ],
                ]);
            }

            $user = DB::transaction(function () use (
                $googleProfile,
                $role,
                $roleId
            ) {
                /*
                 * Prevent duplicate email during simultaneous requests.
                 */
                $existingUser = User::where(
                    'email',
                    $googleProfile['email']
                )
                    ->lockForUpdate()
                    ->first();

                if ($existingUser) {
                    return $existingUser;
                }

                $username = $this->generateUsername(
                    $googleProfile['first_name'],
                    $googleProfile['last_name'],
                    $googleProfile['email']
                );

                $uniqueId = $this->generateUniqueId($role);

                /*
                 * Save unique ID record.
                 */
                $uniqueIDModel = new UniqueID();
                $uniqueIDModel->unique_id = $uniqueId;
                $uniqueIDModel->save();

                /*
                 * Create account only after role selection.
                 */
                $createdUser = User::create([
                    'first_name' => $googleProfile['first_name'],
                    'last_name' => $googleProfile['last_name'],
                    'email' => $googleProfile['email'],
                    'email_verified_at' => now(),

                    'user_name' => $username,
                    'google_id' => $googleProfile['google_id'],
                    'role_id' => $roleId,
                    'unique_id' => $uniqueId,

                    'password' => Hash::make(Str::random(40)),
                    'isapproved' => 1,
                    'is_otp_verified' => true,
                    'kyc' => 0,

                    'api_token' => Str::random(80),
                    'token_created_at' => now(),
                ]);

                UserDetail::create([
                    'user_id' => $createdUser->id,
                    'role_id' => $roleId,
                ]);

                return $createdUser;
            });

            Cache::forget(
                'google_registration:' . $registrationToken
            );

            $user->load('role');

            return response()->json([
                'status' => true,
                'message' => 'Account registered successfully.',
                'data' => [
                    'is_registered' => true,
                    'requires_role_selection' => false,

                    'user_id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'email_verified' => true,
                    'unique_id' => $user->unique_id,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role?->name,
                    'token' => $user->api_token,
                ],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Google registration failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Split complete Google name.
     */
    private function splitFullName(?string $fullName): array
    {
        $fullName = trim(
            preg_replace('/\s+/', ' ', $fullName ?? '')
        );

        if ($fullName === '') {
            return ['', ''];
        }

        $nameParts = explode(' ', $fullName);

        $firstName = array_shift($nameParts);
        $lastName = implode(' ', $nameParts);

        return [
            Str::title($firstName),
            Str::title($lastName),
        ];
    }

    /**
     * Generate unique username.
     */
    private function generateUsername(
        string $firstName,
        string $lastName,
        string $email
    ): string {
        $fullName = trim($firstName . $lastName);

        $baseUsername = Str::lower(
            preg_replace('/[^a-zA-Z0-9]/', '', $fullName)
        );

        if (!$baseUsername) {
            $emailUsername = explode('@', $email)[0];

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

        while (User::where('user_name', $username)->exists()) {
            $username = $baseUsername . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Generate unique ID from role prefix.
     *
     * Example:
     * OWN001
     * OWN002
     * AGT001
     */
    private function generateUniqueId(Role $role): string
    {
        $prefix = strtoupper(trim($role->prefix));

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

    /**
     * Create or refresh API token.
     */
    private function createOrRefreshApiToken(User $user): void
    {
        $tokenExpired =
            empty($user->api_token) ||
            empty($user->token_created_at) ||
            Carbon::parse($user->token_created_at)
                ->lt(now()->subHours(24));

        if ($tokenExpired) {
            $user->forceFill([
                'api_token' => Str::random(80),
                'token_created_at' => now(),
            ])->save();
        }
    }
}