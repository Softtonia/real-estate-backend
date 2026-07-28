<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\Admin\MembershipSettingRequest;
use App\Models\Membership\MembershipSetting;
use App\Models\User;
use App\Services\Membership\MembershipSettingAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminMembershipSettingController extends Controller
{
    public function index(
        Request $request,
        MembershipSettingAdminService $settingService
    ): JsonResponse {
        try {
            $settings = $settingService->paginatedSettings($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership settings fetched successfully.',
                'data' => $settings,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership settings.', $e);
        }
    }

    public function show(
        MembershipSetting $setting,
        MembershipSettingAdminService $settingService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership setting fetched successfully.',
                'data' => $settingService->formattedSetting($setting),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership setting.', $e);
        }
    }

    public function store(
        MembershipSettingRequest $request,
        MembershipSettingAdminService $settingService
    ): JsonResponse {
        try {
            $setting = $settingService->createSetting(
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership setting created successfully.',
                'data' => $settingService->formattedSetting($setting),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership setting.', $e);
        }
    }

    public function update(
        MembershipSettingRequest $request,
        MembershipSetting $setting,
        MembershipSettingAdminService $settingService
    ): JsonResponse {
        try {
            $setting = $settingService->updateSetting(
                setting: $setting,
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership setting updated successfully.',
                'data' => $settingService->formattedSetting($setting),
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to update membership setting.', $e);
        }
    }

    public function destroy(
        Request $request,
        MembershipSetting $setting,
        MembershipSettingAdminService $settingService
    ): JsonResponse {
        try {
            $settingService->deleteSetting(
                setting: $setting,
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership setting deleted successfully.',
                'data' => null,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to delete membership setting.', $e);
        }
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if ($token && Schema::hasColumn('users', 'api_token')) {
            $user = User::query()
                ->where('api_token', $token)
                ->first();

            if ($user) {
                return $user;
            }
        }

        $authUser = $request->user() ?: Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'error' => $e->errors(),
        ], 422);
    }

    private function serverError(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : 'Server error',
        ], 500);
    }
}