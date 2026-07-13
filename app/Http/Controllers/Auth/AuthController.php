<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\OTPMail;
use App\Models\Role;
use App\Models\UniqueID;
use App\Models\User;
use App\Models\UserDetail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => [
                'required',
                'regex:/^[0-9]{10}$/',
                Rule::unique('users', 'phone'),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'user_name' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'regex:/^[a-zA-Z0-9._]+$/',
                Rule::unique('users', 'user_name'),
            ],
            'password' => [
                'required',
                'string',
                'min:8',
            ],
            'role_id' => [
                'required',
                'integer',
                'exists:roles,id',
            ],
            'first_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'last_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower(trim($request->email));
        $phone = trim($request->phone);
        $userName = trim($request->user_name);

        // Do not allow admin registration
        $role = Role::find($request->role_id);

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid role provided.',
            ], 400);
        }

        if (strtolower($role->name) === 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Admin registration is not allowed.',
            ], 403);
        }

        /*
    |--------------------------------------------------------------------------
    | Temporary registration reservation keys
    |--------------------------------------------------------------------------
    */

        $emailReservationKey = 'pending_registration_email:' . hash('sha256', $email);
        $phoneReservationKey = 'pending_registration_phone:' . hash('sha256', $phone);
        $usernameReservationKey = 'pending_registration_username:' . hash('sha256', strtolower($userName));

        // Check pending duplicate email
        if (Cache::has($emailReservationKey)) {
            return response()->json([
                'status' => false,
                'message' => 'This email already has a pending registration.',
                'errors' => [
                    'email' => [
                        'An OTP has already been sent to this email address.'
                    ],
                ],
            ], 409);
        }

        // Check pending duplicate phone
        if (Cache::has($phoneReservationKey)) {
            return response()->json([
                'status' => false,
                'message' => 'This phone number already has a pending registration.',
                'errors' => [
                    'phone' => [
                        'An OTP registration is already pending for this phone number.'
                    ],
                ],
            ], 409);
        }

        // Check pending duplicate username
        if (Cache::has($usernameReservationKey)) {
            return response()->json([
                'status' => false,
                'message' => 'This username already has a pending registration.',
                'errors' => [
                    'user_name' => [
                        'An OTP registration is already pending for this username.'
                    ],
                ],
            ], 409);
        }

        $otp = random_int(1000, 9999);
        $registrationToken = Str::random(64);
        $expiresAt = Carbon::now()->addMinutes(10);

        $registrationCacheKey = 'pending_registration:' . $registrationToken;

        $pendingRegistration = [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'user_name' => $userName,
            'phone' => $phone,
            'email' => $email,

            // Store only hashed password
            'password' => Hash::make($request->password),

            'role_id' => $role->id,

            // Store hashed OTP
            'otp' => Hash::make((string) $otp),

            'created_at' => Carbon::now()->toDateTimeString(),
            'expires_at' => $expiresAt->toDateTimeString(),

            'reservation_keys' => [
                $emailReservationKey,
                $phoneReservationKey,
                $usernameReservationKey,
            ],
        ];

        /*
    |--------------------------------------------------------------------------
    | Save temporarily in Redis for 10 minutes
    |--------------------------------------------------------------------------
    */

        Cache::put(
            $registrationCacheKey,
            $pendingRegistration,
            $expiresAt
        );

        Cache::put(
            $emailReservationKey,
            $registrationToken,
            $expiresAt
        );

        Cache::put(
            $phoneReservationKey,
            $registrationToken,
            $expiresAt
        );

        Cache::put(
            $usernameReservationKey,
            $registrationToken,
            $expiresAt
        );

        /*
    |--------------------------------------------------------------------------
    | Mail configuration
    |--------------------------------------------------------------------------
    */

        $settings = DB::table('mail_configs')
            ->where('status', 1)
            ->first();

        if ($settings) {
            config([
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                'mail.mailers.smtp.encryption' => $settings->encryption,
                'mail.from.address' => $settings->from_address,
                'mail.from.name' => $settings->from_name,
            ]);
        }

        $fullName = trim(
            ($request->first_name ?? '') . ' ' .
                ($request->last_name ?? '')
        );

        try {
            Mail::to($email)->send(
                new OTPMail($otp, $fullName)
            );
        } catch (\Exception $e) {
            // Remove temporary registration when email sending fails
            Cache::forget($registrationCacheKey);
            Cache::forget($emailReservationKey);
            Cache::forget($phoneReservationKey);
            Cache::forget($usernameReservationKey);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP email.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'OTP sent successfully. Complete OTP verification to create your account.',
            'registration_token' => $registrationToken,
            'expires_in' => 600,
            'expires_at' => $expiresAt->toDateTimeString(),
        ], 200);
    }


    public function login(Request $request)
    {
        // Validate request inputs
        $validator = Validator::make($request->all(), [
            'email' => 'required',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $identifier = $request->input('email');
        $password = $request->input('password');

        // Check whether user entered email or phone
        $fieldType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        // Fetch only required user fields and role
        $user = User::with('role:id,name')
            ->select([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'password',
                'role_id',
                'isapproved',
                'kyc',
                'api_token',
            ])
            ->where($fieldType, $identifier)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check password without running Auth::attempt again
        if (!Hash::check($password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $roleName = optional($user->role)->name;

        // Restrict admin login from normal user login API
        if ($roleName === 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Admins are not allowed to log in here.'
            ], 403);
        }

        // Check user account status
        if ((int) $user->isapproved === 2) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is deactivated. Please contact the administrator.',
            ], 403);
        }

        if ((int) $user->isapproved !== 1) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not yet approved. Please wait for approval.',
            ], 403);
        }

        // Generate new token
        $token = Str::random(80);

        $user->forceFill([
            'api_token' => $token,
            'token_created_at' => now(),
        ])->save();

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user_id' => $user->id,
            'role' => $roleName,
            'kyc' => $user->kyc,
        ], 200);
    }


    // for user logout


    public function logout(Request $request)
    {
        try {
            // $user = Auth::user();
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized'
                ], 401);
            }

            // Token invalidate
            $user->api_token = null;
            $user->save();

            Auth::logout();

            return response()->json([
                'is_login' => false,
                'message' => 'Logged out successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Something went wrong.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
    public function verifyRegisterOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'registration_token' => [
                'required',
                'string',
            ],
            'otp' => [
                'required',
                'digits:4',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $registrationToken = trim($request->registration_token);
        $registrationCacheKey = 'pending_registration:' . $registrationToken;

        /*
    |--------------------------------------------------------------------------
    | Prevent same OTP from being verified multiple times
    |--------------------------------------------------------------------------
    */

        $verificationLock = Cache::lock(
            'verify_registration:' . $registrationToken,
            15
        );

        if (!$verificationLock->get()) {
            return response()->json([
                'status' => false,
                'message' => 'This registration is already being processed.',
            ], 409);
        }

        try {
            $pendingRegistration = Cache::get($registrationCacheKey);

            if (!$pendingRegistration) {
                return response()->json([
                    'status' => false,
                    'message' => 'Registration session expired. Please register again.',
                ], 410);
            }

            /*
        |--------------------------------------------------------------------------
        | Verify OTP
        |--------------------------------------------------------------------------
        */

            if (!Hash::check(
                (string) $request->otp,
                $pendingRegistration['otp']
            )) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP.',
                ], 400);
            }

            /*
        |--------------------------------------------------------------------------
        | Check duplicates again before creating user
        |--------------------------------------------------------------------------
        */

            $duplicateErrors = [];

            if (
                User::where('email', $pendingRegistration['email'])
                ->exists()
            ) {
                $duplicateErrors['email'] = [
                    'This email address is already registered.'
                ];
            }

            if (
                User::where('phone', $pendingRegistration['phone'])
                ->exists()
            ) {
                $duplicateErrors['phone'] = [
                    'This phone number is already registered.'
                ];
            }

            if (
                User::where('user_name', $pendingRegistration['user_name'])
                ->exists()
            ) {
                $duplicateErrors['user_name'] = [
                    'This username is already registered.'
                ];
            }

            if (!empty($duplicateErrors)) {
                $this->removePendingRegistration(
                    $registrationCacheKey,
                    $pendingRegistration
                );

                return response()->json([
                    'status' => false,
                    'message' => 'Registration details already exist.',
                    'errors' => $duplicateErrors,
                ], 409);
            }

            $role = Role::find($pendingRegistration['role_id']);

            if (!$role) {
                $this->removePendingRegistration(
                    $registrationCacheKey,
                    $pendingRegistration
                );

                return response()->json([
                    'status' => false,
                    'message' => 'Selected role no longer exists.',
                ], 400);
            }

            if (strtolower($role->name) === 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Admin registration is not allowed.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Lock role sequence to prevent duplicate unique IDs
        |--------------------------------------------------------------------------
        */

            $roleLock = Cache::lock(
                'role_unique_id:' . $role->id,
                15
            );

            if (!$roleLock->get()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Registration is busy. Please try again.',
                ], 409);
            }

            try {
                $result = DB::transaction(function () use (
                    $pendingRegistration,
                    $role
                ) {
                    /*
                |--------------------------------------------------------------------------
                | Generate unique ID only after OTP verification
                |--------------------------------------------------------------------------
                */

                    $lastUniqueID = UniqueID::where(
                        'unique_id',
                        'like',
                        $role->prefix . '%'
                    )
                        ->orderBy('id', 'desc')
                        ->lockForUpdate()
                        ->first();

                    $lastCount = 0;

                    if ($lastUniqueID) {
                        $lastCount = (int) substr(
                            $lastUniqueID->unique_id,
                            strlen($role->prefix)
                        );
                    }

                    $newUniqueID = $role->prefix .
                        str_pad(
                            $lastCount + 1,
                            3,
                            '0',
                            STR_PAD_LEFT
                        );

                    $uniqueIDModel = new UniqueID();
                    $uniqueIDModel->unique_id = $newUniqueID;
                    $uniqueIDModel->save();

                    /*
                |--------------------------------------------------------------------------
                | Create actual user now
                |--------------------------------------------------------------------------
                */

                    $apiToken = Str::random(80);

                    $user = new User();
                    $user->first_name = $pendingRegistration['first_name'];
                    $user->last_name = $pendingRegistration['last_name'];
                    $user->user_name = $pendingRegistration['user_name'];
                    $user->phone = $pendingRegistration['phone'];
                    $user->email = $pendingRegistration['email'];

                    // Password is already hashed in Redis
                    $user->password = $pendingRegistration['password'];

                    $user->role_id = $role->id;
                    $user->unique_id = $newUniqueID;
                    $user->isapproved = 1;
                    $user->is_otp_verified = true;
                    $user->kyc = 0;
                    $user->api_token = $apiToken;
                    $user->token_created_at = Carbon::now();
                    $user->save();

                    UserDetail::create([
                        'user_id' => $user->id,
                        'role_id' => $user->role_id,
                    ]);

                    return [
                        'user' => $user,
                        'api_token' => $apiToken,
                    ];
                });
            } finally {
                $roleLock->release();
            }

            /*
        |--------------------------------------------------------------------------
        | Remove temporary Redis registration
        |--------------------------------------------------------------------------
        */

            $this->removePendingRegistration(
                $registrationCacheKey,
                $pendingRegistration
            );

            return response()->json([
                'status' => true,
                'message' => 'OTP verified and user registered successfully.',
                'api_token' => $result['api_token'],
                'user_id' => $result['user']->id,
                'unique_id' => $result['user']->unique_id,
                'role' => $role->name,
                'kyc' => $result['user']->kyc,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Registration verification failed.',
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            $verificationLock->release();
        }
    }
    private function removePendingRegistration(
        string $registrationCacheKey,
        array $pendingRegistration
    ): void {
        Cache::forget($registrationCacheKey);

        foreach (
            $pendingRegistration['reservation_keys'] ?? []
            as $reservationKey
        ) {
            Cache::forget($reservationKey);
        }
    }
}
