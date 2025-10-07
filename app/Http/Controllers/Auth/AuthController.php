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
        $validator = Validator::make($request->all(), [
            'phone' => [
                'required',
                'unique:users,phone',
                'regex:/^[0-9]{10}$/' // Ensures the phone field contains exactly 10 digits
            ],
            'role_id' => 'required',
            'email' => 'required|email|unique:users,email', // Added email validation for sending mail
            'user_name' => 'required|string|min:3|max:20|unique:users,user_name|regex:/^[a-zA-Z0-9._]+$/',
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
            'email' => 'required', // Can be email or mobile number
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $identifier = $request->input('email');
        $password = $request->input('password');

        // Determine if the identifier is an email or phone number
        $fieldType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        // Retrieve the user by email or phone
        $user = User::where($fieldType, $identifier)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Retrieve the user's role
        $role = $user->role ? $user->role->name : null;

        //  Restrict login for admin users
        if ($role === 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Admins are not allowed to log in here.'
            ], 403);
        }

        //  Check if user is approved or deactivated
        if ($user->isapproved == 2) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is deactivated. Please contact the administrator.',
            ], 403);
        }

        if ($user->isapproved !== 1) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is not yet approved. Please wait for approval.',
            ], 403);
        }


        // Define roles that require KYC verification
        $rolesRequiringKYC = ['agent', 'company', 'consultancy', 'developer'];


        // Attempt to authenticate the user
        if (Auth::attempt([$fieldType => $identifier, 'password' => $password])) {
            // Generate a new API token
            $token = Str::random(80);

            // Update the user's API token in the database
            $user->update(['api_token' => $token]);

            // Return a successful login response
            return response()->json([
                'status' => true,
                'message' => 'Login successful',
                'token' => $token,
                'user_id' => $user->id,
                'role' => $user->role->name,

            ], 200);
        } else {
            // Return an error response for invalid credentials
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }
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
}
