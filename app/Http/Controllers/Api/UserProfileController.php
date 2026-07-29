<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessUserDocumentUploadJob;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;
use App\Models\DynamicPost;
use App\Models\KycRequest;
use App\Models\KycDocument;

class UserProfileController extends Controller
{
    private const MAX_UPLOAD_KB = 2048;
    public function show(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
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

        // Accept Aadhaar number from personal-profile forms as well as KYC forms.
        // This is important when the frontend sends Aadhaar with "Save Changes".
        $this->normalizeKycRequest($request);

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

            // "sometimes" means it is validated only when any supported Aadhaar
            // field/alias was actually included in the request.
            'aadhaar_number' => [
                'sometimes',
                'nullable',
                'digits:12',
                Rule::unique('user_details', 'aadhaar_number')->ignore($user->id, 'user_id'),
            ],
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
            'aadhaar_number.digits' => 'Aadhaar number must contain exactly 12 digits.',
            'aadhaar_number.unique' => 'This Aadhaar number is already linked with another user.',
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

                $detailColumns = [
                    'alternate_number',
                    'no_of_employees',
                    'about_us',
                    'aadhaar_number',
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
                    // Use the database-level persistence helper so Aadhaar and
                    // other user_details fields cannot be skipped by model settings.
                    $this->persistUserDetailPayload($user, $detailPayload);
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

        $validator = Validator::make($request->all(), $rules, [
            'aadhaar_front.max' => 'Aadhaar front must not be greater than 2MB.',
            'aadhaar_back.max' => 'Aadhaar back must not be greater than 2MB.',
            'business_proof.max' => 'Business proof must not be greater than 2MB.',
        ]);

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
                    foreach (
                        [
                            'business_country_id',
                            'business_state_id',
                            'business_city_id',
                            'bussiness_address',
                            'business_pin_code',
                        ] as $column
                    ) {
                        if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                            $detailPayload[$column] = $request->input($column);
                        }
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
                $detail = UserDetail::query()
                    ->where('user_id', $user->id)
                    ->first();

                $oldPath = $detail?->profile_photo;

                UserDetail::updateOrCreate(
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

    public function updateDocuments(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token.',
            ], 401);
        }
        $this->normalizeKycRequest($request);
        $allowedFields = $this->documentUploadFieldsForUser($user);

        $rules = [
            'aadhaar_number' => [
                'nullable',
                'digits:12',
                Rule::unique('user_details', 'aadhaar_number')->ignore($user->id, 'user_id'),
            ],
        ];

        if (!$this->isOwnerUser($user)) {
            $rules['license_number'] = ['nullable', 'string', 'max:200'];
            $rules['rera_number'] = ['nullable', 'string', 'max:50'];
        }

        foreach ($allowedFields as $field => $label) {
            $rules[$field] = [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ];
        }

        $validator = Validator::make($request->all(), $rules, [
            'aadhaar_front.max' => 'Aadhaar front must not be greater than 2MB.',
            'aadhaar_back.max' => 'Aadhaar back must not be greater than 2MB.',
            'business_proof.max' => 'Business proof must not be greater than 2MB.',
        ]);


        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $folders = [
            'aadhaar_front' => 'kyc/aadhaarFront',
            'aadhaar_back' => 'kyc/aadhaarBack',
            'business_proof' => 'kyc/businessProof',
        ];

        $newFilePaths = [];
        $oldFilePaths = [];

        try {
            foreach ($allowedFields as $field => $label) {
                if ($request->hasFile($field) && Schema::hasColumn('user_details', $field)) {
                    $newFilePaths[$field] = $this->storePublicUpload(
                        file: $request->file($field),
                        folder: $folders[$field],
                        prefix: 'u' . $user->id . '_' . $field
                    );
                }
            }

            DB::transaction(function () use ($request, $user, $newFilePaths, &$oldFilePaths) {
                $detail = UserDetail::query()
                    ->where('user_id', $user->id)
                    ->first();

                $detailPayload = $this->kycMetaPayload($request, $user);

                foreach ($newFilePaths as $field => $path) {
                    $oldFilePaths[$field] = $detail?->{$field};
                    $detailPayload[$field] = $path;
                }

                if (count($detailPayload) > 1) {
                    $this->persistUserDetailPayload($user, $detailPayload);
                }

                if (Schema::hasColumn('users', 'kyc')) {
                    $user->update([
                        'kyc' => 1,
                    ]);
                }
            });

            foreach ($oldFilePaths as $oldPath) {
                $this->deletePublicUpload($oldPath);
            }

            $freshUser = User::find($user->id);

            return response()->json([
                'status' => true,
                'message' => 'Documents updated successfully.',
                'data' => $this->formatUserProfile($freshUser),
            ]);
        } catch (Throwable $e) {
            foreach ($newFilePaths as $newPath) {
                $this->deletePublicUpload($newPath);
            }

            return response()->json([
                'status' => false,
                'message' => 'Unable to update documents.',
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
        $this->normalizeKycRequest($request);
        $allowedFields = $this->documentUploadFieldsForUser($user);

        $rules = [
            'aadhaar_number' => [
                'nullable',
                'digits:12',
                Rule::unique('user_details', 'aadhaar_number')->ignore($user->id, 'user_id'),
            ],
            'total_files' => ['nullable', 'integer', 'min:1', 'max:' . count($allowedFields)],
        ];

        if (!$this->isOwnerUser($user)) {
            $rules['license_number'] = ['nullable', 'string', 'max:200'];
            $rules['rera_number'] = ['nullable', 'string', 'max:50'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $uploadId = 'doc_' . $user->id . '_' . Str::uuid()->toString();

            DB::transaction(function () use ($request, $user) {
                $detailPayload = $this->kycMetaPayload($request, $user);
                if (count($detailPayload) > 1) {
                    $this->persistUserDetailPayload($user, $detailPayload);
                }

                if (Schema::hasColumn('users', 'kyc')) {
                    $user->update([
                        'kyc' => 1,
                    ]);
                }
            });

            $progress = $this->initialDocumentUploadProgress(
                uploadId: $uploadId,
                user: $user,
                totalFiles: (int) $request->input('total_files', count($allowedFields)),
                allowedFields: $allowedFields
            );

            $this->cacheStore()->put(
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
        $this->normalizeKycRequest($request);
        $allowedFields = $this->documentUploadFieldsForUser($user);

        $rules = [
            'upload_id' => ['nullable', 'string'],
            'field' => ['required', Rule::in(array_keys($allowedFields))],
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:' . self::MAX_UPLOAD_KB,
            ],

            'aadhaar_number' => [
                'nullable',
                'digits:12',
                Rule::unique('user_details', 'aadhaar_number')->ignore($user->id, 'user_id'),
            ],
            'total_files' => ['nullable', 'integer', 'min:1', 'max:' . count($allowedFields)],
        ];

        if (!$this->isOwnerUser($user)) {
            $rules['license_number'] = ['nullable', 'string', 'max:200'];
            $rules['rera_number'] = ['nullable', 'string', 'max:50'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        $field = (string) $request->input('field');

        $folders = [
            'aadhaar_front' => 'kyc/aadhaarFront',
            'aadhaar_back' => 'kyc/aadhaarBack',
            'business_proof' => 'kyc/businessProof',
        ];

        $uploadId = $request->filled('upload_id')
            ? (string) $request->input('upload_id')
            : 'doc_' . $user->id . '_' . Str::uuid()->toString();

        $progressKey = $this->documentProgressKey($uploadId);
        $progress = $this->cacheStore()->get($progressKey);

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

        if (!$progress) {
            $progress = $this->initialDocumentUploadProgress(
                uploadId: $uploadId,
                user: $user,
                totalFiles: (int) $request->input('total_files', count($allowedFields)),
                allowedFields: $allowedFields
            );

            $this->cacheStore()->put(
                $progressKey,
                $progress,
                now()->addHours(2)
            );
        }

        $newPath = null;
        $oldPath = null;

        try {
            $this->updateDocumentProgress($uploadId, function (array $progress) use ($uploadId, $user, $allowedFields, $field) {
                $progress = $this->ensureDocumentProgressStructure($progress, $uploadId, $user, $allowedFields);

                $progress['files'][$field] = [
                    'status' => 'processing',
                    'percent' => 50,
                    'url' => null,
                    'error' => null,
                ];

                $progress['updated_at'] = now()->toDateTimeString();

                return $this->syncDocumentProgressCounters($progress);
            });

            $file = $request->file('file');

            if (!$file || !$file->isValid()) {
                throw new \Exception('Invalid uploaded file.');
            }

            $extension = strtolower(
                $file->getClientOriginalExtension()
                    ?: $file->extension()
                    ?: 'bin'
            );

            $fileName = 'u'
                . $user->id
                . '_'
                . now()->format('YmdHis')
                . '_'
                . $field
                . '_'
                . Str::random(8)
                . '.'
                . $extension;

            $storedPath = Storage::disk('public_uploads')->putFileAs(
                $folders[$field],
                $file,
                $fileName
            );

            if (!$storedPath || !Storage::disk('public_uploads')->exists($storedPath)) {
                throw new \Exception('Document file could not be saved.');
            }

            $newPath = 'uploads/' . $storedPath;

            DB::transaction(function () use ($request, $user, $field, $newPath, &$oldPath) {
                $detail = UserDetail::query()
                    ->where('user_id', $user->id)
                    ->first();

                $oldPath = $detail?->{$field};

                $detailPayload = $this->kycMetaPayload($request, $user);

                if (Schema::hasColumn('user_details', $field)) {
                    $detailPayload[$field] = $newPath;
                }

                $this->persistUserDetailPayload($user, $detailPayload);

                if (Schema::hasColumn('users', 'kyc')) {
                    $user->update([
                        'kyc' => 1,
                    ]);
                }
            });

            $this->deletePublicUpload($oldPath);

            $this->updateDocumentProgress($uploadId, function (array $progress) use ($uploadId, $user, $allowedFields, $field, $newPath) {
                $progress = $this->ensureDocumentProgressStructure($progress, $uploadId, $user, $allowedFields);

                $progress['files'][$field] = [
                    'status' => 'completed',
                    'percent' => 100,
                    'url' => $this->fileUrl($newPath),
                    'error' => null,
                ];

                $progress['updated_at'] = now()->toDateTimeString();

                return $this->syncDocumentProgressCounters($progress);
            });

            return response()->json([
                'status' => true,
                'message' => $field . ' uploaded successfully.',
                'upload_id' => $uploadId,
                'field' => $field,
                'allowed_fields' => array_keys($allowedFields),
                'file_url' => $this->fileUrl($newPath),
                'progress' => $this->cacheStore()->get($progressKey),
                'data' => $this->formatUserProfile(User::find($user->id)),
            ]);
        } catch (Throwable $e) {
            $this->deletePublicUpload($newPath);

            $this->updateDocumentProgress($uploadId, function (array $progress) use ($uploadId, $user, $allowedFields, $field, $e) {
                $progress = $this->ensureDocumentProgressStructure($progress, $uploadId, $user, $allowedFields);

                $progress['files'][$field] = [
                    'status' => 'failed',
                    'percent' => 0,
                    'url' => null,
                    'error' => $e->getMessage(),
                ];

                $progress['updated_at'] = now()->toDateTimeString();

                return $this->syncDocumentProgressCounters($progress);
            });

            return response()->json([
                'status' => false,
                'message' => 'Unable to upload document file.',
                'error' => $e->getMessage(),
                'progress' => $this->cacheStore()->get($progressKey),
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

        $progress = $this->cacheStore()->get($this->documentProgressKey($uploadId));

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

        $firstName = $user->first_name ?? null;
        $lastName = $user->last_name ?? null;
        $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

        $dashboardCounts = $this->profileDashboardCounts($user);
        $isOwnerRole = $this->isOwnerUser($user);
        $kycSummary = $this->kycModuleSummary($user);

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
            'kyc_status' => $kycSummary['status_label'],
            'admin_kyc_status' => $kycSummary['admin_status'],
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

            'alternate_number' => $detail?->alternate_number ?? null,
            'no_of_employees' => $detail?->no_of_employees ?? null,
            'about_us' => $detail?->about_us ?? null,

            'business_fields_visible' => !$isOwnerRole,

            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        if (!$isOwnerRole) {
            $businessCountryId = $this->detailValue($detail, 'business_country_id') ?: $countryId;
            $businessStateId = $this->detailValue($detail, 'business_state_id') ?: $stateId;
            $businessCityId = $this->detailValue($detail, 'business_city_id') ?: $cityId;

            $raw['bussiness_name'] = $detail?->bussiness_name ?? null;
            $raw['business_phone'] = $detail?->business_phone ?? null;
            $raw['bussiness_email'] = $detail?->bussiness_email ?? null;
            $raw['bussiness_address'] = $detail?->bussiness_address ?? null;
            $raw['business_pin_code'] = $this->detailValue($detail, 'business_pin_code');

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
            'no_of_employees' => [
                'label' => 'Number of Employees',
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

    private function documentProgressKey(string $uploadId): string
    {
        return 'user_document_upload:' . $uploadId;
    }

    private function cacheStore()
    {
        try {
            return Cache::store(env('DOCUMENT_UPLOAD_CACHE_STORE', 'redis'));
        } catch (Throwable $e) {
            return Cache::store(config('cache.default'));
        }
    }

    private function updateDocumentProgress(string $uploadId, callable $callback): array
    {
        $key = $this->documentProgressKey($uploadId);
        $store = $this->cacheStore();

        try {
            if (method_exists($store, 'lock')) {
                return $store->lock($key . ':lock', 10)->block(5, function () use ($store, $key, $callback) {
                    $progress = $store->get($key, []);
                    $progress = $callback(is_array($progress) ? $progress : []);
                    $store->put($key, $progress, now()->addHours(2));

                    return $progress;
                });
            }
        } catch (Throwable $e) {
            // fallback below
        }

        $progress = $store->get($key, []);
        $progress = $callback(is_array($progress) ? $progress : []);
        $store->put($key, $progress, now()->addHours(2));

        return $progress;
    }

    private function validationResponse($validator): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }
    private function ensureDocumentProgressStructure(
        array $progress,
        string $uploadId,
        User $user,
        array $allowedFields
    ): array {
        $progress['upload_id'] = $progress['upload_id'] ?? $uploadId;
        $progress['user_id'] = (int) ($progress['user_id'] ?? $user->id);
        $progress['status'] = $progress['status'] ?? 'started';
        $progress['total_files'] = max(
            1,
            min((int) ($progress['total_files'] ?? count($allowedFields)), count($allowedFields))
        );

        $progress['queued_files'] = (int) ($progress['queued_files'] ?? 0);
        $progress['processed_files'] = (int) ($progress['processed_files'] ?? 0);
        $progress['failed_files'] = (int) ($progress['failed_files'] ?? 0);
        $progress['percent'] = (int) ($progress['percent'] ?? 0);
        $progress['files'] = is_array($progress['files'] ?? null) ? $progress['files'] : [];

        foreach ($allowedFields as $field => $label) {
            $progress['files'][$field] = $progress['files'][$field] ?? [
                'status' => 'pending',
                'percent' => 0,
                'url' => null,
                'error' => null,
            ];
        }

        $progress['files'] = array_intersect_key($progress['files'], $allowedFields);

        $progress['created_at'] = $progress['created_at'] ?? now()->toDateTimeString();
        $progress['updated_at'] = $progress['updated_at'] ?? now()->toDateTimeString();

        return $progress;
    }

    private function syncDocumentProgressCounters(array $progress): array
    {
        $files = $progress['files'] ?? [];

        $queued = 0;
        $processed = 0;
        $failed = 0;

        foreach ($files as $file) {
            $status = $file['status'] ?? 'pending';

            if (in_array($status, ['queued', 'uploading', 'processing'], true)) {
                $queued++;
            }

            if ($status === 'completed') {
                $processed++;
            }

            if ($status === 'failed') {
                $failed++;
            }
        }

        $total = max(1, (int) ($progress['total_files'] ?? count($files) ?: 1));
        $done = min($total, $processed + $failed);

        $progress['queued_files'] = $queued;
        $progress['processed_files'] = $processed;
        $progress['failed_files'] = $failed;
        $progress['percent'] = min(100, (int) round(($done / $total) * 100));

        if ($done >= $total) {
            $progress['status'] = $failed > 0 ? 'completed_with_errors' : 'completed';
        } elseif ($queued > 0) {
            $progress['status'] = 'processing';
        } else {
            $progress['status'] = 'started';
        }

        return $progress;
    }
    /**
     * Normalize Aadhaar input from JSON, form-data, URL-encoded forms,
     * nested payloads, spelling variations, spaces and hyphens.
     *
     * The normalized value is always merged as "aadhaar_number", so all
     * validation and persistence code works with one canonical field name.
     */
    private function normalizeKycRequest(Request $request): void
    {
        $acceptedKeys = [
            // Correct spellings
            'aadhaar_number',
            'aadhaar_no',
            'aadhaar',
            'aadhaar_card_number',
            'aadhaar_card_no',
            'aadhaarNumber',
            'aadhaarNo',
            'aadhaarCardNumber',
            'aadhaarCardNo',

            // Common spellings used by frontends/forms
            'aadhar_number',
            'aadhar_no',
            'aadhar',
            'aadhar_card_number',
            'aadhar_card_no',
            'aadharNumber',
            'aadharNo',
            'aadharCardNumber',
            'aadharCardNo',

            'adhaar_number',
            'adhaar_no',
            'adhaar',
            'adhaar_card_number',
            'adhaar_card_no',
            'adhaarNumber',
            'adhaarNo',
            'adhaarCardNumber',

            'adhar_number',
            'adhar_no',
            'adhar',
            'adhar_card_number',
            'adhar_card_no',
            'adharNumber',
            'adharNo',
            'adharCardNumber',

            'addhar_number',
            'addhar_no',
            'addhar',
            'addhar_card_number',
            'addhar_card_no',
            'addharNumber',
            'addharNo',
            'addharCardNumber',
        ];

        // Compare keys case-insensitively and ignore spaces, underscores and hyphens.
        // For example: "Aadhaar Number", "aadhaar-number" and "aadhaar_number"
        // are treated as the same key.
        $normalizedAcceptedKeys = collect($acceptedKeys)
            ->mapWithKeys(function (string $key): array {
                return [$this->normalizeAadhaarInputKey($key) => true];
            })
            ->all();

        $foundAnyAlias = false;
        $resolvedValue = null;

        foreach ($this->flattenRequestInput($request->all()) as $key => $value) {
            $leafKey = str_contains($key, '.')
                ? (string) Str::afterLast($key, '.')
                : $key;

            if (!isset($normalizedAcceptedKeys[$this->normalizeAadhaarInputKey($leafKey)])) {
                continue;
            }

            $foundAnyAlias = true;

            // Do not stop at an empty canonical field. Some frontend payloads
            // accidentally send aadhaar_number="" plus a populated alias.
            if ($value === null || is_array($value) || $value instanceof UploadedFile) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', trim((string) $value));

            if ($digits !== '') {
                $resolvedValue = $digits;
                break;
            }
        }

        if ($foundAnyAlias) {
            $request->merge([
                'aadhaar_number' => $resolvedValue,
            ]);
        }
    }

    /**
     * Flatten nested request data while retaining the full dotted key path.
     */
    private function flattenRequestInput(array $input, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($input as $key => $value) {
            $fullKey = $prefix === ''
                ? (string) $key
                : $prefix . '.' . $key;

            if (is_array($value)) {
                $flattened += $this->flattenRequestInput($value, $fullKey);
                continue;
            }

            $flattened[$fullKey] = $value;
        }

        return $flattened;
    }

    /**
     * Convert an input key into a comparable form.
     */
    private function normalizeAadhaarInputKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($key)));
    }

    /**
     * Persist user_details without depending on UserDetail::$fillable.
     * This prevents aadhaar_number and other KYC fields from being silently
     * skipped when they are not listed as mass-assignable on the model.
     */
    private function persistUserDetailPayload(User $user, array $payload): void
    {
        if (!Schema::hasTable('user_details') || !Schema::hasColumn('user_details', 'user_id')) {
            throw new \RuntimeException('The user_details table or user_id column is missing.');
        }

        $allowedPayload = [
            'user_id' => $user->id,
        ];

        foreach ($payload as $column => $value) {
            if ($column === 'id' || $column === 'user_id') {
                continue;
            }

            if (Schema::hasColumn('user_details', $column)) {
                $allowedPayload[$column] = $value;
            }
        }

        if (count($allowedPayload) <= 1) {
            return;
        }

        if (Schema::hasColumn('user_details', 'updated_at')) {
            $allowedPayload['updated_at'] = now();
        }

        $existing = DB::table('user_details')
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            $updatePayload = $allowedPayload;
            unset($updatePayload['user_id']);

            DB::table('user_details')
                ->where('user_id', $user->id)
                ->update($updatePayload);

            return;
        }

        if (Schema::hasColumn('user_details', 'created_at')) {
            $allowedPayload['created_at'] = now();
        }

        DB::table('user_details')->insert($allowedPayload);
    }

    private function kycMetaPayload(Request $request, User $user): array
    {
        $payload = [
            'user_id' => $user->id,
        ];

        $columns = ['aadhaar_number'];

        if (!$this->isOwnerUser($user)) {
            $columns[] = 'license_number';
            $columns[] = 'rera_number';
        }

        foreach ($columns as $column) {
            if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                $payload[$column] = $request->input($column);
            }
        }

        return $payload;
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
            'status_label' => 'Not Started',
            'admin_status' => 'Not Submitted',
            'documents_count' => 0,
            'pending_documents_count' => 0,
            'approved_documents_count' => 0,
            'rejected_documents_count' => 0,
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

        if (!$kycRequest) {
            return $default;
        }

        $status = strtolower((string) ($kycRequest->status ?? 'pending'));

        $summary = [
            'request_id' => (int) $kycRequest->id,
            'status' => $status,
            'status_label' => $this->kycModuleStatusLabel($status),
            'admin_status' => $this->adminKycStatusLabel($status),

            'documents_count' => 0,
            'pending_documents_count' => 0,
            'approved_documents_count' => 0,
            'rejected_documents_count' => 0,

            'submitted_at' => optional($kycRequest->submitted_at ?? null)->toDateTimeString(),
            'approved_at' => optional($kycRequest->approved_at ?? null)->toDateTimeString(),
            'rejected_at' => optional($kycRequest->rejected_at ?? null)->toDateTimeString(),

            'rejection_reason' => $kycRequest->rejection_reason
                ?? $kycRequest->reject_reason
                ?? null,

            'can_submit' => in_array($status, ['draft', 'pending', 'not_started'], true),
            'can_resubmit' => in_array($status, ['rejected'], true),
        ];

        if (Schema::hasTable('kyc_documents')) {
            $documentQuery = KycDocument::query()
                ->where('kyc_request_id', (int) $kycRequest->id);

            $summary['documents_count'] = (clone $documentQuery)->count();
            $summary['pending_documents_count'] = (clone $documentQuery)->where('status', 'pending')->count();
            $summary['approved_documents_count'] = (clone $documentQuery)->where('status', 'approved')->count();
            $summary['rejected_documents_count'] = (clone $documentQuery)->where('status', 'rejected')->count();
        }

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
            default => 'Not Started',
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
