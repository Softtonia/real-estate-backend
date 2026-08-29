<?php

namespace App\Http\Controllers;

use App\Mail\OTPMail;
use App\Models\PasswordReset;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\UniqueID;
use App\Models\UserDetail;
use App\Models\UserPersonalDetail;
use App\Models\UserBusinessDetail;
use App\Models\OTP;
use App\Models\JoinRequest;
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
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\SubscribedEmailsImport;
use Illuminate\Support\Facades\Response;
use App\Exports\SubscribedEmailsExport;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;
use App\Models\DynamicPost;
use App\Models\MediaFile;
use App\Models\KycRequest;
use App\Models\KycDocument;
use App\Models\KycActivity;
use App\Models\KycUserExemption;
use Illuminate\Http\UploadedFile;

class UserController extends Controller
{
    private const MAX_UPLOAD_KB = 2048;
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

    private function cleanUserAssociatedData(int $userId): void
    {
        if (Schema::hasTable('user_personal_details')) {
            UserPersonalDetail::where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('user_business_details')) {
            UserBusinessDetail::where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('user_details')) {
            UserDetail::where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('kyc_documents')) {
            KycDocument::where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('kyc_activities')) {
            KycActivity::where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('kyc_user_exemptions')) {
            KycUserExemption::where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('kyc_requests')) {
            KycRequest::where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('api_tokens')) {
            DB::table('api_tokens')->where('user_id', $userId)->delete();
        }

        Cache::forget("user_details_admin_{$userId}");
        Cache::forget("user_details_{$userId}");
        Cache::forget("user_details_website_{$userId}");
        Cache::store('redis')->forget("kyc:user:{$userId}:access");
        Cache::store('redis')->forget("user_details_admin_{$userId}");
        Cache::store('redis')->forget("user_details_{$userId}");
    }
    private function normalizeUserRequestBeforeValidation(Request $request): void
    {
        $fileFields = [
            'profile_photo',
        ];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, $fileFields, true)) {
                continue;
            }

            if (
                $value === 'null' ||
                $value === 'undefined' ||
                $value === 'N/A' ||
                $value === '-'
            ) {
                $request->merge([
                    $key => null,
                ]);
            }
        }

        foreach ($fileFields as $field) {
            if ($request->has($field) && !$request->hasFile($field)) {
                $value = $request->input($field);

                if (
                    $value === null ||
                    $value === '' ||
                    $value === 'null' ||
                    $value === 'undefined' ||
                    $value === 'remove' ||
                    $value === 'deleted'
                ) {
                    $request->merge([
                        'remove_' . $field => 1,
                    ]);
                }

                $request->request->remove($field);
            }
        }

        foreach (['isapproved', 'kyc'] as $field) {
            if (!$request->has($field)) {
                continue;
            }

            $value = $request->input($field);

            if (
                $value === null ||
                $value === '' ||
                $value === '-' ||
                $value === 'N/A' ||
                $value === 'null' ||
                $value === 'undefined'
            ) {
                $request->request->remove($field);
                continue;
            }

            if (is_numeric($value)) {
                $request->merge([
                    $field => (int) $value,
                ]);
            } else {
                $request->request->remove($field);
            }
        }
    }
    private function removableUserFileFields(): array
    {
        return [
            'profile_photo',
        ];
    }

    private function shouldRemoveUserFile(Request $request, string $field): bool
    {
        foreach (['remove_' . $field, 'delete_' . $field, $field . '_removed'] as $key) {
            if (!$request->has($key)) {
                continue;
            }

            return in_array($request->input($key), [1, '1', true, 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function removedUserFilesFromRequest(
        Request $request,
        ?UserDetail $oldDetail,
        array &$oldFiles = []
    ): array {
        $removedFiles = [];

        foreach ($this->removableUserFileFields() as $field) {
            if (!$this->shouldRemoveUserFile($request, $field)) {
                continue;
            }

            if ($oldDetail && !empty($oldDetail->{$field})) {
                $oldFiles[] = $oldDetail->{$field};
            }

            $removedFiles[$field] = null;
        }

        return $removedFiles;
    }
    private function storePublicUpload(UploadedFile $file, string $folder, string $prefix): string
    {
        if (!$file || !$file->isValid()) {
            throw new \Exception('Invalid uploaded file.');
        }

        $folder = trim($folder, '/');

        $extension = strtolower(
            $file->getClientOriginalExtension()
            ?: $file->extension()
            ?: 'bin'
        );

        $fileName = Str::slug($prefix, '_')
            . '_'
            . now()->format('YmdHis')
            . '_'
            . Str::random(8)
            . '.'
            . $extension;

        $storedPath = Storage::disk('public_uploads')->putFileAs(
            $folder,
            $file,
            $fileName
        );

        if (!$storedPath || !Storage::disk('public_uploads')->exists($storedPath)) {
            throw new \Exception('File could not be saved.');
        }

        return 'uploads/' . $storedPath;
    }
    private function deletePublicUpload(?string $path): void
    {
        if (empty($path)) {
            return;
        }

        $path = trim($path);
        $path = str_replace('\\/', '/', $path);
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            $path = ltrim((string) $parsedPath, '/');
        }

        if (str_starts_with($path, 'storage/uploads/')) {
            $path = str_replace('storage/uploads/', 'uploads/', $path);
        }

        if (str_starts_with($path, 'public/uploads/')) {
            $path = str_replace('public/uploads/', 'uploads/', $path);
        }

        if (!str_starts_with($path, 'uploads/')) {
            return;
        }

        $relativePath = substr($path, strlen('uploads/'));

        if (!empty($relativePath)) {
            Storage::disk('public_uploads')->delete($relativePath);
        }
    }
    private function cleanNullableValue(mixed $value): mixed
    {
        if (
            $value === null ||
            $value === '' ||
            $value === '-' ||
            $value === 'N/A' ||
            $value === 'null' ||
            $value === 'undefined'
        ) {
            return null;
        }

        return $value;
    }

    private function fileUrl(?string $path): ?string
    {
        $path = $this->cleanNullableValue($path);

        if (empty($path)) {
            return null;
        }

        $path = trim((string) $path);
        $path = str_replace('\\/', '/', $path);
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/uploads/')) {
            $path = str_replace('storage/uploads/', 'uploads/', $path);
        }

        if (str_starts_with($path, 'public/uploads/')) {
            $path = str_replace('public/uploads/', 'uploads/', $path);
        }

        if (str_starts_with($path, 'uploads/')) {
            $relativePath = substr($path, strlen('uploads/'));

            return Storage::disk('public_uploads')->url($relativePath);
        }

        return url($path);
    }

    private function isOwnerRoleName(?string $roleName): bool
    {
        $roleText = strtolower(trim((string) $roleName));
        $roleText = str_replace([' ', '_', '-'], '', $roleText);

        return in_array($roleText, [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
    }
    private function storeUserFilesFromRequest(
        Request $request,
        ?User $user,
        Role $role,
        ?UserDetail $oldDetail = null,
        array &$oldFiles = []
    ): array {
        $newFiles = [];

        $prefix = $user ? 'u' . $user->id : 'user';

        if ($request->hasFile('profile_photo')) {
            $oldFiles[] = $oldDetail?->profile_photo;

            $newFiles['profile_photo'] = $this->storePublicUpload(
                $request->file('profile_photo'),
                'users',
                $prefix . '_profile'
            );
        }


        return $newFiles;
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
                return response()->json([
                    'status' => false,
                    'message' => 'User id is required.',
                ], 400);
            }

            $cacheKey = "user_details_admin_{$userId}";

            $response = Cache::store('redis')->remember($cacheKey, 300, function () use ($userId) {
                $userData = DB::table('users')
                    ->leftJoin('user_personal_details', 'users.id', '=', 'user_personal_details.user_id')
                    ->leftJoin('user_business_details', 'users.id', '=', 'user_business_details.user_id')
                    ->leftJoin('roles', 'users.role_id', '=', 'roles.id')

                    ->leftJoin('countries as business_countries', 'user_business_details.country_id', '=', 'business_countries.id')
                    ->leftJoin('states as business_states', 'user_business_details.state_id', '=', 'business_states.id')
                    ->leftJoin('cities as business_cities', 'user_business_details.city_id', '=', 'business_cities.id')

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

                        'user_business_details.business_name as bussiness_name',
                        'user_business_details.business_name',
                        'user_business_details.business_address as bussiness_address',
                        'user_business_details.business_address',
                        'user_business_details.business_email as bussiness_email',
                        'user_business_details.business_email',
                        'user_business_details.business_phone',

                        'user_business_details.country_id as business_country_id',
                        'user_business_details.state_id as business_state_id',
                        'user_business_details.city_id as business_city_id',
                        'business_countries.name as business_country',
                        'business_states.name as business_state',
                        'business_cities.name as business_city',

                        'user_business_details.area_locality as business_area_locality',
                        'user_business_details.colony as business_colony',
                        'user_business_details.street_address as business_street_address',
                        'user_business_details.business_pin_code',

                        'user_personal_details.address',
                        'user_personal_details.profile_photo',

                        'user_business_details.license_number',
                        'user_business_details.rera_number',
                        'user_personal_details.alternate_number',
                        'user_business_details.no_of_employees',
                        'user_personal_details.about_us',

                        'users.created_at',
                        'users.updated_at'
                    )
                    ->first();

                if (!$userData) {
                    return null;
                }

                $isOwnerRole = $this->isOwnerRoleName($userData->role_name);

                return [
                    'id' => (int) $userData->id,
                    'first_name' => $this->cleanNullableValue($userData->first_name),
                    'last_name' => $this->cleanNullableValue($userData->last_name),
                    'full_name' => trim(
                        ($this->cleanNullableValue($userData->first_name) ?? '') . ' ' .
                        ($this->cleanNullableValue($userData->last_name) ?? '')
                    ) ?: null,
                    'user_name' => $this->cleanNullableValue($userData->user_name),
                    'email' => $this->cleanNullableValue($userData->email),
                    'phone' => $this->cleanNullableValue($userData->phone),

                    'role_id' => $this->cleanNullableValue($userData->role_id),
                    'role_name' => $this->cleanNullableValue($userData->role_name),
                    'unique_id' => $this->cleanNullableValue($userData->unique_id),
                    'isapproved' => $userData->isapproved,
                    'kyc' => $userData->kyc,
                    'is_otp_verified' => $userData->is_otp_verified,

                    'country_id' => $this->cleanNullableValue($userData->country_id),
                    'state_id' => $this->cleanNullableValue($userData->state_id),
                    'city_id' => $this->cleanNullableValue($userData->city_id),
                    'country' => $this->cleanNullableValue($userData->country),
                    'state' => $this->cleanNullableValue($userData->state),
                    'city' => $this->cleanNullableValue($userData->city),

                    'area_locality' => $this->cleanNullableValue($userData->area_locality),
                    'colony' => $this->cleanNullableValue($userData->colony),
                    'street_address' => $this->cleanNullableValue($userData->street_address),
                    'pin_code' => $this->cleanNullableValue($userData->pin_code),
                    'about' => $this->cleanNullableValue($userData->about),

                    'business_fields_visible' => !$isOwnerRole,

                    'bussiness_name' => $isOwnerRole ? null : $this->cleanNullableValue($userData->bussiness_name),
                    'bussiness_address' => $isOwnerRole ? null : $this->cleanNullableValue($userData->bussiness_address),
                    'bussiness_email' => $isOwnerRole ? null : $this->cleanNullableValue($userData->bussiness_email),
                    'business_phone' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_phone),

                    'business_country_id' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_country_id),
                    'business_state_id' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_state_id),
                    'business_city_id' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_city_id),
                    'business_country' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_country),
                    'business_state' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_state),
                    'business_city' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_city),

                    'business_area_locality' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_area_locality),
                    'business_colony' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_colony),
                    'business_street_address' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_street_address),
                    'business_pin_code' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_pin_code),

                    'address' => $this->cleanNullableValue($userData->address),

                    'profile_photo' => $this->fileUrl($userData->profile_photo),


                    'license_number' => $this->cleanNullableValue($userData->license_number),
                    'rera_number' => $this->cleanNullableValue($userData->rera_number),
                    'alternate_number' => $this->cleanNullableValue($userData->alternate_number),
                    'no_of_employees' => $this->cleanNullableValue($userData->no_of_employees),
                    'about_us' => $this->cleanNullableValue($userData->about_us),

                    'created_at' => $userData->created_at,
                    'updated_at' => $userData->updated_at,
                ];
            });

            if (!$response) {
                \Log::error('User not found', ['id' => $userId]);

                return response()->json([
                    'status' => false,
                    'message' => 'User account not found or session expired.',
                    'error' => 'Unauthorized. User does not exist.',
                ], 401);
            }

            if (is_array($response)) {
                $response['notifications'] = \App\Models\Notification\UserNotification::where('user_id', $userId)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->take(50)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'user_id' => $item->user_id,
                            'title' => $item->title,
                            'message' => $item->body,
                            'body' => $item->body,
                            'type' => ucfirst($item->type ?? 'System'),
                            'type_slug' => strtolower($item->type ?? 'system'),
                            'image_url' => $item->image_url,
                            'data' => $item->data,
                            'is_read' => !empty($item->read_at),
                            'read_at' => $item->read_at ? $item->read_at->format('M d, Y h:i A') : null,
                            'date_time' => $item->created_at ? $item->created_at->format('M d, Y h:i A') : null,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    });

                $response['login_history'] = \App\Models\UserIpLog::where('user_id', $userId)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->take(50)
                    ->get();

                app(\App\Services\Activity\UserActivityService::class)->seedSampleActivitiesIfEmpty((int) $userId);

                $activityLogs = \App\Models\UserActivityLog::where('user_id', $userId)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->take(50)
                    ->get();

                $response['activity_log'] = $activityLogs;
                $response['activities'] = $activityLogs;
            }

            return response()->json($response, 200);
        } catch (\Throwable $th) {
            \Log::error('Error fetching user details:', [
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Internal Server Error.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    public function getdetailsbyuseridForWebsite(Request $request)
    {
        try {
            $userId = $request->input('id');

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'User id is required.',
                ], 400);
            }

            $cacheKey = "user_details_website_{$userId}";

            $response = Cache::store('redis')->remember($cacheKey, 300, function () use ($userId) {
                $userData = DB::table('users')
                    ->leftJoin('user_personal_details', 'users.id', '=', 'user_personal_details.user_id')
                    ->leftJoin('user_business_details', 'users.id', '=', 'user_business_details.user_id')
                    ->leftJoin('roles', 'users.role_id', '=', 'roles.id')

                    ->leftJoin('countries as business_countries', 'user_business_details.country_id', '=', 'business_countries.id')
                    ->leftJoin('states as business_states', 'user_business_details.state_id', '=', 'business_states.id')
                    ->leftJoin('cities as business_cities', 'user_business_details.city_id', '=', 'business_cities.id')

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

                        'user_business_details.business_name as bussiness_name',
                        'user_business_details.business_name',
                        'user_business_details.business_address as bussiness_address',
                        'user_business_details.business_address',
                        'user_business_details.business_email as bussiness_email',
                        'user_business_details.business_email',
                        'user_business_details.business_phone',

                        'user_business_details.country_id as business_country_id',
                        'user_business_details.state_id as business_state_id',
                        'user_business_details.city_id as business_city_id',
                        'business_countries.name as business_country',
                        'business_states.name as business_state',
                        'business_cities.name as business_city',

                        'user_personal_details.address',
                        'user_personal_details.pin_code',
                        'user_business_details.business_pin_code',
                        'user_personal_details.profile_photo',
                        'user_business_details.license_number',
                        'user_business_details.rera_number',
                        'user_personal_details.alternate_number',
                        'user_business_details.no_of_employees',
                        'user_personal_details.about_us',

                        'users.created_at',
                        'users.updated_at'
                    )
                    ->first();

                if (!$userData) {
                    return null;
                }

                $isOwnerRole = $this->isOwnerRoleName($userData->role_name);

                return [
                    'id' => (int) $userData->id,
                    'first_name' => $this->cleanNullableValue($userData->first_name),
                    'last_name' => $this->cleanNullableValue($userData->last_name),
                    'full_name' => trim(
                        ($this->cleanNullableValue($userData->first_name) ?? '') . ' ' .
                        ($this->cleanNullableValue($userData->last_name) ?? '')
                    ) ?: null,
                    'user_name' => $this->cleanNullableValue($userData->user_name),
                    'email' => $this->cleanNullableValue($userData->email),
                    'phone' => $this->cleanNullableValue($userData->phone),

                    'role_id' => $this->cleanNullableValue($userData->role_id),
                    'role_name' => $this->cleanNullableValue($userData->role_name),
                    'unique_id' => $this->cleanNullableValue($userData->unique_id),
                    'isapproved' => $userData->isapproved,
                    'kyc' => $userData->kyc,

                    'business_fields_visible' => !$isOwnerRole,

                    'bussiness_name' => $isOwnerRole ? null : $this->cleanNullableValue($userData->bussiness_name),
                    'bussiness_address' => $isOwnerRole ? null : $this->cleanNullableValue($userData->bussiness_address),
                    'bussiness_email' => $isOwnerRole ? null : $this->cleanNullableValue($userData->bussiness_email),
                    'business_phone' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_phone),

                    'business_country_id' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_country_id),
                    'business_state_id' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_state_id),
                    'business_city_id' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_city_id),
                    'business_country' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_country),
                    'business_state' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_state),
                    'business_city' => $isOwnerRole ? null : $this->cleanNullableValue($userData->business_city),

                    'address' => $this->cleanNullableValue($userData->address),
                    'pin_code' => $this->cleanNullableValue($userData->pin_code),

                    'profile_photo' => $this->fileUrl($userData->profile_photo),

                    'license_number' => $this->cleanNullableValue($userData->license_number),
                    'rera_number' => $this->cleanNullableValue($userData->rera_number),
                    'alternate_number' => $this->cleanNullableValue($userData->alternate_number),
                    'no_of_employees' => $this->cleanNullableValue($userData->no_of_employees),
                    'about_us' => $this->cleanNullableValue($userData->about_us),


                    'created_at' => $userData->created_at,
                    'updated_at' => $userData->updated_at,
                ];
            });

            if (!$response) {
                return response()->json([
                    'status' => false,
                    'message' => 'No data found for this user.',
                ], 404);
            }

            return response()->json($response, 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'message' => 'Internal Server Error.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    // for update user by id


    public function updateuserbyid(Request $request)
    {
        return $this->updateUserRecordFromRequest(
            request: $request,
            userId: (int) $request->input('id'),
            adminMode: true
        );
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
        $this->normalizeUserRequestBeforeValidation($request);

        $role = Role::find($request->input('role_id'));

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid role provided.',
            ], 400);
        }

        if ($this->roleText($role) === 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'You cannot create an admin user.',
            ], 400);
        }

        $validator = Validator::make(
            $request->all(),
            $this->baseUserValidationRules($request, null, true),
            [
                'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
                'profile_photo.max' => 'Profile photo must not be greater than 2MB.',
            ]
        );

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $newFiles = [];
        $user = null;

        try {
            $user = DB::transaction(function () use ($request, $role, &$newFiles) {
                $uniqueIDModel = $this->createUniqueIdForRole($role);

                $userPayload = $this->userPayloadFromRequest($request, $role, true);

                $userPayload['role_id'] = $role->id;
                $userPayload['unique_id'] = $uniqueIDModel->unique_id;

                if (Schema::hasColumn('users', 'created_by')) {
                    $userPayload['created_by'] = Auth::id() ?? 0;
                }

                $user = new User();

                foreach ($userPayload as $column => $value) {
                    if (Schema::hasColumn('users', $column)) {
                        $user->{$column} = $value;
                    }
                }

                $user->save();

                if (
                    Schema::hasTable('user_has_unique_ids')
                    && Schema::hasColumn('user_has_unique_ids', 'user_id')
                    && Schema::hasColumn('user_has_unique_ids', 'unique_id')
                ) {
                    DB::table('user_has_unique_ids')->insert([
                        'user_id' => $user->id,
                        'unique_id' => $uniqueIDModel->id,
                    ]);
                }

                $oldFiles = [];

                $newFiles = $this->storeUserFilesFromRequest(
                    request: $request,
                    user: $user,
                    role: $role,
                    oldDetail: null,
                    oldFiles: $oldFiles
                );

                $detailPayload = $this->userDetailPayloadFromRequest($request, $user, $role);

                if (Schema::hasColumn('user_details', 'created_by')) {
                    $detailPayload['created_by'] = Auth::id() ?? 0;
                }

                foreach ($newFiles as $column => $path) {
                    if (Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $path;
                    }
                }

                $this->persistUserDetailPayload($user, $detailPayload);

                return $user;
            });

            $this->clearUserCaches($user->id);

            return response()->json([
                'status' => true,
                'message' => 'User created successfully.',
                'data' => [
                    'id' => (int) $user->id,
                    'unique_id' => $user->unique_id,
                ],
            ], 201);
        } catch (\Throwable $e) {
            foreach ($newFiles as $path) {
                $this->deletePublicUpload($path);
            }

            \Log::error('User create failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to create user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateUser(Request $request)
    {
        return $this->updateUserRecordFromRequest(
            request: $request,
            userId: (int) $request->input('id'),
            adminMode: false
        );
    }
    private function normalizeUserDeletionRequest(Request $request): void
    {
        if (!empty($request->getContent())) {
            $jsonData = json_decode($request->getContent(), true);
            if (is_array($jsonData)) {
                $request->merge($jsonData);
            }
        }
    }

    //  for delete user
    public function checkUserDeletion(Request $request)
    {
        $this->normalizeUserDeletionRequest($request);

        try {
            $userId = $request->input('id') ?? $request->input('user_id') ?? $request->query('id') ?? $request->query('user_id');

            if (!$userId || !User::where('id', $userId)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            $user = User::select(['id', 'first_name', 'last_name', 'user_name', 'email', 'phone', 'role_id'])->find($userId);

            $listingsQuery = DynamicPost::query();
            if (Schema::hasColumn('dynamic_posts', 'author_id')) {
                $listingsQuery->where('author_id', $userId);
            } elseif (Schema::hasColumn('dynamic_posts', 'user_id')) {
                $listingsQuery->where('user_id', $userId);
            }

            $totalListings = (clone $listingsQuery)->count();

            $byStatus = (clone $listingsQuery)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $userName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            if ($userName === '') {
                $userName = $user->user_name ?? $user->email ?? null;
            }

            return response()->json([
                'status' => true,
                'message' => 'User deletion check completed.',
                'data' => [
                    'user' => [
                        'id' => (int) $user->id,
                        'name' => $userName,
                        'first_name' => $user->first_name ?? null,
                        'last_name' => $user->last_name ?? null,
                        'user_name' => $user->user_name ?? null,
                        'email' => $user->email ?? null,
                    ],
                    'has_listings' => $totalListings > 0,
                    'total_listings' => $totalListings,
                    'listings_by_status' => $byStatus,
                    'available_actions' => [
                        [
                            'action' => 'delete_all',
                            'label' => 'Delete user and permanently delete all their related listings',
                        ],
                        [
                            'action' => 'reassign',
                            'label' => 'Delete user and reassign all listings to another user/agent',
                            'requires_reassign_user_id' => true,
                        ],
                        [
                            'action' => 'keep_orphan',
                            'label' => 'Delete user and set listings owner to null',
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to check user deletion status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteUser(Request $request)
    {
        $this->normalizeUserDeletionRequest($request);

        try {
            $request->validate([
                'id' => 'nullable|exists:users,id',
                'user_id' => 'nullable|exists:users,id',
                'listing_action' => 'nullable|string|in:delete_all,reassign,keep_orphan',
                'reassign_user_id' => 'nullable|exists:users,id',
                'target_user_id' => 'nullable|exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => false, 'error' => $e->errors()], 422);
        }

        $userId = $request->input('id') ?? $request->input('user_id') ?? $request->query('id') ?? $request->query('user_id');

        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'User ID is required.'], 422);
        }

        $targetUser = User::find($userId);
        if (!$targetUser) {
            return response()->json(['status' => false, 'message' => 'User not found.'], 404);
        }

        $listingsQuery = DynamicPost::query();
        if (Schema::hasColumn('dynamic_posts', 'author_id')) {
            $listingsQuery->where('author_id', $userId);
        } elseif (Schema::hasColumn('dynamic_posts', 'user_id')) {
            $listingsQuery->where('user_id', $userId);
        }

        $totalListings = (clone $listingsQuery)->count();
        $listingAction = strtolower(trim((string) $request->input('listing_action')));

        if ($totalListings > 0 && empty($listingAction)) {
            return response()->json([
                'status' => false,
                'message' => 'User has active listings. Please select a listing_action ("delete_all", "reassign", or "keep_orphan").',
                'error' => 'listing_action_required',
                'data' => [
                    'user_id' => (int) $userId,
                    'total_listings' => $totalListings,
                    'available_actions' => ['delete_all', 'reassign', 'keep_orphan'],
                ],
            ], 422);
        }

        $reassignUserId = $request->input('reassign_user_id') ?? $request->input('target_user_id') ?? $request->query('reassign_user_id') ?? $request->query('target_user_id');

        if ($totalListings > 0 && $listingAction === 'reassign') {
            if (!$reassignUserId) {
                return response()->json([
                    'status' => false,
                    'message' => 'reassign_user_id parameter is required when listing_action is "reassign".',
                    'error' => 'reassign_user_id_required',
                ], 422);
            }

            if ((int) $reassignUserId === (int) $userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot reassign listings to the user being deleted.',
                    'error' => 'invalid_reassign_user',
                ], 422);
            }

            $newUser = User::find($reassignUserId);
            if (!$newUser) {
                return response()->json([
                    'status' => false,
                    'message' => 'Reassign target user not found.',
                    'error' => 'target_user_not_found',
                ], 404);
            }
        }

        DB::beginTransaction();

        try {
            $affectedListings = $totalListings;

            if ($totalListings > 0) {
                if ($listingAction === 'delete_all') {
                    $postsToDelete = (clone $listingsQuery)->get();
                    foreach ($postsToDelete as $post) {
                        $this->deleteDynamicPostPermanently($post);
                    }
                } elseif ($listingAction === 'reassign') {
                    $updatePayload = [];
                    if (Schema::hasColumn('dynamic_posts', 'author_id')) {
                        $updatePayload['author_id'] = (int) $reassignUserId;
                    }
                    if (Schema::hasColumn('dynamic_posts', 'user_id')) {
                        $updatePayload['user_id'] = (int) $reassignUserId;
                    }
                    if (Schema::hasColumn('dynamic_posts', 'assigned_user_id')) {
                        $updatePayload['assigned_user_id'] = (int) $reassignUserId;
                    }

                    if (!empty($updatePayload)) {
                        (clone $listingsQuery)->update($updatePayload);
                    }
                } elseif ($listingAction === 'keep_orphan') {
                    $updatePayload = [];
                    if (Schema::hasColumn('dynamic_posts', 'author_id')) {
                        $updatePayload['author_id'] = null;
                    }
                    if (Schema::hasColumn('dynamic_posts', 'user_id')) {
                        $updatePayload['user_id'] = null;
                    }
                    if (Schema::hasColumn('dynamic_posts', 'assigned_user_id')) {
                        $updatePayload['assigned_user_id'] = null;
                    }

                    if (!empty($updatePayload)) {
                        (clone $listingsQuery)->update($updatePayload);
                    }
                }
            }

            // Delete all associated user details, KYC data, and caches
            $this->cleanUserAssociatedData((int) $userId);

            if ($targetUser->api_token) {
                Cache::forget('api_token_user:' . $targetUser->api_token);
            }

            $targetUser->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'User deleted successfully.',
                'data' => [
                    'deleted_user_id' => (int) $userId,
                    'listing_action' => $listingAction ?: 'none',
                    'affected_listings_count' => $affectedListings,
                    'reassigned_to_user_id' => ($listingAction === 'reassign') ? (int) $reassignUserId : null,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to delete user: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        $this->normalizeUserDeletionRequest($request);
        try {
            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:users,id',
                'listing_action' => 'nullable|string|in:delete_all,reassign,keep_orphan',
                'reassign_user_id' => 'nullable|exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => false, 'error' => $e->errors()], 422);
        }

        $userIds = array_map('intval', $request->input('ids'));
        $listingAction = strtolower(trim((string) $request->input('listing_action', 'keep_orphan')));
        $reassignUserId = $request->input('reassign_user_id');

        if ($listingAction === 'reassign') {
            if (!$reassignUserId) {
                return response()->json([
                    'status' => false,
                    'message' => 'reassign_user_id parameter is required when listing_action is "reassign".',
                ], 422);
            }

            if (in_array((int) $reassignUserId, $userIds, true)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Reassign target user cannot be one of the users being deleted.',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            $deletedCount = 0;

            foreach ($userIds as $userId) {
                $user = User::find($userId);
                if (!$user) {
                    continue;
                }

                $listingsQuery = DynamicPost::query();
                if (Schema::hasColumn('dynamic_posts', 'author_id')) {
                    $listingsQuery->where('author_id', $userId);
                } elseif (Schema::hasColumn('dynamic_posts', 'user_id')) {
                    $listingsQuery->where('user_id', $userId);
                }

                $userListings = (clone $listingsQuery)->get();

                if ($userListings->isNotEmpty()) {
                    if ($listingAction === 'delete_all') {
                        foreach ($userListings as $post) {
                            $this->deleteDynamicPostPermanently($post);
                        }
                    } elseif ($listingAction === 'reassign') {
                        $updatePayload = [];
                        if (Schema::hasColumn('dynamic_posts', 'author_id')) {
                            $updatePayload['author_id'] = (int) $reassignUserId;
                        }
                        if (Schema::hasColumn('dynamic_posts', 'user_id')) {
                            $updatePayload['user_id'] = (int) $reassignUserId;
                        }
                        if (!empty($updatePayload)) {
                            (clone $listingsQuery)->update($updatePayload);
                        }
                    } elseif ($listingAction === 'keep_orphan') {
                        $updatePayload = [];
                        if (Schema::hasColumn('dynamic_posts', 'author_id')) {
                            $updatePayload['author_id'] = null;
                        }
                        if (Schema::hasColumn('dynamic_posts', 'user_id')) {
                            $updatePayload['user_id'] = null;
                        }
                        if (!empty($updatePayload)) {
                            (clone $listingsQuery)->update($updatePayload);
                        }
                    }
                }

                $this->cleanUserAssociatedData((int) $userId);
                $user->delete();
                $deletedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => "{$deletedCount} users deleted successfully.",
                'data' => [
                    'deleted_count' => $deletedCount,
                    'listing_action' => $listingAction,
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Bulk user deletion failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function deleteDynamicPostPermanently(DynamicPost $ownedListing): void
    {
        $mediaIds = [];

        if (!empty($ownedListing->featured_image_id)) {
            $mediaIds[] = (int) $ownedListing->featured_image_id;
        }

        $galleryIds = is_array($ownedListing->gallery_image_ids)
            ? $ownedListing->gallery_image_ids
            : (is_string($ownedListing->gallery_image_ids) ? json_decode($ownedListing->gallery_image_ids, true) ?? [] : []);

        $mediaIds = collect(array_merge($mediaIds, $galleryIds))
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $mediaFiles = MediaFile::query()
            ->whereIn('id', $mediaIds)
            ->get();

        if (method_exists($ownedListing, 'taxonomyTerms')) {
            $ownedListing->taxonomyTerms()->detach();
        }

        $metaTable = $this->dynamicPostMetaTable();
        if ($metaTable) {
            $metaQuery = DB::table($metaTable);
            if (Schema::hasColumn($metaTable, 'entity_id')) {
                $metaQuery->where('entity_id', $ownedListing->id);
            } elseif (Schema::hasColumn($metaTable, 'post_id')) {
                $metaQuery->where('post_id', $ownedListing->id);
            } elseif (Schema::hasColumn($metaTable, 'dynamic_post_id')) {
                $metaQuery->where('dynamic_post_id', $ownedListing->id);
            }
            if (Schema::hasColumn($metaTable, 'entity_type')) {
                $metaQuery->where('entity_type', 'post');
            }
            $metaQuery->delete();
        }

        if (
            Schema::hasTable('custom_field_repeater_values')
            && Schema::hasColumn('custom_field_repeater_values', 'entity_id')
        ) {
            DB::table('custom_field_repeater_values')
                ->where('entity_type', 'post')
                ->where('entity_id', $ownedListing->id)
                ->delete();
        }

        if (Schema::hasTable('property_featured_promotions')) {
            DB::table('property_featured_promotions')
                ->where('dynamic_post_id', $ownedListing->id)
                ->delete();
        }

        if (Schema::hasTable('property_verification_revisions')) {
            DB::table('property_verification_revisions')
                ->where('dynamic_post_id', $ownedListing->id)
                ->delete();
        }

        if (method_exists($ownedListing, 'forceDelete')) {
            $ownedListing->forceDelete();
        } else {
            $ownedListing->delete();
        }

        foreach ($mediaFiles as $media) {
            $disk = $media->disk ?: 'public';
            $path = $media->path;

            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }

            if (method_exists($media, 'forceDelete')) {
                $media->forceDelete();
            } else {
                $media->delete();
            }
        }
    }

    private function dynamicPostMetaTable(): ?string
    {
        foreach (
            [
                'custom_field_values',
                'dynamic_post_meta',
                'dynamic_post_metas',
                'post_meta',
                'post_metas',
                'custom_field_meta',
            ] as $table
        ) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
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
        $search = trim((string) $request->search);
        $searchByRelated = $request->search_by_related;

        $query = User::join(
            'roles',
            'roles.id',
            '=',
            'users.role_id'
        );

        if (
            $request->has('search_by_related')
            && (string) $searchByRelated === '0'
        ) {
            $query->where(
                'roles.name',
                '!=',
                'admin'
            );
        }

        if (
            $searchByRelated !== null
            && $searchByRelated !== ''
            && (string) $searchByRelated !== '0'
        ) {
            $query->where(
                'roles.name',
                'LIKE',
                '%' . trim((string) $searchByRelated) . '%'
            );
        }

        if ($search !== '') {
            $like = '%' . $search . '%';

            $query->where(function ($q) use ($like) {
                $q->where(
                    'users.unique_id',
                    'LIKE',
                    $like
                )
                    ->orWhere(
                        'users.first_name',
                        'LIKE',
                        $like
                    )
                    ->orWhere(
                        'users.last_name',
                        'LIKE',
                        $like
                    )
                    ->orWhereRaw(
                        "CONCAT_WS(' ', users.first_name, users.last_name) LIKE ?",
                        [$like]
                    )
                    ->orWhere(
                        'users.user_name',
                        'LIKE',
                        $like
                    )
                    ->orWhere(
                        'users.email',
                        'LIKE',
                        $like
                    )
                    ->orWhere(
                        'users.phone',
                        'LIKE',
                        $like
                    )
                    ->orWhere(
                        'roles.name',
                        'LIKE',
                        $like
                    );
            });
        }

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
            ->orderByDesc('users.id')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $users,
        ], 200);
    }

    // Duplicate bulkDelete declaration removed - primary implementation handled by conditional bulkDelete above.


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
            $roleId = $request->input('role_id');
            $perPage = max(1, min((int) $request->input('per_page', 10), 100));

            $propertyPostTypeId = DB::table('post_types')
                ->whereRaw('LOWER(TRIM(slug)) = ?', ['property-listing'])
                ->value('id');

            $purposeTaxonomyId = DB::table('taxonomies')
                ->where(function ($query) {
                    $query
                        ->whereRaw('LOWER(TRIM(slug)) = ?', ['purpose'])
                        ->orWhereRaw('LOWER(TRIM(name)) = ?', ['purpose']);
                })
                ->value('id');

            $sellTermId = null;
            $rentTermId = null;

            if ($purposeTaxonomyId) {
                $purposeTerms = DB::table('taxonomy_terms')
                    ->where('taxonomy_id', (int) $purposeTaxonomyId)
                    ->where(function ($query) {
                        $query
                            ->whereRaw("LOWER(TRIM(slug)) IN ('sell', 'rent')")
                            ->orWhereRaw("LOWER(TRIM(name)) IN ('sell', 'rent')");
                    })
                    ->get([
                        'id',
                        'name',
                        'slug',
                    ]);

                foreach ($purposeTerms as $term) {
                    $slug = strtolower(trim((string) ($term->slug ?? '')));
                    $name = strtolower(trim((string) ($term->name ?? '')));

                    if ($slug === 'sell' || $name === 'sell') {
                        $sellTermId = (int) $term->id;
                    }

                    if ($slug === 'rent' || $name === 'rent') {
                        $rentTermId = (int) $term->id;
                    }
                }
            }

            $propertyPurposeCounts = DB::table('users')
                ->selectRaw('
                NULL as listing_user_id,
                0 as properties_for_sell,
                0 as properties_for_rent
            ')
                ->whereRaw('1 = 0');

            if (
                $propertyPostTypeId
                && $purposeTaxonomyId
                && Schema::hasTable('dynamic_posts')
                && Schema::hasTable('post_taxonomy_terms')
                && Schema::hasTable('taxonomy_terms')
                && Schema::hasColumn('post_taxonomy_terms', 'dynamic_post_id')
                && Schema::hasColumn('post_taxonomy_terms', 'taxonomy_term_id')
            ) {
                $hasAssignmentTable =
                    Schema::hasTable('dynamic_post_user')
                    && Schema::hasColumn('dynamic_post_user', 'dynamic_post_id')
                    && Schema::hasColumn('dynamic_post_user', 'user_id');

                $listingUserColumns = [];

                if ($hasAssignmentTable) {
                    $listingUserColumns[] = 'dpu.user_id';
                }

                if (Schema::hasColumn('dynamic_posts', 'user_id')) {
                    $listingUserColumns[] = 'dp.user_id';
                }

                if (Schema::hasColumn('dynamic_posts', 'author_id')) {
                    $listingUserColumns[] = 'dp.author_id';
                }

                if (!empty($listingUserColumns)) {
                    $listingUserExpression = count($listingUserColumns) === 1
                        ? $listingUserColumns[0]
                        : 'COALESCE(' . implode(', ', $listingUserColumns) . ')';

                    $propertyPurposeCounts = DB::table('dynamic_posts as dp');

                    if ($hasAssignmentTable) {
                        $propertyPurposeCounts->leftJoin(
                            'dynamic_post_user as dpu',
                            'dpu.dynamic_post_id',
                            '=',
                            'dp.id'
                        );
                    }

                    $propertyPurposeCounts
                        ->join(
                            'post_taxonomy_terms as ptt',
                            'ptt.dynamic_post_id',
                            '=',
                            'dp.id'
                        )
                        ->join(
                            'taxonomy_terms as tt',
                            'tt.id',
                            '=',
                            'ptt.taxonomy_term_id'
                        )
                        ->where(
                            'dp.post_type_id',
                            (int) $propertyPostTypeId
                        )
                        ->where(
                            'tt.taxonomy_id',
                            (int) $purposeTaxonomyId
                        )
                        ->whereRaw(
                            $listingUserExpression . ' IS NOT NULL'
                        );

                    if (
                        Schema::hasColumn(
                            'post_taxonomy_terms',
                            'taxonomy_id'
                        )
                    ) {
                        $propertyPurposeCounts->where(
                            'ptt.taxonomy_id',
                            (int) $purposeTaxonomyId
                        );
                    }

                    if (
                        Schema::hasColumn(
                            'dynamic_posts',
                            'deleted_at'
                        )
                    ) {
                        $propertyPurposeCounts->whereNull('dp.deleted_at');
                    }

                    $propertyPurposeCounts
                        ->selectRaw(
                            $listingUserExpression . ' as listing_user_id'
                        )
                        ->selectRaw(
                            '
                        COUNT(
                            DISTINCT CASE
                                WHEN (
                                    LOWER(TRIM(tt.slug)) = ?
                                    OR LOWER(TRIM(tt.name)) = ?
                                )
                                THEN dp.id
                            END
                        ) as properties_for_sell
                        ',
                            ['sell', 'sell']
                        )
                        ->selectRaw(
                            '
                        COUNT(
                            DISTINCT CASE
                                WHEN (
                                    LOWER(TRIM(tt.slug)) = ?
                                    OR LOWER(TRIM(tt.name)) = ?
                                )
                                THEN dp.id
                            END
                        ) as properties_for_rent
                        ',
                            ['rent', 'rent']
                        )
                        ->where(function ($query) use ($sellTermId, $rentTermId) {
                            if ($sellTermId) {
                                $query->orWhere('tt.id', $sellTermId);
                            }

                            if ($rentTermId) {
                                $query->orWhere('tt.id', $rentTermId);
                            }

                            $query
                                ->orWhereRaw("LOWER(TRIM(tt.slug)) IN ('sell', 'rent')")
                                ->orWhereRaw("LOWER(TRIM(tt.name)) IN ('sell', 'rent')");
                        })
                        ->groupByRaw($listingUserExpression);
                }
            }

            $query = DB::table('users')
                ->where('users.isapproved', 1)
                ->leftJoin(
                    'user_details',
                    'users.id',
                    '=',
                    'user_details.user_id'
                )
                ->leftJoin(
                    'roles',
                    'users.role_id',
                    '=',
                    'roles.id'
                )
                ->leftJoin(
                    'countries',
                    'user_details.country_id',
                    '=',
                    'countries.id'
                )
                ->leftJoin(
                    'states',
                    'user_details.state_id',
                    '=',
                    'states.id'
                )
                ->leftJoin(
                    'cities',
                    'user_details.city_id',
                    '=',
                    'cities.id'
                )
                ->leftJoinSub(
                    $propertyPurposeCounts,
                    'property_purpose_counts',
                    function ($join) {
                        $join->on(
                            'property_purpose_counts.listing_user_id',
                            '=',
                            'users.id'
                        );
                    }
                )
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
                    DB::raw('
                    COALESCE(
                        property_purpose_counts.properties_for_sell,
                        0
                    ) as properties_for_sell
                '),
                    DB::raw('
                    COALESCE(
                        property_purpose_counts.properties_for_rent,
                        0
                    ) as properties_for_rent
                '),
                    'users.created_at',
                    'users.updated_at'
                );

            if (!empty($roleId)) {
                $query->where('users.role_id', $roleId);
            }

            $paginatedData = $query->paginate($perPage);

            $paginatedData
                ->getCollection()
                ->transform(function ($user) use ($AuthUser) {
                    $email = $user->email;
                    $phone = $user->phone;

                    if (!$AuthUser) {
                        if (!empty($email)) {
                            $email = preg_replace(
                                '/(?<=.{2}).(?=.*@)/',
                                '*',
                                $email
                            );
                        }

                        if (!empty($phone)) {
                            $phone = substr($phone, 0, 3)
                                . '****'
                                . substr($phone, -3);
                        }
                    }

                    return [
                        'id' => (int) $user->id,
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
                        'profile_photo' => $user->profile_photo
                            ? url($user->profile_photo)
                            : null,
                        'about_us' => $user->about_us,
                        'properties_for_sell' => (int) $user->properties_for_sell,
                        'properties_for_rent' => (int) $user->properties_for_rent,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'User details retrieved successfully',
                'users' => $paginatedData,
            ], 200);
        } catch (\Throwable $th) {
            \Log::error('Error fetching user list by role:', [
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error.',
                'error' => $th->getMessage(),
            ], 500);
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
                    'status' => false,
                    'message' => 'User account does not exist or session expired.',
                    'error' => 'Unauthorized. User does not exist.',
                ], 401);
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
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                'login_history' => \App\Models\UserIpLog::where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->take(50)
                    ->get(),
                'ip_logs' => \App\Models\UserIpLog::where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->take(50)
                    ->get(),
                'notifications' => \App\Models\Notification\UserNotification::where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->take(50)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'user_id' => $item->user_id,
                            'title' => $item->title,
                            'message' => $item->body,
                            'body' => $item->body,
                            'type' => ucfirst($item->type ?? 'System'),
                            'type_slug' => strtolower($item->type ?? 'system'),
                            'image_url' => $item->image_url,
                            'data' => $item->data,
                            'is_read' => !empty($item->read_at),
                            'read_at' => $item->read_at ? $item->read_at->format('M d, Y h:i A') : null,
                            'date_time' => $item->created_at ? $item->created_at->format('M d, Y h:i A') : null,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    }),
                'activity_log' => \App\Models\UserActivityLog::where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->take(50)
                    ->get(),
                'activities' => \App\Models\UserActivityLog::where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->take(50)
                    ->get(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'User details retrieved successfully',
                'user' => $userData,
                'login_history' => $userData['login_history'],
                'notifications' => $userData['notifications'],
                'activity_log' => $userData['activity_log'],
                'activities' => $userData['activities'],
            ], 200);
        } catch (\Throwable $th) {
            \Log::error('Error fetching user details by id:', ['error' => $th->getMessage()]);
            return response()->json(['error' => 'Internal Server Error.'], 500);
        }
    }


    // Update the current user's details
    public function updateCurrentUser(Request $request)
    {
        $user = $this->resolveCurrentUserFromRequest($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not authenticated.',
            ], 401);
        }

        return $this->updateUserRecordFromRequest($request, (int) $user->id, false, true);
    }
    private function updateUserRecordFromRequest(
        Request $request,
        int $userId,
        bool $adminMode = false,
        bool $currentUserMode = false
    ) {
        $this->normalizeUserRequestBeforeValidation($request);

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'User id is required.',
            ], 422);
        }

        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $roleId = $request->input('role_id', $user->role_id);
        $role = Role::find($roleId);

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid role provided.',
            ], 400);
        }

        if ($this->roleText($role) === 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'You cannot update user as admin role.',
            ], 400);
        }

        $rules = $this->baseUserValidationRules($request, $user, false);

        if ($currentUserMode) {
            unset($rules['id']);
        }

        if (!$request->has('role_id')) {
            $rules['role_id'] = ['nullable', 'exists:roles,id'];
        }

        $validator = Validator::make($request->all(), $rules, [
            'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
            'profile_photo.max' => 'Profile photo must not be greater than 2MB.',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $newFiles = [];
        $oldFiles = [];
        $removedFiles = [];

        try {
            $existingDetail = UserDetail::query()
                ->where('user_id', $user->id)
                ->first();

            $removedFiles = $this->removedUserFilesFromRequest(
                request: $request,
                oldDetail: $existingDetail,
                oldFiles: $oldFiles
            );

            $newFiles = $this->storeUserFilesFromRequest(
                request: $request,
                user: $user,
                role: $role,
                oldDetail: $existingDetail,
                oldFiles: $oldFiles
            );

            DB::transaction(function () use ($request, $user, $role, $newFiles, $removedFiles, $adminMode) {
                $userPayload = $this->userPayloadFromRequest($request, $role, false);

                if ($request->has('role_id')) {
                    $userPayload['role_id'] = $role->id;
                }

                if (!$adminMode) {
                    unset(
                        $userPayload['isapproved'],
                        $userPayload['reject_reason'],
                        $userPayload['kyc']
                    );
                }

                $userPayload = $this->payloadForTable('users', $userPayload);

                foreach ($userPayload as $column => $value) {
                    if (Schema::hasColumn('users', $column)) {
                        $user->{$column} = $value;
                    }
                }

                if ($user->isDirty()) {
                    $user->save();
                }

                $detailPayload = $this->userDetailPayloadFromRequest($request, $user, $role);

                foreach ($removedFiles as $column => $value) {
                    if (
                        Schema::hasColumn('user_details', $column)
                        && !array_key_exists($column, $newFiles)
                    ) {
                        $detailPayload[$column] = null;
                    }
                }

                foreach ($newFiles as $column => $path) {
                    if (Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $path;
                    }
                }

                $this->persistUserDetailPayload($user, $detailPayload);
            });

            foreach (array_unique(array_filter($oldFiles)) as $oldPath) {
                $this->deletePublicUpload($oldPath);
            }

            $this->clearUserCaches($user->id);

            return response()->json([
                'status' => true,
                'message' => $currentUserMode
                    ? 'Profile updated successfully.'
                    : 'User updated successfully.',
                'data' => [
                    'id' => (int) $user->id,
                ],
            ], 200);
        } catch (\Throwable $e) {
            foreach ($newFiles as $path) {
                $this->deletePublicUpload($path);
            }

            \Log::error('User update failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to update user.',
                'error' => $e->getMessage(),
            ], 500);
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
    private function validationResponse($validator, int $status = 422)
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], $status);
    }

    private function roleText(?Role $role): string
    {
        if (!$role) {
            return '';
        }

        $text = strtolower(trim((string) (
            $role->slug
            ?? $role->name
            ?? $role->role_name
            ?? ''
        )));

        return str_replace([' ', '_', '-'], '', $text);
    }

    private function isOwnerRole(?Role $role): bool
    {
        return in_array($this->roleText($role), [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
    }

    private function isBusinessProfileRole(?Role $role): bool
    {
        return in_array($this->roleText($role), [
            'agent',
            'company',
            'developer',
            'consultancy',
        ], true);
    }

    private function requestHasAny(Request $request, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($request->has($key)) {
                return true;
            }
        }

        return false;
    }



    private function payloadForTable(string $table, array $payload): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        return collect($payload)
            ->filter(fn($value, $column) => Schema::hasColumn($table, $column))
            ->toArray();
    }

    private function persistUserDetailPayload(User $user, array $payload): void
    {
        $personalKeys = [
            'alternate_number',
            'profile_photo',
            'about_us',
            'country_id',
            'state_id',
            'city_id',
            'area_locality',
            'colony',
            'street_address',
            'address',
            'pin_code',
            'created_by'
        ];

        $businessKeysMap = [
            'bussiness_name' => 'business_name',
            'business_name' => 'business_name',
            'business_phone' => 'business_phone',
            'bussiness_email' => 'business_email',
            'business_email' => 'business_email',
            'bussiness_address' => 'business_address',
            'business_address' => 'business_address',
            'business_pin_code' => 'business_pin_code',
            'business_country_id' => 'country_id',
            'business_state_id' => 'state_id',
            'business_city_id' => 'city_id',
            'license_number' => 'license_number',
            'rera_number' => 'rera_number',
            'no_of_employees' => 'no_of_employees',
            'about_business' => 'about_business',
            'created_by' => 'created_by',
        ];

        $personalPayload = [];
        $businessPayload = [];

        foreach ($payload as $key => $value) {
            if ($key === 'id' || $key === 'user_id') {
                continue;
            }
            if (in_array($key, $personalKeys)) {
                $personalPayload[$key] = $value;
            }
            if (array_key_exists($key, $businessKeysMap)) {
                $targetCol = $businessKeysMap[$key];
                $businessPayload[$targetCol] = $value;
            }
        }

        if (!empty($personalPayload)) {
            UserPersonalDetail::updateOrCreate(
                ['user_id' => $user->id],
                $personalPayload
            );
        }

        if (!empty($businessPayload)) {
            UserBusinessDetail::updateOrCreate(
                ['user_id' => $user->id],
                $businessPayload
            );
        }
    }

    private function createUniqueIdForRole(Role $role): UniqueID
    {
        $prefix = (string) ($role->prefix ?? '');

        $nextNumber = UniqueID::query()
            ->where('unique_id', 'like', $prefix . '%')
            ->count() + 1;

        do {
            $uniqueValue = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            $nextNumber++;
        } while (UniqueID::query()->where('unique_id', $uniqueValue)->exists());

        $uniqueIDModel = new UniqueID();
        $uniqueIDModel->unique_id = $uniqueValue;
        $uniqueIDModel->save();

        return $uniqueIDModel;
    }

    private function resolveCurrentUserFromRequest(Request $request): ?User
    {
        $authUser = Auth::user();

        if ($authUser) {
            return $authUser;
        }

        $token = $request->bearerToken();

        if (!$token && $request->filled('api_token')) {
            $token = $request->input('api_token');
        }

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }

    private function baseUserValidationRules(Request $request, ?User $user = null, bool $create = false): array
    {
        $userId = $user?->id;

        $rules = [
            'id' => [$create ? 'nullable' : 'required', 'integer', 'exists:users,id'],

            'first_name' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],

            'role_id' => [$create ? 'required' : 'nullable', 'exists:roles,id'],

            'password' => [$create ? 'nullable' : 'nullable', 'string', 'min:8'],

            'email' => [
                'nullable',
                'email',
                Rule::unique('users', 'email')->ignore($userId),
            ],

            'phone' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($userId),
            ],

            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'area_locality' => ['nullable', 'string', 'max:255'],
            'colony' => ['nullable', 'string', 'max:255'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'pin_code' => ['nullable', 'string', 'max:20'],
            'about' => ['nullable', 'string'],

            'isapproved' => ['nullable', 'in:1,2,3,4'],
            'reject_reason' => ['nullable', 'string', 'max:1000'],
            'kyc' => ['nullable', 'in:0,1,2,3'],


            'license_number' => ['nullable', 'string', 'max:200'],
            'rera_number' => ['nullable', 'string', 'max:50'],
            'alternate_number' => ['nullable', 'string', 'max:200'],
            'no_of_employees' => ['nullable', 'integer'],
            'about_us' => ['nullable', 'string'],

            'bussiness_name' => ['nullable', 'string', 'max:255'],
            'bussiness_address' => ['nullable', 'string', 'max:500'],
            'business_address' => ['nullable', 'string', 'max:500'],
            'business_phone' => ['nullable', 'string', 'max:20'],
            'bussiness_email' => ['nullable', 'email', 'max:255'],

            'business_country_id' => ['nullable', 'exists:countries,id'],
            'business_state_id' => ['nullable', 'exists:states,id'],
            'business_city_id' => ['nullable', 'exists:cities,id'],
            'business_area_locality' => ['nullable', 'string', 'max:255'],
            'business_colony' => ['nullable', 'string', 'max:255'],
            'business_street_address' => ['nullable', 'string', 'max:255'],
            'business_pin_code' => ['nullable', 'string', 'max:20'],

            'profile_photo' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:' . self::MAX_UPLOAD_KB,
            ],

        ];

        if (Schema::hasColumn('users', 'user_name')) {
            $rules['user_name'] = [
                'nullable',
                'string',
                'min:3',
                'max:20',
                'regex:/^[a-zA-Z0-9._]+$/',
                Rule::unique('users', 'user_name')->ignore($userId),
            ];
        }

        return $rules;
    }

    private function userPayloadFromRequest(Request $request, Role $role, bool $create = false): array
    {
        $payload = [];

        foreach (
            [
                'first_name',
                'last_name',
                'user_name',
                'email',
                'phone',
                'role_id',
                'isapproved',
                'reject_reason',
                'kyc',
                'country_id',
                'state_id',
                'city_id',
                'area_locality',
                'colony',
                'street_address',
                'pin_code',
                'about',
            ] as $column
        ) {
            if ($request->has($column)) {
                $payload[$column] = $request->input($column);
            }
        }

        if ($create) {
            $payload['api_token'] = Str::random(60);

            if (!$request->has('role_id')) {
                $payload['role_id'] = $role->id;
            }

            if (!$request->has('isapproved')) {
                $payload['isapproved'] = 2;
            }

            if (!$request->has('kyc')) {
                $payload['kyc'] = 0;
            }
        }

        if ($request->filled('password')) {
            $payload['password'] = Hash::make($request->input('password'));
        } elseif ($create) {
            $payload['password'] = Hash::make(Str::random(24));
        }

        return $this->payloadForTable('users', $payload);
    }

    private function userDetailPayloadFromRequest(Request $request, User $user, Role $role): array
    {
        $isOwnerRole = $this->isOwnerRole($role);

        $payload = [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ];

        foreach (
            [
                'license_number',
                'rera_number',
                'alternate_number',
                'no_of_employees',
                'about_us',
            ] as $column
        ) {
            if ($request->has($column)) {
                $payload[$column] = $request->input($column);
            }
        }

        if (!$isOwnerRole) {
            foreach (
                [
                    'bussiness_name',
                    'bussiness_address',
                    'bussiness_email',
                    'business_phone',
                ] as $column
            ) {
                if ($request->has($column)) {
                    $payload[$column] = $request->input($column);
                }
            }

            if ($request->has('business_address')) {
                $payload['address'] = $request->input('business_address');
            }

            if ($request->has('business_country_id')) {
                $payload['country_id'] = $request->input('business_country_id');
            }

            if ($request->has('business_state_id')) {
                $payload['state_id'] = $request->input('business_state_id');
            }

            if ($request->has('business_city_id')) {
                $payload['city_id'] = $request->input('business_city_id');
            }

            if ($request->has('business_pin_code')) {
                $payload['pin_code'] = $request->input('business_pin_code');
            }

            if ($request->has('business_area_locality')) {
                $payload['area_locality'] = $request->input('business_area_locality');
            }

            if ($request->has('business_colony')) {
                $payload['colony'] = $request->input('business_colony');
            }

            if ($request->has('business_street_address')) {
                $payload['street_address'] = $request->input('business_street_address');
            }
        }

        return $payload;
    }
}
