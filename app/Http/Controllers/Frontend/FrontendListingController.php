<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\StoreFrontendListingRequest;
use App\Services\FrontendListingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class FrontendListingController extends Controller
{
    public function __construct(
        private readonly FrontendListingService $listingService
    ) {
    }

    public function formOptions(
        int|string $postType
    ): JsonResponse {
        try {
            $data = $this->listingService
                ->getFormOptions($postType);

            return response()->json([
                'status' => true,
                'message' => 'Frontend listing form options fetched successfully.',
                'data' => $data,
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to fetch frontend listing form options.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function store(
        StoreFrontendListingRequest $request
    ): JsonResponse {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Authentication is required.',
                ], 401);
            }

            $listing = $this->listingService->create(
                $request->validated(),
                $user
            );

            return response()->json([
                'status' => true,
                'message' => 'Listing submitted successfully and is awaiting approval.',
                'data' => [
                    'listing' => $this->listingService
                        ->formatListing($listing),
                ],
            ], 201);
        } catch (ValidationException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (QueryException $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Database error while creating listing.',
                'error' => $exception->getMessage(),
            ], 500);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to create listing.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }
}