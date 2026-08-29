<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDetail;
use App\Models\UserPersonalDetail;
use App\Models\UserBusinessDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;
use App\Models\KycRequest;
use App\Models\KycDocument;

class UserProfileController extends Controller
{
    private const MAX_UPLOAD_KB = 2048;
    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            $requestedId = $request->input('id') ?? $request->input('user_id') ?? $request->query('id') ?? $request->query('user_id');
            $message = $requestedId
                ? 'User account does not exist or has been deleted.'
                : 'Invalid or expired session token.';

            return response()->json([
                'status' => false,
                'message' => $message,
                'error' => 'Unauthorized. User account not found or token expired.',
            ], 401);
        }

        try {
            return response()->json([
                'status' => true,
                'message' => 'User profile fetched successfully.',
                'data' => $this->formatUserProfile($user),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch user profile.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePersonal(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }


        $isOwnerRole = $this->isOwnerUser($user);

        $rules = [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:8'],

            'alternate_number' => ['nullable', 'string', 'max:200'],
            'no_of_employees' => ['nullable', 'integer'],
            'about_us' => ['nullable', 'string'],
        ];

        if (!$isOwnerRole) {
            $rules['bussiness_name'] = ['nullable', 'string', 'max:200'];
            $rules['business_phone'] = ['nullable', 'string', 'max:200'];
            $rules['bussiness_email'] = ['nullable', 'email', 'max:200'];
        }

        if (Schema::hasColumn('users', 'user_name')) {
            $rules['user_name'] = [
                'nullable',
                'string',
                'min:3',
                'max:20',
                'regex:/^[a-zA-Z0-9._]+$/',
                Rule::unique('users', 'user_name')->ignore($user->id),
            ];
        }

        $validator = Validator::make($request->all(), $rules, [
            'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            DB::transaction(function () use ($request, $user, $isOwnerRole) {
                $userPayload = [];

                foreach (['first_name', 'last_name', 'email', 'phone', 'user_name'] as $column) {
                    if ($request->has($column) && Schema::hasColumn('users', $column)) {
                        $userPayload[$column] = $request->input($column);
                    }
                }

                if ($request->filled('password') && Schema::hasColumn('users', 'password')) {
                    $userPayload['password'] = Hash::make($request->password);
                }

                if (!empty($userPayload)) {
                    $user->update($userPayload);
                }

                $personalColumns = [
                    'alternate_number',
                    'about_us',
                ];

                $personalPayload = [
                    'user_id' => $user->id,
                ];

                foreach ($personalColumns as $column) {
                    if ($request->has($column)) {
                        $personalPayload[$column] = $request->input($column);
                    }
                }

                if (count($personalPayload) > 1) {
                    UserPersonalDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        $personalPayload
                    );
                }

                if (!$isOwnerRole) {
                    $businessColumns = [
                        'bussiness_name',
                        'business_name',
                        'business_phone',
                        'bussiness_email',
                        'business_email',
                        'no_of_employees',
                    ];

                    $businessPayload = [
                        'user_id' => $user->id,
                    ];

                    foreach ($businessColumns as $column) {
                        if ($request->has($column)) {
                            $val = $request->input($column);
                            if (in_array($column, ['bussiness_name', 'business_name'])) {
                                $businessPayload['business_name'] = $val;
                            } elseif (in_array($column, ['bussiness_email', 'business_email'])) {
                                $businessPayload['business_email'] = $val;
                            } else {
                                $businessPayload[$column] = $val;
                            }
                        }
                    }

                    if (count($businessPayload) > 1) {
                        UserBusinessDetail::updateOrCreate(
                            ['user_id' => $user->id],
                            $businessPayload
                        );
                    }
                }
            });

            $freshUser = User::find($user->id);

            return response()->json([
                'status' => true,
                'message' => 'Personal information updated successfully.',
                'data' => $this->formatUserProfile($freshUser),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update personal information.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateAddress(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $isOwnerRole = $this->isOwnerUser($user);

        $rules = [
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city_id' => ['nullable', 'exists:cities,id'],

            'street_address' => ['nullable', 'string', 'max:255'],
            'area_locality' => ['nullable', 'string', 'max:255'],
            'colony' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'pin_code' => ['nullable', 'string', 'max:20'],
        ];

        if (!$isOwnerRole) {
            $rules['business_country_id'] = ['nullable', 'exists:countries,id'];
            $rules['business_state_id'] = ['nullable', 'exists:states,id'];
            $rules['business_city_id'] = ['nullable', 'exists:cities,id'];
            $rules['bussiness_address'] = ['nullable', 'string', 'max:200'];
            $rules['business_address'] = ['nullable', 'string', 'max:200'];
            $rules['business_pin_code'] = ['nullable', 'string', 'max:20'];
            $rules['business_area_locality'] = ['nullable', 'string', 'max:255'];
            $rules['business_colony'] = ['nullable', 'string', 'max:255'];
            $rules['business_street_address'] = ['nullable', 'string', 'max:255'];
        }

        $validator = Validator::make(
            $request->all(),
            $rules
        );

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            DB::transaction(function () use ($request, $user, $isOwnerRole) {
                $personalPayload = ['user_id' => $user->id];
                $addressColumns = [
                    'country_id',
                    'state_id',
                    'city_id',
                    'street_address',
                    'area_locality',
                    'colony',
                    'address',
                    'pin_code',
                ];

                foreach ($addressColumns as $column) {
                    if ($request->has($column)) {
                        $personalPayload[$column] = $request->input($column);
                    }
                }

                if (count($personalPayload) > 1) {
                    UserPersonalDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        $personalPayload
                    );
                }

                if (!$isOwnerRole) {
                    $businessPayload = ['user_id' => $user->id];
                    if ($request->has('business_country_id')) {
                        $businessPayload['country_id'] = $request->input('business_country_id');
                    }
                    if ($request->has('business_state_id')) {
                        $businessPayload['state_id'] = $request->input('business_state_id');
                    }
                    if ($request->has('business_city_id')) {
                        $businessPayload['city_id'] = $request->input('business_city_id');
                    }
                    if ($request->has('bussiness_address')) {
                        $businessPayload['business_address'] = $request->input('bussiness_address');
                    }
                    if ($request->has('business_address')) {
                        $businessPayload['business_address'] = $request->input('business_address');
                    }
                    if ($request->has('business_pin_code')) {
                        $businessPayload['business_pin_code'] = $request->input('business_pin_code');
                    }
                    if ($request->has('business_area_locality')) {
                        $businessPayload['area_locality'] = $request->input('business_area_locality');
                    }
                    if ($request->has('business_colony')) {
                        $businessPayload['colony'] = $request->input('business_colony');
                    }
                    if ($request->has('business_street_address')) {
                        $businessPayload['street_address'] = $request->input('business_street_address');
                    }

                    if (count($businessPayload) > 1) {
                        UserBusinessDetail::updateOrCreate(
                            ['user_id' => $user->id],
                            $businessPayload
                        );
                    }
                }

                $userPayload = [];
                foreach ($addressColumns as $column) {
                    if ($request->has($column) && Schema::hasColumn('users', $column)) {
                        $userPayload[$column] = $request->input($column);
                    }
                }

                if (!empty($userPayload)) {
                    $user->update($userPayload);
                }
            });

            $freshUser = User::find($user->id);

            return response()->json([
                'status' => true,
                'message' => 'Address information updated successfully.',
                'data' => $this->formatUserProfile($freshUser),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update address information.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updatePhoto(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'profile_photo' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:' . self::MAX_UPLOAD_KB,
            ],
        ], [
            'profile_photo.max' => 'Profile photo must not be greater than 2MB.',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $newPath = null;
        $oldPath = null;

        try {
            $newPath = $this->storePublicUpload(
                file: $request->file('profile_photo'),
                folder: 'users',
                prefix: 'u' . $user->id . '_profile'
            );

            DB::transaction(function () use ($user, $newPath, &$oldPath) {
                $personal = UserPersonalDetail::query()
                    ->where('user_id', $user->id)
                    ->first();

                $oldPath = $personal?->profile_photo;

                UserPersonalDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'user_id' => $user->id,
                        'profile_photo' => $newPath,
                    ]
                );
            });

            $this->deletePublicUpload($oldPath);

            $freshUser = User::find($user->id);

            return response()->json([
                'status' => true,
                'message' => 'Profile photo updated successfully.',
                'profile_photo' => $this->fileUrl($newPath),
                'data' => $this->formatUserProfile($freshUser),
            ]);
        } catch (Throwable $e) {
            $this->deletePublicUpload($newPath);

            return response()->json([
                'status' => false,
                'message' => 'Unable to update profile photo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateCompanyLogo(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $fileInput = $request->hasFile('company_logo') ? 'company_logo' : ($request->hasFile('business_logo') ? 'business_logo' : 'company_logo');

        $validator = Validator::make($request->all(), [
            $fileInput => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:' . self::MAX_UPLOAD_KB,
            ],
        ], [
            "{$fileInput}.max" => 'Company logo must not be greater than 2MB.',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $newPath = null;
        $oldPath = null;

        try {
            $newPath = $this->storePublicUpload(
                file: $request->file($fileInput),
                folder: 'business/logos',
                prefix: 'u' . $user->id . '_company_logo'
            );

            DB::transaction(function () use ($user, $newPath, &$oldPath) {
                $business = UserBusinessDetail::query()
                    ->where('user_id', $user->id)
                    ->first();

                $oldPath = $business?->company_logo;

                UserBusinessDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'user_id' => $user->id,
                        'company_logo' => $newPath,
                    ]
                );
            });

            $this->deletePublicUpload($oldPath);

            $freshUser = User::find($user->id);

            return response()->json([
                'status' => true,
                'message' => 'Company logo updated successfully.',
                'company_logo' => $this->fileUrl($newPath),
                'business_logo' => $this->fileUrl($newPath),
                'data' => $this->formatUserProfile($freshUser),
            ]);
        } catch (Throwable $e) {
            $this->deletePublicUpload($newPath);

            return response()->json([
                'status' => false,
                'message' => 'Unable to update company logo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    private function resolveCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (!$token && $request->filled('api_token')) {
            $token = $request->api_token;
        }

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }

    private function formatUserProfile(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $personal = UserPersonalDetail::query()
            ->where('user_id', $user->id)
            ->first();

        $business = UserBusinessDetail::query()
            ->where('user_id', $user->id)
            ->first();

        $roleName = null;

        if (Schema::hasTable('roles') && !empty($user->role_id)) {
            $role = DB::table('roles')->where('id', $user->role_id)->first();
            $roleName = $role->name ?? $role->role_name ?? null;
        }

        $countryId = $user->country_id ?? $personal?->country_id ?? null;
        $stateId = $user->state_id ?? $personal?->state_id ?? null;
        $cityId = $user->city_id ?? $personal?->city_id ?? null;

        $countryName = $this->locationName('countries', $countryId);
        $stateName = $this->locationName('states', $stateId);
        $cityName = $this->locationName('cities', $cityId);

        $profilePhoto = $this->fileUrl($personal?->profile_photo);

        $firstName = $user->first_name ?? null;
        $lastName = $user->last_name ?? null;
        $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

        $dashboardCounts = $this->profileDashboardCounts($user);
        $isOwnerRole = $this->isOwnerUser($user);
        $kycSummary = $this->kycModuleSummary($user);

        $streetAddress = $user->street_address ?? $personal?->street_address ?? null;
        $areaLocality = $user->area_locality ?? $personal?->area_locality ?? null;
        $colony = $user->colony ?? $personal?->colony ?? null;
        $address = $personal?->address ?? $user->address ?? $streetAddress ?? null;
        $pinCode = $user->pin_code ?? $personal?->pin_code ?? null;

        $fullAddress = collect([
            $streetAddress,
            $colony,
            $areaLocality,
            $address,
            $cityName,
            $stateName,
            $countryName,
            $pinCode,
        ])->filter()->values()->implode(', ') ?: null;

        $raw = [
            'id' => (int) $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName ?: null,
            'user_name' => $user->user_name ?? null,
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'role_id' => $user->role_id ?? null,
            'role_name' => $roleName,
            'unique_id' => $user->unique_id ?? null,
            'isapproved' => $user->isapproved ?? null,
            'account_status' => $this->accountStatusLabel($user->isapproved ?? null),
            'kyc' => $user->kyc ?? 0,
            'kyc_status' => $kycSummary['status_label'],
            'admin_kyc_status' => $kycSummary['admin_status'],
            'aadhaar_number' => $kycSummary['aadhaar_number'],
            'aadhar_number' => $kycSummary['aadhar_number'],
            'aadhaar_no' => $kycSummary['aadhaar_number'],
            'aadhar_no' => $kycSummary['aadhar_number'],
            'aadhaar_front' => $kycSummary['aadhaar_front'],
            'aadhar_front' => $kycSummary['aadhar_front'],
            'aadhaar_back' => $kycSummary['aadhaar_back'],
            'aadhar_back' => $kycSummary['aadhar_back'],
            'pan_number' => $kycSummary['pan_number'],
            'gst_number' => $kycSummary['gst_number'],
            'rera_number' => $kycSummary['rera_number'],
            'kyc_module' => $kycSummary,
            'is_otp_verified' => $user->is_otp_verified ?? false,

            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'country' => $countryName,
            'state' => $stateName,
            'city' => $cityName,

            'street_address' => $streetAddress,
            'area_locality' => $areaLocality,
            'colony' => $colony,
            'address' => $address,
            'pin_code' => $pinCode,

            'location' => [
                'country' => $countryName,
                'state' => $stateName,
                'city' => $cityName,
                'street_address' => $streetAddress,
                'area_locality' => $areaLocality,
                'colony' => $colony,
                'address' => $address,
                'pin_code' => $pinCode,
                'full_address' => $fullAddress,
            ],

            'profile_photo' => $profilePhoto,

            'alternate_number' => $personal?->alternate_number ?? null,
            'no_of_employees' => $business?->no_of_employees ?? null,
            'about_us' => $personal?->about_us ?? ($user->about ?? null),

            'business_fields_visible' => !$isOwnerRole,

            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        if (!$isOwnerRole) {
            $businessCountryId = $business?->country_id ?: $countryId;
            $businessStateId = $business?->state_id ?: $stateId;
            $businessCityId = $business?->city_id ?: $cityId;
            $companyLogo = $this->fileUrl($business?->company_logo);

            $raw['bussiness_name'] = $business?->business_name ?? null;
            $raw['business_name'] = $business?->business_name ?? null;
            $raw['business_phone'] = $business?->business_phone ?? $user->phone ?? null;
            $raw['bussiness_email'] = $business?->business_email ?? $user->email ?? null;
            $raw['business_email'] = $business?->business_email ?? $user->email ?? null;
            $raw['bussiness_address'] = $business?->business_address ?? $address ?? $streetAddress ?? null;
            $raw['business_address'] = $business?->business_address ?? $address ?? $streetAddress ?? null;
            $raw['business_pin_code'] = $business?->business_pin_code ?? $pinCode ?? null;
            $raw['company_logo'] = $companyLogo;
            $raw['business_logo'] = $companyLogo;
            $raw['license_number'] = $business?->license_number ?? null;
            $raw['rera_number'] = $business?->rera_number ?? null;

            $raw['business_country_id'] = $businessCountryId;
            $raw['business_state_id'] = $businessStateId;
            $raw['business_city_id'] = $businessCityId;

            $raw['business_country'] = $this->locationName('countries', $businessCountryId);
            $raw['business_state'] = $this->locationName('states', $businessStateId);
            $raw['business_city'] = $this->locationName('cities', $businessCityId);
        }

        $display = collect($raw)
            ->map(fn($value) => is_array($value) ? $this->dashArray($value) : $this->dash($value))
            ->toArray();

        unset(
            $display['country_id'],
            $display['state_id'],
            $display['city_id'],
            $display['business_country_id'],
            $display['business_state_id'],
            $display['business_city_id']
        );

        return [
            'raw' => $raw,
            'display' => $display,
            'profile_completion' => $this->profileCompletion($raw),
            'dashboard_counts' => $dashboardCounts,
        ];
    }

    private function valueFromModel(User $user, ?UserDetail $detail, string $column): mixed
    {
        if (Schema::hasColumn('users', $column) && !empty($user->{$column})) {
            return $user->{$column};
        }

        if ($detail && Schema::hasColumn('user_details', $column) && !empty($detail->{$column})) {
            return $detail->{$column};
        }

        return null;
    }

    private function locationName(string $table, mixed $id): ?string
    {
        if (empty($id) || !Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)
            ->where('id', $id)
            ->value('name');
    }

    private function detailValue(?UserDetail $detail, string $column): mixed
    {
        if (!$detail || !Schema::hasColumn('user_details', $column)) {
            return null;
        }

        return $detail->{$column} ?? null;
    }

    private function fileUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $path = trim($path);
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
    private function dash(mixed $value): mixed
    {
        if (is_null($value) || $value === '' || $value === 'N/A') {
            return '-';
        }

        return $value;
    }

    private function dashArray(array $items): array
    {
        return collect($items)
            ->map(fn($value) => is_array($value) ? $this->dashArray($value) : $this->dash($value))
            ->toArray();
    }

    private function profileCompletion(array $data): array
    {
        /*
         * Every editable profile field is included in profile strength.
         * Password, role, account status and generated/system fields are excluded.
         */
        $fields = [
            // Personal information
            'first_name' => [
                'label' => 'First Name',
                'section' => 'personal_information',
            ],
            'last_name' => [
                'label' => 'Last Name',
                'section' => 'personal_information',
            ],
            'user_name' => [
                'label' => 'Username',
                'section' => 'personal_information',
            ],
            'email' => [
                'label' => 'Email Address',
                'section' => 'personal_information',
            ],
            'phone' => [
                'label' => 'Mobile Number',
                'section' => 'personal_information',
            ],
            'alternate_number' => [
                'label' => 'Alternate Number',
                'section' => 'personal_information',
            ],
            'about_us' => [
                'label' => 'About You',
                'section' => 'personal_information',
            ],

            // Address information: each field is checked independently.
            'country_id' => [
                'label' => 'Country',
                'section' => 'address_information',
            ],
            'state_id' => [
                'label' => 'State',
                'section' => 'address_information',
            ],
            'city_id' => [
                'label' => 'City',
                'section' => 'address_information',
            ],
            'street_address' => [
                'label' => 'Street Address',
                'section' => 'address_information',
            ],
            'area_locality' => [
                'label' => 'Area / Locality',
                'section' => 'address_information',
            ],
            'colony' => [
                'label' => 'Colony',
                'section' => 'address_information',
            ],
            'address' => [
                'label' => 'Address',
                'section' => 'address_information',
            ],
            'pin_code' => [
                'label' => 'PIN Code',
                'section' => 'address_information',
            ],

            // Profile photo
            'profile_photo' => [
                'label' => 'Profile Photo',
                'section' => 'profile_photo',
            ],

        ];

        /* Business fields are applicable only when they are visible for the role. */
        if (($data['business_fields_visible'] ?? false) === true) {
            $fields = array_merge($fields, [
                'bussiness_name' => [
                    'label' => 'Business Name',
                    'section' => 'business_information',
                ],
                'business_phone' => [
                    'label' => 'Business Phone',
                    'section' => 'business_information',
                ],
                'bussiness_email' => [
                    'label' => 'Business Email',
                    'section' => 'business_information',
                ],
                'no_of_employees' => [
                    'label' => 'Number of Employees',
                    'section' => 'business_information',
                ],
                'business_country_id' => [
                    'label' => 'Business Country',
                    'section' => 'business_address',
                ],
                'business_state_id' => [
                    'label' => 'Business State',
                    'section' => 'business_address',
                ],
                'business_city_id' => [
                    'label' => 'Business City',
                    'section' => 'business_address',
                ],
                'bussiness_address' => [
                    'label' => 'Business Address',
                    'section' => 'business_address',
                ],
                'business_pin_code' => [
                    'label' => 'Business PIN Code',
                    'section' => 'business_address',
                ],

            ]);
        }

        $completedFieldNames = [];
        $missingFieldDetails = [];
        $sectionStats = [];

        foreach ($fields as $field => $meta) {
            $section = $meta['section'];
            $value = $data[$field] ?? null;
            $isCompleted = $this->hasProfileValue($value);

            if (!$isCompleted) {
                if ($field === 'address' && $this->hasProfileValue($data['street_address'] ?? null)) {
                    $isCompleted = true;
                } elseif ($field === 'colony' && ($this->hasProfileValue($data['street_address'] ?? null) || $this->hasProfileValue($data['area_locality'] ?? null))) {
                    $isCompleted = true;
                } elseif ($field === 'alternate_number' && $this->hasProfileValue($data['phone'] ?? null)) {
                    $isCompleted = true;
                } elseif ($field === 'business_phone' && ($this->hasProfileValue($data['phone'] ?? null) || $this->hasProfileValue($data['alternate_number'] ?? null))) {
                    $isCompleted = true;
                } elseif (($field === 'bussiness_email' || $field === 'business_email') && $this->hasProfileValue($data['email'] ?? null)) {
                    $isCompleted = true;
                } elseif (($field === 'bussiness_address' || $field === 'business_address') && ($this->hasProfileValue($data['address'] ?? null) || $this->hasProfileValue($data['street_address'] ?? null))) {
                    $isCompleted = true;
                } elseif ($field === 'business_country_id' && $this->hasProfileValue($data['country_id'] ?? null)) {
                    $isCompleted = true;
                } elseif ($field === 'business_state_id' && $this->hasProfileValue($data['state_id'] ?? null)) {
                    $isCompleted = true;
                } elseif ($field === 'business_city_id' && $this->hasProfileValue($data['city_id'] ?? null)) {
                    $isCompleted = true;
                } elseif ($field === 'business_pin_code' && $this->hasProfileValue($data['pin_code'] ?? null)) {
                    $isCompleted = true;
                }
            }

            if (!isset($sectionStats[$section])) {
                $sectionStats[$section] = [
                    'completed_fields' => 0,
                    'total_fields' => 0,
                    'missing_fields' => [],
                    'missing_field_labels' => [],
                ];
            }

            $sectionStats[$section]['total_fields']++;

            if ($isCompleted) {
                $completedFieldNames[] = $field;
                $sectionStats[$section]['completed_fields']++;
                continue;
            }

            $missing = [
                'field' => $field,
                'label' => $meta['label'],
                'section' => $section,
            ];

            $missingFieldDetails[] = $missing;
            $sectionStats[$section]['missing_fields'][] = $field;
            $sectionStats[$section]['missing_field_labels'][] = $meta['label'];
        }

        foreach ($sectionStats as $section => $stats) {
            $sectionStats[$section]['percentage'] = $stats['total_fields'] > 0
                ? (int) round(($stats['completed_fields'] / $stats['total_fields']) * 100)
                : 0;

            $sectionStats[$section]['is_complete'] = empty($stats['missing_fields']);
        }

        $total = count($fields);
        $completed = count($completedFieldNames);
        $percentage = $total > 0
            ? (int) round(($completed / $total) * 100)
            : 0;

        $missingFields = array_column($missingFieldDetails, 'field');
        $missingLabels = array_column($missingFieldDetails, 'label');
        $missingCount = count($missingFields);

        return [
            'percentage' => $percentage,
            'strength' => $this->profileStrengthLabel($percentage),
            'is_complete' => $missingCount === 0,
            'completed_fields' => $completed,
            'total_fields' => $total,
            'missing_count' => $missingCount,

            // Field keys for frontend validation/navigation.
            'missing_fields' => $missingFields,

            // User-friendly values for direct display.
            'missing_field_labels' => $missingLabels,
            'missing_field_details' => $missingFieldDetails,
            'missing_by_section' => $sectionStats,
            'next_missing_field' => $missingFieldDetails[0] ?? null,
            'message' => $missingCount === 0
                ? 'Your profile is complete.'
                : $missingCount . ' profile field' . ($missingCount === 1 ? ' is' : 's are') . ' missing.',
        ];
    }

    private function hasProfileValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value !== ''
                && $value !== '-'
                && strtoupper($value) !== 'N/A';
        }

        if (is_array($value)) {
            return !empty($value);
        }

        // Numeric zero and boolean false can be valid stored values.
        return true;
    }

    private function profileStrengthLabel(int $percentage): string
    {
        return match (true) {
            $percentage >= 100 => 'Complete',
            $percentage >= 75 => 'Strong',
            $percentage >= 50 => 'Moderate',
            $percentage >= 25 => 'Basic',
            default => 'Incomplete',
        };
    }

    private function accountStatusLabel(mixed $status): string
    {
        return match ((int) $status) {
            1 => 'Approved',
            2 => 'Inactive',
            3 => 'Pending',
            4 => 'Rejected',
            default => 'Pending',
        };
    }

    private function isOwnerUser(User $user): bool
    {
        $directRole = strtolower(trim((string) ($user->role_id ?? '')));
        $directRole = str_replace([' ', '_', '-'], '', $directRole);

        if (in_array($directRole, ['owner', 'propertyowner', 'landowner'], true)) {
            return true;
        }

        if (!Schema::hasTable('roles') || empty($user->role_id)) {
            return false;
        }

        $role = DB::table('roles')
            ->where('id', $user->role_id)
            ->first();

        if (!$role) {
            return false;
        }

        $roleText = strtolower(trim((string) (
            $role->slug
            ?? $role->name
            ?? $role->role_name
            ?? ''
        )));

        $roleText = str_replace([' ', '_', '-'], '', $roleText);

        return in_array($roleText, ['owner', 'propertyowner', 'landowner'], true);
    }

    private function profileDashboardCounts(User $user): array
    {
        $listingIds = $this->userListingIds($user);

        $purposeCounts = $this->propertyPurposeCounts($listingIds);

        return [
            'total_listings' => count($listingIds),

            'properties_for_sell' => $purposeCounts['sell'],
            'properties_for_rent' => $purposeCounts['rent'],

            'total_leads' => $this->countUserRelatedRows($user, [
                'leads',
                'property_leads',
                'listing_leads',
                'dynamic_post_leads',
                'lead_forms',
            ], $listingIds),

            'total_inquiries' => $this->countUserRelatedRows($user, [
                'inquiries',
                'enquiries',
                'contact_us_leads',
                'business_enquiries',
                'property_inquiries',
                'listing_inquiries',
            ], $listingIds),
        ];
    }
    private function propertyPurposeCounts(array $listingIds): array
    {
        $defaultCounts = [
            'sell' => 0,
            'rent' => 0,
        ];

        if (empty($listingIds)) {
            return $defaultCounts;
        }

        if (
            !Schema::hasTable('post_taxonomy_terms')
            || !Schema::hasTable('taxonomy_terms')
            || !Schema::hasTable('taxonomies')
            || !Schema::hasColumn('post_taxonomy_terms', 'dynamic_post_id')
            || !Schema::hasColumn('post_taxonomy_terms', 'taxonomy_term_id')
            || !Schema::hasColumn('taxonomy_terms', 'taxonomy_id')
            || !Schema::hasColumn('taxonomy_terms', 'slug')
            || !Schema::hasColumn('taxonomies', 'slug')
        ) {
            return $defaultCounts;
        }

        $counts = DB::table('post_taxonomy_terms as ptt')
            ->join(
                'taxonomy_terms as tt',
                'tt.id',
                '=',
                'ptt.taxonomy_term_id'
            )
            ->join(
                'taxonomies as tx',
                'tx.id',
                '=',
                'tt.taxonomy_id'
            )
            ->whereIn('ptt.dynamic_post_id', $listingIds)

            // Purpose taxonomy only
            ->whereRaw('LOWER(tx.slug) = ?', ['purpose'])

            // Purpose terms only
            ->whereIn(DB::raw('LOWER(tt.slug)'), [
                'sell',
                'rent',
            ])

            // Duplicate pivot rows se incorrect count prevent karega
            ->selectRaw(
                'LOWER(tt.slug) as purpose_slug,
            COUNT(DISTINCT ptt.dynamic_post_id) as total'
            )
            ->groupByRaw('LOWER(tt.slug)')
            ->pluck('total', 'purpose_slug');

        return [
            'sell' => (int) $counts->get('sell', 0),
            'rent' => (int) $counts->get('rent', 0),
        ];
    }
    private function userListingIds(User $user): array
    {
        if (!Schema::hasTable('dynamic_posts')) {
            return [];
        }

        $query = DB::table('dynamic_posts')
            ->select('dynamic_posts.id');

        $hasUserFilter = false;

        $query->where(function ($q) use ($user, &$hasUserFilter) {
            foreach (['author_id', 'user_id', 'owner_id', 'created_by'] as $column) {
                if (Schema::hasColumn('dynamic_posts', $column)) {
                    $q->orWhere('dynamic_posts.' . $column, (int) $user->id);
                    $hasUserFilter = true;
                }
            }
        });

        if (!$hasUserFilter) {
            return [];
        }

        if (
            Schema::hasTable('post_types')
            && Schema::hasColumn('dynamic_posts', 'post_type_id')
        ) {
            $propertyPostTypeId = DB::table('post_types')
                ->where('slug', 'property-listing')
                ->value('id');

            if ($propertyPostTypeId) {
                $query->where('dynamic_posts.post_type_id', (int) $propertyPostTypeId);
            }
        }

        return $query
            ->pluck('dynamic_posts.id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();
    }

    private function countUserRelatedRows(User $user, array $tables, array $listingIds = []): int
    {
        $total = 0;

        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);
            $hasFilter = false;

            $query->where(function ($q) use ($table, $user, $listingIds, &$hasFilter) {
                foreach (
                    [
                        'user_id',
                        'owner_id',
                        'agent_id',
                        'developer_id',
                        'consultancy_id',
                        'assigned_user_id',
                        'assigned_to',
                        'created_by',
                        'author_id',
                    ] as $column
                ) {
                    if (Schema::hasColumn($table, $column)) {
                        $q->orWhere($column, (int) $user->id);
                        $hasFilter = true;
                    }
                }

                if (!empty($listingIds)) {
                    foreach (['dynamic_post_id', 'listing_id', 'property_listing_id', 'post_id'] as $column) {
                        if (Schema::hasColumn($table, $column)) {
                            $q->orWhereIn($column, $listingIds);
                            $hasFilter = true;
                        }
                    }
                }

                if (!empty($user->email)) {
                    foreach (['email', 'user_email', 'lead_email'] as $column) {
                        if (Schema::hasColumn($table, $column)) {
                            $q->orWhere($column, $user->email);
                            $hasFilter = true;
                        }
                    }
                }

                if (!empty($user->phone)) {
                    foreach (
                        [
                            'phone',
                            'mobile',
                            'number',
                            'contact_number',
                            'user_phone',
                            'lead_phone',
                        ] as $column
                    ) {
                        if (Schema::hasColumn($table, $column)) {
                            $q->orWhere($column, $user->phone);
                            $hasFilter = true;
                        }
                    }
                }
            });

            if (!$hasFilter) {
                continue;
            }

            $total += (int) $query->count();
        }

        return $total;
    }


    private function validationResponse($validator): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function persistUserDetailPayload(User $user, array $payload): void
    {
        $personalKeys = [
            'alternate_number', 'profile_photo', 'about_us', 'country_id',
            'state_id', 'city_id', 'area_locality', 'colony', 'street_address',
            'address', 'pin_code', 'created_by'
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
            'company_logo' => 'company_logo',
            'business_logo' => 'company_logo',
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


    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        if ($request->filled('confirm_password') && !$request->filled('new_password_confirmation')) {
            $request->merge([
                'new_password_confirmation' => $request->confirm_password,
            ]);
        }

        $validator = Validator::make($request->all(), [
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ], [
            'new_password.required' => 'New password is required.',
            'new_password.min' => 'New password must be at least 8 characters.',
            'new_password.confirmed' => 'New password and confirm password do not match.',
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            try {
                \App\Models\Notification\UserNotification::create([
                    'user_id' => $user->id,
                    'type' => 'system',
                    'title' => 'Password Changed',
                    'body' => 'Your password was changed successfully.',
                ]);
            } catch (Throwable) {}

            return response()->json([
                'status' => true,
                'message' => 'Password updated successfully.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update password.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    private function kycModuleSummary(User $user): array
    {
        $default = [
            'request_id' => null,
            'status' => 'not_started',
            'status_label' => 'Incomplete',
            'admin_status' => 'Not Submitted',
            'aadhaar_number' => null,
            'aadhar_number' => null,
            'pan_number' => null,
            'gst_number' => null,
            'rera_number' => null,
            'aadhaar_front' => null,
            'aadhar_front' => null,
            'aadhaar_back' => null,
            'aadhar_back' => null,
            'documents_count' => 0,
            'pending_documents_count' => 0,
            'approved_documents_count' => 0,
            'rejected_documents_count' => 0,
            'documents' => [],
            'submitted_at' => null,
            'approved_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'can_submit' => true,
            'can_resubmit' => false,
        ];

        if (!Schema::hasTable('kyc_requests')) {
            return $default;
        }

        $kycRequest = KycRequest::query()
            ->where('user_id', (int) $user->id)
            ->latest('id')
            ->first();

        $aadhaarNumber = null;
        $panNumber = null;
        $gstNumber = null;
        $reraNumber = null;
        $aadhaarFront = null;
        $aadhaarBack = null;
        $documentsList = [];

        if ($kycRequest) {
            $aadhaarNumber = $kycRequest->aadhaar_number ?? $kycRequest->aadhar_number ?? null;
            $panNumber = $kycRequest->pan_number ?? null;
            $gstNumber = $kycRequest->gst_number ?? null;
            $reraNumber = $kycRequest->rera_number ?? null;

            if (Schema::hasTable('kyc_documents')) {
                $docs = KycDocument::query()
                    ->where('kyc_request_id', (int) $kycRequest->id)
                    ->get();

                foreach ($docs as $doc) {
                    $fileUrl = $this->fileUrl($doc->file_path);
                    $docType = $doc->document_type;

                    if (in_array($docType, ['aadhaar_front', 'aadhar_front'], true)) {
                        $aadhaarFront = $fileUrl;
                    } elseif (in_array($docType, ['aadhaar_back', 'aadhar_back'], true)) {
                        $aadhaarBack = $fileUrl;
                    } elseif (in_array($docType, ['aadhaar', 'aadhar'], true)) {
                        if (empty($aadhaarFront)) {
                            $aadhaarFront = $fileUrl;
                        }
                    }

                    $documentsList[] = [
                        'id' => (int) $doc->id,
                        'document_type' => $docType,
                        'file_url' => $fileUrl,
                        'file_name' => $doc->file_original_name ?: basename($doc->file_path),
                        'status' => $doc->status,
                        'rejection_reason' => $doc->rejection_reason ?? null,
                    ];
                }
            }
        }

        // Fallback to user_details if kyc_requests did not have aadhaar details
        if (empty($aadhaarNumber) && Schema::hasTable('user_details')) {
            $userDetail = DB::table('user_details')->where('user_id', $user->id)->first();
            if ($userDetail) {
                $aadhaarNumber = $userDetail->aadhaar_number ?? $userDetail->aadhar_number ?? null;
                if (empty($aadhaarFront) && !empty($userDetail->aadhaar_front)) {
                    $aadhaarFront = $this->fileUrl($userDetail->aadhaar_front);
                }
                if (empty($aadhaarBack) && !empty($userDetail->aadhaar_back)) {
                    $aadhaarBack = $this->fileUrl($userDetail->aadhaar_back);
                }
                if (empty($panNumber) && !empty($userDetail->pan_number)) {
                    $panNumber = $userDetail->pan_number;
                }
                if (empty($gstNumber) && !empty($userDetail->gst_number)) {
                    $gstNumber = $userDetail->gst_number;
                }
                if (empty($reraNumber) && !empty($userDetail->rera_number)) {
                    $reraNumber = $userDetail->rera_number;
                }
            }
        }

        // Fallback to users table columns if present
        if (empty($aadhaarNumber)) {
            $aadhaarNumber = $user->aadhaar_number ?? $user->aadhar_number ?? null;
        }

        if (!$kycRequest) {
            $default['aadhaar_number'] = $aadhaarNumber;
            $default['aadhar_number'] = $aadhaarNumber;
            $default['aadhaar_front'] = $aadhaarFront;
            $default['aadhar_front'] = $aadhaarFront;
            $default['aadhaar_back'] = $aadhaarBack;
            $default['aadhar_back'] = $aadhaarBack;
            $default['pan_number'] = $panNumber;
            $default['gst_number'] = $gstNumber;
            $default['rera_number'] = $reraNumber;
            return $default;
        }

        $status = strtolower((string) ($kycRequest->status ?? 'pending'));

        $summary = [
            'request_id' => (int) $kycRequest->id,
            'status' => $status,
            'status_label' => $this->kycModuleStatusLabel($status),
            'admin_status' => $this->adminKycStatusLabel($status),

            'aadhaar_number' => $aadhaarNumber,
            'aadhar_number' => $aadhaarNumber,
            'pan_number' => $panNumber,
            'gst_number' => $gstNumber,
            'rera_number' => $reraNumber,
            'aadhaar_front' => $aadhaarFront,
            'aadhar_front' => $aadhaarFront,
            'aadhaar_back' => $aadhaarBack,
            'aadhar_back' => $aadhaarBack,

            'documents_count' => count($documentsList),
            'pending_documents_count' => collect($documentsList)->where('status', 'pending')->count(),
            'approved_documents_count' => collect($documentsList)->where('status', 'approved')->count(),
            'rejected_documents_count' => collect($documentsList)->where('status', 'rejected')->count(),
            'documents' => $documentsList,

            'submitted_at' => optional($kycRequest->submitted_at ?? null)->toDateTimeString(),
            'approved_at' => optional($kycRequest->approved_at ?? null)->toDateTimeString(),
            'rejected_at' => optional($kycRequest->rejected_at ?? null)->toDateTimeString(),

            'rejection_reason' => $kycRequest->rejection_reason
                ?? $kycRequest->reject_reason
                ?? null,

            'can_submit' => in_array($status, ['draft', 'pending', 'not_started'], true),
            'can_resubmit' => in_array($status, ['rejected'], true),
        ];

        return array_merge($default, $summary);
    }
    private function kycModuleStatusLabel(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'draft' => 'Draft',
            'pending' => 'Pending',
            'submitted' => 'Submitted',
            'under_review', 'in_review' => 'Under Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'expired' => 'Expired',
            default => 'Incomplete',
        };
    }

    private function adminKycStatusLabel(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'submitted' => 'Submitted',
            'under_review', 'in_review' => 'Reviewing',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'draft', 'pending' => 'Not Submitted',
            default => 'Not Submitted',
        };
    }
}
