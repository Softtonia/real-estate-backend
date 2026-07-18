<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessUserDocumentUploadJob;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class UserProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveCurrentUser($request);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or expired token.',
                ], 401);
            }

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

        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
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

            'bussiness_name' => ['nullable', 'string', 'max:200'],
            'business_phone' => ['nullable', 'string', 'max:200'],
            'bussiness_email' => ['nullable', 'email', 'max:200'],
        ];

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
            DB::transaction(function () use ($request, $user) {
                $userPayload = [];

                foreach ([
                    'first_name',
                    'last_name',
                    'email',
                    'phone',
                    'user_name',
                ] as $column) {
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

                $detailPayload = [
                    'user_id' => $user->id,
                ];

                foreach ([
                    'alternate_number',
                    'no_of_employees',
                    'about_us',
                    'bussiness_name',
                    'business_phone',
                    'bussiness_email',
                ] as $column) {
                    if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $request->input($column);
                    }
                }

                if (count($detailPayload) > 1) {
                    UserDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        $detailPayload
                    );
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

        $validator = Validator::make($request->all(), [
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'address' => ['nullable', 'string', 'max:255'],
            'pin_code' => ['nullable', 'string', 'max:20'],

            'business_country_id' => ['nullable', 'exists:countries,id'],
            'business_state_id' => ['nullable', 'exists:states,id'],
            'business_city_id' => ['nullable', 'exists:cities,id'],
            'bussiness_address' => ['nullable', 'string', 'max:200'],
            'business_pin_code' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $detailPayload = [
                    'user_id' => $user->id,
                ];

                foreach ([
                    'country_id',
                    'state_id',
                    'city_id',
                    'address',
                    'pin_code',
                ] as $column) {
                    if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $request->input($column);
                    }
                }

                $businessMap = [
                    'business_country_id' => 'country_id',
                    'business_state_id' => 'state_id',
                    'business_city_id' => 'city_id',
                    'bussiness_address' => 'bussiness_address',
                    'business_pin_code' => 'pin_code',
                ];

                foreach ($businessMap as $requestKey => $dbColumn) {
                    if ($request->has($requestKey) && Schema::hasColumn('user_details', $dbColumn)) {
                        $detailPayload[$dbColumn] = $request->input($requestKey);
                    }
                }

                if (count($detailPayload) > 1) {
                    UserDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        $detailPayload
                    );
                }

                $userPayload = [];

                foreach ([
                    'country_id',
                    'state_id',
                    'city_id',
                    'address',
                    'pin_code',
                ] as $column) {
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

    /*
    |--------------------------------------------------------------------------
    | Old Single API: Documents Upload
    |--------------------------------------------------------------------------
    | This still works, but for large files use:
    | 1. startDocumentUpload()
    | 2. uploadDocumentFile()
    | 3. documentUploadProgress()
    |--------------------------------------------------------------------------
    */
    public function updateDocuments(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'aadhaar_number' => [
                'nullable',
                'digits:12',
                Rule::unique('user_details', 'aadhaar_number')->ignore($user->id, 'user_id'),
            ],
            'aadhaar_front' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'aadhaar_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'business_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'license_number' => ['nullable', 'string', 'max:200'],
            'rera_number' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $detailPayload = [
                    'user_id' => $user->id,
                ];

                foreach ([
                    'aadhaar_number',
                    'license_number',
                    'rera_number',
                ] as $column) {
                    if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $request->input($column);
                    }
                }

                $fileFields = [
                    'aadhaar_front' => 'uploads/kyc/aadhaarFront',
                    'aadhaar_back' => 'uploads/kyc/aadhaarBack',
                    'business_proof' => 'uploads/kyc/businessProof',
                ];

                foreach ($fileFields as $field => $directory) {
                    if ($request->hasFile($field) && Schema::hasColumn('user_details', $field)) {
                        $file = $request->file($field);

                        if (!$file->isValid()) {
                            throw new \Exception($field . ' upload failed.');
                        }

                        $extension = strtolower($file->getClientOriginalExtension());

                        $fileName = 'u'
                            . $user->id
                            . '_'
                            . time()
                            . '_'
                            . $field
                            . '_'
                            . uniqid()
                            . '.'
                            . $extension;

                        Storage::disk('public')->putFileAs(
                            $directory,
                            $file,
                            $fileName
                        );

                        $detailPayload[$field] = 'storage/' . $directory . '/' . $fileName;
                    }
                }

                if (count($detailPayload) > 1) {
                    UserDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        $detailPayload
                    );
                }

                if (Schema::hasColumn('users', 'kyc')) {
                    $user->update([
                        'kyc' => 1,
                    ]);
                }
            });

            $freshUser = User::find($user->id);

            return response()->json([
                'status' => true,
                'message' => 'Documents updated successfully.',
                'data' => $this->formatUserProfile($freshUser),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update documents.',
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
            'profile_photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $file = $request->file('profile_photo');

                if (!$file->isValid()) {
                    throw new \Exception('Profile photo upload failed.');
                }

                $extension = strtolower($file->getClientOriginalExtension());

                $fileName = 'u'
                    . $user->id
                    . '_'
                    . time()
                    . '_'
                    . uniqid()
                    . '.'
                    . $extension;

                $directory = 'uploads/users';

                Storage::disk('public')->putFileAs(
                    $directory,
                    $file,
                    $fileName
                );

                $profilePhotoPath = 'storage/' . $directory . '/' . $fileName;

                UserDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'user_id' => $user->id,
                        'profile_photo' => $profilePhotoPath,
                    ]
                );
            });

            $freshUser = User::find($user->id);

            return response()->json([
                'status' => true,
                'message' => 'Profile photo updated successfully.',
                'data' => $this->formatUserProfile($freshUser),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to update profile photo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Redis Upload Progress APIs
    |--------------------------------------------------------------------------
    */

    public function startDocumentUpload(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'aadhaar_number' => [
                'nullable',
                'digits:12',
                Rule::unique('user_details', 'aadhaar_number')->ignore($user->id, 'user_id'),
            ],
            'license_number' => ['nullable', 'string', 'max:200'],
            'rera_number' => ['nullable', 'string', 'max:50'],
            'total_files' => ['nullable', 'integer', 'min:1', 'max:3'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $uploadId = 'doc_' . $user->id . '_' . Str::uuid()->toString();

            DB::transaction(function () use ($request, $user) {
                $detailPayload = [
                    'user_id' => $user->id,
                ];

                foreach ([
                    'aadhaar_number',
                    'license_number',
                    'rera_number',
                ] as $column) {
                    if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $request->input($column);
                    }
                }

                if (count($detailPayload) > 1) {
                    UserDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        $detailPayload
                    );
                }

                if (Schema::hasColumn('users', 'kyc')) {
                    $user->update([
                        'kyc' => 1,
                    ]);
                }
            });

            $totalFiles = (int) $request->input('total_files', 3);

            $progress = [
                'upload_id' => $uploadId,
                'user_id' => (int) $user->id,
                'status' => 'started',
                'total_files' => $totalFiles,
                'queued_files' => 0,
                'processed_files' => 0,
                'failed_files' => 0,
                'percent' => 0,
                'files' => [
                    'aadhaar_front' => [
                        'status' => 'pending',
                        'percent' => 0,
                        'url' => null,
                        'error' => null,
                    ],
                    'aadhaar_back' => [
                        'status' => 'pending',
                        'percent' => 0,
                        'url' => null,
                        'error' => null,
                    ],
                    'business_proof' => [
                        'status' => 'pending',
                        'percent' => 0,
                        'url' => null,
                        'error' => null,
                    ],
                ],
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];

            Cache::store('redis')->put(
                $this->documentProgressKey($uploadId),
                $progress,
                now()->addHours(2)
            );

            return response()->json([
                'status' => true,
                'message' => 'Document upload session started successfully.',
                'upload_id' => $uploadId,
                'progress' => $progress,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to start document upload.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadDocumentFile(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'upload_id' => ['required', 'string'],
            'field' => ['required', Rule::in([
                'aadhaar_front',
                'aadhaar_back',
                'business_proof',
            ])],
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $uploadId = (string) $request->input('upload_id');
            $field = (string) $request->input('field');

            $progressKey = $this->documentProgressKey($uploadId);
            $progress = Cache::store('redis')->get($progressKey);

            if (!$progress) {
                return response()->json([
                    'status' => false,
                    'message' => 'Upload session not found or expired.',
                ], 404);
            }

            if ((int) ($progress['user_id'] ?? 0) !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'This upload session does not belong to current user.',
                ], 403);
            }

            $file = $request->file('file');

            if (!$file->isValid()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid uploaded file.',
                ], 422);
            }

            $extension = strtolower($file->getClientOriginalExtension());

            $tempFileName = 'temp_'
                . $user->id
                . '_'
                . time()
                . '_'
                . $field
                . '_'
                . uniqid()
                . '.'
                . $extension;

            $tempPath = Storage::disk('local')->putFileAs(
                'temp/document_uploads/' . $uploadId,
                $file,
                $tempFileName
            );

            $this->updateDocumentProgress($uploadId, function (array $progress) use ($field) {
                $progress['status'] = 'uploading';
                $progress['queued_files'] = (int) ($progress['queued_files'] ?? 0) + 1;
                $progress['files'][$field]['status'] = 'queued';
                $progress['files'][$field]['percent'] = 10;
                $progress['files'][$field]['error'] = null;
                $progress['updated_at'] = now()->toDateTimeString();

                return $progress;
            });

            ProcessUserDocumentUploadJob::dispatch(
                userId: (int) $user->id,
                uploadId: $uploadId,
                field: $field,
                tempPath: $tempPath,
                extension: $extension
            );

            return response()->json([
                'status' => true,
                'message' => $field . ' uploaded and queued for processing.',
                'upload_id' => $uploadId,
                'field' => $field,
                'progress' => Cache::store('redis')->get($progressKey),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to upload document file.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function documentUploadProgress(Request $request, string $uploadId): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        $progress = Cache::store('redis')->get($this->documentProgressKey($uploadId));

        if (!$progress) {
            return response()->json([
                'status' => false,
                'message' => 'Upload progress not found or expired.',
            ], 404);
        }

        if ((int) ($progress['user_id'] ?? 0) !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'This upload session does not belong to current user.',
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => 'Document upload progress fetched successfully.',
            'data' => $progress,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

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

        $detail = UserDetail::query()
            ->where('user_id', $user->id)
            ->first();

        $roleName = null;

        if (Schema::hasTable('roles') && !empty($user->role_id)) {
            $role = DB::table('roles')->where('id', $user->role_id)->first();
            $roleName = $role->name ?? $role->role_name ?? null;
        }

        $countryId = $this->valueFromModel($user, $detail, 'country_id');
        $stateId = $this->valueFromModel($user, $detail, 'state_id');
        $cityId = $this->valueFromModel($user, $detail, 'city_id');

        $countryName = $this->locationName('countries', $countryId);
        $stateName = $this->locationName('states', $stateId);
        $cityName = $this->locationName('cities', $cityId);

        $profilePhoto = $this->fileUrl($detail?->profile_photo);
        $aadhaarFront = $this->fileUrl($detail?->aadhaar_front);
        $aadhaarBack = $this->fileUrl($detail?->aadhaar_back);
        $businessProof = $this->fileUrl($detail?->business_proof);

        $firstName = $user->first_name ?? null;
        $lastName = $user->last_name ?? null;

        $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

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
            'kyc_status' => $this->kycStatusLabel($user->kyc ?? 0),
            'is_otp_verified' => $user->is_otp_verified ?? false,

            'country_id' => $countryId,
            'state_id' => $stateId,
            'city_id' => $cityId,
            'country' => $countryName,
            'state' => $stateName,
            'city' => $cityName,
            'address' => $detail?->address ?? null,
            'pin_code' => $detail?->pin_code ?? null,

            'bussiness_name' => $detail?->bussiness_name ?? null,
            'business_phone' => $detail?->business_phone ?? null,
            'bussiness_email' => $detail?->bussiness_email ?? null,
            'bussiness_address' => $detail?->bussiness_address ?? null,

            'profile_photo' => $profilePhoto,
            'aadhaar_number' => $detail?->aadhaar_number ?? null,
            'aadhaar_front' => $aadhaarFront,
            'aadhaar_back' => $aadhaarBack,
            'business_proof' => $businessProof,
            'license_number' => $detail?->license_number ?? null,
            'rera_number' => $detail?->rera_number ?? null,
            'alternate_number' => $detail?->alternate_number ?? null,
            'no_of_employees' => $detail?->no_of_employees ?? null,
            'about_us' => $detail?->about_us ?? null,

            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        return [
            'raw' => $raw,
            'display' => collect($raw)
                ->map(fn ($value) => $this->dash($value))
                ->toArray(),
            'profile_completion' => $this->profileCompletion($raw),
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

    private function fileUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url($path);
    }

    private function dash(mixed $value): mixed
    {
        if (is_null($value) || $value === '' || $value === 'N/A') {
            return '-';
        }

        return $value;
    }

    private function profileCompletion(array $data): array
    {
        $fields = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'country_id',
            'state_id',
            'city_id',
            'address',
            'pin_code',
            'profile_photo',
            'aadhaar_number',
            'aadhaar_front',
            'aadhaar_back',
            'business_proof',
        ];

        $completed = 0;

        foreach ($fields as $field) {
            if (!empty($data[$field]) && $data[$field] !== '-') {
                $completed++;
            }
        }

        $percentage = count($fields) > 0
            ? round(($completed / count($fields)) * 100)
            : 0;

        return [
            'percentage' => $percentage,
            'completed_fields' => $completed,
            'total_fields' => count($fields),
            'missing_fields' => collect($fields)
                ->filter(fn ($field) => empty($data[$field]) || $data[$field] === '-')
                ->values()
                ->toArray(),
        ];
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

    private function kycStatusLabel(mixed $status): string
    {
        return match ((int) $status) {
            0 => 'Pending',
            1 => 'In Progress',
            2 => 'Approved',
            3 => 'Rejected',
            default => 'Pending',
        };
    }

    private function documentProgressKey(string $uploadId): string
    {
        return 'user_document_upload:' . $uploadId;
    }

    private function updateDocumentProgress(string $uploadId, callable $callback): array
    {
        $key = $this->documentProgressKey($uploadId);
        $lockKey = $key . ':lock';

        return Cache::store('redis')->lock($lockKey, 10)->block(5, function () use ($key, $callback) {
            $progress = Cache::store('redis')->get($key, []);

            $progress = $callback($progress);

            Cache::store('redis')->put($key, $progress, now()->addHours(2));

            return $progress;
        });
    }

    private function validationResponse($validator): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }
}