<?php

namespace App\Http\Controllers;

use App\Mail\OTPMail;
use App\Models\PasswordReset;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Agent;
use App\Models\UniqueID;
use App\Models\UserDetail;
use App\Models\OTP;
use App\Models\JoinRequest;
use App\Models\ProjectList;
use App\Models\PropertyList;
use App\Models\Status;
use App\Models\Property;
use App\Models\Purpose;
use App\Models\Customfieldvalue;
use App\Models\CustomField;
use App\Models\Keyword;
use App\Models\CompanyConsultancyProject;
use App\Models\SiteSetting;
use App\Models\SubscribedEmail;
use App\Models\TopFeature;
use App\Models\Page;
use App\Models\Location;
use Hash;
use Auth;
use Str;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Log;
use Validator;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\SubscribedEmailsImport;
use Illuminate\Support\Facades\Response;
use App\Exports\SubscribedEmailsExport;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
class UserController extends Controller
{


    // Function to append base URL to gallery images
    private function appendBaseURL($gallery, $baseURL)
    {
        return array_map(function ($image) use ($baseURL) {
            return $baseURL . '/public/uploads/gallery/' . $image;
        }, $gallery);
    }



    // Function to correct file paths for images and videos
    private function correctFilePath($filePath, $baseURL, $basePath, $Fname)
    {
        $publicPath = $basePath . '/public/';
        if (strpos($filePath, $publicPath) !== false) {
            $relativePath = str_replace($publicPath, '', $filePath);
            return $baseURL . '/' . $relativePath;
        }
        return $baseURL . '/public/uploads/' . $Fname . '/' . $filePath;
    }

    public function changeuserPassword(Request $request)
    {
        $user = Auth::user();
        // dd($user);
        if (!$user) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $user = Auth::user(); // Get authenticated user

        // Update the password
        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully',
        ], 200);
    }
    public function changePassword(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Get authenticated user
        $user = Auth::user();

        // Check if the old password matches
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect',
            ], 400);
        }

        // Update the new password
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully',
        ], 200);
    }

    // for register
    public function registerOld(Request $request)
    {
        dd($request->all());
        // Validate request data
        try {
            $request->validate([
                'first_name' => 'required',
                //'last_name' => 'required',
                'phone' => 'required|unique:users',
                'email' => 'required|unique:users',
                'role_id' => 'required|exists:roles,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        // Check if the role is admin
        $adminRoleId = 1;
        if ($request->role_id == $adminRoleId) {
            return response()->json(['error' => 'You cannot create the admin role as it already exists.'], 400);
        }

        // Generate unique ID based on role
        $role = Role::find($request->role_id);
        if (!$role) {
            return response()->json(['error' => 'Invalid role provided.'], 400);
        }

        $userRole = User::where('role_id', $role->id)->count();

        if ($userRole == 0) {
            // If no users exist for this role, start the count from 001
            $uniqueIDModel = new UniqueID();
            // Generate unique ID with prefix and padded count
            $uniqueIDModel->unique_id = $role->prefix . str_pad(1, 3, '0', STR_PAD_LEFT); // Starts from 001
            $uniqueIDModel->save();
        } else {

            // If users exist, fetch the highest current count for this role's prefix and increment it
            $lastUniqueID = UniqueID::where('unique_id', 'like', $role->prefix . '%')
                ->orderBy('unique_id', 'desc')
                ->first();

            // If there are no existing unique IDs, start from 001
            if (!$lastUniqueID) {
                $newUniqueID = $role->prefix . str_pad(1, 3, '0', STR_PAD_LEFT); // Start from 001
            } else {
                // Extract the numeric part from the last unique_id
                $lastCount = (int) substr($lastUniqueID->unique_id, strlen($role->prefix));

                // Increment the count and generate the new unique_id
                $newUniqueID = $role->prefix . str_pad($lastCount + 1, 3, '0', STR_PAD_LEFT);
            }

            // Save the new unique_id
            $uniqueIDModel = new UniqueID();
            $uniqueIDModel->unique_id = $newUniqueID;
            $uniqueIDModel->save();
        }

        $token = Str::random(60);

        if ($request->role_id == 2) {
            $isapproved = 1;
        } else {
            $isapproved = 2;
        }

        // Create a new user
        $user = new User();
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->fullname = $request->fullname ?? null;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->api_token = $token;
        $user->remember_token = $request->token;
        //$user->requestId = $request->uid;
        $user->role_id = $request->role_id; // Set role_id
        $user->password = Hash::make($request->password);
        $user->unique_id = $uniqueIDModel->unique_id;
        $user->created_by = Auth::user()->id ?? 0;
        $user->isapproved = $isapproved;

        // Add entry to user_has_unique_ids table
        DB::beginTransaction();
        try {
            $user->save();
            DB::table('user_has_unique_ids')->insert([
                'user_id' => $user->id,
                'unique_id' => $uniqueIDModel->id,
            ]);


            // Create and save OTP record
            // $otp = new Otp();
            // $otp->phone = $request->phone;
            // $otp->otp = '123456' ?? $request->otp;
            // $otp->user_id = $user->id;
            // $otp->phone = $user->phone;
            // $otp->uid = $request->uid;
            // $otp->save();

            $userDetail = array(
                'user_id' => $user->id,
                'role_id' => $request->role_id
            );

            UserDetail::create($userDetail);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage()); // Log the exception message
            return response()->json(['error' => 'Failed to register user.' . $e->getMessage()], 500);
        }

        // Return response
        return response()->json(
            ['status' => true, 'message' => 'User registeration successfully.', 'data' => $user],
            201
        );
    }


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
        $isApproved = ($role->name === 'owner') ? 1 : 3;

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


        // Save user and OTP data
        $user = new User();
        $user->user_name = $request->input('user_name');
        $user->phone = $request->phone;
        $user->email = $request->email;
        $user->password = Hash::make($request->password);
        $user->role_id = $request->role_id;
        $user->unique_id = $uniqueIDModel->unique_id;
        $user->isapproved = $isApproved; // Set isapproved based on role
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->isapproved = 3; // Set default isapproved Under Review

        $user->save();

        $token = Str::random(80);

        // Update the user's API token in the database
        $user->update(['api_token' => $token]);

        DB::table('otps')->insert([
            // 'phone' => $request->phone,
            // 'email' => $request->email,
            'otp' => $otp,
            'user_id' => $user->id,
            'isOTPVerified' => false,
            'expire_date_time' => Carbon::now()->addMinutes(2), // Add 5 minutes to the current time
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
        ], 200);
    }

    //  check username availability
    public function checkUsernameAvailability(Request $request)
    {
        // Advanced validation with custom rules
        $validator = Validator::make($request->all(), [
            'user_name' => [
                'required',
                'string',
                'min:3',
                'max:20',
                'regex:/^[a-zA-Z0-9._]+$/', // Alphanumeric + underscore + dot
            ],
        ], [
            'user_name.regex' => 'Username can only contain letters, numbers, underscores, and dots.',
        ]);

        // Return validation errors in a structured format
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $username = $request->input('user_name');

        // Check if the username exists in the database (case-insensitive)
        $exists = User::whereRaw('LOWER(user_name) = ?', [strtolower($username)])->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'username' => $username,
                'available' => !$exists,
            ],
            'message' => $exists
                ? 'Username is already taken.'
                : 'Username is available.',
        ], 200);
    }






    // for forgot password

    public function forgetPassword(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid email format',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check if the user exists
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Email not found',
            ], 404);
        }

        // Generate a reset token and URL
        $token = Str::random(40);
        $domain = url('/');
        $url = $domain . '/reset-password?token=' . $token;

        $data = [
            'url' => $url,
            'email' => $request->email,
            'title' => 'Password Reset',
            'body' => 'Click here to reset your password',
        ];

        // Send the reset link email
        try {
            Mail::send('forgetPasswordMail', ['data' => $data], function ($message) use ($data) {
                $message->to($data['email'])->subject($data['title']);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to send email. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Save the token to the database with the timestamp
        PasswordReset::updateOrCreate(
            ['email' => $request->email],
            [
                'email' => $request->email,
                'token' => $token,
                'created_at' => Carbon::now(),
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'Password reset link sent to your email',
        ], 200);
    }

    // for reset password
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'password' => 'required|string|min:8|confirmed', // Ensure password confirmation matches
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $passwordReset = PasswordReset::where('token', $request->token)->first();

        if ($passwordReset) {
            $user = User::where('email', $passwordReset->email)->first();

            if ($user) {
                $user->update([
                    'password' => Hash::make($request->password),
                ]);

                $passwordReset->delete();

                return response()->json('Password reset successfully');
            } else {
                return response()->json('User not found', 404);
            }
        } else {
            return response()->json('Invalid or expired token', 400);
        }
    }

    // for reset password load password
    public function resetPasswordLoad(Request $request)
    {
        $resetData = PasswordReset::where('token', $request->token)->first();

        if ($resetData) {
            $user = User::where('email', $resetData->email)->first();
            return view('reset-password', compact('user', 'resetData'));
        } else {
            return view('404');
        }
    }

    // for login
    // public function loginOldUser(Request $request)
    // {

    //     // Validate input
    //     $request->validate([
    //         'login' => 'required',
    //         'password' => 'required',
    //     ]);

    //     // Get input from user
    //     $credentials = $request->only('login', 'password');

    //     try {
    //         // Find the user by email or phone
    //         $user = User::where('email', $credentials['login'])
    //             ->orWhere('phone', $credentials['login'])
    //             ->first();

    //         // Check if the user exists
    //         if (!$user) {
    //             // Check if the login is an email or phone
    //             if (filter_var($credentials['login'], FILTER_VALIDATE_EMAIL)) {
    //                 return response()->json(['email' => 'Invalid email'], 401);
    //             } else {
    //                 return response()->json(['phone' => 'Invalid phone number'], 401);
    //             }
    //         }

    //         // Check if the password matches
    //         if (!Hash::check($credentials['password'], $user->password)) {
    //             return response()->json(['password' => 'Invalid password'], 401);
    //         }

    //         // Load the role relationship
    //         $user->load('role');

    //         // Generate a new API token
    //         $api_token = Str::random(60);

    //         // Update the user's API token
    //         $user->api_token = $api_token;
    //         $user->save();

    //         // Return the response with user details
    //         return response()->json([
    //             'id' => $user->id,
    //             'fullname' => $user->fullname,
    //             'phone' => $user->phone,
    //             'email' => $user->email,
    //             'role' => $user->role->name,
    //             'token' => $user->api_token,
    //             'isapproved' => $user->isapproved,
    //         ], 200);

    //     } catch (\Exception $e) {
    //         // Log the error for debugging purposes
    //         Log::error('Login Error: ' . $e->getMessage());

    //         // Return the actual error message
    //         return response()->json(['error' => 'An unexpected error occurred. Please try again later.'], 500);
    //     }
    // }

    // public function login(Request $request)
    // {
    //     // Validate request inputs
    //     $validator = Validator::make($request->all(), [
    //         'email' => 'required', // Can be email or mobile number
    //         'password' => 'required|string|min:8',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json($validator->errors(), 422);
    //     }

    //     $identifier = $request->input('email');
    //     $password = $request->input('password');

    //     // Determine if the identifier is an email or phone number
    //     $fieldType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

    //     // Retrieve the user by email or phone
    //     $user = User::where($fieldType, $identifier)->first();

    //     if (!$user) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'User not found'
    //         ], 404);
    //     }

    //     // Check if OTP verification is required
    //     if ($fieldType === 'email') {
    //         $otpRecord = DB::table('otps')->where('user_id', $user->id)->first();

    //         if (!$otpRecord || !$otpRecord->isOTPVerified) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'OTP is not verified'
    //             ], 403);
    //         }

    //         // // Check if OTP verification was recent (e.g., within the last 15 minutes)
    //         // $otpVerifiedTime = Carbon::parse($otpRecord->updated_at); // Assuming `updated_at` tracks OTP verification
    //         // if (Carbon::now()->diffInMinutes($otpVerifiedTime) > 15) {
    //         //     return response()->json([
    //         //         'status' => false,
    //         //         'message' => 'OTP verification has expired. Please verify again.'
    //         //     ], 403);
    //         // }
    //     }

    //     // Attempt to authenticate the user
    //     if (Auth::attempt([$fieldType => $identifier, 'password' => $password])) {
    //         // Generate a new API token
    //         $token = Str::random(80);

    //         // Update the user's API token in the database
    //         $user->update(['api_token' => $token]);

    //         // Get the user's role name (if assigned)
    //         $roleName = $user->role ? $user->role->name : 'No Role Assigned';

    //         // Return a successful login response
    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Login successful',
    //             'token' => $token,
    //             'user' => [
    //                 'id' => $user->id,
    //                 'first_name' => $user->first_name,
    //                 'last_name' => $user->last_name,
    //                 // 'fullname' => $user->fullname,
    //                 'email' => $user->email,
    //                 'phone' => $user->phone,
    //                 'api_token' => $token,
    //                 'created_at' => $user->created_at,
    //                 'updated_at' => $user->updated_at,
    //                 'role' => $roleName,
    //             ],
    //         ], 200);
    //     } else {
    //         // Return an error response for invalid credentials
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Invalid credentials'
    //         ], 401);
    //     }
    // }
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

        // ❌ Restrict login for admin users
        if ($role === 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Admins are not allowed to log in here.'
            ], 403);
        }

        // Define roles that require KYC verification
        $rolesRequiringKYC = ['agent', 'company', 'consultancy', 'developer'];

        // Check if KYC is required and not completed
        // if (in_array($role, $rolesRequiringKYC) && $user->kyc == '0') {
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'KYC is not completed. Please complete KYC verification.'
        //     ], 403);
        // }

        // Check if OTP verification is required for email login
        // if ($fieldType === 'email') {
        //     $otpRecord = DB::table('otps')->where('user_id', $user->id)->first();

        //     if (!$otpRecord || !$otpRecord->isOTPVerified) {
        //         return response()->json([
        //             'status' => false,
        //             'message' => 'OTP is not verified'
        //         ], 403);
        //     }
        // }

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

            ], 200);
        } else {
            // Return an error response for invalid credentials
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }
    }


    // public function login(Request $request)
    // {
    //     // Validate request inputs
    //     $validator = Validator::make($request->all(), [
    //         'identifier' => 'required', // Can be email or mobile number
    //         'password' => 'required|string|min:8',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json($validator->errors(), 422);
    //     }

    //     $identifier = $request->input('identifier');
    //     $password = $request->input('password');

    //     // Determine if the identifier is an email or phone number
    //     $fieldType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

    //     // Retrieve the user by email or phone
    //     $user = User::where($fieldType, $identifier)->first();

    //     if (!$user) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'User not found'
    //         ], 404);
    //     }

    //     // Check if the user's mobile number is verified (if logging in with phone)
    //     if ($fieldType === 'phone') {
    //         $otpRecord = DB::table('otps')->where('user_id', $user->id)->first();
    //         if (!$otpRecord || $otpRecord->isOTPVerified == 0) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Mobile number is not verified'
    //             ], 403);
    //         }
    //     }

    //     // Attempt to authenticate the user
    //     if (Auth::attempt([$fieldType => $identifier, 'password' => $password])) {
    //         // Generate a new API token
    //         $token = Str::random(80);

    //         // Update the user's API token in the database
    //         $user->update(['api_token' => $token]);

    //         // Get the user's role name (if assigned)
    //         $roleName = $user->role ? $user->role->name : 'No Role Assigned';

    //         // Return a successful login response along with the permissions
    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Login successful',
    //             'token' => $token,
    //             'user' => [
    //                 'id' => $user->id,
    //                 'first_name' => $user->first_name,
    //                 'last_name' => $user->last_name,
    //                 'fullname' => $user->fullname,
    //                 'email' => $user->email,
    //                 'phone' => $user->phone,
    //                 'api_token' => $token,
    //                 'created_at' => $user->created_at,
    //                 'updated_at' => $user->updated_at,
    //                 'role' => $roleName,
    //             ],
    //         ], 200);
    //     } else {
    //         // Return an error response for invalid credentials
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Invalid credentials'
    //         ], 401);
    //     }
    // }


    //     public function login(Request $request)
// {
//     // Validate request inputs
//     $validator = Validator::make($request->all(), [
//         'identifier' => 'required', // Can be email or mobile number
//         'password' => 'required|string|min:8',
//     ]);

    //     if ($validator->fails()) {
//         return response()->json($validator->errors(), 422);
//     }

    //     $identifier = $request->input('identifier');
//     $password = $request->input('password');

    //     // Determine if the identifier is an email or phone number
//     $fieldType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

    //     // Retrieve the user by email or phone
//     $user = User::where($fieldType, $identifier)->first();

    //     if (!$user) {
//         return response()->json([
//             'status' => false,
//             'message' => 'User not found'
//         ], 404);
//     }

    //     // Check if the identifier is an email and exists in the subscribed_emails table
//     // if ($fieldType === 'email') {
//     //     $subscribedEmail = DB::table('subscribed_emails')->where('subscribe_email', $identifier)->first();
//     //     if (!$subscribedEmail) {
//     //         return response()->json([
//     //             'status' => false,
//     //             'message' => 'Email is not subscribed'
//     //         ], 403);
//     //     } else {
//     //         // Update the user_id in the subscribed_emails table if not already set
//     //         if ($subscribedEmail->user_id) {
//     //             DB::table('subscribed_emails')
//     //                 ->where('subscribe_email', $identifier)
//     //                 ->update(['user_id' => $user->id]);
//     //         }
//     //     }
//     // }

    //     // Check if the user's mobile number is verified (if logging in with phone)
//     if ($fieldType === 'phone') {
//         $otpRecord = DB::table('otps')->where('user_id', $user->id)->first();
//         if (!$otpRecord || $otpRecord->isOTPVerified == 0) {
//             return response()->json([
//                 'status' => false,
//                 'message' => 'Mobile number is not verified'
//             ], 403);
//         }
//     }

    //     // Attempt to authenticate the user
//     if (Auth::attempt([$fieldType => $identifier, 'password' => $password])) {
//         // Generate a new API token
//         $token = Str::random(80);

    //         // Update the user's API token in the database
//         $user->update(['api_token' => $token]);

    //         // Get the user's role name (if assigned)
//         $roleName = $user->role ? $user->role->name : 'No Role Assigned';

    //         // Return a successful login response along with the permissions
//         return response()->json([
//             'status' => true,
//             'message' => 'Login successful',
//             'token' => $token,
//             'user' => [
//                 'id' => $user->id,
//                 'first_name' => $user->first_name,
//                 'last_name' => $user->last_name,
//                 'fullname' => $user->fullname,
//                 'email' => $user->email,
//                 'phone' => $user->phone,
//                 'api_token' => $token,
//                 'created_at' => $user->created_at,
//                 'updated_at' => $user->updated_at,
//                 'role' => $roleName,
//             ],
//         ], 200);
//     } else {
//         // Return an error response for invalid credentials
//         return response()->json([
//             'status' => false,
//             'message' => 'Invalid credentials'
//         ], 401);
//     }
// }


    // for check uniqueness
    public function checkUnique(Request $request)
    {
        // Validate the request input
        $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|digits:10',
        ]);

        $email = $request->input('email');
        $phone = $request->input('phone');

        $response = [];

        // Check if the email exists
        if ($email) {
            $emailExists = User::where('email', $email)->exists();
            $response['email'] = [
                'exists' => $emailExists,
                'message' => $emailExists ? 'Email already exists' : 'Email is available',
            ];
        }

        // Check if the phone number exists
        if ($phone) {
            $phoneExists = User::where('phone', $phone)->exists();
            $response['phone'] = [
                'exists' => $phoneExists,
                'message' => $phoneExists ? 'Phone number already exists' : 'Phone number is available',
            ];
        }

        // If neither email nor phone is provided, return an error
        if (empty($email) && empty($phone)) {
            return response()->json(['error' => 'Please provide either an email or a phone number'], 400);
        }

        return response()->json($response, 200);
    }




    // for store otp verification
    public function storeOtpVerificationData(Request $request)
    {
        // Validate request data
        $request->validate([
            'otp' => 'required',
            'phone' => 'required',
            'uid' => 'required'
        ]);

        // Find the OTP record based on the provided phone number and OTP
        $otpRecord = Otp::where('phone', $request->phone)
            ->where('otp', $request->otp)
            ->first();

        // Check if OTP record exists and if it's valid
        if (!$otpRecord) {
            return response()->json(['error' => 'Invalid OTP.'], 400);
        }

        // Assuming the OTP is valid, now we update the user's uid in the users table
        $user = User::where('phone', $request->phone)->latest()->first();

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Update the uid
        $user->uid = $request->uid;
        $user->save();

        // Optionally, you can delete the OTP record if it's no longer needed
        $otpRecord->delete();

        return response()->json(['message' => 'User registered successfully.']);
    }

    // for all user list
    // public function alluserlist(Request $request)
    // {
    //     try {


    //         $users = User::where('role_id', '!=', 1)
    //             ->with('role')
    //             ->get();

    //         // Extract the necessary information from each user
    //         $userList = $users->map(function ($user) {
    //             $roleName = $user->role ? $user->role->name : null; // Check if role is loaded
    //             return [
    //                 'id' => $user->id,
    //                 'first_name' => $user->first_name,
    //                 'last_name' => $user->last_name,
    //                 'email' => $user->email,
    //                 'phone' => $user->phone,
    //                 'role_name' => $roleName,
    //                 'unique_id' => $user->unique_id,
    //                 'isapproved' => $user->isapproved,
    //                 // Add more fields as needed
    //             ];
    //         });

    //         // Return the user list as JSON response
    //         return response()->json($userList, 200);
    //     } catch (\Throwable $th) {
    //         // Handle any exceptions and return an error response
    //         return response()->json(['error' => $th->getMessage()], 500);
    //     }
    // }

    public function alluserlist(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 20);

            $users = User::where('role_id', '!=', 1)
                ->with('role')
                ->paginate($perPage);

            $userList = $users->getCollection()->map(function ($user) {
                $roleName = $user->role ? $user->role->name : null;
                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'user_name' => $user->user_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role_name' => $roleName,
                    'unique_id' => $user->unique_id,
                    'isapproved' => $user->isapproved,
                ];
            });

            // Build pagination links
            $baseUrl = $request->url();
            $queryParams = $request->query();
            $queryParams['per_page'] = $users->perPage();

            $firstPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => 1]));
            $lastPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $users->lastPage()]));

            return response()->json([
                'message' => 'All Users List',
                'data' => $userList,
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'links' => [
                    'first' => $firstPageUrl,
                    'last' => $lastPageUrl,
                    'next' => $users->nextPageUrl(),
                    'prev' => $users->previousPageUrl(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // for get details by user_id
//         public function getdetailsbyuserid(Request $request)
// {
//     try {


    //         // Get user ID from the request
//         $userId = $request->input('id'); // Get the `id` from the query string (e.g., ?id=201)

    //         // Ensure the user exists
//         $userData = DB::table('users')
//             ->join('user_details', 'users.id', '=', 'user_details.user_id')
//             ->join('roles', 'users.role_id', '=', 'roles.id')
//             ->leftJoin('countries', 'user_details.country_id', '=', 'countries.id')
//             ->leftJoin('states', 'user_details.state_id', '=', 'states.id')
//             ->leftJoin('cities', 'user_details.city_id', '=', 'cities.id')
//             ->where('users.id', $userId)
//             ->select(
//                 'users.id',
//                 'users.first_name',
//                 'users.last_name',
//                 'users.email',
//                 'users.phone',
//                 'users.role_id',
//                 'users.unique_id',
//                 'users.isapproved',
//                 'roles.name as role_name',
//                 'user_details.bussiness_name',
//                 'user_details.bussiness_address',
//                 'user_details.bussiness_email',
//                 'user_details.business_phone',
//                 'countries.name as country',
//                 'states.name as state',
//                 'cities.name as city',
//                 'user_details.address',
//                 'user_details.pin_code',
//                 'user_details.profile_photo',
//                 'user_details.license_number',
//                 'user_details.alternate_number',
//                 'user_details.no_of_employees',
//                 'user_details.about_us',
//                 'users.created_at',
//                 'users.updated_at',
//                 'user_details.country_id',
//                 'user_details.state_id',
//                 'user_details.city_id'
//             )
//             ->first();

    //         // If no user found for the requested ID
//         if (!$userData) {
//             return response()->json(['error' => 'No data found for this user.'], 404);
//         }

    //         // Return the user data with the correct `id`
//         return response()->json([
//             'id' => $userData->id,  // Return the requested `id`
//             'first_name' => $userData->first_name,
//             'last_name' => $userData->last_name,
//             'email' => $userData->email,
//             'phone' => $userData->phone,
//             'role_id' => $userData->role_id,
//             'role_name' => $userData->role_name,
//             'unique_id' => $userData->unique_id,
//             'isapproved' => $userData->isapproved,
//             'bussiness_name' => $userData->bussiness_name,
//             'bussiness_address' => $userData->bussiness_address,
//             'bussiness_email' => $userData->bussiness_email,
//             'business_phone' => $userData->business_phone,
//             'country_id' => $userData->country_id ?? 'N/A', // Handle missing country_id
//             'state_id' => $userData->state_id ?? 'N/A', // Handle missing state_id
//             'city_id' => $userData->city_id ?? 'N/A', // Handle missing city_id
//             'address' => $userData->address,
//             'pin_code' => $userData->pin_code,
//             'profile_photo' => $userData->profile_photo ? url($userData->profile_photo) : null, // Convert to full URL
//             'license_number' => $userData->license_number,
//             'alternate_number' => $userData->alternate_number,
//             'no_of_employees' => $userData->no_of_employees,
//             'about_us' => $userData->about_us,
//             'created_at' => $userData->created_at,
//             'updated_at' => $userData->updated_at
//         ], 200);

    //     } catch (\Throwable $th) {
//         return response()->json(['error' => $th->getMessage()], 500);
//     }
// }



    public function getdetailsbyuserid(Request $request)
    {
        try {

            $userId = $request->id;

            // Fetch user details if not an admin
            $userData = DB::table('users')
                ->leftJoin('user_details', 'users.id', '=', 'user_details.user_id')
                ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                ->leftJoin('countries', 'user_details.country_id', '=', 'countries.id')
                ->leftJoin('states', 'user_details.state_id', '=', 'states.id')
                ->leftJoin('cities', 'user_details.city_id', '=', 'cities.id')
                ->leftJoin('countries as user_countries', 'users.country_id', '=', 'user_countries.id')
                ->leftJoin('states as user_states', 'users.state_id', '=', 'user_states.id')
                ->leftJoin('cities as user_cities', 'users.city_id', '=', 'user_cities.id')
                ->where('users.id', $userId)
                ->select(
                    'users.id',
                    'users.first_name',
                    'users.last_name',
                    'users.user_name',
                    'users.email',
                    'users.phone',
                    'users.role_id',
                    DB::raw("IFNULL(roles.name, 'No Role') as role_name"),
                    'users.unique_id',
                    'users.isapproved',

                    'users.country_id',
                    'users.state_id',
                    'users.city_id',
                    'user_countries.name as country',
                    'user_states.name as state',
                    'user_cities.name as city',
                    'users.area_locality',
                    'users.colony',
                    'users.street_address',
                    'users.pin_code',
                    'users.about',

                    'user_details.bussiness_name',
                    'user_details.bussiness_address',
                    'user_details.bussiness_email',
                    'user_details.business_phone',
                    'user_details.country_id as business_country_id',
                    'user_details.state_id as business_state_id',
                    'user_details.city_id as business_city_id',
                    'countries.name as business_country',
                    'states.name as business_state',
                    'cities.name as business_city',
                    'user_details.area_locality as business_area_locality',
                    'user_details.colony as business_colony',
                    'user_details.street_address as business_street_address',
                    'user_details.pin_code as business_pin_code',
                    // 'user_details.about_us as business_about_us',

                    'user_details.address',
                    'user_details.profile_photo',
                    'user_details.license_number',
                    'user_details.alternate_number',
                    'user_details.no_of_employees',
                    'user_details.about_us',
                    'users.created_at',
                    'users.updated_at',

                )
                ->first();

            // Debugging: Log the retrieved user data

            if (!$userData) {
                \Log::error('User not found', ['id' => $userId]);
                return response()->json(['error' => 'No data found for this user.'], 404);
            }

            return response()->json([
                'id' => $userData->id,
                'first_name' => $userData->first_name,
                'last_name' => $userData->last_name,
                'user_name' => $userData->user_name,
                'email' => $userData->email,
                'phone' => $userData->phone,
                'role_id' => $userData->role_id,
                'role_name' => $userData->role_name,
                'unique_id' => $userData->unique_id,
                'isapproved' => $userData->isapproved,
                // 'kyc' => $userData->kyc,
                'country_id' => $userData->country_id ?? 'N/A',
                'state_id' => $userData->state_id ?? 'N/A',
                'city_id' => $userData->city_id ?? 'N/A',
                'country' => $userData->country ?? 'N/A',
                'state' => $userData->state ?? 'N/A',
                'city' => $userData->city ?? 'N/A',
                'area_locality' => $userData->area_locality ?? 'N/A',
                'colony' => $userData->colony ?? 'N/A',
                'street_address' => $userData->street_address ?? 'N/A',
                'pin_code' => $userData->pin_code ?? 'N/A',
                'about' => $userData->about,
                //
                'bussiness_name' => $userData->bussiness_name,
                'bussiness_address' => $userData->bussiness_address,
                'bussiness_email' => $userData->bussiness_email,
                'business_phone' => $userData->business_phone,
                'business_country_id' => $userData->business_country_id ?? 'N/A',
                'business_state_id' => $userData->business_state_id ?? 'N/A',
                'business_city_id' => $userData->business_city_id ?? 'N/A',
                'business_country' => $userData->business_country ?? 'N/A',
                'business_state' => $userData->business_state ?? 'N/A',
                'business_city' => $userData->business_city ?? 'N/A',
                'business_area_locality' => $userData->business_area_locality ?? 'N/A',
                'business_colony' => $userData->business_colony ?? 'N/A',
                'business_street_address' => $userData->business_street_address ?? 'N/A',
                'business_pin_code' => $userData->business_pin_code ?? 'N/A',
                'address' => $userData->address,
                'profile_photo' => $userData->profile_photo ? url($userData->profile_photo) : null,
                'license_number' => $userData->license_number,
                'alternate_number' => $userData->alternate_number,
                'no_of_employees' => $userData->no_of_employees,
                'about_us' => $userData->about_us,
                'created_at' => $userData->created_at,
                'updated_at' => $userData->updated_at
            ], 200);

        } catch (\Throwable $th) {
            \Log::error('Error fetching user details:', ['error' => $th->getMessage()]);
            return response()->json(['error' => 'Internal Server Error.'], 500);
        }
    }


    public function getdetailsbyuseridForWebsite(Request $request)
    {
        try {


            // Get user ID from the request
            $userId = $request->input('id'); // Get the `id` from the query string (e.g., ?id=201)

            // Ensure the user exists
            $userData = DB::table('users')
                ->join('user_details', 'users.id', '=', 'user_details.user_id')
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->leftJoin('countries', 'user_details.country_id', '=', 'countries.id')
                ->leftJoin('states', 'user_details.state_id', '=', 'states.id')
                ->leftJoin('cities', 'user_details.city_id', '=', 'cities.id')
                ->where('users.id', $userId)
                ->select(
                    'users.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.phone',
                    'users.role_id',
                    'users.unique_id',
                    'users.isapproved',
                    'roles.name as role_name',
                    'user_details.bussiness_name',
                    'user_details.bussiness_address',
                    'user_details.bussiness_email',
                    'user_details.business_phone',
                    'countries.name as country',
                    'states.name as state',
                    'cities.name as city',
                    'user_details.address',
                    'user_details.pin_code',
                    'user_details.profile_photo',
                    'user_details.license_number',
                    'user_details.alternate_number',
                    'user_details.no_of_employees',
                    'user_details.about_us',
                    'users.created_at',
                    'users.updated_at',
                    'user_details.country_id',
                    'user_details.state_id',
                    'user_details.city_id'
                )
                ->first();

            // If no user found for the requested ID
            if (!$userData) {
                return response()->json(['error' => 'No data found for this user.'], 404);
            }

            // Return the user data with the correct `id`
            return response()->json([
                'id' => $userData->id,  // Return the requested `id`
                'first_name' => $userData->first_name,
                'last_name' => $userData->last_name,
                'email' => $userData->email,
                'phone' => $userData->phone,
                'role_id' => $userData->role_id,
                'role_name' => $userData->role_name,
                'unique_id' => $userData->unique_id,
                'isapproved' => $userData->isapproved,
                'bussiness_name' => $userData->bussiness_name,
                'bussiness_address' => $userData->bussiness_address,
                'bussiness_email' => $userData->bussiness_email,
                'business_phone' => $userData->business_phone,
                'country_id' => $userData->country_id ?? 'N/A', // Handle missing country_id
                'state_id' => $userData->state_id ?? 'N/A', // Handle missing state_id
                'city_id' => $userData->city_id ?? 'N/A', // Handle missing city_id
                'address' => $userData->address,
                'pin_code' => $userData->pin_code,
                'profile_photo' => $userData->profile_photo ? url($userData->profile_photo) : null, // Convert to full URL
                'license_number' => $userData->license_number,
                'alternate_number' => $userData->alternate_number,
                'no_of_employees' => $userData->no_of_employees,
                'about_us' => $userData->about_us,
                'created_at' => $userData->created_at,
                'updated_at' => $userData->updated_at
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // for update user by id
//     public function updateuserbyid(Request $request)
// {
//     // Authorization check


    //     // Validate request data
//     try {
//         $request->validate([
//             'first_name' => ['required', 'string', 'max:255'],
//             'last_name' => ['required', 'string', 'max:255'],

    //             'role_id' => ['required', 'exists:roles,id'],
//             'password' => [
//                 'nullable',
//                 'min:8',
//                 'regex:/[A-Z]/',  // At least one uppercase letter
//                 'regex:/[a-z]/',  // At least one lowercase letter
//                 'regex:/[0-9]/',  // At least one number
//                 'regex:/[@$!%*#?&]/', // At least one special character
//             ],
//         ]);
//     } catch (\Illuminate\Validation\ValidationException $e) {
//         return response()->json(['error' => $e->errors()], 400);
//     }

    //     // Prevent updating to the admin role
//     if ($request->role_id == 1) {
//         return response()->json(['error' => 'You cannot create the admin role as it already exists.'], 400);
//     }

    //     // Find the user
//     $user = User::find($request->id);

    //     // Check if user exists before updating
//     if (!$user) {
//         return response()->json(['error' => 'User not found.'], 404);
//     }

    //     // Update user details
//     // $user->email = $request->email;
//     $user->first_name = $request->first_name;
//     $user->last_name = $request->last_name;
//     $user->phone = $request->phone;
//     $user->role_id = $request->role_id;
//     $user->isapproved = $request->isapproved;
//     $user->reject_reason = $request->reject_reason;

    //     // Update password only if provided
//     if ($request->filled('password')) {
//         $user->password = Hash::make($request->password);
//     }

    //     DB::beginTransaction();
//     try {
//         $user->save();

    //         // Prepare user detail data
//         $userDetail = [
//             'user_id' => $user->id,
//             'role_id' => $request->role_id,
//             'bussiness_name' => $request->bussiness_name,
//             'bussiness_address' => $request->bussiness_address,
//             'bussiness_email' => $request->bussiness_email,
//             'business_phone' => $request->business_phone,
//             'country_id' => $request->country_id ?? null, // Use null if missing
//             'state_id' => $request->state_id ?? null, // Use null if missing
//             'city_id' => $request->city_id ?? null, // Use null if missing
//             'address' => $request->address,
//             'pin_code' => $request->pin_code,
//             'license_number' => $request->license_number,
//             'alternate_number' => $request->alternate_number,
//             'no_of_employees' => $request->no_of_employees,
//             'about_us' => $request->about_us
//         ];

    //         // Handle profile photo upload and store only the relative path
//         if ($request->hasFile('profile_photo')) {
//             $file = $request->file('profile_photo');
//             $fileName = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
//             $file->move(public_path('uploads/users'), $fileName);
//             $userDetail['profile_photo'] = 'uploads/users/' . $fileName; // Store relative path
//         }

    //         // Update user details
//         UserDetail::where('user_id', $user->id)->update($userDetail);

    //         DB::commit();

    //         return response()->json(['status' => true, 'message' => 'User updated successfully.'], 200);
//     } catch (\Exception $e) {
//         DB::rollBack();
//         \Log::error($e->getMessage());
//         return response()->json(['error' => 'Failed to update user. ' . $e->getMessage()], 500);
//     }
// }


    public function updateuserbyid(Request $request)
    {
        try {
            $request->validate([
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'role_id' => ['required', 'exists:roles,id'],
                'password' => [
                    'nullable',
                    'min:8',
                    'regex:/[A-Z]/',        // at least one uppercase
                    'regex:/[a-z]/',        // at least one lowercase
                    'regex:/[0-9]/',        // at least one digit
                    'regex:/[@$!%*#?&]/',   // at least one special character
                ],
                'user_name' => [
                    'required',
                    'string',
                    'min:3',
                    'max:20',
                    'regex:/^[a-zA-Z0-9._]+$/',
                    Rule::unique('users', 'user_name')->ignore($request->id),
                ],
                'country_id' => ['required', 'exists:countries,id'],
                'state_id' => ['required', 'exists:states,id'],
                'city_id' => ['required', 'exists:cities,id'],
                'area_locality' => ['nullable', 'string'],
                'colony' => ['nullable', 'string'],
                'street_address' => ['nullable', 'string'],
                'pin_code' => ['required', 'numeric', 'min:6'],
                'about' => ['nullable', 'string'],
            ], [
                'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
                'password.regex' => 'Password must include uppercase, lowercase, number, and special character.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        if ($request->role_id == 1) {
            return response()->json(['error' => 'You cannot create the admin role as it already exists.'], 400);
        }

        $role = Role::find($request->role_id);
        if (!$role) {
            return response()->json(['error' => 'Invalid role provided.'], 400);
        }



        // Conditional validation based on role
        $conditionalRoles = ['agent', 'company', 'developer', 'consultancy'];
        if (in_array(strtolower($role->name), $conditionalRoles)) {
            try {
                $request->validate([
                    'bussiness_name' => 'required|string|max:255',
                    'business_phone' => 'required|string|max:20',
                    'bussiness_email' => 'required|email',
                    'country_id' => 'required|numeric|exists:countries,id',
                    'state_id' => 'required|numeric|exists:states,id',
                    'city_id' => 'required|numeric|exists:cities,id',
                    'license_number' => 'required|string|max:100',
                    'business_country_id' => 'required|numeric|exists:countries,id',
                    'business_state_id' => 'required|numeric|exists:states,id',
                    'business_city_id' => 'required|numeric|exists:cities,id',
                    'business_area_locality' => 'nullable|string',
                    'business_colony' => 'nullable|string',
                    'business_street_address' => 'nullable|string',
                    'business_pin_code' => 'required|numeric|min:6',
                    'about_me' => 'nullable|string',
                ]);
            } catch (ValidationException $e) {
                return response()->json(['error' => $e->errors()], 400);
            }
        }

        $user = User::find($request->id);
        // \Log::info($user);
        if (!$user) {
            return response()->json(['error' => 'User not found.'], 200);
        }

        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->user_name = $request->user_name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->role_id = $request->role_id;
        $user->isapproved = $request->isapproved;
        $user->reject_reason = $request->reject_reason;
        $user->country_id = $request->country_id;
        $user->state_id = $request->state_id;
        $user->city_id = $request->city_id;
        $user->area_locality = $request->area_locality;
        $user->colony = $request->colony;
        $user->street_address = $request->street_address;
        $user->pin_code = $request->pin_code;
        $user->about = $request->about;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        DB::beginTransaction();
        try {
            $user->save();

            $userDetail = [
                'user_id' => $user->id,
                'role_id' => $request->role_id,
                'bussiness_name' => $request->bussiness_name,
                'bussiness_address' => $request->bussiness_address,
                'bussiness_email' => $request->bussiness_email,
                'business_phone' => $request->business_phone,
                'country_id' => $request->business_country_id ?? null,
                'state_id' => $request->business_state_id ?? null,
                'city_id' => $request->business_city_id ?? null,
                'address' => $request->address,
                'pin_code' => $request->business_pin_code,
                'license_number' => $request->license_number,
                'alternate_number' => $request->alternate_number,
                'no_of_employees' => $request->no_of_employees,
                'about_us' => $request->about_us,
                'area_locality' => $request->business_area_locality,
                'colony' => $request->business_colony,
                'street_address' => $request->business_street_address,
            ];

            if ($request->hasFile('profile_photo')) {
                $file = $request->file('profile_photo');
                $fileName = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $file->move(public_path('uploads/users'), $fileName);
                $userDetail['profile_photo'] = 'uploads/users/' . $fileName;
            }

            UserDetail::where('user_id', $user->id)->update($userDetail);



            // Check role and required fields for KYC update
            $rolesForKYC = ['agent', 'company', 'consultancy', 'developer'];
            $role = Role::find($request->role_id);
            if ($role && in_array(strtolower($role->name), $rolesForKYC)) {
                if (
                    $request->bussiness_name && $request->bussiness_address && $request->bussiness_email &&
                    $request->business_phone && $request->country_id && $request->state_id && $request->city_id &&
                    $request->address && $request->pin_code && $request->license_number && $request->business_country_id &&
                    $request->business_state_id && $request->business_city_id && $request->business_area_locality && $request->business_colony && $request->business_street_address && $request->business_pin_code
                ) {
                    $user->isapproved = 3;
                    $user->save();
                }
            }

            DB::commit();

            return response()->json(['status' => true, 'message' => 'User updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed to update user. ' . $e->getMessage()], 500);
        }
    }


    // for update user status
    public function updateuserstatus(Request $request)
    {
        try {


            // Validate the incoming request data
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'isapproved' => 'required|integer|in:1,2,3,4', // Ensure it's one of the allowed values
                'reject_reason' => 'required_if:isapproved,4|string|min:3', // Required if isapproved is 4
            ], [
                'reject_reason.required_if' => 'Reject reason is required when status is rejected.',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Find the user by ID
            $user = User::find($request->input('user_id'));

            if (!$user) {
                return response()->json(['error' => 'User not found.'], 404);
            }

            // Prevent updating admin status
            if ($user->id == 1 || $user->role_id == 1) {
                return response()->json(['message' => 'You cannot update admin.'], 422);
            }

            // Update the isapproved status in the users table
            $user->isapproved = $request->input('isapproved');

            // Save reject_reason if status is rejected
            if ($request->input('isapproved') == 4) {
                $user->reject_reason = $request->input('reject_reason');
            } else {
                $user->reject_reason = null; // Clear reject_reason if not rejected
            }

            $user->save();

            // Prepare response message
            $message = ($user->isapproved == 1) ? 'User status updated to approved.' : (($user->isapproved == 4) ? 'User status updated to rejected.' : 'User status updated.');
            $loginMessage = ($user->isapproved == 1) ? 'User can now login.' : 'User cannot login.';

            return response()->json(['message' => $message, 'login_message' => $loginMessage], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // for get all status
    // public function getallstatus(Request $request)
    // {
    //     $statuses = ['approved', 'reject', 'pending'];

    //     return response()->json(['data' => $statuses]);
    // }

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

    // for get data by token
    public function getDataByToken(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        try {
            $lastSegment = $request->id;

            $user = User::where('api_token', $lastSegment)->first();

            // If user not found
            if (!$user) {
                return response()->json(['error' => 'Invalid URL'], 401);
            }

            // Restrict access for admin users
            if ($user->role->name === 'admin') {
                return response()->json(['error' => 'Access denied'], 403);
            }

            return response()->json([
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role' => $user->role->name,
                'token' => $user->api_token,
                'isapproved' => $user->isapproved,
                'is_login' => true,
                'kyc' => $user->kyc
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }









    public function allAgentListingByAdmin(Request $request)
    {
        try {
            // Fetch users with the role name 'agent' and eager load related data
            $users = User::whereHas('role', function ($query) {
                $query->where('name', 'agent');
            })
                ->with('role')
                ->with([
                    'userDetails' => function ($query) {
                        $query->with(['country', 'state', 'city']); // Load country, state, and city relationships
                    }
                ])
                ->get();

            // Extract the necessary information from each user
            $userList = $users->map(function ($user) {
                $roleName = $user->role ? $user->role->name : null;
                $userDetails = $user->userDetails ?? null;

                // Get country, state, and city names
                $countryName = $userDetails && $userDetails->country ? $userDetails->country->name : null;
                $stateName = $userDetails && $userDetails->state ? $userDetails->state->name : null;
                $cityName = $userDetails && $userDetails->city ? $userDetails->city->name : null;

                // Generate profile photo URL
                $profilePhotoUrl = $userDetails && $userDetails->profile_photo
                    ? url($userDetails->profile_photo) // Generate the full URL to the profile photo
                    : null;

                return [
                    'id' => $user->id,
                    'fullname' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role_name' => $roleName,
                    'role_id' => $user->role_id,
                    'unique_id' => $user->unique_id,
                    'isapproved' => $user->isapproved,
                    'bussiness_name' => $userDetails ? $userDetails->bussiness_name : null,
                    'bussiness_address' => $userDetails ? $userDetails->bussiness_address : null,
                    'bussiness_email' => $userDetails ? $userDetails->bussiness_email : null,
                    'business_phone' => $userDetails ? $userDetails->business_phone : null,
                    'profile_photo' => $profilePhotoUrl, // Include the full URL to the profile photo
                    'address' => $userDetails ? $userDetails->address : null,

                    // Include country, state, and city details
                    'country_id' => $userDetails && $userDetails->country ? $userDetails->country->id : null,
                    'country_name' => $userDetails && $userDetails->country ? $userDetails->country->name : null,
                    'state_id' => $userDetails && $userDetails->state ? $userDetails->state->id : null,
                    'state_name' => $userDetails && $userDetails->state ? $userDetails->state->name : null,
                    'city_id' => $userDetails && $userDetails->city ? $userDetails->city->id : null,
                    'city_name' => $userDetails && $userDetails->city ? $userDetails->city->name : null,

                    'pin_code' => $userDetails ? $userDetails->pin_code : null,
                    'license_number' => $userDetails ? $userDetails->license_number : null,
                    'alternate_number' => $userDetails ? $userDetails->alternate_number : null,
                ];
            });

            // Return the user list as JSON response
            return response()->json($userList, 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // for cosultancy listing
    public function allConsultancyListing(Request $request)
    {
        try {
            $users = User::where('role_id', 5)
                ->with('role')
                ->with('userDetails')
                ->get();

            // Extract the necessary information from each user
            $userList = $users->map(function ($user) {
                $roleName = $user->role ? $user->role->name : null;
                $userDetails = $user->userDetails ?? null;

                return [
                    'id' => $user->id,
                    'fullname' => $user->fullname,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role_name' => $roleName,
                    'role_id' => $user->role_id,
                    'uid' => $user->uid,
                    'unique_id' => $user->unique_id,
                    'isapproved' => $user->isapproved,
                    'kyc' => $user->kyc,
                    'bussiness_name' => $userDetails ? $userDetails->bussiness_name : null,
                    'bussiness_address' => $userDetails ? $userDetails->bussiness_address : null,
                    'bussiness_email' => $userDetails ? $userDetails->bussiness_email : null,
                    'business_phone' => $userDetails ? $userDetails->business_phone : null,
                    'profile_photo' => $userDetails ? $userDetails->profile_photo : null,
                    'address' => $userDetails ? $userDetails->address : null,
                    'country' => $userDetails ? $userDetails->country : null,
                    'state' => $userDetails ? $userDetails->state : null,
                    'city' => $userDetails ? $userDetails->city : null,
                    'pin_code' => $userDetails ? $userDetails->pin_code : null,
                    'license_number' => $userDetails ? $userDetails->license_number : null,
                    'alternate_number' => $userDetails ? $userDetails->alternate_number : null,
                ];
            });

            // Return the user list as JSON response
            return response()->json($userList, 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function getAllConsultancyListingByCompany(Request $request)
    {
        try {
            $authUser = Auth::user();

            if (!$authUser) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            // Fetch consultancies that accepted the request
            $consultancies = \App\Models\JoinRequest::with('user.userDetails', 'user.role')
                ->where('type', 'company-consultancy')
                ->where('status', 2)
                ->get();

            // Format the response
            $consultancyList = $consultancies->map(function ($joinRequest) {
                $user = $joinRequest->user;
                $userDetails = $user?->userDetails;

                return [
                    'id' => $joinRequest->id,
                    'fullname' => $user?->fullname,
                    'email' => $user?->email,
                    'phone' => $user?->phone,
                    'role_name' => optional($user?->role)->name,
                    'role_id' => $user?->role_id,
                    'uid' => $user?->uid,
                    'unique_id' => $user?->unique_id,
                    'isapproved' => $user?->isapproved,
                    'bussiness_name' => $userDetails?->bussiness_name,
                    'bussiness_address' => $userDetails?->bussiness_address,
                    'bussiness_email' => $userDetails?->bussiness_email,
                    'business_phone' => $userDetails?->business_phone,
                    'profile_photo' => $userDetails?->profile_photo,
                    'address' => $userDetails?->address,
                    'country' => $userDetails?->country,
                    'state' => $userDetails?->state,
                    'city' => $userDetails?->city,
                    'pin_code' => $userDetails?->pin_code,
                    'license_number' => $userDetails?->license_number,
                    'alternate_number' => $userDetails?->alternate_number,
                ];
            });

            return response()->json($consultancyList, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function createUser(Request $request)
    {

        $authUser = Auth::user();

        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'required|unique:users',
                'email' => 'required|email|unique:users',
                'role_id' => 'required|exists:roles,id',
                'password' => 'required',
                'country_id' => 'required|exists:countries,id',
                'state_id' => 'required|exists:states,id',
                'city_id' => 'required|exists:cities,id',
                'area_locality' => 'nullable|string',
                'colony' => 'nullable|string',
                'street_address' => 'nullable|string',
                'pin_code' => 'required|numeric|min:6',
                'about' => 'nullable|string',
                'user_name' => 'required|string|min:3|max:20|unique:users,user_name|regex:/^[a-zA-Z0-9._]+$/',
            ], [
                'user_name.regex' => 'Only letters, numbers, dot and underscore are allowed in username.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Failed',
                'errors' => $e->errors()
            ], 422);
        }

        // Fetch the role using role_id
        $role = Role::find($request->role_id);
        if (!$role) {
            return response()->json(['error' => 'Invalid role provided.'], 400);
        }

        // **Dynamic Admin Role Check**
        if (strtolower($role->name) === 'admin') {
            return response()->json(['error' => 'You cannot create an admin role.'], 400);
        }

        // Conditional validation for specific roles
        $conditionalRoles = ['agent', 'company', 'developer', 'consultancy'];
        if (in_array(strtolower($role->name), $conditionalRoles)) {
            try {
                $request->validate([
                    'bussiness_name' => 'required|string|max:255',
                    'business_phone' => 'required|string|max:20',
                    'bussiness_email' => 'required|email',
                    'business_country_id' => 'required|numeric|exists:countries,id',
                    'business_state_id' => 'required|numeric|exists:states,id',
                    'business_city_id' => 'required|numeric|exists:cities,id',
                    'license_number' => 'required|string|max:100',
                    'business_area_locality' => 'nullable|string',
                    'business_colony' => 'nullable|string',
                    'business_street_address' => 'nullable|string',
                    'business_pin_code' => 'required|numeric|min:6',
                    'about_me' => 'nullable|string',
                ]);
            } catch (ValidationException $e) {
                return response()->json([
                    'message' => 'Validation Failed (Additional Fields)',
                    'errors' => $e->errors()
                ], 422);
            }
        }



        $prefix = $role->prefix ?? '';
        $uniqueIDModel = new UniqueID();
        $uniqueIDModel->unique_id = $prefix . str_pad(UniqueID::count() + 1, 3, '0', STR_PAD_LEFT);
        $uniqueIDModel->save();

        $token = Str::random(60);

        // Handle profile photo upload
        $profilePhotoPath = null;
        if ($request->hasFile('profile_photo')) {
            $photo = $request->file('profile_photo');
            $fileName = time() . '_' . str_replace(' ', '_', $photo->getClientOriginalName());
            $photo->move(public_path('uploads/users'), $fileName);
            $profilePhotoPath = 'uploads/users/' . $fileName;
        } elseif ($request->has('icon') && !empty($request->icon)) {
            $profilePhotoPath = $request->icon;
        }

        DB::beginTransaction();
        try {
            $user = User::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'user_name' => $request->user_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'api_token' => $token,
                'remember_token' => $request->token,
                'unique_id' => $uniqueIDModel->unique_id,
                'role_id' => $request->role_id,
                'password' => Hash::make($request->password),
                'isapproved' => $request->isapproved,
                'created_by' => $authUser->id,
                'country_id' => $request->country_id,
                'state_id' => $request->state_id,
                'city_id' => $request->city_id,
                'area_locality' => $request->area_locality,
                'colony' => $request->colony,
                'street_address' => $request->street_address,
                'pin_code' => $request->pin_code,
                'about' => $request->about
            ]);
            // dd($user);
            DB::table('user_has_unique_ids')->insert([
                'user_id' => $user->id,
                'unique_id' => $uniqueIDModel->id,
            ]);

            UserDetail::create([
                'user_id' => $user->id,
                'role_id' => $request->role_id,
                'bussiness_name' => $request->bussiness_name,
                'bussiness_address' => $request->bussiness_address,
                'bussiness_email' => $request->bussiness_email,
                'business_phone' => $request->business_phone,
                'country_id' => $request->business_country_id,
                'state_id' => $request->business_state_id,
                'city_id' => $request->business_city_id,
                'address' => $request->business_address,
                'pin_code' => $request->business_pin_code,
                'license_number' => $request->license_number,
                'alternate_number' => $request->alternate_number,
                'no_of_employees' => $request->no_of_employees,
                'profile_photo' => $profilePhotoPath,
                'created_by' => $authUser->id,
                'about_us' => $request->about_us,
                'area_locality' => $request->business_area_locality,
                'colony' => $request->business_colony,
                'street_address' => $request->business_street_address,
            ]);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'User created successfully.'], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed to create user. ' . $e->getMessage()], 500);
        }
    }


    public function updateUser(Request $request)
    {

        // Validate request data
        try {
            $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'id' => 'required|exists:users,id',
                'phone' => 'required|unique:users,phone,' . $request->id,
                'email' => 'required|unique:users,email,' . $request->id,
                'role_id' => 'required|exists:roles,id',
                'password' => 'nullable',

            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        $adminRoleId = 1;
        if ($request->role_id == $adminRoleId) {
            return response()->json(['error' => 'You cannot create the admin role as it already exists.'], 400);
        }

        $role = Role::find($request->role_id);
        if (!$role) {
            return response()->json(['error' => 'Invalid role provided.'], 400);
        }



        // Conditional validation based on role
        $conditionalRoles = ['agent', 'company', 'developer', 'consultancy'];
        if (in_array(strtolower($role->name), $conditionalRoles)) {
            try {
                $request->validate([
                    'bussiness_name' => 'required|string|max:255',
                    'business_phone' => 'required|string|max:20',
                    'bussiness_email' => 'required|email',
                    'country_id' => 'required|numeric|exists:countries,id',
                    'state_id' => 'required|numeric|exists:states,id',
                    'city_id' => 'required|numeric|exists:cities,id',
                    'license_number' => 'required|string|max:100',
                ]);
            } catch (ValidationException $e) {
                return response()->json(['error' => $e->errors()], 400);
            }
        }



        $id = $request->id;
        $user = User::find($id);

        if (!$user) {
            return response()->json(['error' => 'User not found.'], 200);
        }
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->role_id = $request->role_id;
        $user->user_name = $request->user_name;

        // Update password only if provided
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        DB::beginTransaction();
        try {
            $user->save();

            $userDetail = [
                'user_id' => $user->id,
                'role_id' => $request->role_id,
                'bussiness_name' => $request->bussiness_name,
                'bussiness_address' => $request->bussiness_address,
                'bussiness_email' => $request->bussiness_email,
                'business_phone' => $request->business_phone,
                'country_id' => is_numeric($request->country_id) ? $request->country_id : null,
                'state_id' => is_numeric($request->state_id) ? $request->state_id : null,
                'city_id' => is_numeric($request->city_id) ? $request->city_id : null,
                'address' => $request->address,
                'pin_code' => $request->pin_code,
                'license_number' => $request->license_number,
                'alternate_number' => $request->alternate_number,
                'no_of_employees' => $request->no_of_employees,
            ];


            // Handle profile photo or icon
            $profilePhotoPath = null;
            if ($request->hasFile('profile_photo')) {
                $photo = $request->file('profile_photo');
                $fileName = time() . '_' . str_replace(' ', '_', $photo->getClientOriginalName());
                $photo->move(public_path('uploads/users'), $fileName);
                $profilePhotoPath = 'uploads/users/' . $fileName;
            } elseif ($request->has('icon') && !empty($request->icon)) {
                $profilePhotoPath = $request->icon;
            }

            if ($profilePhotoPath) {
                $userDetail['profile_photo'] = $profilePhotoPath;
            }

            UserDetail::where('user_id', $id)->update($userDetail);

            DB::commit();

            return response()->json(['status' => true, 'message' => 'User updated successfully.'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed to update user. ' . $e->getMessage()], 500);
        }
    }


    //  for delete user
    public function deleteUser(Request $request)
    {


        try {
            $request->validate([
                'id' => 'required|exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        $id = $request->id;

        User::where('id', $id)->delete();
        UserDetail::where('user_id', $id)->delete();

        return response()->json(['status' => true, 'message' => 'User deleted successfully.'], 201);
    }



    // for create agent
    // public function createAgent(Request $request)
    // {

    //     if ($request->header('api-token') == '') {
    //         return response()->json(['error' => 'Please enter api token first.'], 422);
    //     }

    //     $requestToken = $request->header('api-token');


    //     $userId = null;

    //     $userData = User::where('api_token', $requestToken)->first();

    //     // Validate that the user exists in the database
    //     if (!$userData) {
    //         return response()->json(['error' => 'User not found'], 404);
    //     }

    //     $userId = $userData->id;

    //     // Check if the role is admin
    //     $adminRoleId = 1;
    //     if ($request->role_id == $adminRoleId) {
    //         return response()->json(['error' => 'You cannot create the admin role as it already exists.'], 400);
    //     }

    //     // Generate unique ID based on role
    //     $role = Role::find($request->role_id);
    //     if (!$role) {
    //         return response()->json(['error' => 'Invalid role provided.'], 400);
    //     }

    //     // Validate request data
    //     try {
    //         $request->validate([
    //             'phone' => 'required|unique:users',
    //             'email' => 'required|unique:users',
    //             // 'role_id' => 'required|exists:roles,id',
    //         ]);
    //     } catch (ValidationException $e) {
    //         return response()->json(['error' => $e->errors()], 400);
    //     }

    //     $prefix = '';
    //     switch ($role->name) {
    //         case 'agent':
    //             $prefix = 'URA';
    //             break;
    //         case 'developer':
    //             $prefix = 'URD';
    //             break;
    //         case 'consultancy':
    //             $prefix = 'URC';
    //             break;
    //         case 'owner':
    //             $prefix = 'URO';
    //             break;
    //         case 'company':
    //             $prefix = 'URCMY';
    //             break;
    //         default:
    //             break;
    //     }

    //     // Create a new UniqueID model and save it
    //     $uniqueIDModel = new UniqueID();
    //     $uniqueIDModel->unique_id = $prefix . str_pad($uniqueIDModel->count() + 1, 3, '0', STR_PAD_LEFT);
    //     $uniqueIDModel->save();

    //     $token = Str::random(60);

    //     $isapproved = 'approved';

    //     // Create a new user
    //     $user = new User();
    //     $user->fullname = $request->fullname;
    //     $user->email = $request->email;
    //     $user->phone = $request->phone;
    //     $user->api_token = $token;
    //     $user->remember_token = $request->token;
    //     $user->uid = $request->uid;
    //     $user->role_id = $request->role_id;
    //     $user->password = Hash::make($request->password);
    //     $user->unique_id = $uniqueIDModel->unique_id;
    //     $user->isapproved = $isapproved;
    //     $user->created_by = $userId;

    //     // Add entry to user_has_unique_ids table
    //     DB::beginTransaction();
    //     try {
    //         $user->save();
    //         DB::table('user_has_unique_ids')->insert([
    //             'user_id' => $user->id,
    //             'unique_id' => $uniqueIDModel->id,
    //         ]);

    //         $userDetailData = UserDetail::where('user_id', $userId)->first();

    //         $userDetail = array(
    //             'user_id' => $user->id,
    //             'created_by' => $userId,
    //             'role_id' => $request->role_id,
    //             'bussiness_name' => isset($request->bussiness_name) ? $request->bussiness_name : $userDetailData->bussiness_name,
    //             'bussiness_address' => isset($request->bussiness_address) ? $request->bussiness_address : $userDetailData->bussiness_address,
    //             'bussiness_email' => isset($request->bussiness_email) ? $request->bussiness_email : $userDetailData->bussiness_email,
    //             'business_phone' => isset($request->business_phone) ? $request->business_phone : $userDetailData->business_phone,
    //             'country' => isset($request->country) ? $request->country : $userDetailData->country,
    //             'state' => isset($request->state) ? $request->state : $userDetailData->state,
    //             'city' => isset($request->city) ? $request->city : $userDetailData->city,
    //             'address' => isset($request->address) ? $request->address : $userDetailData->address,
    //             'pin_code' => isset($request->pin_code) ? $request->pin_code : $userDetailData->pin_code,
    //             'license_number' => isset($request->license_number) ? $request->license_number : $userDetailData->license_number,
    //             'alternate_number' => isset($request->alternate_number) ? $request->alternate_number : $userDetailData->alternate_number,
    //             'no_of_employees' => isset($request->no_of_employees) ? $request->no_of_employees : $userDetailData->no_of_employees,
    //             'purpose_id' => isset($request->purpose_id) ? $request->purpose_id : $userDetailData->purpose_id,
    //             'property_id' => isset($request->property_id) ? $request->property_id : $userDetailData->property_id,
    //             'property_type_id' => isset($request->property_type_id) ? $request->property_type_id : $userDetailData->property_type_id,
    //         );


    //         if ($userDetailData->profile_photo) {
    //             $userDetail['profile_photo'] = $userDetailData->profile_photo;
    //         }

    //         UserDetail::create($userDetail);

    //         DB::commit();

    //         return response()->json(['status' => true, 'message' => 'Agent created successfully.'], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         \Log::error($e->getMessage()); // Log the exception message
    //         return response()->json(['error' => 'Failed to create user.' . $e->getMessage()], 500);
    //     }
    // }
    public function createAgent(Request $request)
    {
        // Check if API token is present
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract the token by removing the "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Check if the token is empty after removing "Bearer "
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the token dynamically (check in the database)
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $userId = $user->id;

        $role = Role::find($user->role_id);
        if (!$role || $role->name !== 'consultancy') {
            return response()->json(['error' => 'User does not have the required role.'], 400);
        }

        // Fetch the prefix from the role table
        $prefix = $role->prefix;  // Assuming the 'prefix' field exists in the roles table

        // Validate request data
        try {
            $request->validate([
                'phone' => 'required|unique:users',
                'email' => 'required|unique:users',
                // 'role_id' => 'required|exists:roles,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        // Generate unique ID for the user using the dynamic prefix
        $uniqueIDModel = new UniqueID();
        $uniqueIDModel->unique_id = $prefix . str_pad($uniqueIDModel->count() + 1, 3, '0', STR_PAD_LEFT);
        $uniqueIDModel->save();

        $token = Str::random(60);
        $isapproved = '1';
        $role = Role::find($request->role_id);
        if (!$role || $role->name !== 'agent') {
            return response()->json(['error' => 'Invalid role ID. Only "agent" role is allowed.'], 422);
        }
        // Create new user
        $user = new User();
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->email = $request->email;
        $user->phone = $request->phone;
        $user->api_token = $token;
        $user->remember_token = $request->token;
        // $user->uid = $request->uid;
        $user->role_id = $request->role_id;
        $user->password = Hash::make($request->password);
        $user->unique_id = $uniqueIDModel->unique_id;
        $user->isapproved = $isapproved;
        $user->created_by = $userId;

        // Begin transaction for user creation and user details
        DB::beginTransaction();
        try {
            $user->save();
            DB::table('user_has_unique_ids')->insert([
                'user_id' => $user->id,
                'unique_id' => $uniqueIDModel->id,
            ]);

            // Add user details
            $userDetailData = UserDetail::where('user_id', $userId)->first();
            $userDetail = array(
                'user_id' => $user->id,
                'created_by' => $userId,
                'role_id' => $request->role_id,
                'bussiness_name' => isset($request->bussiness_name) ? $request->bussiness_name : $userDetailData->bussiness_name,
                'bussiness_address' => isset($request->bussiness_address) ? $request->bussiness_address : $userDetailData->bussiness_address,
                'bussiness_email' => isset($request->bussiness_email) ? $request->bussiness_email : $userDetailData->bussiness_email,
                'business_phone' => isset($request->business_phone) ? $request->business_phone : $userDetailData->business_phone,
                'country' => isset($request->country) ? $request->country : $userDetailData->country,
                'state' => isset($request->state) ? $request->state : $userDetailData->state,
                'city' => isset($request->city) ? $request->city : $userDetailData->city,
                'address' => isset($request->address) ? $request->address : $userDetailData->address,
                'pin_code' => isset($request->pin_code) ? $request->pin_code : $userDetailData->pin_code,
                'license_number' => isset($request->license_number) ? $request->license_number : $userDetailData->license_number,
                'alternate_number' => isset($request->alternate_number) ? $request->alternate_number : $userDetailData->alternate_number,
                'no_of_employees' => isset($request->no_of_employees) ? $request->no_of_employees : $userDetailData->no_of_employees,
                'purpose_id' => isset($request->purpose_id) ? $request->purpose_id : $userDetailData->purpose_id,
                'property_id' => isset($request->property_id) ? $request->property_id : $userDetailData->property_id,
                'property_type_id' => isset($request->property_type_id) ? $request->property_type_id : $userDetailData->property_type_id,
            );

            if ($userDetailData->profile_photo) {
                $userDetail['profile_photo'] = $userDetailData->profile_photo;
            }

            UserDetail::create($userDetail);
            DB::commit();

            return response()->json(['status' => true, 'message' => 'Agent created successfully.'], 201);


        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed to create user. ' . $e->getMessage()], 500);
        }
    }


    // for agent listings
    public function getAgentListing(Request $request)
    {
        try {

            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');


            $userId = null;

            $userData = User::where('api_token', $requestToken)->first();

            $userId = $userData->id;

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }


            $users = User::where('created_by', $userId)
                ->with('role')
                ->with('userDetails')
                ->get();

            // Extract the necessary information from each user
            $userList = $users->map(function ($user) {
                $roleName = $user->role ? $user->role->name : null;
                $userDetails = $user->userDetails ?? null;
                return [
                    'id' => $user->id,
                    'fullname' => $user->fullname,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role_name' => $roleName,
                    'user_id' => $user->id,
                    'role_id' => $user->role_id,
                    'api_token' => $user->api_token,
                    'uid' => $user->uid,
                    'unique_id' => $user->unique_id,
                    'isapproved' => $user->isapproved,
                    'created_by' => $userDetails ? $userDetails->created_by : null,
                    'bussiness_name' => $userDetails ? $userDetails->bussiness_name : null,
                    'bussiness_address' => $userDetails ? $userDetails->bussiness_address : null,
                    'bussiness_email' => $userDetails ? $userDetails->bussiness_email : null,
                    'business_phone' => $userDetails ? $userDetails->business_phone : null,
                    'profile_photo' => $userDetails ? url($userDetails->profile_photo) : null,
                    'address' => $userDetails ? $userDetails->address : null,
                    'country' => $userDetails ? $userDetails->country : null,
                    'state' => $userDetails ? $userDetails->state : null,
                    'city' => $userDetails ? $userDetails->city : null,
                    'pin_code' => $userDetails ? $userDetails->pin_code : null,
                    'license_number' => $userDetails ? $userDetails->license_number : null,
                    'alternate_number' => $userDetails ? $userDetails->alternate_number : null,
                    'no_of_employees' => $userDetails ? $userDetails->no_of_employees : null,
                    'purpose_id' => $userDetails ? explode(',', $userDetails->purpose_id) : null,
                    'property_id' => $userDetails ? explode(',', $userDetails->property_id) : null,
                    'property_type_id' => $userDetails ? explode(',', $userDetails->property_type_id) : null,
                ];


            });


            // Return the user list as JSON response
            return response()->json($userList, 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // for consultancy agent listings
    public function getCompanyConsultancyListing(Request $request)
    {
        try {
            // Retrieve authenticated user
            $user = auth()->user();
            $userId = $user->id;

            // Fetch users created by the authenticated company
            $consultancy_ids_from_users_created_by = User::where('created_by', $userId)
                ->pluck('id')
                ->toArray();

            // Check if there's a relevant column linking join requests to companies
            $consultancy_ids_from_join_requests = JoinRequest::where([
                'user_id' => $userId, // Adjust if `user_id` isn't the correct company reference
                'status' => 2 // Status 2 = Accepted
            ])
                ->pluck('user_id') // Ensure this column exists and stores user IDs
                ->toArray();

            // Merge and remove duplicates
            $final_consultancy_id_arr = array_unique(
                array_merge($consultancy_ids_from_users_created_by, $consultancy_ids_from_join_requests)
            );

            // Retrieve users with role and details
            $users = User::whereIn('id', $final_consultancy_id_arr)
                ->with(['role', 'userDetails'])
                ->get();

            return response()->json($users, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // for seach consultancy by company
    // public function searchConsultancyById(Request $request)
    // {
    //     try {

    //         if ($request->header('api-token') == '') {
    //             return response()->json(['error' => 'Please enter api token first.'], 422);
    //         }

    //         $requestToken = $request->header('api-token');

    //         $userId = null;

    //         $userData = User::where('api_token', $requestToken)->first();

    //         // Validate that the user exists in the database
    //         if (!$userData) {
    //             return response()->json(['error' => 'Company not found'], 404);
    //         }

    //         $userId = $userData->id;

    //         $userData = User::where('role_id', operator: 5)->where('created_by', 0)->get();

    //         $returnArr = [];

    //         if (count($userData)) {

    //             foreach ($userData as $user) {

    //                 $joinRequestData = JoinRequest::where('consultancy_id', $user->id)->where('company_id', $userId)->first();

    //                 if (!$joinRequestData) {
    //                     $user_status = 'normal';
    //                 } else {

    //                     if ($joinRequestData->status == 'accepted') {
    //                         $user_status = 'conneted';
    //                     } elseif ($joinRequestData->status == 'requested') {
    //                         $user_status = 'requested';
    //                     } else {
    //                         $user_status = 'normal';
    //                     }

    //                 }

    //                 $returnArr[] = [
    //                     'id' => $user->id,
    //                     'name' => $user->fullname,
    //                     'email' => $user->email,
    //                     'phone' => $user->phone,
    //                     'role_id' => $user->role_id,
    //                     'unique_id' => $user->unique_id,
    //                     'user_status' => $user_status,
    //                 ];

    //             }

    //         }

    //         return response()->json(['status' => true, 'data' => $returnArr], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json(['error' => 'Failed.' . $e->getMessage()], 500);
    //     }
    // }
    public function searchConsultancyById(Request $request)
    {
        try {
            // Get authenticated user (set by the middleware)
            $user = Auth::user();
            $userId = $user->id;

            // Fetch users with the role name "Consultancy" and created_by = 0
            $userDataList = User::whereHas('role', function ($query) {
                $query->where('name', 'Consultancy'); // Checking by role name
            })->where('created_by', 0)
                ->with([
                    'userDetails',
                    'joinRequest' => function ($query) use ($userId) {
                        $query->where('user_id', $userId);
                    }
                ])->get();
            $user = User::find($userId);
            $userDetails = $user->userDetails; // This should return the related user_details

            $returnArr = [];

            foreach ($userDataList as $user) {
                // Get business name from user_details
                $business_name = optional($user->userDetails)->business_name ?? '';

                // Get join request status
                $joinRequestData = $user->joinRequest->first();
                $user_status = match (optional($joinRequestData)->status) {
                    'accepted' => 'connected',
                    'requested' => 'requested',
                    default => 'normal',
                };

                // **Allowed fields only: business_name, unique_id, email_id**
                $returnArr[] = [
                    'business_name' => $userDetails->bussiness_name,
                    'bussiness_address' => $userDetails->bussiness_address,
                    'unique_id' => $user->unique_id,
                    'email_id' => $user->email,
                ];
            }

            return response()->json(['status' => true, 'data' => $returnArr], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed: ' . $e->getMessage()], 500);
        }
    }




    // for send join request to consultancy
    public function sendRequestByCompanyToConsultancy(Request $request)
    {
        try {

            $companyUser = Auth::user();
            $companyId = $companyUser->id;

            if (!$companyId) {
                return response()->json(['error' => 'Company not found'], 404);
            }
            $userId = $companyUser->id;

            // Validate that the consultancy user exists in the database
            if (!User::where('id', $request->consultancy_id)->exists()) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            // Check if the request already exists
            $check = JoinRequest::where([
                'user_id' => $request->consultancy_id,
                'type' => 'company-consultancy'
            ])->first();

            if ($check) {
                JoinRequest::where([
                    'user_id' => $request->consultancy_id,
                    'type' => 'company-consultancy'
                ])->delete();

                return response()->json(['status' => true, 'message' => 'Request removed successfully.'], 201);
            } else {
                JoinRequest::insert([
                    'user_id' => $request->consultancy_id,
                    'type' => 'company-consultancy',
                    'status' => 1, // Changed from 'requested' to 1
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                return response()->json(['status' => true, 'message' => 'Request sent successfully.'], 201);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }



    // for get all join requests of consultancy
    public function getConsultancyAllJoinRequest(Request $request)
    {
        try {
            $consultancyUser = Auth::user();

            if (!$consultancyUser) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $consultancyUser->id;

            // Fetch join requests with related company and user details
            $joinRequests = JoinRequest::with(['company.userDetail'])
                ->where('type', 'company-consultancy')
                ->where('user_id', $userId)
                ->get();

            if ($joinRequests->isEmpty()) {
                return response()->json(['error' => 'No request found for this consultancy'], 404);
            }

            // Ensure userDetail exists before accessing its properties
            $userDetails = $consultancyUser->userDetail ?? null;

            $returnData = [
                'consultancy' => [
                    'id' => $consultancyUser->id,
                    'fullname' => $consultancyUser->fullname,
                    'email' => $consultancyUser->email,
                    'phone' => $consultancyUser->phone,
                    'role_id' => $consultancyUser->role_id,
                    'role_name' => $consultancyUser->role_name,
                    'unique_id' => $consultancyUser->unique_id,
                    'api_token' => $consultancyUser->api_token,
                    'profile_photo' => $userDetails?->profile_photo,
                    'business_name' => $userDetails?->bussiness_name,
                    'business_address' => $userDetails?->bussiness_address,
                    'business_email' => $userDetails?->bussiness_email,
                    'business_phone' => $userDetails?->business_phone,
                    'address' => $userDetails?->address,
                ],
                'join_requests' => []
            ];

            foreach ($joinRequests as $row) {
                $company = $row->company ?? null; // Check if company exists

                if (!$company) {
                    // Log this error instead of stopping execution
                    \Log::error("Company not found for join request ID: " . $row->id);
                    continue; // Skip this request and move to the next one
                }

                $companyDetails = $company->userDetail ?? null; // Check if userDetail exists

                $returnData['join_requests'][] = [
                    'status' => $row->status,
                    'company' => [
                        'id' => $company->id ?? null,
                        'fullname' => $company->fullname ?? null,
                        'email' => $company->email ?? null,
                        'phone' => $company->phone ?? null,
                        'role_id' => $company->role_id ?? null,
                        'role_name' => $company->role_name ?? null,
                        'unique_id' => $company->unique_id ?? null,
                        'api_token' => $company->api_token ?? null,
                        'profile_photo' => $companyDetails?->profile_photo,
                        'business_name' => $companyDetails?->bussiness_name,
                        'business_address' => $companyDetails?->bussiness_address,
                        'business_email' => $companyDetails?->bussiness_email,
                        'business_phone' => $companyDetails?->business_phone,
                        'address' => $companyDetails?->address,
                    ],
                ];
            }


            return response()->json($returnData, 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // for accept and decline the request
    // public function acceptDeclineCompanyRequestByConsultancy(Request $request)
    // {
    //     try {

    //         if ($request->header('api-token') == '') {
    //             return response()->json(['error' => 'Please enter api token first.'], 422);
    //         }

    //         $requestToken = $request->header('api-token');

    //         $userId = null;

    //         $userData = User::where('api_token', $requestToken)->first();

    //         // Validate that the user exists in the database
    //         if (!$userData) {
    //             return response()->json(['error' => 'Consultancy not found'], 404);
    //         }

    //         $userId = $userData->id;


    //         // Validate that the user exists in the database
    //         if (!User::where('id', $request->company_id)->exists()) {
    //             return response()->json(['error' => 'Consultancy not found'], 404);
    //         }


    //         JoinRequest::where(['consultancy_id' => $userId, 'company_id' => $request->company_id])->update(['status' => $request->status]);

    //         return response()->json(['status' => true, 'message' => "Request $request->status  successfully."], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         \Log::error($e->getMessage());
    //         return response()->json(['error' => 'Failed.' . $e->getMessage()], 500);
    //     }
    // }

    // public function acceptDeclineCompanyRequestByConsultancy(Request $request)
    // {
    //     try {



    //         $consultancyUser = Auth::user();

    //         if (!$consultancyUser) {
    //             return response()->json(['error' => 'Consultancy not found'], 404);
    //         }

    //         $userId = $consultancyUser->id;

    //         // Validate that the company exists in the database
    //         if (!User::where('id', $request->company_id)->exists()) {
    //             return response()->json(['error' => 'Company not found'], 404);
    //         }

    //         // Validate that the request status is an integer
    //         if (!in_array($request->status, [1, 2, 3])) {
    //             return response()->json(['error' => 'Invalid status. Allowed values: 1 (Requested), 2 (Accepted), 3 (Rejected).'], 422);
    //         }

    //         $update = JoinRequest::where([
    //             'user_id' => $request->company_id,
    //             'type' => 'company-consultancy'
    //         ])->update(['status' => $request->status]);

    //         if ($update) {
    //             return response()->json(['status' => true, 'message' => "Request status updated successfully."], 201);
    //         } else {
    //             return response()->json(['error' => 'No matching request found.'], 404);
    //         }

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         \Log::error($e->getMessage());
    //         return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
    //     }
    // }


    public function acceptDeclineCompanyRequestByConsultancy(Request $request)
    {
        try {
            $authUser = Auth::user();

            if (!$authUser) {
                return response()->json(['error' => 'User not found'], 404);
            }

            // Determine user type
            $userRole = $authUser->role->name;
            $isCompany = $userRole === 'company';
            $isConsultancy = $userRole === 'consultancy';
            $isAgent = $userRole === 'agent';

            if (!$isCompany && !$isConsultancy && !$isAgent) {
                return response()->json(['error' => 'Unauthorized action'], 403);
            }

            // Determine the target user and request type
            if ($isCompany) {
                $targetUserId = $request->consultancy_id;
                $requestType = 'company-consultancy';
            } elseif ($isAgent) {
                $targetUserId = $request->consultancy_id;
                $requestType = 'consultancy-agent';
            } elseif ($isConsultancy) {
                if ($request->company_id) {
                    $targetUserId = $request->company_id;
                    $requestType = 'company-consultancy';
                } elseif ($request->agent_id) {
                    $targetUserId = $request->agent_id;
                    $requestType = 'consultancy-agent';
                } else {
                    return response()->json(['error' => 'Missing target user ID.'], 400);
                }
            }

            // Ensure the target user exists
            $targetUser = User::find($targetUserId);
            if (!$targetUser) {
                return response()->json(['error' => 'Target user not found'], 404);
            }

            // Validate status
            if (!in_array($request->status, [1, 2, 3])) {
                return response()->json([
                    'error' => 'Invalid status. Allowed values: 1 (Requested), 2 (Accepted), 3 (Rejected).'
                ], 422);
            }

            // Update JoinRequest with correct where conditions
            $update = JoinRequest::where('type', $requestType)
                ->where(function ($query) use ($authUser, $targetUserId) {
                    $query->where('user_id', $authUser->id)
                        ->orWhere('user_id', $targetUserId);
                })
                ->update(['status' => $request->status]);



            if ($update) {
                return response()->json([
                    'status' => true,
                    'message' => "Request status updated successfully."
                ], 201);
            } else {
                return response()->json(['error' => 'No matching request found.'], 404);
            }

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }

    // for leave the company
    public function leaveTheComapnyByConsultancy(Request $request)
    {
        try {
            $consultancyUser = Auth::user();

            if (!$consultancyUser) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $consultancyUser->id;

            // Validate that the user exists
            if (!User::where('id', $request->user_id)->exists()) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            // Check if consultancy_id exists in join_requests, otherwise use user_id or consultant_id
            $update = JoinRequest::where(['user_id' => $userId,])
                ->update(['status' => '5']); // Change if 'status' is an integer

            if ($update) {
                return response()->json(['status' => true, 'message' => 'You left the company successfully.'], 201);
            } else {
                return response()->json(['error' => 'No matching record found.'], 404);
            }

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }

    // for get consultancy details
    public function getConsultancyDetailsWithCompany(Request $request)
    {
        try {
            $consultancyUser = Auth::user();

            if (!$consultancyUser) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $consultancyUser->id;

            // Get the IDs of users who have accepted join requests where the consultancy is stored under 'user_id'
            $user_ids_from_join_requests = JoinRequest::where([
                'user_id' => $userId,  // Changed from 'consultancy_id' to 'user_id'
                'status' => 2, // 2 = Accepted
                'type' => 'company-consultancy'
            ])->pluck('user_id')->toArray();

            // Get the count of users whose IDs are in the array from join requests
            $companyCountFromJoinRequests = count($user_ids_from_join_requests);

            // Get user data for the associated users
            $companyData = User::whereIn('id', array_unique($user_ids_from_join_requests))->get();

            // Fetch user details from multiple tables
            $userData = DB::table('users')
                ->join('user_details', 'users.id', '=', 'user_details.user_id')
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->where('users.id', $userId)
                ->where('users.role_id', '!=', 1)
                ->select('users.*', 'user_details.*', 'roles.name as role_name')
                ->first();

            // Check if user data exists
            if (!$userData) {
                return response()->json(['error' => 'No data found on this user.'], 404);
            }

            return response()->json([
                'id' => $userData->id,
                'fullname' => $userData->fullname ?? $userData->first_name, // Use correct column
                'email' => $userData->email,
                'phone' => $userData->phone,
                'role_id' => $userData->role_id,
                'role_name' => $userData->role_name,
                'unique_id' => $userData->unique_id,
                'isapproved' => $userData->isapproved,
                'bussiness_name' => $userData->bussiness_name,
                'bussiness_address' => $userData->bussiness_address,
                'bussiness_email' => $userData->bussiness_email,
                'business_phone' => $userData->business_phone,
                'country' => $userData->country_id,
                'state' => $userData->state_id,
                'city' => $userData->city_id,
                'address' => $userData->address,
                'pin_code' => $userData->pin_code,
                'profile_photo' => url($userData->profile_photo),
                'license_number' => $userData->license_number,
                'alternate_number' => $userData->alternate_number,
                'no_of_employees' => $userData->no_of_employees,
                // 'purpose_id' => explode(',', $userData->purpose_id),
                // 'property_id' => explode(',', $userData->property_id),
                // 'property_type_id' => explode(',', $userData->property_type_id),
                'created_at' => $userData->created_at,
                'updated_at' => $userData->updated_at,
                'total_company' => $companyCountFromJoinRequests,
                'company' => $companyData
            ], 200);

        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // this is for listing by userId
    public function getCompanyProjectListing(Request $request)
    {
        try {

            // if ($request->header('api-token') == '') {
            //     return response()->json(['error' => 'Please enter api token first.'], 422);
            // }

            // $requestToken = $request->header('api-token');

            // $userId = null;

            // $userData = User::where('api_token', $requestToken)->first();

            // // Validate that the user exists in the database
            // if (!$userData) {
            //     return response()->json(['error' => 'Company not found'], 404);
            // }

            $companyUser = Auth::user();

            if (!$companyUser) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $userId = $companyUser->id;
            // $userId = $userData->id;


            $baseURL = config('app.url');
            $basePath = public_path();

            $projects = ProjectList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])->where('user_id', $userId)->get();

            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $projectData = [
                    'id' => $property->id,
                    'project_unique_id' => $property->project_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $projectData;
            });

            return response()->json($projectsData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // this is for assign project to consultancy by company
    public function assignProjectToConsultancyByCompany(Request $request)
    {
        try {
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            $userId = $user->id;

            $role = Role::find($user->role_id);
            if (!$role || $role->name !== 'company') {
                return response()->json(['error' => 'User does not have the required role.'], 400);
            }

            // if (!$userData) {
            //     return response()->json(['error' => 'Company not found'], 404);
            // }

            $userId = $user->id;
            $project_ids = explode(',', $request->project_id);
            $consultancy_id = $request->consultancy_id;

            foreach ($project_ids as $project_id) {
                $existingEntry = CompanyConsultancyProject::where('company_id', $userId)
                    ->where('consultancy_id', $consultancy_id)
                    ->where('project_id', $project_id)
                    ->where('type', 'company-consultancy')
                    ->first();

                if ($existingEntry) {
                    return response()->json(['message' => 'Project with ID ' . $project_id . ' already assigned to consultancy'], 409);
                }
            }

            foreach ($project_ids as $project_id) {
                $insertData = [
                    'company_id' => $userId,
                    'consultancy_id' => $consultancy_id,
                    'project_id' => $project_id,
                    'type' => 'company-consultancy' // Assuming this is the type you want to set
                ];

                CompanyConsultancyProject::create($insertData);
            }

            return response()->json(['message' => 'Project assigned successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed.' . $e->getMessage()], 500);
        }
    }



    // this is for fetch listing assigned projects of company
    public function fetchAssignedProjectOfCompany(Request $request)
    {
        try {
            // if ($request->header('api-token') == '') {
            //     return response()->json(['error' => 'Please enter api token first.'], 422);
            // }

            // $requestToken = $request->header('api-token');
            // $userData = User::where('api_token', $requestToken)->first();

            // // Validate that the user exists in the database
            // if (!$userData) {
            //     return response()->json(['error' => 'Company not found'], 404);
            // }

            // $userId = $userData->id;
            $companyUser = Auth::user();

            if (!$companyUser) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $userId = $companyUser->id;
            // Fetch distinct consultancies assigned to the company
            $consultancies = CompanyConsultancyProject::where('company_id', $userId)
                ->where('type', 'company-consultancy')
                ->with('consultancy')
                ->get()
                ->groupBy('consultancy_id');

            $returnData = [];
            $returnData['company'] = $companyUser;
            $returnData['consultancies'] = [];

            foreach ($consultancies as $consultancyId => $projects) {
                $consultancyData = [
                    'consultancy' => $projects->first()->consultancy,
                    'assigned_projects_count' => $projects->count()
                ];

                $returnData['consultancies'][] = $consultancyData;
            }

            return response()->json(['data' => $returnData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    // this is foe get property data by project id
    public function propertyDetailsByProjectId(Request $request)
    {
        try {
            if ($request->project_id == '') {
                return response()->json(['error' => 'Project ID is required'], 400);
            }

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])->where('project_id', $request->project_id)->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'posted_on' => date('d M, Y', strtotime($property->created_at)),
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // this is for assign project to agent by consultancy
    public function assignProjectToAgentByConsultancy(Request $request)
    {
        try {
            if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
                return response()->json(['error' => 'Please provide an API token.'], 422);
            }

            // Retrieve the Authorization header
            $authorizationHeader = $request->header('Authorization');

            // Check if the header starts with "Bearer "
            if (!str_starts_with($authorizationHeader, 'Bearer ')) {
                return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
            }

            // Extract the token by removing the "Bearer " prefix
            $requestToken = substr($authorizationHeader, 7);

            // Check if the token is empty after removing "Bearer "
            if (empty($requestToken)) {
                return response()->json(['error' => 'Token is missing.'], 422);
            }

            // Verify the token dynamically (check in the database)
            $user = User::where('api_token', $requestToken)->first();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
            }

            $userId = $user->id;

            $role = Role::find($user->role_id);
            if (!$role || $role->name !== 'consultancy') {
                return response()->json(['error' => 'User does not have the required role.'], 400);
            }
            $userId = $user->id;
            $project_ids = explode(',', $request->project_id);
            $agent_id = $request->agent_id;

            foreach ($project_ids as $project_id) {
                $existingEntry = CompanyConsultancyProject::where('consultancy_id', $userId)
                    ->where('agent_id', $agent_id)
                    ->where('project_id', $project_id)
                    ->where('type', 'consultancy-agent')
                    ->first();

                if ($existingEntry) {
                    return response()->json(['message' => 'Project with ID ' . $project_id . ' already assigned to this agent'], 409);
                }
            }

            foreach ($project_ids as $project_id) {
                $insertData = [
                    'consultancy_id' => $userId,
                    'agent_id' => $agent_id,
                    'project_id' => $project_id,
                    'type' => 'consultancy-agent'
                ];

                CompanyConsultancyProject::create($insertData);
            }

            return response()->json(['message' => 'Project assigned successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed.' . $e->getMessage()], 500);
        }
    }


    // this is for fetch listing total assigned projects  of consultancy
    public function fetchTotalAssignedProjectToConsultancy(Request $request)
    {
        try {
            // if ($request->header('api-token') == '') {
            //     return response()->json(['error' => 'Please enter api token first.'], 422);
            // }

            // $requestToken = $request->header('api-token');
            // $userData = User::where('api_token', $requestToken)->first();

            // // Validate that the user exists in the database
            // if (!$userData) {
            //     return response()->json(['error' => 'Consultancy not found'], 404);
            // }

            // $userId = $userData->id;


            $companyUser = Auth::user();

            if (!$companyUser) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $companyUser->id;
            $companyConsultancyProjects = CompanyConsultancyProject::with('company')
                ->where('consultancy_id', $userId)
                ->where('type', 'company-consultancy')
                ->get();

            $companies = $companyConsultancyProjects->map(function ($ccProject) {
                return $ccProject->company;
            })->unique('id')->values();

            $returnData = [
                'consultancy' => $companyUser,
                'companies' => []
            ];

            foreach ($companies as $company) {
                $companyProjects = $companyConsultancyProjects->where('company_id', $company->id);
                $companyProjectsCount = $companyProjects->count();
                $projectsData = $companyProjects->map(function ($ccProject) {
                    return $this->getProjectDataConsultancy($ccProject->project_id);
                });

                $returnData['companies'][] = [
                    'company' => $company,
                    'assigned_projects_count' => $companyProjectsCount,
                    'projects' => $projectsData
                ];
            }

            return response()->json(['data' => $returnData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    // this is for fetch listing total assigned projects  of consultancy
    public function fetchConsultancyTotalAssignedProjects(Request $request)
    {
        try {
            // Check if the API token is present in the request headers
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter API token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $userData->id;

            // Fetch all projects assigned to this consultancy
            $projectsData = CompanyConsultancyProject::where('consultancy_id', $userId)
                ->where('type', 'company-consultancy')
                ->get()
                ->map(function ($ccProject) {
                    return $this->getProjectDataConsultancy($ccProject->project_id);
                })
                ->filter(function ($projectData) {
                    return !is_null($projectData);
                });

            // Return only the projects data
            return response()->json(['data' => $projectsData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }




    // this is for get project data of consultancy
    private function getProjectDataConsultancy($project_id)
    {
        $baseURL = config('app.url');

        $project = ProjectList::with([
            'location',
            'user',
            'propertyType',
            'purpose',
            'property',
            'propertystatus',
            'customFieldValues.customField',
            'customFieldValues.customFieldOption'
        ])->find($project_id);

        if (!$project) {
            return null;
        }

        $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
            $customField = $customFieldValue->customField;
            $customFieldOption = $customFieldValue->customFieldOption ?? null;

            $fieldValue = $customFieldValue->field_meta_value;
            if ($customField && $customField->field_type == 'checkbox') {
                // For checkbox, explode the value to get an array
                $fieldValueArray = explode(',', $fieldValue);
            } elseif ($customField && $customField->field_type == 'media') {
                // Handling for media field
                // Add baseURL to media file
                $fieldValueArray = json_decode($fieldValue);
                $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                    return $baseURL . '/uploads/media/' . $file;
                });
            } else {
                // For other field types or if $customField is null, keep the value as is
                $fieldValueArray = $fieldValue;
            }

            return [
                'custom_field_id' => $customField ? $customField->id : null,
                'field_type' => $customField ? $customField->field_type : null,
                'field_value' => $fieldValueArray,
                'field_name' => $customField ? $customField->field_name : null,
            ];
        });

        return [
            'id' => $project->id,
            'project_unique_id' => $project->project_unique_id,
            'name' => $project->name,
            'description' => $project->description,
            'location_id' => $project->location_id,
            'location_name' => optional($project->location)->name,
            'status' => $project->status,
            'status_reason' => $project->status_reason,
            'project_status' => $project->project_status,
            'user_id' => $project->user_id,
            'listed_by' => optional(optional($project->user)->role)->name,
            'purpose_id' => $project->purpose_id,
            'purpose_id_name' => optional($project->purpose)->name,
            'property_id' => $project->property_id,
            'property_id_name' => optional($project->property)->name,
            'property_status_id' => $project->property_status_id,
            'property_status_id_name' => optional($project->propertystatus)->name,
            'property_type_id' => $project->property_type_id,
            'property_type_id_name' => optional($project->propertyType)->name,
            'total_view' => $project->analytics()->count(),
            'custom_field_values' => $formattedCustomFieldValues,
        ];
    }



    // this is for fetch listing assigned projects of agent
    public function fetchAssignedProjectOfAgent(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $userId = $userData->id;

            // Fetch projects assigned to the agent by consultancy
            $consultancyProjects = CompanyConsultancyProject::where('agent_id', $userId)
                ->where('type', 'consultancy-agent')
                ->with('consultancy')
                ->get()
                ->groupBy('consultancy_id');

            $returnData = [];
            $returnData['agent'] = $userData;
            $returnData['consultancies'] = [];

            foreach ($consultancyProjects as $consultancyId => $projects) {
                $consultancyData = [
                    'consultancy' => $projects->first()->consultancy,
                    'assigned_projects_count' => $projects->count()
                ];

                $returnData['consultancies'][] = $consultancyData;
            }

            return response()->json(['data' => $returnData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }

    // this is for fetch listing assigned projects of agent
    public function fetchAgentTotalAssignedProject(Request $request)
    {
        try {
            // Check if the API token is present in the request headers
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter API token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Agent not found'], 404);
            }

            $userId = $userData->id;

            // Fetch projects assigned to the agent by consultancy
            $consultancyProjects = CompanyConsultancyProject::where('agent_id', $userId)
                ->where('type', 'consultancy-agent')
                ->with('consultancy')
                ->get();

            // Extract and transform project data
            $projectsData = $consultancyProjects->map(function ($project) {
                return $this->getProjectDataConsultancy($project->project_id);
            });

            // Return only the projects data
            return response()->json(['data' => $projectsData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }




    // this is for fetch listing total projects of consultancy
    public function fetchTotalProjectOfConsultancy(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter api token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $userData->id;
            $companyConsultancyProjects = CompanyConsultancyProject::with('project')
                ->where('consultancy_id', $userId)
                ->where('type', 'company-consultancy')
                ->get();

            $returnData = [
                'consultancy' => $userData,
                'projects' => []
            ];

            foreach ($companyConsultancyProjects as $ccProject) {
                $returnData['projects'][] = $this->getTotalProjectDataConsultancy($ccProject->project_id);
            }

            return response()->json(['data' => $returnData], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }


    // this is for get total project of consultancy
    private function getTotalProjectDataConsultancy($project_id)
    {
        $baseURL = config('app.url');

        $project = ProjectList::with([
            'location',
            'user',
            'propertyType',
            'purpose',
            'property',
            'propertystatus',
            'customFieldValues.customField',
            'customFieldValues.customFieldOption'
        ])->find($project_id);

        if (!$project) {
            return null;
        }

        $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
            $customField = $customFieldValue->customField;
            $customFieldOption = $customFieldValue->customFieldOption ?? null;

            $fieldValue = $customFieldValue->field_meta_value;
            if ($customField && $customField->field_type == 'checkbox') {
                // For checkbox, explode the value to get an array
                $fieldValueArray = explode(',', $fieldValue);
            } elseif ($customField && $customField->field_type == 'media') {
                // Handling for media field
                // Add baseURL to media file
                $fieldValueArray = json_decode($fieldValue);
                $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                    return $baseURL . '/uploads/media/' . $file;
                });
            } else {
                // For other field types or if $customField is null, keep the value as is
                $fieldValueArray = $fieldValue;
            }

            return [
                'custom_field_id' => $customField ? $customField->id : null,
                'field_type' => $customField ? $customField->field_type : null,
                'field_value' => $fieldValueArray,
                'field_name' => $customField ? $customField->field_name : null,
            ];
        });

        return [
            'id' => $project->id,
            'project_unique_id' => $project->project_unique_id,
            'name' => $project->name,
            'description' => $project->description,
            'location_id' => $project->location_id,
            'location_name' => optional($project->location)->name,
            'status' => $project->status,
            'status_reason' => $project->status_reason,
            'project_status' => $project->project_status,
            'user_id' => $project->user_id,
            'listed_by' => optional(optional($project->user)->role)->name,
            'purpose_id' => $project->purpose_id,
            'purpose_id_name' => optional($project->purpose)->name,
            'property_id' => $project->property_id,
            'property_id_name' => optional($project->property)->name,
            'property_status_id' => $project->property_status_id,
            'property_status_id_name' => optional($project->propertystatus)->name,
            'property_type_id' => $project->property_type_id,
            'property_type_id_name' => optional($project->propertyType)->name,
            'total_view' => $project->analytics()->count(),
            'custom_field_values' => $formattedCustomFieldValues,
        ];
    }



    // this is for fetch listing project details of consultancy & company
    public function viewProjectDetailsOfConsultancy(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter API token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Consultancy not found'], 404);
            }

            $userId = $userData->id;
            $companyId = $request->input('company_id'); // Assuming company_id is passed as a query parameter

            $companyConsultancyProjects = CompanyConsultancyProject::with('company')
                ->where('consultancy_id', $userId)
                ->where('company_id', $companyId)
                ->where('type', 'company-consultancy')
                ->get();

            // Check if projects exist
            if ($companyConsultancyProjects->isEmpty()) {
                return response()->json(['error' => 'No projects found for this company.'], 404);
            }

            // Fetch detailed project data
            $projectDetails = $companyConsultancyProjects->map(function ($ccProject) {
                return $this->getProjectDetailsOfConsultancy($ccProject->id);
            });

            return response()->json(['projects' => $projectDetails], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }

    // Assuming is a method in the same controller
    private function getProjectDetailsOfConsultancy($project_id)
    {
        $baseURL = config('app.url');

        $project = ProjectList::with([
            'location',
            'user',
            'propertyType',
            'purpose',
            'property',
            'propertystatus',
            'customFieldValues.customField',
            'customFieldValues.customFieldOption'
        ])->find($project_id);

        if (!$project) {
            return null;
        }

        $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
            $customField = $customFieldValue->customField;
            $customFieldOption = $customFieldValue->customFieldOption ?? null;

            $fieldValue = $customFieldValue->field_meta_value;
            if ($customField && $customField->field_type == 'checkbox') {
                // For checkbox, explode the value to get an array
                $fieldValueArray = explode(',', $fieldValue);
            } elseif ($customField && $customField->field_type == 'media') {
                // Handling for media field
                // Add baseURL to media file
                $fieldValueArray = json_decode($fieldValue);
                $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                    return $baseURL . '/uploads/media/' . $file;
                });
            } else {
                // For other field types or if $customField is null, keep the value as is
                $fieldValueArray = $fieldValue;
            }

            return [
                'custom_field_id' => $customField ? $customField->id : null,
                'field_type' => $customField ? $customField->field_type : null,
                'field_value' => $fieldValueArray,
                'field_name' => $customField ? $customField->field_name : null,
            ];
        });

        return [
            'id' => $project->id,
            'project_unique_id' => $project->project_unique_id,
            'name' => $project->name,
            'description' => $project->description,
            'location_id' => $project->location_id,
            'location_name' => optional($project->location)->name,
            'status' => $project->status,
            'status_reason' => $project->status_reason,
            'project_status' => $project->project_status,
            'user_id' => $project->user_id,
            'listed_by' => optional(optional($project->user)->role)->name,
            'purpose_id' => $project->purpose_id,
            'purpose_id_name' => optional($project->purpose)->name,
            'property_id' => $project->property_id,
            'property_id_name' => optional($project->property)->name,
            'property_status_id' => $project->property_status_id,
            'property_status_id_name' => optional($project->propertystatus)->name,
            'property_type_id' => $project->property_type_id,
            'property_type_id_name' => optional($project->propertyType)->name,
            'total_view' => $project->analytics()->count(),
            'custom_field_values' => $formattedCustomFieldValues,
        ];
    }


    // this is for fetch listing project details of consultancy & company
    public function viewProjectDetailsOfCompany(Request $request)
    {
        try {
            if ($request->header('api-token') == '') {
                return response()->json(['error' => 'Please enter API token first.'], 422);
            }

            $requestToken = $request->header('api-token');
            $userData = User::where('api_token', $requestToken)->first();

            // Validate that the user exists in the database
            if (!$userData) {
                return response()->json(['error' => 'Company not found'], 404);
            }

            $userId = $userData->id;
            $consultancyId = $request->input('consultancy_id'); // Assuming company_id is passed as a query parameter

            $companyConsultancyProjects = CompanyConsultancyProject::with('company')
                ->where('company_id', $userId)
                ->where('consultancy_id', $consultancyId)
                ->where('type', 'company-consultancy')
                ->get();

            // Check if projects exist
            if ($companyConsultancyProjects->isEmpty()) {
                return response()->json(['error' => 'No projects found for this consultancy.'], 404);
            }

            // Fetch detailed project data
            $projectDetails = $companyConsultancyProjects->map(function ($ccProject) {
                return $this->getProjectDetailsOfCompany($ccProject->id);
            });

            return response()->json(['projects' => $projectDetails], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed. ' . $e->getMessage()], 500);
        }
    }

    // Assuming is a method in the same controller
    private function getProjectDetailsOfCompany($project_id)
    {
        $baseURL = config('app.url');

        $project = ProjectList::with([
            'location',
            'user',
            'propertyType',
            'purpose',
            'property',
            'propertystatus',
            'customFieldValues.customField',
            'customFieldValues.customFieldOption'
        ])->find($project_id);

        if (!$project) {
            return null;
        }

        $formattedCustomFieldValues = $project->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
            $customField = $customFieldValue->customField;
            $customFieldOption = $customFieldValue->customFieldOption ?? null;

            $fieldValue = $customFieldValue->field_meta_value;
            if ($customField && $customField->field_type == 'checkbox') {
                // For checkbox, explode the value to get an array
                $fieldValueArray = explode(',', $fieldValue);
            } elseif ($customField && $customField->field_type == 'media') {
                // Handling for media field
                // Add baseURL to media file
                $fieldValueArray = json_decode($fieldValue);
                $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                    return $baseURL . '/uploads/media/' . $file;
                });
            } else {
                // For other field types or if $customField is null, keep the value as is
                $fieldValueArray = $fieldValue;
            }

            return [
                'custom_field_id' => $customField ? $customField->id : null,
                'field_type' => $customField ? $customField->field_type : null,
                'field_value' => $fieldValueArray,
                'field_name' => $customField ? $customField->field_name : null,
            ];
        });

        return [
            'id' => $project->id,
            'project_unique_id' => $project->project_unique_id,
            'name' => $project->name,
            'description' => $project->description,
            'location_id' => $project->location_id,
            'location_name' => optional($project->location)->name,
            'status' => $project->status,
            'status_reason' => $project->status_reason,
            'project_status' => $project->project_status,
            'user_id' => $project->user_id,
            'listed_by' => optional(optional($project->user)->role)->name,
            'purpose_id' => $project->purpose_id,
            'purpose_id_name' => optional($project->purpose)->name,
            'property_id' => $project->property_id,
            'property_id_name' => optional($project->property)->name,
            'property_status_id' => $project->property_status_id,
            'property_status_id_name' => optional($project->propertystatus)->name,
            'property_type_id' => $project->property_type_id,
            'property_type_id_name' => optional($project->propertyType)->name,
            'total_view' => $project->analytics()->count(),
            'custom_field_values' => $formattedCustomFieldValues,
        ];
    }


    // this is for search property
    public function searchProperty(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            $propertiesQuery = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption']);

            if ($request->purpose == 'buy') {

                if (!empty($request->property_id)) {
                    $propertiesQuery->where('property_id', $request->property_id);
                }

                if (!empty($request->property_type_id)) {
                    $explod_property_type_id = explode(',', $request->property_type_id);
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
                }

                if (!empty($request->property_status_id)) {
                    $explod_property_status_id = explode(',', $request->property_status_id);
                    $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
                }

                if (!empty($request->property_price_low) && !empty($request->property_price_high)) {
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

                    if ($checkCustomField) {
                        $custom_field_id = $checkCustomField->id;

                        $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                            ->whereBetween('field_meta_value', [$request->property_price_low, $request->property_price_high])
                            ->pluck('properties_listing_id')
                            ->toArray();

                        $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                    }
                }
            }

            if ($request->purpose == 'rent') {

                if (!empty($request->property_id)) {
                    $propertiesQuery->where('property_id', $request->property_id);
                }

                if (!empty($request->property_type_id)) {
                    $explod_property_type_id = explode(',', $request->property_type_id);
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
                }

                if (!empty($request->property_status_id)) {
                    $explod_property_status_id = explode(',', $request->property_status_id);
                    $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
                }

                if (!empty($request->rent_price_low) && !empty($request->rent_price_high)) {
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

                    if ($checkCustomField) {
                        $custom_field_id = $checkCustomField->id;

                        $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                            ->whereBetween('field_meta_value', [$request->rent_price_low, $request->rent_price_high])
                            ->pluck('properties_listing_id')
                            ->toArray();

                        $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                    }
                }


                if (!empty($request->posted_by)) {

                    $user_id_arr = [];

                    if ($request->posted_by == 'agent') {
                        $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
                    }

                    if ($request->posted_by == 'owner') {
                        $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
                    }

                    $propertiesQuery->whereIn('user_id', $user_id_arr);
                }
            }

            if ($request->purpose == 'pgco-living') {

                if (!empty($request->property_type_id)) {
                    $explod_property_type_id = explode(',', $request->property_type_id);
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
                }

                if (!empty($request->property_status_id)) {
                    $explod_property_status_id = explode(',', $request->property_status_id);
                    $propertiesQuery->whereIn('property_status_id', $explod_property_status_id);
                }

                if (!empty($request->pg_rent_price_low) && !empty($request->pg_rent_price_high)) {
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

                    if ($checkCustomField) {
                        $custom_field_id = $checkCustomField->id;

                        $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                            ->whereBetween('field_meta_value', [$request->pg_rent_price_low, $request->pg_rent_price_high])
                            ->pluck('properties_listing_id')
                            ->toArray();

                        $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                    }
                }


                if (!empty($request->posted_by)) {

                    $user_id_arr = [];

                    if ($request->posted_by == 'agent') {
                        $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
                    }

                    if ($request->posted_by == 'owner') {
                        $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
                    }

                    $propertiesQuery->whereIn('user_id', $user_id_arr);
                }


                if (!empty($request->availabel_for)) {
                    $checkCustomField = CustomField::where('field_name', 'listing_available_for')->first();

                    if ($checkCustomField) {
                        $custom_field_id = $checkCustomField->id;

                        $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                            ->whereRaw("FIND_IN_SET(?, field_meta_value)", [$request->availabel_for])
                            ->pluck('properties_listing_id')
                            ->toArray();

                        $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                    }
                }
            }


            if ($request->purpose == 'plot_land') {

                if (!empty($request->property_id)) {
                    $propertiesQuery->where('property_id', $request->property_id);
                }

                if (!empty($request->property_type_id)) {
                    $explod_property_type_id = explode(',', $request->property_type_id);
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
                }

                if (!empty($request->plot_price_low) && !empty($request->plot_price_high)) {
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

                    if ($checkCustomField) {
                        $custom_field_id = $checkCustomField->id;

                        $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                            ->whereBetween('field_meta_value', [$request->plot_price_low, $request->plot_price_high])
                            ->pluck('properties_listing_id')
                            ->toArray();

                        $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                    }
                }


                if (!empty($request->posted_by)) {

                    $user_id_arr = [];

                    if ($request->posted_by == 'agent') {
                        $user_id_arr = User::where('role_id', 3)->pluck('id')->toArray();
                    }

                    if ($request->posted_by == 'owner') {
                        $user_id_arr = User::where('role_id', 2)->pluck('id')->toArray();
                    }

                    $propertiesQuery->whereIn('user_id', $user_id_arr);
                }


                if (!empty($request->plot_area)) {

                }
            }


            if ($request->purpose == 'project') {

                if (!empty($request->property_id)) {
                    $propertiesQuery->where('property_id', $request->property_id);
                }

                if (!empty($request->property_type_id)) {
                    $explod_property_type_id = explode(',', $request->property_type_id);
                    $propertiesQuery->whereIn('property_type_id', $explod_property_type_id);
                }

                if (!empty($request->project_price_low) && !empty($request->project_price_high)) {
                    $checkCustomField = CustomField::where('field_name', 'property_rent_amount')->first();

                    if ($checkCustomField) {
                        $custom_field_id = $checkCustomField->id;

                        $properties_listing_id_arr = CustomFieldValue::where('custom_field_id', $custom_field_id)
                            ->whereBetween('field_meta_value', [$request->project_price_low, $request->project_price_high])
                            ->pluck('properties_listing_id')
                            ->toArray();

                        $propertiesQuery->whereIn('id', $properties_listing_id_arr);
                    }
                }
            }


            if (isset($request->keyword)) {
                $property_id_arr = Keyword::where('keyword', $request->keyword)->where('property_id', '!=', null)->pluck('property_id')->toArray();
                $propertiesQuery->whereIn('id', $property_id_arr);
            }

            // Get the results from the query builder
            $properties = $propertiesQuery->get();

            // Now use map on the collection
            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' on line ' . $th->getLine()], 500);
        }
    }



    // this is for listing of owner propeprty
    public function listingOfAllOwnerProperty(Request $request)
    {
        try {

            $userId = User::where('role_id', '2')->pluck('id')->toArray();

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])
                ->whereIn('user_id', $userId)
                ->where('status', 'approved')
                ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is for listing of all projects
    public function listingOfAllProjects(Request $request)
    {
        try {

            $baseURL = config('app.url');
            $basePath = public_path();

            $projects = ProjectList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])->get();

            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $projectData = [
                    'id' => $property->id,
                    'project_unique_id' => $property->project_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $projectData;
            });

            return response()->json($projectsData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // this is for listing of ready to move propeprty
    public function listingOfReadyToMoveProperty(Request $request)
    {
        try {

            $propertyStatusId = Status::where('slug', 'ready-to-move')->value('id') ?? 0;

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])
                ->where('property_status_id', $propertyStatusId)
                ->where('status', 'approved')
                ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // for top agent listing
    public function allTopAgentListing(Request $request)
    {
        try {

            $users = User::where('role_id', 3)
                ->with('role')
                ->with('userDetails')
                ->get();

            // Extract the necessary information from each user
            $userList = $users->map(function ($user) {
                $roleName = $user->role ? $user->role->name : null;
                $userDetails = $user->userDetails ?? null;

                return [
                    'id' => $user->id,
                    'fullname' => $user->fullname,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role_name' => $roleName,
                    'role_id' => $user->role_id,
                    'api_token' => $user->uid,
                    'uid' => $user->uid,
                    'unique_id' => $user->unique_id,
                    'isapproved' => $user->isapproved,
                    'bussiness_name' => $userDetails ? $userDetails->bussiness_name : null,
                    'bussiness_address' => $userDetails ? $userDetails->bussiness_address : null,
                    'bussiness_email' => $userDetails ? $userDetails->bussiness_email : null,
                    'business_phone' => $userDetails ? $userDetails->business_phone : null,
                    'profile_photo' => $userDetails ? $userDetails->profile_photo : null,
                    'address' => $userDetails ? $userDetails->address : null,
                    'country' => $userDetails ? $userDetails->country : null,
                    'state' => $userDetails ? $userDetails->state : null,
                    'city' => $userDetails ? $userDetails->city : null,
                    'pin_code' => $userDetails ? $userDetails->pin_code : null,
                    'license_number' => $userDetails ? $userDetails->license_number : null,
                    'alternate_number' => $userDetails ? $userDetails->alternate_number : null,
                ];
            });


            // Return the user list as JSON response
            return response()->json($userList, 200);
        } catch (\Throwable $th) {
            // Handle any exceptions and return an error response
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is for listing of ready to move propeprty
    public function listingOfBudgeHomesProperty(Request $request)
    {
        try {

            $propertyId = Property::where('slug', 'like', '%residential%')->value('id') ?? 0;

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])
                ->where('property_id', $propertyId)
                ->where('status', 'approved')
                ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is for listing of all trending projects
    public function listingOfAllTrendingProject(Request $request)
    {
        try {
            $baseURL = config('app.url');
            $basePath = public_path();

            // Fetch projects in descending order by creation date
            $projects = ProjectList::with([
                'location',
                'user',
                'propertyType',
                'purpose',
                'property',
                'propertystatus',
                'customFieldValues.customField',
                'customFieldValues.customFieldOption'
            ])
                ->orderBy('id', 'desc')
                ->get();

            $projectsData = $projects->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $projectData = [
                    'id' => $property->id,
                    'project_unique_id' => $property->project_unique_id,
                    'name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'project_status' => $property->project_status,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:i A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:i A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $projectData;
            });

            return response()->json($projectsData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // this is for listing of property for buy
    public function listingOfPropertyForBuy(Request $request)
    {
        try {

            $purposeId = Purpose::where('slug', 'like', '%buy%')->value('id') ?? 0;

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])
                ->where('purpose_id', $purposeId)
                ->where('status', 'approved')
                ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // this is for listing of property for rent
    public function listingOfPropertyForRent(Request $request)
    {
        try {

            $purposeId = Purpose::where('slug', 'like', '%rent%')->value('id') ?? 0;

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])
                ->where('purpose_id', $purposeId)
                ->where('status', 'approved')
                ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }





    public function updateSiteSetting(Request $request)
    {
        // Manual validator
        $validator = Validator::make($request->all(), [
            'website_logo' => 'nullable|image|max:2048|mimes:png,jpg,jpeg',
            'mobile_logo' => 'nullable|image|max:2048|mimes:png,jpg,jpeg',
            'site_name' => 'nullable|string',
            'favicon' => 'nullable|image|max:2048|mimes:png,jpg,jpeg',
            'address' => 'nullable|string',
            'mobile_number' => 'nullable|numeric|digits:10',
            'email' => 'nullable|string|email',
            'copyright_text' => 'nullable|string',
            'disclaimer' => 'nullable|string',
            'site_short_description' => 'nullable|string',
            'subscribe_short_description' => 'nullable|string',
            'facebook' => 'nullable|string',
            'instagram' => 'nullable|string',
            'twitter' => 'nullable|string',
            'ticket_prefix' => 'nullable|string'
        ]);

        // If validation fails, return JSON
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Get validated data
        $data = $validator->validated();

        // Handle website_logo upload
        if ($request->hasFile('website_logo')) {
            $file = $request->file('website_logo');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/website_logo'), $fileName);
            $data['website_logo'] = $fileName;
        }

        // Handle mobile_logo upload
        if ($request->hasFile('mobile_logo')) {
            $file = $request->file('mobile_logo');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/mobile_logo'), $fileName);
            $data['mobile_logo'] = $fileName;
        }

        // Handle favicon upload
        if ($request->hasFile('favicon')) {
            $file = $request->file('favicon');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/favicon'), $fileName);
            $data['favicon'] = $fileName;
        }

        // Update or create settings
        $setting = SiteSetting::first();
        if ($setting) {
            foreach ($data as $key => $value) {
                $setting->$key = $value;
            }
            $setting->save();
        } else {
            $setting = SiteSetting::create($data);
        }

        return response()->json([
            'status' => true,
            'message' => 'Settings updated successfully',
            'settings' => $setting
        ]);
    }


    // this is for listing of property with project
    public function listingOfPropertyWithProject(Request $request)
    {
        try {

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])
                ->where('status', 'approved')
                ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    public function insertSubscribeEmail(Request $request)
    {
        // Validate the email
        $validator = Validator::make($request->all(), [
            'subscribe_email' => 'required|email|unique:subscribed_emails,subscribe_email',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Save the email to the database
        // Assuming you have a Subscription model and subscriptions table
        $subscription = new SubscribedEmail();
        $subscription->subscribe_email = $request->subscribe_email;
        $subscription->is_subscribed = true;
        $subscription->save();

        return response()->json(['message' => 'Subscription successful'], 200);
    }


    public function listingOfSubscribedEmails(Request $request)
    {

        $subscription = SubscribedEmail::get();

        return response()->json(['data' => $subscription], 200);
    }





    public function importSubscribedEmails(Request $request)
    {
        // Validate the file
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:csv,xlsx,xls|max:2048', // 2MB max size
        ]);

        // If validation fails, return a JSON response with error details
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        try {
            // Get the uploaded file
            $file = $request->file('file');

            // Import the file using the SubscribedEmailsImport class
            Excel::import($import = new SubscribedEmailsImport, $file);

            // Get the import summary (successes, duplicates, invalid emails, etc.)
            $summary = $import->getSummary();

            // Generate an error log if there were any errors during the import
            $errorLogFile = $import->generateErrorLog();
            return response()->json([
                'message' => 'Emails import completed successfully.',
                'summary' => $summary,
                'error_log_url' => $errorLogFile ? url($errorLogFile) : null,
            ], 200);


        } catch (\Exception $e) {
            // Return a JSON response in case of an exception
            return response()->json([
                'error' => 'Error processing the file: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportSubscribedEmails($format = 'csv', Request $request)
    {
        // Validate the requested format
        if (!in_array($format, ['csv', 'xlsx'])) {
            return response()->json(['error' => 'Invalid format. Only CSV and Excel are supported.'], 400);
        }

        // Get filter parameters from the request
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $tag = $request->query('tag');
        $isSubscribed = $request->query('is_subscribed');

        // Log the filter parameters for debugging
        \Log::info("Filters - Start Date: $startDate, End Date: $endDate, Tag: $tag, Is Subscribed: $isSubscribed");

        // Prepare the filters for export
        $filters = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'tag' => $tag,
            'is_subscribed' => $isSubscribed,
        ];

        // File name and path setup
        $fileName = 'subscribed_emails_' . now()->format('Y_m_d_H_i_s') . '.' . $format;
        $filePath = 'uploads/error_log/' . $fileName;

        // Ensure the directory exists
        if (!Storage::exists('uploads/error_log')) {
            Storage::makeDirectory('uploads/error_log');
        }

        // Export the filtered data and store it
        (new SubscribedEmailsExport($filters))->store($filePath, 'public');

        // Generate the public URL for the file
        $fileUrl = Storage::url($filePath);

        // Return the file URL as a response
        return response()->json([
            'message' => 'File exported successfully!',
            'file_url' => url($fileUrl)
        ]);
    }

















    // this is for listing of propeprty by location
    public function propertyListingByLocation(Request $request)
    {
        try {

            $location_slug = $request->location_slug;

            $location_id = Location::where('slug', $location_slug)->value('id');

            $baseURL = config('app.url');
            $basePath = public_path();

            $properties = PropertyList::with(['location', 'user', 'propertyType', 'purpose', 'property', 'propertystatus', 'project', 'customFieldValues.customField', 'customFieldValues.customFieldOption'])
                ->where('location_id', $location_id)
                ->where('status', 'approved')
                ->get();

            $propertiesData = $properties->map(function ($property) use ($baseURL, $basePath) {
                $formattedCustomFieldValues = $property->customFieldValues->map(function ($customFieldValue) use ($baseURL) {
                    $customField = $customFieldValue->customField;
                    $customFieldOption = $customFieldValue->customFieldOption ?? null;

                    $fieldValue = $customFieldValue->field_meta_value;
                    if ($customField && $customField->field_type == 'checkbox') {
                        // For checkbox, explode the value to get an array
                        $fieldValueArray = explode(',', $fieldValue);
                    } elseif ($customField && $customField->field_type == 'media') {
                        // Handling for media field
                        // Add baseURL to media file
                        $fieldValueArray = json_decode($fieldValue);
                        $fieldValueArray = collect($fieldValueArray)->map(function ($file) use ($baseURL) {
                            return $baseURL . '/uploads/media/' . $file;
                        });
                    } else {
                        // For other field types or if $customField is null, keep the value as is
                        $fieldValueArray = $fieldValue;
                    }

                    // Include all options for the field
                    $customFieldOptions = $customField ? $customField->options : null;

                    return [
                        'custom_field_id' => $customField ? $customField->id : null,
                        'field_type' => $customField ? $customField->field_type : null,
                        'field_value' => $fieldValueArray,
                        'field_name' => $customField ? $customField->field_name : null,
                        // 'custom_field_options' => $customFieldOptions,
                    ];
                });

                // Prepare property data
                $propertyData = [
                    'id' => $property->id,
                    'property_unique_id' => $property->property_unique_id,
                    'property_name' => $property->name,
                    'description' => $property->description,
                    'location_id' => $property->location_id,
                    'location_name' => optional($property->location)->name,
                    'property_address' => $property->property_address,
                    'status' => $property->status,
                    'status_reason' => $property->status_reason,
                    'user_id' => $property->user_id,
                    'listed_by' => optional(optional($property->user)->role)->name,
                    'featured_image' => $property->featured_image ? $this->correctFilePath($property->featured_image, $baseURL, $basePath, 'featured_image') : null,
                    'purpose_id' => $property->purpose_id,
                    'purpose_id_name' => optional($property->purpose)->name,
                    'property_id' => $property->property_id,
                    'property_id_name' => optional($property->property)->name,
                    'property_status_id' => $property->property_status_id,
                    'property_status_id_name' => optional($property->propertystatus)->name,
                    'property_type_id' => $property->property_type_id,
                    'property_type_id_name' => optional($property->propertyType)->name,
                    'project_id' => $property->project_id,
                    'project_id_name' => optional($property->project)->name,
                    'total_view' => $property->analytics()->count(),
                    'date' => date('d m Y', strtotime($property->created_at)),
                    'time' => date('h:m A', strtotime($property->created_at)),
                    'timestamp' => date('d m Y h:m A', strtotime($property->created_at)),
                    'custom_field_values' => $formattedCustomFieldValues,
                ];

                return $propertyData;
            });

            return response()->json($propertiesData);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function searchUser(Request $request)
    {
        $search = $request->search;
        $searchByRelated = $request->search_by_related; // Pass a specific role name if needed

        $users = User::join('roles', 'roles.id', '=', 'users.role_id') // Join users with roles
            ->where(function ($query) use ($search, $searchByRelated) {
                // Check if specific role-based filtering is requested
                if ($searchByRelated) {
                    $query->where('roles.name', 'LIKE', '%' . $searchByRelated . '%'); // Filter by specific role
                }

                // General search across multiple fields
                $query->where(function ($q) use ($search) {
                    $q->where('users.unique_id', 'LIKE', '%' . $search . '%')
                        ->orWhere('users.email', 'LIKE', '%' . $search . '%')
                        ->orWhere('users.phone', 'LIKE', '%' . $search . '%')
                        ->orWhere('roles.name', 'LIKE', '%' . $search . '%'); // Search by role name
                });
            })
            ->select('users.*', 'roles.name as role_name')
            ->get();

        if ($request->search_by_related == 0) {
            $users = User::join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.name', '!=', 'admin')
                ->where(function ($query) use ($search, $searchByRelated) {
                    // Check if specific role-based filtering is requested
                    if ($searchByRelated) {
                        $query->where('roles.name', 'LIKE', '%' . $searchByRelated . '%'); // Filter by specific role
                    }

                    // General search across multiple fields
                    $query->where(function ($q) use ($search) {
                        $q->where('users.unique_id', 'LIKE', '%' . $search . '%')
                            ->orWhere('users.email', 'LIKE', '%' . $search . '%')
                            ->orWhere('users.phone', 'LIKE', '%' . $search . '%')
                            ->orWhere('roles.name', 'LIKE', '%' . $search . '%'); // Search by role name
                    });
                })
                ->select('users.*', 'roles.name as role_name')
                ->get();
        }



        return response()->json([
            'status' => true,
            'data' => $users
        ], 200);
    }

    public function bulkDelete(Request $request)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract the token by removing the "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Check if the token is empty after removing "Bearer "
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the token dynamically (e.g., check in the database)
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        // Validate the incoming request
        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        try {
            $ids = $request->input('ids'); // Get the list of IDs from the request

            DB::beginTransaction();

            // Delete users and their associated details in bulk
            User::whereIn('id', $ids)->delete();
            UserDetail::whereIn('user_id', $ids)->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Users deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json([
                'error' => 'Failed to delete users. ' . $e->getMessage(),
            ], 500);
        }
    }


    public function filterByRole(Request $request)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract the token by removing the "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Check if the token is empty after removing "Bearer "
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the token dynamically (check in the database)
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }
        $roleName = $request->query('role'); // Role name from query parameter

        if (!$roleName) {
            return response()->json([
                'status' => false,
                'message' => 'Role parameter is required.'
            ], 400);
        }

        // Fetch the role ID dynamically
        $role = Role::where('name', $roleName)->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Role not found.'
            ], 404);
        }

        // Fetch users based on the role ID
        $users = User::where('role_id', $role->id)->get();

        return response()->json([
            'status' => true,
            'data' => $users
        ], 200);
    }



    public function filterByStatus(Request $request)
    {
        if (!$request->hasHeader('Authorization') || empty($request->header('Authorization'))) {
            return response()->json(['error' => 'Please provide an API token.'], 422);
        }

        // Retrieve the Authorization header
        $authorizationHeader = $request->header('Authorization');

        // Check if the header starts with "Bearer "
        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        // Extract the token by removing the "Bearer " prefix
        $requestToken = substr($authorizationHeader, 7);

        // Check if the token is empty after removing "Bearer "
        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        // Verify the token dynamically (check in the database)
        $user = User::where('api_token', $requestToken)->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        // Get the status value from the query parameter
        $statusValue = $request->query('isapproved');

        // Validate the status value
        if ($statusValue === null || !in_array($statusValue, ['1', '0'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or missing status value. Use "1" or "0".'
            ], 400);
        }

        // Fetch users based on the `isapproved` status
        $users = User::where('isapproved', $statusValue)->get();

        return response()->json([
            'status' => true,
            'data' => $users
        ], 200);
    }



    public function getConsultancyAgents(Request $request, $id)
    {
        try {
            // Fetch the role
            $role = Role::find($id);

            // Check if the role exists and is 'consultancy'
            if (!$role) {
                return response()->json(['error' => 'Role not found'], 404);
            }

            if ($role->name !== 'consultancy') {
                return response()->json(['error' => 'This role is not consultancy'], 400);
            }

            // Fetch users with the consultancy role
            $agents = User::where('role_id', $id)->get();

            return response()->json($agents, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }






    public function updateProfile(Request $request)
    {
        try {
            // Retrieve authenticated user from token
            $user = auth()->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Invalid or missing token.'], 401);
            }
            // Prevent profile update if isapproved is 2 or 4
            if ($user->isapproved == 2 || $user->isapproved == 4) {
                return response()->json(['error' => 'Profile update not allowed. Your account is restricted.'], 403);
            }
            // Log the authenticated user for debugging
            \Log::info('Authenticated User:', ['id' => $user->id, 'email' => $user->email]);

            // Validate request data
            $request->validate([
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'password' => [
                    'nullable',
                    'min:8',
                    'regex:/[A-Z]/',
                    'regex:/[a-z]/',
                    'regex:/[0-9]/',
                    'regex:/[@$!%*#?&]/',
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        // Prevent admin role modification
        if ($user->role_id == 1) {
            return response()->json(['error' => 'You cannot create the admin role as it already exists.'], 400);
        }

        // Find user to update
        $userToUpdate = User::find($user->id);

        if (!$userToUpdate) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Ensure user can only update their own profile unless they are an admin
        if ($user->id !== $userToUpdate->id && $user->role_id !== 1) {
            return response()->json(['error' => 'Unauthorized. You can only update your own profile.'], 403);
        }

        // Update user details
        $userToUpdate->first_name = $request->first_name;
        $userToUpdate->last_name = $request->last_name;

        if ($request->filled('password')) {
            $userToUpdate->password = Hash::make($request->password);
        }

        DB::beginTransaction();
        try {
            $userToUpdate->save();

            // Fetch existing user details
            $userDetail = UserDetail::firstOrNew(['user_id' => $userToUpdate->id]);

            // Update User Details
            $userDetail->fill([
                'bussiness_name' => $request->bussiness_name,
                'bussiness_address' => $request->bussiness_address,
                'bussiness_email' => $request->bussiness_email,
                'business_phone' => $request->business_phone,
                'country_id' => $request->country_id ?? null,
                'state_id' => $request->state_id ?? null,
                'city_id' => $request->city_id ?? null,
                'address' => $request->address,
                'pin_code' => $request->pin_code,
                'license_number' => $request->license_number,
                'alternate_number' => $request->alternate_number,
                'no_of_employees' => $request->no_of_employees,
                'about_us' => $request->about_us
            ]);

            // Handling Profile Photo Upload
            if ($request->hasFile('profile_photo')) {
                $file = $request->file('profile_photo');
                $fileName = time() . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                $file->move(public_path('uploads/users'), $fileName);
                $userDetail->profile_photo = 'uploads/users/' . $fileName;
            }

            $userDetail->save();

            // Role-Based KYC Requirement
            $rolesForKYC = ['agent', 'company', 'consultancy', 'developer'];
            $role = Role::find($userToUpdate->role_id);

            if ($role && in_array(strtolower($role->name), $rolesForKYC)) {
                $requiredFields = [
                    'bussiness_name',
                    'bussiness_address',
                    'bussiness_email',
                    'business_phone',
                    'country_id',
                    'state_id',
                    'city_id',
                    'address',
                    'pin_code',
                    'profile_photo'
                ];

                // Check missing fields
                $missingFields = [];
                foreach ($requiredFields as $field) {
                    if (empty($request->input($field)) && empty($userDetail->$field)) {
                        $missingFields[] = $field;
                    }
                }

                // Set KYC status based on missing fields
                $userToUpdate->kyc = (string) 1;
                $userToUpdate->save();

                // Fetch from DB to verify
                $updatedUser = User::find($userToUpdate->id);
                \Log::info('KYC updated in DB:', ['id' => $updatedUser->id, 'kyc' => $updatedUser->kyc]);

                // Debugging
                if (!empty($missingFields)) {
                    \Log::warning('KYC not set due to missing fields: ', $missingFields);
                }
            }

            DB::commit();
            return response()->json(['status' => true, 'message' => 'User updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('User Update Error: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Failed to update user. Please try again.'], 500);
        }
    }

    public function getDataUserDetailsByRole(Request $request)
    {
        $AuthUser = auth('sanctum')->user() ?? User::where('api_token', $request->bearerToken())->first();

        try {
            $roleId = $request->role_id; // Optional filter
            $perPage = $request->per_page ?? 10; // Default 10 records per page

            $query = DB::table('users')->where('users.isapproved', '=', 1)
                ->leftJoin('user_details', 'users.id', '=', 'user_details.user_id')
                ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                ->leftJoin('countries', 'user_details.country_id', '=', 'countries.id')
                ->leftJoin('states', 'user_details.state_id', '=', 'states.id')
                ->leftJoin('cities', 'user_details.city_id', '=', 'cities.id')
                ->select(
                    'users.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.phone',
                    'users.role_id',
                    DB::raw("IFNULL(roles.name, 'No Role') as role_name"),
                    'users.unique_id',
                    'users.isapproved',
                    'users.country_id',
                    'users.state_id',
                    'users.city_id',
                    'countries.name as country',
                    'states.name as state',
                    'cities.name as city',
                    'users.area_locality',
                    'users.colony',
                    'users.street_address',
                    'users.pin_code',
                    'users.about',
                    'user_details.bussiness_name',
                    'user_details.profile_photo',
                    'user_details.about_us',
                    'users.created_at',
                    'users.updated_at'
                )
                ->where('roles.name', '!=', 'admin'); // Exclude Admin role

            // Filter by role if provided
            if (!empty($roleId)) {
                $query->where('users.role_id', $roleId);
            }

            // Paginate results
            $paginatedData = $query->paginate($perPage);

            // Format each user record
            $paginatedData->getCollection()->transform(function ($user) use ($AuthUser) {

                $email = $user->email;
                $phone = $user->phone;

                // Masking only if NOT Authenticated User
                if (!$AuthUser) {
                    if (!empty($email)) {
                        // Example: te***@gmail.com
                        $email = preg_replace('/(?<=.{2}).(?=.*@)/', '*', $email);
                    }
                    if (!empty($phone)) {
                        // Example: 957****274
                        $phone = substr($phone, 0, 3) . '****' . substr($phone, -3);
                    }
                }

                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $email,
                    'phone' => $phone,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role_name,
                    'unique_id' => $user->unique_id,
                    'isapproved' => $user->isapproved,
                    'country' => $user->country ?? 'N/A',
                    'state' => $user->state ?? 'N/A',
                    'city' => $user->city ?? 'N/A',
                    'area_locality' => $user->area_locality ?? 'N/A',
                    'colony' => $user->colony ?? 'N/A',
                    'street_address' => $user->street_address ?? 'N/A',
                    'pin_code' => $user->pin_code ?? 'N/A',
                    'about' => $user->about,
                    'bussiness_name' => $user->bussiness_name,
                    'profile_photo' => $user->profile_photo ? url($user->profile_photo) : null,
                    'about_us' => $user->about_us,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'User details retrieved successfully',
                'users' => $paginatedData
            ], 200);

        } catch (\Throwable $th) {
            \Log::error('Error fetching user list by role:', ['error' => $th->getMessage()]);
            return response()->json(['error' => 'Internal Server Error.'], 500);
        }
    }



  public function getDataUserDetailsById(Request $request)
{
    $AuthUser = auth('sanctum')->user() ?? User::where('api_token', $request->bearerToken())->first();

    try {
        $Id = $request->id; // Required filter

        $query = DB::table('users')
            ->leftJoin('user_details', 'users.id', '=', 'user_details.user_id')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->leftJoin('countries', 'user_details.country_id', '=', 'countries.id')
            ->leftJoin('states', 'user_details.state_id', '=', 'states.id')
            ->leftJoin('cities', 'user_details.city_id', '=', 'cities.id')
            ->leftJoin('countries as user_countries', 'users.country_id', '=', 'user_countries.id')
            ->leftJoin('states as user_states', 'users.state_id', '=', 'user_states.id')
            ->leftJoin('cities as user_cities', 'users.city_id', '=', 'user_cities.id')
            ->leftJoin('purposes','user_details.purpose_id','=','purposes.id')
            ->leftJoin('properties','user_details.property_id','=','properties.id')
            ->leftJoin('property_types','user_details.property_type_id','=','property_types.id')

            ->select(
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.user_name',
                'users.email',
                'users.phone',
                'users.role_id',
                DB::raw("IFNULL(roles.name, 'No Role') as role_name"),
                'users.unique_id',
                'users.country_id',
                'users.state_id',
                'users.city_id',
                'user_countries.name as country',
                'user_states.name as state',
                'user_cities.name as city',
                'users.area_locality',
                'users.colony',
                'users.street_address',
                'users.pin_code',
                'users.about',

                'user_details.bussiness_name',
                'user_details.bussiness_address',
                'user_details.bussiness_email',
                'user_details.business_phone',
                'user_details.country_id as business_country_id',
                'user_details.state_id as business_state_id',
                'user_details.city_id as business_city_id',
                'countries.name as business_country',
                'states.name as business_state',
                'cities.name as business_city',
                'user_details.area_locality as business_area_locality',
                'user_details.colony as business_colony',
                'user_details.street_address as business_street_address',
                'user_details.pin_code as business_pin_code',
                'user_details.address',
                'user_details.profile_photo',
                'user_details.license_number',
                'user_details.alternate_number',
                'user_details.no_of_employees',
                'user_details.about_us',
                'user_details.rera_number',
                'user_details.purpose_id',
                'purposes.name as purpose_name',
                'user_details.property_id',
                'properties.name as property_name',
                'user_details.property_type_id',
                'property_types.name as property_type_name',

                'users.created_at',
                'users.updated_at'
            )
            ->where('roles.name', '!=', 'admin') // Exclude Admin role
            ->where('users.isapproved','=',1)
            ->where('users.id', $Id);

        $user = $query->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 200);
        }

        // Masking (only if NOT Authenticated User)
        $email = $user->email;
        $phone = $user->phone;
        $bisiness_email = $user->bussiness_email;
        $bisiness_phone = $user->business_phone;
        $alternate_number = $user->alternate_number;

        if (!$AuthUser) {
            if (!empty($email)) {
                $email = preg_replace('/(?<=.{2}).(?=.*@)/', '*', $email);
            }
            if (!empty($phone)) {
                $phone = substr($phone, 0, 3) . '****' . substr($phone, -3);
            }
            if (!empty($bisiness_email)) {
                $bisiness_email = preg_replace('/(?<=.{2}).(?=.*@)/', '*', $bisiness_email);
            }
            if (!empty($bisiness_phone)) {
                $bisiness_phone = substr($bisiness_phone, 0, 3) . '****' . substr($bisiness_phone, -3);
            }
            if (!empty($alternate_number)) {
                $alternate_number = substr($alternate_number, 0, 3) . '****' . substr($alternate_number, -3);
                }
        }

        $userData = [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'user_name' => $user->user_name,
            'email' => $email,
            'phone' => $phone,
            'role_id' => $user->role_id,
            'role_name' => $user->role_name,
            'unique_id' => $user->unique_id,
            'country' => $user->country ?? 'N/A',
            'state' => $user->state ?? 'N/A',
            'city' => $user->city ?? 'N/A',
            'area_locality' => $user->area_locality ?? 'N/A',
            'colony' => $user->colony ?? 'N/A',
            'street_address' => $user->street_address ?? 'N/A',
            'pin_code' => $user->pin_code ?? 'N/A',
            'about' => $user->about,
            'bussiness_name' => $user->bussiness_name,
            'bussiness_address' => $user->bussiness_address,
            'bussiness_email' => $bisiness_email,
            'business_phone' => $bisiness_phone,
            'business_country' => $user->business_country ?? 'N/A',
            'business_state' => $user->business_state ?? 'N/A',
            'business_city' => $user->business_city ?? 'N/A',
            'business_area_locality' => $user->business_area_locality ?? 'N/A',
            'business_colony' => $user->business_colony ?? 'N/A',
            'business_street_address' => $user->business_street_address ?? 'N/A',
            'business_pin_code' => $user->business_pin_code ?? 'N/A',
            'address' => $user->address,
            'profile_photo' => $user->profile_photo ? url($user->profile_photo) : null,
            'license_number' => $user->license_number,
            'alternate_number' => $alternate_number,
            'rera_number' => $user->rera_number,
            'no_of_employees' => $user->no_of_employees,
            'about_us' => $user->about_us,
            'purpose_id' => $user->purpose_id,
            'purpose_name' => $user->purpose_name,
            'property_id' => $user->property_id,
            'property_name' => $user->property_name,
            'property_type_id' => $user->property_type_id,
            'property_type_name' => $user->property_type_name,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at
        ];

        return response()->json([
            'success' => true,
            'message' => 'User details retrieved successfully',
            'user' => $userData
        ], 200);

    } catch (\Throwable $th) {
        \Log::error('Error fetching user details by id:', ['error' => $th->getMessage()]);
        return response()->json(['error' => 'Internal Server Error.'], 500);
    }
}





}
