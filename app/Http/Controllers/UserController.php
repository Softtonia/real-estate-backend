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
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{

    private function clearUserCaches($userId = null)
    {
        Cache::store('redis')->forget('user_status_list');
        Cache::store('redis')->forget('all_agent_listing_admin');
        Cache::store('redis')->forget('all_consultancy_listing');
        Cache::store('redis')->forget('user_analytics');
        if ($userId) {
            Cache::store('redis')->forget("user_details_admin_{$userId}");
            Cache::store('redis')->forget("user_details_website_{$userId}");
        }
    }
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
    // public function registerOld(Request $request)
    // {
    //     dd($request->all());
    //     // Validate request data
    //     try {
    //         $request->validate([
    //             'first_name' => 'required',
    //             //'last_name' => 'required',
    //             'phone' => 'required|unique:users',
    //             'email' => 'required|unique:users',
    //             'role_id' => 'required|exists:roles,id',
    //         ]);
    //     } catch (ValidationException $e) {
    //         return response()->json(['error' => $e->errors()], 400);
    //     }

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

    //     $userRole = User::where('role_id', $role->id)->count();

    //     if ($userRole == 0) {
    //         // If no users exist for this role, start the count from 001
    //         $uniqueIDModel = new UniqueID();
    //         // Generate unique ID with prefix and padded count
    //         $uniqueIDModel->unique_id = $role->prefix . str_pad(1, 3, '0', STR_PAD_LEFT); // Starts from 001
    //         $uniqueIDModel->save();
    //     } else {

    //         // If users exist, fetch the highest current count for this role's prefix and increment it
    //         $lastUniqueID = UniqueID::where('unique_id', 'like', $role->prefix . '%')
    //             ->orderBy('unique_id', 'desc')
    //             ->first();

    //         // If there are no existing unique IDs, start from 001
    //         if (!$lastUniqueID) {
    //             $newUniqueID = $role->prefix . str_pad(1, 3, '0', STR_PAD_LEFT); // Start from 001
    //         } else {
    //             // Extract the numeric part from the last unique_id
    //             $lastCount = (int) substr($lastUniqueID->unique_id, strlen($role->prefix));

    //             // Increment the count and generate the new unique_id
    //             $newUniqueID = $role->prefix . str_pad($lastCount + 1, 3, '0', STR_PAD_LEFT);
    //         }

    //         // Save the new unique_id
    //         $uniqueIDModel = new UniqueID();
    //         $uniqueIDModel->unique_id = $newUniqueID;
    //         $uniqueIDModel->save();
    //     }

    //     $token = Str::random(60);

    //     if ($request->role_id == 2) {
    //         $isapproved = 1;
    //     } else {
    //         $isapproved = 2;
    //     }

    //     // Create a new user
    //     $user = new User();
    //     $user->first_name = $request->first_name;
    //     $user->last_name = $request->last_name;
    //     $user->fullname = $request->fullname ?? null;
    //     $user->email = $request->email;
    //     $user->phone = $request->phone;
    //     $user->api_token = $token;
    //     $user->remember_token = $request->token;
    //     //$user->requestId = $request->uid;
    //     $user->role_id = $request->role_id; // Set role_id
    //     $user->password = Hash::make($request->password);
    //     $user->unique_id = $uniqueIDModel->unique_id;
    //     $user->created_by = Auth::user()->id ?? 0;
    //     $user->isapproved = $isapproved;

    //     // Add entry to user_has_unique_ids table
    //     DB::beginTransaction();
    //     try {
    //         $user->save();
    //         DB::table('user_has_unique_ids')->insert([
    //             'user_id' => $user->id,
    //             'unique_id' => $uniqueIDModel->id,
    //         ]);


    //         // Create and save OTP record
    //         // $otp = new Otp();
    //         // $otp->phone = $request->phone;
    //         // $otp->otp = '123456' ?? $request->otp;
    //         // $otp->user_id = $user->id;
    //         // $otp->phone = $user->phone;
    //         // $otp->uid = $request->uid;
    //         // $otp->save();

    //         $userDetail = array(
    //             'user_id' => $user->id,
    //             'role_id' => $request->role_id
    //         );

    //         UserDetail::create($userDetail);

    //         DB::commit();
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error($e->getMessage()); // Log the exception message
    //         return response()->json(['error' => 'Failed to register user.' . $e->getMessage()], 500);
    //     }

    //     // Return response
    //     return response()->json(
    //         ['status' => true, 'message' => 'User registeration successfully.', 'data' => $user],
    //         201
    //     );
    // }


    // for check uniqueness



    public function checkUnique(Request $request)
    {
        // Validate the request input
        $request->validate([
            'id' => 'nullable|exists:users,id', // update ke case me bhejna hoga
            'email' => 'nullable|email',
            'phone' => 'nullable|digits:10',
            'user_name' => ['nullable', 'string', 'min:3', 'max:20', 'regex:/^[a-zA-Z0-9._]+$/'],
        ], [
            'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
        ]);

        $id = $request->input('id'); // update case me bhejna hoga
        $email = $request->input('email');
        $phone = $request->input('phone');
        $userName = $request->input('user_name');

        $response = [];

        // Email check
        if ($email) {
            $query = User::where('email', $email);
            if ($id) {
                $query->where('id', '!=', $id); // apna khud ka record ignore kare
            }
            $emailExists = $query->exists();

            $response['email'] = [
                'exists' => $emailExists,
                'message' => $emailExists ? 'Email already exists' : 'Email is available',
            ];
        }

        // Phone check
        if ($phone) {
            $query = User::where('phone', $phone);
            if ($id) {
                $query->where('id', '!=', $id);
            }
            $phoneExists = $query->exists();

            $response['phone'] = [
                'exists' => $phoneExists,
                'message' => $phoneExists ? 'Phone number already exists' : 'Phone number is available',
            ];
        }

        // Username check
        if ($userName) {
            $query = User::where('user_name', $userName);
            if ($id) {
                $query->where('id', '!=', $id);
            }
            $userNameExists = $query->exists();

            $response['user_name'] = [
                'exists' => $userNameExists,
                'message' => $userNameExists ? 'Username already exists' : 'Username is available',
            ];
        }

        if (empty($email) && empty($phone) && empty($userName)) {
            return response()->json(['error' => 'Please provide either an email, phone number, or username'], 400);
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

    public function alluserlist(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $page = (int) $request->input('page', 1);

            $cacheKey = "users_all_list_page_{$page}_per_page_{$perPage}";

            $response = Cache::store('redis')->remember($cacheKey, 60, function () use ($request, $perPage) {

                $users = User::select([
                    'id',
                    'first_name',
                    'last_name',
                    'user_name',
                    'email',
                    'phone',
                    'role_id',
                    'unique_id',
                    'isapproved',
                    'kyc',
                ])
                    ->where('role_id', '!=', 1)
                    ->with('role:id,name')
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
                        'kyc' => $user->kyc,
                    ];
                });

                $baseUrl = $request->url();
                $queryParams = $request->query();
                $queryParams['per_page'] = $users->perPage();

                $firstPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => 1]));
                $lastPageUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $users->lastPage()]));

                return [
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
                ];
            });

            return response()->json($response, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // for get details by user_id
    public function getdetailsbyuserid(Request $request)
    {
        try {
            $userId = $request->id;

            if (!$userId) {
                return response()->json(['error' => 'User id is required.'], 400);
            }

            $cacheKey = "user_details_admin_{$userId}";

            $response = Cache::store('redis')->remember($cacheKey, 300, function () use ($userId) {

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
                        'users.kyc',
                        'users.is_otp_verified',
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
                        'user_details.aadhaar_number',
                        'user_details.aadhaar_front',
                        'user_details.aadhaar_back',
                        'user_details.business_proof',

                        'user_details.address',
                        'user_details.profile_photo',
                        'user_details.license_number',
                        'user_details.alternate_number',
                        'user_details.no_of_employees',
                        'user_details.about_us',
                        'users.created_at',
                        'users.updated_at'
                    )
                    ->first();

                if (!$userData) {
                    return null;
                }

                return [
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
                    'kyc' => $userData->kyc,
                    'is_otp_verified' => $userData->is_otp_verified,
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
                    'aadhaar_number' => $userData->aadhaar_number,
                    'aadhaar_front' => $userData->aadhaar_front ? url($userData->aadhaar_front) : null,
                    'aadhaar_back' => $userData->aadhaar_back ? url($userData->aadhaar_back) : null,
                    'business_proof' => $userData->business_proof ? url($userData->business_proof) : null,
                    'license_number' => $userData->license_number,
                    'alternate_number' => $userData->alternate_number,
                    'no_of_employees' => $userData->no_of_employees,
                    'about_us' => $userData->about_us,
                    'created_at' => $userData->created_at,
                    'updated_at' => $userData->updated_at
                ];
            });

            if (!$response) {
                \Log::error('User not found', ['id' => $userId]);
                return response()->json(['error' => 'No data found for this user.'], 404);
            }

            return response()->json($response, 200);
        } catch (\Throwable $th) {
            \Log::error('Error fetching user details:', ['error' => $th->getMessage()]);
            return response()->json(['error' => 'Internal Server Error.'], 500);
        }
    }


    public function getdetailsbyuseridForWebsite(Request $request)
    {
        try {
            $userId = $request->input('id');

            if (!$userId) {
                return response()->json(['error' => 'User id is required.'], 400);
            }

            $cacheKey = "user_details_website_{$userId}";

            $response = Cache::store('redis')->remember($cacheKey, 300, function () use ($userId) {

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

                if (!$userData) {
                    return null;
                }

                return [
                    'id' => $userData->id,
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
                    'country_id' => $userData->country_id ?? 'N/A',
                    'state_id' => $userData->state_id ?? 'N/A',
                    'city_id' => $userData->city_id ?? 'N/A',
                    'address' => $userData->address,
                    'pin_code' => $userData->pin_code,
                    'profile_photo' => $userData->profile_photo ? url($userData->profile_photo) : null,
                    'license_number' => $userData->license_number,
                    'alternate_number' => $userData->alternate_number,
                    'no_of_employees' => $userData->no_of_employees,
                    'about_us' => $userData->about_us,
                    'created_at' => $userData->created_at,
                    'updated_at' => $userData->updated_at
                ];
            });

            if (!$response) {
                return response()->json(['error' => 'No data found for this user.'], 404);
            }

            return response()->json($response, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }


    // for update user by id


    public function updateuserbyid(Request $request)
    {

        $authUser = Auth::user();


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
                'country_id' => ['nullable', 'exists:countries,id'],
                'state_id' => ['nullable', 'exists:states,id'],
                'city_id' => ['nullable', 'exists:cities,id'],
                'area_locality' => ['nullable', 'string'],
                'colony' => ['nullable', 'string'],
                'street_address' => ['nullable', 'string'],
                'pin_code' => ['nullable', 'numeric', 'min:6'],
                'about' => ['nullable', 'string'],
                // KYC fields
                'kyc' => 'nullable|in:0,1,2',
                'aadhaar_number' => [
                    'required',
                    'digits:12',
                    Rule::unique('user_details', 'aadhaar_number')->ignore($request->id, 'user_id'),
                ],
                'aadhaar_front' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'min:10', 'max:5120'],
                'aadhaar_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'min:10', 'max:5120'],

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
                    'business_proof' => ['nullable', 'file', 'mimes:pdf', 'min:10', 'max:5120'],
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

        if ($authUser->role->name == 'admin') {
            $user->kyc = $request->kyc ?? $user->kyc;
        }

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


            if ($authUser->role->name == 'admin') {

                $userDetail['aadhaar_number'] = $request->aadhaar_number;
                if ($user->role->name == 'owner') {
                    if ($request->hasFile('aadhaar_front')) {
                        $file = $request->file('aadhaar_front');
                        $fileName = time() . '_front_' . str_replace(' ', '_', $file->getClientOriginalName());
                        $file->move(public_path('uploads/kyc/aadhaarFront'), $fileName);
                        $userDetail['aadhaar_front'] = 'uploads/kyc/aadhaarFront/' . $fileName;
                    }

                    if ($request->hasFile('aadhaar_back')) {
                        $file = $request->file('aadhaar_back');
                        $fileName = time() . '_back_' . str_replace(' ', '_', $file->getClientOriginalName());
                        $file->move(public_path('uploads/kyc/aadhaarBack'), $fileName);
                        $userDetail['aadhaar_back'] = 'uploads/kyc/aadhaarBack/' . $fileName;
                    }
                } else {

                    if ($request->hasFile('aadhaar_front')) {
                        $file = $request->file('aadhaar_front');
                        $fileName = time() . '_front_' . str_replace(' ', '_', $file->getClientOriginalName());
                        $file->move(public_path('uploads/kyc/aadhaarFront'), $fileName);
                        $userDetail['aadhaar_front'] = 'uploads/kyc/aadhaarFront/' . $fileName;
                    }

                    if ($request->hasFile('aadhaar_back')) {
                        $file = $request->file('aadhaar_back');
                        $fileName = time() . '_back_' . str_replace(' ', '_', $file->getClientOriginalName());
                        $file->move(public_path('uploads/kyc/aadhaarBack'), $fileName);
                        $userDetail['aadhaar_back'] = 'uploads/kyc/aadhaarBack/' . $fileName;
                    }

                    if ($request->hasFile('business_proof')) {
                        $file = $request->file('business_proof');
                        $fileName = time() . '_proof_' . str_replace(' ', '_', $file->getClientOriginalName());
                        $file->move(public_path('uploads/kyc/businessProof'), $fileName);
                        $userDetail['business_proof'] = 'uploads/kyc/businessProof/' . $fileName;
                    }
                }
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
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'isapproved' => 'required|integer|in:1,2',
                'reject_reason' => 'required_if:isapproved,4|string|min:3',
            ], [
                'reject_reason.required_if' => 'Reject reason is required when status is rejected.',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $user = User::select([
                'id',
                'role_id',
                'isapproved',
            ])
                ->find($request->input('user_id'));

            if (!$user) {
                return response()->json(['error' => 'User not found.'], 404);
            }

            if ($user->id == 1 || $user->role_id == 1) {
                return response()->json(['message' => 'You cannot update admin.'], 422);
            }

            $user->isapproved = $request->input('isapproved');
            $user->save();

            $message = ($user->isapproved == 1) ? 'User status updated to approved.' : (($user->isapproved == 4) ? 'User status updated to rejected.' : 'User status updated.');
            $loginMessage = ($user->isapproved == 1) ? 'User can now login.' : 'User cannot login.';

            return response()->json(['message' => $message, 'login_message' => $loginMessage], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }



    // for get all status

    function getUserStatusList()
    {
        return Cache::store('redis')->remember('user_status_list', 86400, function () {
            $column = DB::select("
            SHOW FULL COLUMNS
            FROM users
            WHERE Field = 'isapproved'
        ");

            if (!empty($column)) {
                $comment = $column[0]->Comment; // e.g. "Active=1, Deactive=2, UnderReview=3, Reject=4"

                $statuses = [];
                $pairs = explode(',', $comment);

                foreach ($pairs as $pair) {
                    if (str_contains($pair, '=')) {
                        [$label, $value] = array_map('trim', explode('=', $pair));
                        $statuses[(int) $value] = $label;
                    }
                }

                return $statuses;
            }

            return [];
        });
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

            $cacheKey = 'user_by_token_' . md5($lastSegment);

            $user = Cache::store('redis')->remember($cacheKey, now()->addMinutes(1), function () use ($lastSegment) {
                return User::where('api_token', $lastSegment)->first();
            });

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
            $cacheKey = 'all_agent_listing_admin';

            $userList = Cache::store('redis')->remember($cacheKey, 120, function () {
                $users = User::whereHas('role', function ($query) {
                    $query->where('name', 'agent');
                })
                    ->with('role')
                    ->with([
                        'userDetails' => function ($query) {
                            $query->with(['country', 'state', 'city']);
                        }
                    ])
                    ->get();

                return $users->map(function ($user) {
                    $roleName = $user->role ? $user->role->name : null;
                    $userDetails = $user->userDetails ?? null;

                    $profilePhotoUrl = $userDetails && $userDetails->profile_photo
                        ? url($userDetails->profile_photo)
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
                        'profile_photo' => $profilePhotoUrl,
                        'address' => $userDetails ? $userDetails->address : null,

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
            });

            return response()->json($userList, 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    // for cosultancy listing
    public function allConsultancyListing(Request $request)
    {
        try {
            $cacheKey = 'all_consultancy_listing';

            $userList = Cache::store('redis')->remember($cacheKey, 120, function () {
                $users = User::where('role_id', 5)
                    ->with('role')
                    ->with('userDetails')
                    ->get();

                return $users->map(function ($user) {
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
            });

            return response()->json($userList, 200);
        } catch (\Throwable $th) {
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

            $consultancies = \App\Models\JoinRequest::with('user.userDetails', 'user.role')
                ->where('type', 'company-consultancy')
                ->where('status', 2)
                ->get();

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
                'country_id' => 'nullable|exists:countries,id',
                'state_id' => 'nullable|exists:states,id',
                'city_id' => 'nullable|exists:cities,id',
                'area_locality' => 'nullable|string',
                'colony' => 'nullable|string',
                'street_address' => 'nullable|string',
                'pin_code' => 'nullable|numeric|min:6',
                'about' => 'nullable|string',
                'user_name' => 'required|string|min:3|max:20|unique:users,user_name|regex:/^[a-zA-Z0-9._]+$/',

                // KYC fields
                'kyc' => 'nullable|in:0,1,2',
                'aadhaar_number' => [
                    'nullable',
                    'digits:12',
                    Rule::unique('user_details', 'aadhaar_number')
                ],
                'aadhaar_front' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'min:10', 'max:5120'],
                'aadhaar_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'min:10', 'max:5120'],
                'business_proof' => ['nullable', 'file', 'mimes:pdf', 'min:10', 'max:5120'],

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
        $aadhaarFrontPath = null;
        $aadhaarBackPath = null;
        $businessProofPath = null;

        if ($request->hasFile('profile_photo')) {
            $photo = $request->file('profile_photo');
            $fileName = time() . '_' . str_replace(' ', '_', $photo->getClientOriginalName());
            $photo->move(public_path('uploads/users'), $fileName);
            $profilePhotoPath = 'uploads/users/' . $fileName;
        } elseif ($request->has('icon') && !empty($request->icon)) {
            $profilePhotoPath = $request->icon;
        }

        if ($request->hasFile('aadhaar_front')) {
            $aadhaarFront = $request->file('aadhaar_front');
            $fileName = time() . '_aadhaar_front_' . str_replace(' ', '_', $aadhaarFront->getClientOriginalName());
            $aadhaarFront->move(public_path('uploads/kyc/aadhaarFront'), $fileName);
            $aadhaarFrontPath = 'uploads/kyc/aadhaarFront/' . $fileName;
        }

        if ($request->hasFile('aadhaar_back')) {
            $aadhaarBack = $request->file('aadhaar_back');
            $fileName = time() . '_aadhaar_back_' . str_replace(' ', '_', $aadhaarBack->getClientOriginalName());
            $aadhaarBack->move(public_path('uploads/kyc/aadhaarBack'), $fileName);
            $aadhaarBackPath = 'uploads/kyc/aadhaarBack/' . $fileName;
        }

        if ($request->hasFile('business_proof')) {
            $businessProof = $request->file('business_proof');
            $fileName = time() . '_business_proof_' . str_replace(' ', '_', $businessProof->getClientOriginalName());
            $businessProof->move(public_path('uploads/kyc/businessProof'), $fileName);
            $businessProofPath = 'uploads/kyc/businessProof/' . $fileName;
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
                'kyc' => $request->kyc ?? 0,
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
                // KYC fields
                'aadhaar_number' => $request->aadhaar_number,
                'aadhaar_front' => $aadhaarFrontPath,
                'aadhaar_back' => $aadhaarBackPath,
                'business_proof' => $businessProofPath,
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

        DB::beginTransaction();

        try {
            User::where('id', $id)->delete();
            UserDetail::where('user_id', $id)->delete();

            DB::commit();

            return response()->json(['status' => true, 'message' => 'User deleted successfully.'], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to delete user. ' . $e->getMessage()], 500);
        }
    }



    // for create agent

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

            $userData = User::select([
                'id',
                'role_id',
                'api_token',
            ])
                ->where('api_token', $requestToken)
                ->first();

            if (!$userData) {
                return response()->json(['error' => 'User not found'], 404);
            }

            $userId = $userData->id;

            $users = User::where('created_by', $userId)
                ->with('role')
                ->with('userDetails')
                ->get();

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

            return response()->json($userList, 200);
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






    // for top agent listing
    public function allTopAgentListing(Request $request)
    {
        try {
            $users = User::where('role_id', 3)
                ->with('role')
                ->with('userDetails')
                ->get();

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

            return response()->json($userList, 200);
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
        $searchByRelated = $request->search_by_related;

        $query = User::join('roles', 'roles.id', '=', 'users.role_id');

        if ($request->search_by_related == 0) {
            $query->where('roles.name', '!=', 'admin');
        }

        $query->where(function ($query) use ($search, $searchByRelated) {

            if ($searchByRelated) {
                $query->where('roles.name', 'LIKE', '%' . $searchByRelated . '%');
            }

            $query->where(function ($q) use ($search) {
                $q->where('users.unique_id', 'LIKE', '%' . $search . '%')
                    ->orWhere('users.email', 'LIKE', '%' . $search . '%')
                    ->orWhere('users.phone', 'LIKE', '%' . $search . '%')
                    ->orWhere('roles.name', 'LIKE', '%' . $search . '%');
            });
        });

        $users = $query
            ->select(
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.user_name',
                'users.email',
                'users.phone',
                'users.google_id',
                'users.role_id',
                'users.unique_id',
                'users.isapproved',
                'users.reject_reason',
                'users.kyc',
                'users.is_otp_verified',
                'users.created_by',
                'users.email_otp_expires_at',
                'users.token_created_at',
                'users.created_at',
                'users.updated_at',
                'roles.name as role_name'
            )
            ->get();

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

        $authorizationHeader = $request->header('Authorization');

        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        $user = User::select([
            'id',
            'role_id',
            'api_token',
        ])
            ->where('api_token', $requestToken)
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        try {
            $ids = $request->input('ids');

            DB::beginTransaction();

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

        $authorizationHeader = $request->header('Authorization');

        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        $user = User::select([
            'id',
            'role_id',
            'isapproved',
            'kyc',
            'api_token',
        ])
            ->where('api_token', $requestToken)
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $roleName = $request->query('role');

        if (!$roleName) {
            return response()->json([
                'status' => false,
                'message' => 'Role parameter is required.'
            ], 400);
        }

        $role = Role::select(['id', 'name'])
            ->where('name', $roleName)
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Role not found.'
            ], 404);
        }

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

        $authorizationHeader = $request->header('Authorization');

        if (!str_starts_with($authorizationHeader, 'Bearer ')) {
            return response()->json(['error' => 'Invalid token format. Token must start with "Bearer ".'], 422);
        }

        $requestToken = substr($authorizationHeader, 7);

        if (empty($requestToken)) {
            return response()->json(['error' => 'Token is missing.'], 422);
        }

        $user = User::select([
            'id',
            'role_id',
            'isapproved',
            'kyc',
            'api_token',
        ])
            ->where('api_token', $requestToken)
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized. Invalid API token.'], 401);
        }

        $statusValue = $request->query('isapproved');

        if ($statusValue === null || !in_array($statusValue, ['1', '0'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or missing status value. Use "1" or "0".'
            ], 400);
        }

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
            $role = Role::select(['id', 'name'])
                ->find($id);

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
        $AuthUser = auth('sanctum')->user();

        if (!$AuthUser && $request->bearerToken()) {
            $AuthUser = User::select([
                'id',
                'email',
                'phone',
                'role_id',
                'isapproved',
                'kyc',
                'api_token',
            ])
                ->where('api_token', $request->bearerToken())
                ->first();
        }

        try {
            $roleId = $request->role_id;
            $perPage = $request->per_page ?? 10;

            $query = DB::table('users')
                ->where('users.isapproved', '=', 1)
                ->leftJoin('user_details', 'users.id', '=', 'user_details.user_id')
                ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                ->leftJoin('countries', 'user_details.country_id', '=', 'countries.id')
                ->leftJoin('states', 'user_details.state_id', '=', 'states.id')
                ->leftJoin('cities', 'user_details.city_id', '=', 'cities.id')
                ->where('roles.name', '!=', 'admin')
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
                );

            if (!empty($roleId)) {
                $query->where('users.role_id', $roleId);
            }

            $paginatedData = $query->paginate($perPage);

            $paginatedData->getCollection()->transform(function ($user) use ($AuthUser) {
                $email = $user->email;
                $phone = $user->phone;

                if (!$AuthUser) {
                    if (!empty($email)) {
                        $email = preg_replace('/(?<=.{2}).(?=.*@)/', '*', $email);
                    }

                    if (!empty($phone)) {
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
        $AuthUser = auth('sanctum')->user();

        if (!$AuthUser && $request->bearerToken()) {
            $AuthUser = User::select([
                'id',
                'email',
                'phone',
                'role_id',
                'isapproved',
                'kyc',
                'api_token',
            ])
                ->where('api_token', $request->bearerToken())
                ->first();
        }

        try {
            $Id = $request->id;

            $query = DB::table('users')
                ->leftJoin('user_details', 'users.id', '=', 'user_details.user_id')
                ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                ->leftJoin('countries', 'user_details.country_id', '=', 'countries.id')
                ->leftJoin('states', 'user_details.state_id', '=', 'states.id')
                ->leftJoin('cities', 'user_details.city_id', '=', 'cities.id')
                ->leftJoin('countries as user_countries', 'users.country_id', '=', 'user_countries.id')
                ->leftJoin('states as user_states', 'users.state_id', '=', 'user_states.id')
                ->leftJoin('cities as user_cities', 'users.city_id', '=', 'user_cities.id')
                ->leftJoin('purposes', 'user_details.purpose_id', '=', 'purposes.id')
                ->leftJoin('properties', 'user_details.property_id', '=', 'properties.id')
                ->leftJoin('property_types', 'user_details.property_type_id', '=', 'property_types.id')
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
                ->where('roles.name', '!=', 'admin')
                ->where('users.isapproved', '=', 1)
                ->where('users.id', $Id);

            $user = $query->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 200);
            }

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


    // Update the current user's details
    public function updateCurrentUser(Request $request)
    {
        try {
            $request->validate([
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
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
                    Rule::unique('users', 'user_name')->ignore(Auth::id()),
                ],
                'country_id' => ['required', 'exists:countries,id'],
                'state_id' => ['required', 'exists:states,id'],
                'city_id' => ['required', 'exists:cities,id'],
                'area_locality' => ['nullable', 'string'],
                'colony' => ['nullable', 'string'],
                'street_address' => ['nullable', 'string'],
                'pin_code' => ['required', 'numeric', 'digits:6'],
                'about' => ['nullable', 'string'],
            ], [
                'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
                'password.regex' => 'Password must include uppercase, lowercase, number, and special character.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => $e->errors()], 400);
        }

        $user = Auth::user(); // current user from token

        if (!$user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        // Update user basic fields
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->user_name = $request->user_name;
        $user->email = $request->email ?? $user->email;
        $user->phone = $request->phone ?? $user->phone;
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

            // User detail update
            $userDetail = [
                'user_id' => $user->id,
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

            UserDetail::updateOrCreate(
                ['user_id' => $user->id],
                $userDetail
            );

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Profile updated successfully.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Failed to update profile. ' . $e->getMessage()], 500);
        }
    }
    public function userAnalytics(Request $request)
    {
        try {
            $cacheKey = 'user_analytics';

            $analytics = Cache::store('redis')->remember($cacheKey, 60, function () {

                $data = User::query()
                    ->where('role_id', '!=', 1) // exclude admin
                    ->selectRaw("
                    COUNT(*) as total_users,
                    SUM(CASE WHEN isapproved = 1 THEN 1 ELSE 0 END) as active_users,
                    SUM(CASE WHEN isapproved = 2 THEN 1 ELSE 0 END) as inactive_users,
                    SUM(CASE WHEN isapproved = 3 THEN 1 ELSE 0 END) as pending_invites
                ")
                    ->first();

                return [
                    'total_users' => (int) $data->total_users,
                    'active_users' => (int) $data->active_users,
                    'inactive_users' => (int) $data->inactive_users,
                    'pending_invites' => (int) $data->pending_invites,
                ];
            });

            return response()->json([
                'status' => true,
                'message' => 'User analytics fetched successfully.',
                'data' => $analytics,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch user analytics.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
