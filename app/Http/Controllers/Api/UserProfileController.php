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

        $isOwnerRole = $this->isOwnerUser($user);

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

                foreach (
                    [
                        'first_name',
                        'last_name',
                        'email',
                        'phone',
                        'user_name',
                    ] as $column
                ) {
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

                $detailColumns = [
                    'alternate_number',
                    'no_of_employees',
                    'about_us',
                ];

                if (!$isOwnerRole) {
                    $detailColumns = array_merge($detailColumns, [
                        'bussiness_name',
                        'business_phone',
                        'bussiness_email',
                    ]);
                }

                $detailPayload = [
                    'user_id' => $user->id,
                ];

                foreach ($detailColumns as $column) {
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
            $rules['business_pin_code'] = ['nullable', 'string', 'max:20'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            DB::transaction(function () use ($request, $user, $isOwnerRole) {
                $detailPayload = [
                    'user_id' => $user->id,
                ];

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
                    if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $request->input($column);
                    }
                }

                $missingSeparateColumns = !Schema::hasColumn('user_details', 'street_address')
                    || !Schema::hasColumn('user_details', 'area_locality')
                    || !Schema::hasColumn('user_details', 'colony');

                if (
                    $missingSeparateColumns
                    && Schema::hasColumn('user_details', 'address')
                    && (
                        $request->filled('street_address')
                        || $request->filled('colony')
                        || $request->filled('area_locality')
                        || $request->filled('address')
                    )
                ) {
                    $detailPayload['address'] = collect([
                        $request->input('street_address'),
                        $request->input('colony'),
                        $request->input('area_locality'),
                        $request->input('address'),
                    ])->filter()->values()->implode(', ');
                }

                if (!$isOwnerRole) {
                    if ($request->has('bussiness_address') && Schema::hasColumn('user_details', 'bussiness_address')) {
                        $detailPayload['bussiness_address'] = $request->input('bussiness_address');
                    }
                }

                if (count($detailPayload) > 1) {
                    UserDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        $detailPayload
                    );
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

                foreach (
                    [
                        'aadhaar_number',
                        'license_number',
                        'rera_number',
                    ] as $column
                ) {
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

                        $this->ensurePublicDirectory($directory);

                        $file->move(public_path($directory), $fileName);

                        $filePath = $directory . '/' . $fileName;

                        if (!file_exists(public_path($filePath))) {
                            throw new \Exception($field . ' could not be saved in public uploads.');
                        }

                        $detailPayload[$field] = $filePath;
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
            $file = $request->file('profile_photo');

            if (!$file || !$file->isValid()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid profile photo file.',
                ], 422);
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

            $this->ensurePublicDirectory($directory);

            $file->move(public_path($directory), $fileName);

            $profilePhotoPath = $directory . '/' . $fileName;

            if (!file_exists(public_path($profilePhotoPath))) {
                throw new \Exception('Profile photo could not be saved in public uploads.');
            }

            DB::transaction(function () use ($user, $profilePhotoPath) {
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
                'profile_photo' => $this->fileUrl($profilePhotoPath),
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

                foreach (
                    [
                        'aadhaar_number',
                        'license_number',
                        'rera_number',
                    ] as $column
                ) {
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

        $allowedFields = $this->documentUploadFieldsForUser($user);

        $validator = Validator::make($request->all(), [
            'upload_id' => ['nullable', 'string'],
            'field' => ['required', Rule::in(array_keys($allowedFields))],
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],
            'aadhaar_number' => [
                'nullable',
                'digits:12',
                Rule::unique('user_details', 'aadhaar_number')->ignore($user->id, 'user_id'),
            ],
            'license_number' => ['nullable', 'string', 'max:200'],
            'rera_number' => ['nullable', 'string', 'max:50'],
            'total_files' => ['nullable', 'integer', 'min:1', 'max:' . count($allowedFields)],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $field = (string) $request->input('field');

            /*
        |--------------------------------------------------------------------------
        | Auto Generate Upload ID
        |--------------------------------------------------------------------------
        | Now frontend does not need to call documents/start API.
        |--------------------------------------------------------------------------
        */
            $uploadId = $request->filled('upload_id')
                ? (string) $request->input('upload_id')
                : 'doc_' . $user->id . '_' . Str::uuid()->toString();

            $progressKey = $this->documentProgressKey($uploadId);
            $progress = Cache::store('redis')->get($progressKey);

            if ($request->filled('upload_id') && !$progress) {
                return response()->json([
                    'status' => false,
                    'message' => 'Upload session not found or expired.',
                ], 404);
            }

            if ($progress && (int) ($progress['user_id'] ?? 0) !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'This upload session does not belong to current user.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | Create Progress Session Automatically
        |--------------------------------------------------------------------------
        */
            if (!$progress) {
                $totalFiles = (int) $request->input('total_files', count($allowedFields));

                $progress = $this->initialDocumentUploadProgress(
                    uploadId: $uploadId,
                    user: $user,
                    totalFiles: $totalFiles,
                    allowedFields: $allowedFields
                );

                Cache::store('redis')->put(
                    $progressKey,
                    $progress,
                    now()->addHours(2)
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Save Document Text Fields
        |--------------------------------------------------------------------------
        */
            DB::transaction(function () use ($request, $user) {
                $detailPayload = [
                    'user_id' => $user->id,
                ];

                foreach (
                    [
                        'aadhaar_number',
                        'license_number',
                        'rera_number',
                    ] as $column
                ) {
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

            $file = $request->file('file');

            if (!$file || !$file->isValid()) {
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

            $this->updateDocumentProgress($uploadId, function (array $progress) use ($field, $allowedFields) {
                foreach ($allowedFields as $allowedField => $label) {
                    $progress['files'][$allowedField] = $progress['files'][$allowedField] ?? [
                        'status' => 'pending',
                        'percent' => 0,
                        'url' => null,
                        'error' => null,
                    ];
                }

                $progress['status'] = 'uploading';
                $progress['queued_files'] = (int) ($progress['queued_files'] ?? 0) + 1;
                $progress['files'][$field]['status'] = 'queued';
                $progress['files'][$field]['percent'] = 10;
                $progress['files'][$field]['error'] = null;
                $progress['updated_at'] = now()->toDateTimeString();

                return $progress;
            });

            ProcessUserDocumentUploadJob::dispatch(
                (int) $user->id,
                $uploadId,
                $field,
                $tempPath,
                $extension
            );

            return response()->json([
                'status' => true,
                'message' => $field . ' uploaded and queued for processing.',
                'upload_id' => $uploadId,
                'field' => $field,
                'allowed_fields' => array_keys($allowedFields),
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
    private function documentUploadFieldsForUser(User $user): array
    {
        $fields = [
            'aadhaar_front' => 'Aadhaar Front',
            'aadhaar_back' => 'Aadhaar Back',
            'business_proof' => 'Business Proof',
        ];

        if ($this->isOwnerUser($user)) {
            unset($fields['business_proof']);
        }

        return $fields;
    }

    private function initialDocumentUploadProgress(
        string $uploadId,
        User $user,
        int $totalFiles,
        array $allowedFields
    ): array {
        $totalFiles = max(1, min($totalFiles, count($allowedFields)));

        $files = [];

        foreach ($allowedFields as $field => $label) {
            $files[$field] = [
                'status' => 'pending',
                'percent' => 0,
                'url' => null,
                'error' => null,
            ];
        }

        return [
            'upload_id' => $uploadId,
            'user_id' => (int) $user->id,
            'status' => 'started',
            'total_files' => $totalFiles,
            'queued_files' => 0,
            'processed_files' => 0,
            'failed_files' => 0,
            'percent' => 0,
            'files' => $files,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
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

        $dashboardCounts = $this->profileDashboardCounts($user);
        $isOwnerRole = $this->isOwnerUser($user);

        $streetAddress = $this->detailValue($detail, 'street_address');
        $areaLocality = $this->detailValue($detail, 'area_locality');
        $colony = $this->detailValue($detail, 'colony');
        $address = $detail?->address ?? null;
        $pinCode = $detail?->pin_code ?? null;

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
            'kyc_status' => $this->kycStatusLabel($user->kyc ?? 0),
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
            'aadhaar_number' => $detail?->aadhaar_number ?? null,
            'aadhaar_front' => $aadhaarFront,
            'aadhaar_back' => $aadhaarBack,
            'business_proof' => $businessProof,
            'license_number' => $detail?->license_number ?? null,
            'rera_number' => $detail?->rera_number ?? null,
            'alternate_number' => $detail?->alternate_number ?? null,
            'no_of_employees' => $detail?->no_of_employees ?? null,
            'about_us' => $detail?->about_us ?? null,

            'business_fields_visible' => !$isOwnerRole,

            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        if (!$isOwnerRole) {
            $raw['bussiness_name'] = $detail?->bussiness_name ?? null;
            $raw['business_phone'] = $detail?->business_phone ?? null;
            $raw['bussiness_email'] = $detail?->bussiness_email ?? null;
            $raw['bussiness_address'] = $detail?->bussiness_address ?? null;

            $raw['business_country'] = $countryName;
            $raw['business_state'] = $stateName;
            $raw['business_city'] = $cityName;
        }

        $display = collect($raw)
            ->map(fn($value) => is_array($value) ? $this->dashArray($value) : $this->dash($value))
            ->toArray();

        unset(
            $display['country_id'],
            $display['state_id'],
            $display['city_id']
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

        return url($path);
    }

    private function ensurePublicDirectory(string $directory): void
    {
        $path = public_path($directory);

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
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
        $fields = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'country_id',
            'state_id',
            'city_id',
            'street_address',
            'area_locality',
            'colony',
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
                ->filter(fn($field) => empty($data[$field]) || $data[$field] === '-')
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

    private function isOwnerUser(User $user): bool
    {
        $directRole = strtolower(trim((string) ($user->role_id ?? '')));
        $directRole = str_replace([' ', '_', '-'], '', $directRole);

        if (in_array($directRole, [
            'owner',
            'propertyowner',
            'landowner',
        ], true)) {
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

        return in_array($roleText, [
            'owner',
            'propertyowner',
            'landowner',
        ], true);
    }

    private function profileDashboardCounts(User $user): array
    {
        $listingIds = $this->userListingIds($user);

        return [
            'total_listings' => count($listingIds),
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

    private function userListingIds(User $user): array
    {
        if (!Schema::hasTable('dynamic_posts')) {
            return [];
        }

        $query = DB::table('dynamic_posts')
            ->select('dynamic_posts.id');

        $hasUserFilter = false;

        $query->where(function ($q) use ($user, &$hasUserFilter) {
            foreach (
                [
                    'author_id',
                    'user_id',
                    'owner_id',
                    'created_by',
                ] as $column
            ) {
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
                    foreach (
                        [
                            'dynamic_post_id',
                            'listing_id',
                            'property_listing_id',
                            'post_id',
                        ] as $column
                    ) {
                        if (Schema::hasColumn($table, $column)) {
                            $q->orWhereIn($column, $listingIds);
                            $hasFilter = true;
                        }
                    }
                }

                if (!empty($user->email)) {
                    foreach (
                        [
                            'email',
                            'user_email',
                            'lead_email',
                        ] as $column
                    ) {
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
