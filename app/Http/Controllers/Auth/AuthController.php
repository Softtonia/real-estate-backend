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

class AuthController extends Controller
{

    public function register(Request $request)
    {
        if ($request->has('phone') && is_string($request->phone)) {
            $request->merge([
                'phone' => preg_replace('/[^\d+]/', '', $request->phone)
            ]);
        }

        $validator = Validator::make($request->all(), [
            'phone' => [
                'required',
                'string',
                'regex:/^\+?[0-9]{10,15}$/',
                'unique:users,phone',
            ],
            'role_id' => 'required',
            'email' => 'required|email|unique:users,email', // Added email validation for sending mail
            'user_name' => 'required|string|min:3|max:20|unique:users,user_name|regex:/^[a-zA-Z0-9._]+$/',
        ], [
            'phone.regex' => 'The phone number format is invalid. It must be between 10 and 15 digits (optional country code prefix like 91 or +91).',
            'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check if the role is admin
        // Find the role ID of 'admin'
        $adminRole = DB::table('roles')->where('name', 'admin')->first();

        if ($adminRole && $request->role_id == $adminRole->id) {
            return response()->json(['error' => 'You cannot create the admin role as it already exists.'], 400);
        }


        // Find the role by the role_id
        $role = Role::find($request->role_id);
        if (!$role) {
            return response()->json(['error' => 'Invalid role provided.'], 400);
        }

        // Set isapproved based on the role_name
        // $isApproved = ($role->name === 'owner') ? 1 : 3;

        // Generate unique ID based on role
        $userRole = User::where('role_id', $role->id)->count();

        if ($userRole == 0) {
            // If no users exist for this role, start the count from 001
            $uniqueIDModel = new UniqueID();
            $uniqueIDModel->unique_id = $role->prefix . str_pad(1, 3, '0', STR_PAD_LEFT);
            $uniqueIDModel->save();
        } else {
            // Fetch the highest current count and increment it
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

        // Generate OTP
        $otp = random_int(1000, 9999); // 4-digit OTP


        $isApproved = 1;
        // Save user and OTP data
        $user = new User();
        $user->user_name = $request->input('user_name');
        $user->phone = $request->phone;
        $user->email = $request->email;
        $user->password = Hash::make($request->password);
        $user->role_id = $request->role_id;
        $user->unique_id = $uniqueIDModel->unique_id;
        $user->isapproved = $isApproved; // Set isapproved based on role
        $user->is_otp_verified = false; // Initially set to false
        $user->kyc = 0; // Set default KYC status to Pending
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;


        $user->save();



        $token = Str::random(80);

        // Update the user's API token in the database
        $user->update(['api_token' => $token]);

        $userDetails = UserDetail::create([
            'user_id' => $user->id,
            'role_id' => $user->role_id,
        ]);

        DB::table('otps')->insert([
            // 'phone' => $request->phone,
            // 'email' => $request->email,
            'otp' => $otp,
            'user_id' => $user->id,
            'isOTPVerified' => false,
            'expire_date_time' => Carbon::now()->addMinutes(10), // Add 5 minutes to the current time
        ]);

        $fullName = $user->first_name . ' ' . $user->last_name;


        // ✅ Mail configuration from database
        $settings = DB::table('mail_configs')->where('status', 1)->first();
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

        // Send OTP via email
        try {
            Mail::to($request->email)->send(new OTPMail($otp, $fullName));
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP email',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'User registered successfully. OTP sent via email.',
            'api_token' => $token,
            'user_id' => $user->id,
            'role' => $role->name,
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
            'otp' => ['required', 'digits:4'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = null;

        // 1. Check Bearer token or api_token input/header
        $authorizationHeader = $request->header('Authorization');
        $token = null;

        if ($authorizationHeader && str_starts_with($authorizationHeader, 'Bearer ')) {
            $token = trim(substr($authorizationHeader, 7));
        }

        if (!$token) {
            $token = $request->input('api_token') ?? $request->header('X-API-TOKEN') ?? $request->header('api_token');
        }

        if ($token) {
            $user = User::with('role:id,name')->where('api_token', $token)->first();
        }

        // 2. Fallback to user ID parameter
        if (!$user && ($request->filled('id') || $request->filled('user_id'))) {
            $userId = $request->input('id') ?? $request->input('user_id');
            $user = User::with('role:id,name')->find($userId);
        }

        // 3. Fallback to email parameter
        if (!$user && $request->filled('email')) {
            $user = User::with('role:id,name')->where('email', $request->input('email'))->first();
        }

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid API token or user not found.',
            ], 401);
        }

        $otpRecord = DB::table('otps')
            ->where('user_id', $user->id)
            ->where('otp', $request->otp)
            ->where('isOTPVerified', false)
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP.',
            ], 400);
        }

        if (!empty($otpRecord->expire_date_time) && Carbon::parse($otpRecord->expire_date_time)->isPast()) {
            return response()->json([
                'status' => false,
                'message' => 'OTP expired. Please request a new OTP.',
            ], 400);
        }

        DB::transaction(function () use ($user, $otpRecord) {
            $user->forceFill([
                'is_otp_verified' => true,
                'token_created_at' => now(),
            ])->save();

            DB::table('otps')
                ->where('id', $otpRecord->id)
                ->update([
                    'isOTPVerified' => true,
                ]);
        });

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully. Please log in to continue.',
            'api_token' => $user->api_token,
            'user_id' => $user->id,
            'role' => optional($user->role)->name,
            'kyc' => $user->kyc,
            'redirect_to' => 'login',
            'is_login' => false,
        ], 200);
    }
}
