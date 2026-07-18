<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
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

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'user_name' => [
                'nullable',
                'string',
                'min:3',
                'max:20',
                'regex:/^[a-zA-Z0-9._]+$/',
                Rule::unique('users', 'user_name')->ignore($user->id),
            ],
            'email' => [
                'nullable',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => [
                'nullable',
                'regex:/^[0-9]{10}$/',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'alternate_number' => ['nullable', 'string', 'max:20'],
            'no_of_employees' => ['nullable', 'numeric'],
            'about' => ['nullable', 'string'],
            'about_us' => ['nullable', 'string'],
        ], [
            'user_name.regex' => 'Only letters, numbers, dot, and underscore are allowed in username.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $userPayload = [];

                foreach ([
                    'first_name',
                    'last_name',
                    'user_name',
                    'email',
                    'phone',
                    'date_of_birth',
                    'gender',
                    'about',
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
                ] as $column) {
                    if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $request->input($column);
                    }
                }

                UserDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    $detailPayload
                );
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
            'area_locality' => ['nullable', 'string', 'max:255'],
            'colony' => ['nullable', 'string', 'max:255'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'pin_code' => ['nullable', 'numeric', 'digits:6'],

            'business_country_id' => ['nullable', 'exists:countries,id'],
            'business_state_id' => ['nullable', 'exists:states,id'],
            'business_city_id' => ['nullable', 'exists:cities,id'],
            'business_area_locality' => ['nullable', 'string', 'max:255'],
            'business_colony' => ['nullable', 'string', 'max:255'],
            'business_street_address' => ['nullable', 'string', 'max:255'],
            'business_pin_code' => ['nullable', 'numeric', 'digits:6'],
            'bussiness_address' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $userPayload = [];

                foreach ([
                    'country_id',
                    'state_id',
                    'city_id',
                    'area_locality',
                    'colony',
                    'street_address',
                    'pin_code',
                ] as $column) {
                    if ($request->has($column) && Schema::hasColumn('users', $column)) {
                        $userPayload[$column] = $request->input($column);
                    }
                }

                if (!empty($userPayload)) {
                    $user->update($userPayload);
                }

                $detailPayload = [
                    'user_id' => $user->id,
                ];

                $map = [
                    'business_country_id' => 'country_id',
                    'business_state_id' => 'state_id',
                    'business_city_id' => 'city_id',
                    'business_area_locality' => 'area_locality',
                    'business_colony' => 'colony',
                    'business_street_address' => 'street_address',
                    'business_pin_code' => 'pin_code',
                    'bussiness_address' => 'bussiness_address',
                    'address' => 'address',
                ];

                foreach ($map as $requestKey => $dbColumn) {
                    if ($request->has($requestKey) && Schema::hasColumn('user_details', $dbColumn)) {
                        $detailPayload[$dbColumn] = $request->input($requestKey);
                    }
                }

                UserDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    $detailPayload
                );
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
            'aadhaar_front' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'aadhaar_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'business_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'license_number' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $detailPayload = [
                    'user_id' => $user->id,
                ];

                foreach (['aadhaar_number', 'license_number'] as $column) {
                    if ($request->has($column) && Schema::hasColumn('user_details', $column)) {
                        $detailPayload[$column] = $request->input($column);
                    }
                }

                foreach ([
                    'aadhaar_front' => 'uploads/kyc/aadhaarFront',
                    'aadhaar_back' => 'uploads/kyc/aadhaarBack',
                    'business_proof' => 'uploads/kyc/businessProof',
                ] as $field => $directory) {
                    if ($request->hasFile($field) && Schema::hasColumn('user_details', $field)) {
                        $file = $request->file($field);
                        $fileName = time() . '_' . $field . '_' . str_replace(' ', '_', $file->getClientOriginalName());
                        $file->move(public_path($directory), $fileName);
                        $detailPayload[$field] = $directory . '/' . $fileName;
                    }
                }

                UserDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    $detailPayload
                );

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
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $file = $request->file('profile_photo');
                $fileName = time() . '_profile_' . str_replace(' ', '_', $file->getClientOriginalName());
                $file->move(public_path('uploads/users'), $fileName);

                UserDetail::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'user_id' => $user->id,
                        'profile_photo' => 'uploads/users/' . $fileName,
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

    private function formatUserProfile(User $user): array
    {
        $row = DB::table('users')
            ->leftJoin('user_details', 'users.id', '=', 'user_details.user_id')
            ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
            ->leftJoin('countries as user_countries', 'users.country_id', '=', 'user_countries.id')
            ->leftJoin('states as user_states', 'users.state_id', '=', 'user_states.id')
            ->leftJoin('cities as user_cities', 'users.city_id', '=', 'user_cities.id')
            ->leftJoin('countries as business_countries', 'user_details.country_id', '=', 'business_countries.id')
            ->leftJoin('states as business_states', 'user_details.state_id', '=', 'business_states.id')
            ->leftJoin('cities as business_cities', 'user_details.city_id', '=', 'business_cities.id')
            ->where('users.id', $user->id)
            ->select([
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.user_name',
                'users.email',
                'users.phone',
                'users.role_id',
                'roles.name as role_name',
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
                'users.created_at',
                'users.updated_at',

                'user_details.bussiness_name',
                'user_details.bussiness_address',
                'user_details.bussiness_email',
                'user_details.business_phone',
                'user_details.country_id as business_country_id',
                'user_details.state_id as business_state_id',
                'user_details.city_id as business_city_id',
                'business_countries.name as business_country',
                'business_states.name as business_state',
                'business_cities.name as business_city',
                'user_details.area_locality as business_area_locality',
                'user_details.colony as business_colony',
                'user_details.street_address as business_street_address',
                'user_details.pin_code as business_pin_code',
                'user_details.address',
                'user_details.profile_photo',
                'user_details.aadhaar_number',
                'user_details.aadhaar_front',
                'user_details.aadhaar_back',
                'user_details.business_proof',
                'user_details.license_number',
                'user_details.alternate_number',
                'user_details.no_of_employees',
                'user_details.about_us',
            ])
            ->first();

        if (!$row) {
            return [];
        }

        $profilePhoto = !empty($row->profile_photo) ? url($row->profile_photo) : null;
        $aadhaarFront = !empty($row->aadhaar_front) ? url($row->aadhaar_front) : null;
        $aadhaarBack = !empty($row->aadhaar_back) ? url($row->aadhaar_back) : null;
        $businessProof = !empty($row->business_proof) ? url($row->business_proof) : null;

        $raw = [
            'id' => $row->id,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'full_name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
            'user_name' => $row->user_name,
            'email' => $row->email,
            'phone' => $row->phone,
            'role_id' => $row->role_id,
            'role_name' => $row->role_name,
            'unique_id' => $row->unique_id,
            'isapproved' => $row->isapproved,
            'account_status' => $this->accountStatusLabel($row->isapproved),
            'kyc' => $row->kyc,
            'kyc_status' => $this->kycStatusLabel($row->kyc),
            'is_otp_verified' => $row->is_otp_verified,

            'country_id' => $row->country_id,
            'state_id' => $row->state_id,
            'city_id' => $row->city_id,
            'country' => $row->country,
            'state' => $row->state,
            'city' => $row->city,
            'area_locality' => $row->area_locality,
            'colony' => $row->colony,
            'street_address' => $row->street_address,
            'pin_code' => $row->pin_code,
            'about' => $row->about,

            'bussiness_name' => $row->bussiness_name,
            'bussiness_address' => $row->bussiness_address,
            'bussiness_email' => $row->bussiness_email,
            'business_phone' => $row->business_phone,
            'business_country_id' => $row->business_country_id,
            'business_state_id' => $row->business_state_id,
            'business_city_id' => $row->business_city_id,
            'business_country' => $row->business_country,
            'business_state' => $row->business_state,
            'business_city' => $row->business_city,
            'business_area_locality' => $row->business_area_locality,
            'business_colony' => $row->business_colony,
            'business_street_address' => $row->business_street_address,
            'business_pin_code' => $row->business_pin_code,

            'address' => $row->address,
            'profile_photo' => $profilePhoto,
            'aadhaar_number' => $row->aadhaar_number,
            'aadhaar_front' => $aadhaarFront,
            'aadhaar_back' => $aadhaarBack,
            'business_proof' => $businessProof,
            'license_number' => $row->license_number,
            'alternate_number' => $row->alternate_number,
            'no_of_employees' => $row->no_of_employees,
            'about_us' => $row->about_us,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];

        return [
            'raw' => $raw,
            'display' => collect($raw)
                ->map(fn ($value) => $this->dash($value))
                ->toArray(),
            'profile_completion' => $this->profileCompletion($raw),
        ];
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
            'user_name',
            'email',
            'phone',
            'country_id',
            'state_id',
            'city_id',
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

        $percentage = round(($completed / count($fields)) * 100);

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
}