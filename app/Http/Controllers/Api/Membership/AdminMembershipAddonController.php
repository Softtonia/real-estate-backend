<?php

namespace App\Http\Controllers\Api\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\Admin\MembershipAddonRequest;
use App\Models\Membership\MembershipAddon;
use App\Models\User;
use App\Services\Membership\MembershipAddonAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminMembershipAddonController extends Controller
{
    public function index(
        Request $request,
        MembershipAddonAdminService $addonService
    ): JsonResponse {
        try {
            $addons = $addonService->paginatedAddons($request->all());

            return response()->json([
                'status' => true,
                'message' => 'Membership add-ons fetched successfully.',
                'data' => $addons,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership add-ons.', $e);
        }
    }

    public function show(
        MembershipAddon $addon,
        MembershipAddonAdminService $addonService
    ): JsonResponse {
        try {
            return response()->json([
                'status' => true,
                'message' => 'Membership add-on fetched successfully.',
                'data' => $addonService->addonDetail($addon),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to fetch membership add-on.', $e);
        }
    }

    public function store(
        MembershipAddonRequest $request,
        MembershipAddonAdminService $addonService
    ): JsonResponse {
        try {
            $addon = $addonService->createAddon(
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on created successfully.',
                'data' => $addon,
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to create membership add-on.', $e);
        }
    }

    public function update(
        MembershipAddonRequest $request,
        MembershipAddon $addon,
        MembershipAddonAdminService $addonService
    ): JsonResponse {
        try {
            $addon = $addonService->updateAddon(
                addon: $addon,
                data: $request->validated(),
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on updated successfully.',
                'data' => $addon,
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Unable to update membership add-on.', $e);
        }
    }

    public function destroy(
        Request $request,
        MembershipAddon $addon,
        MembershipAddonAdminService $addonService
    ): JsonResponse {
        try {
            $addonService->deleteAddon(
                addon: $addon,
                admin: $this->resolveCurrentUser($request)
            );

            return response()->json([
                'status' => true,
                'message' => 'Membership add-on deleted or deactivated successfully.',
                'data' => null,
            ]);
        } catch (Throwable $e) {
            return $this->serverError('Unable to delete membership add-on.', $e);
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