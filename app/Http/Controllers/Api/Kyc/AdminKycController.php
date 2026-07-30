<?php

namespace App\Http\Controllers\Api\Kyc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\KycReviewRequest;
use App\Http\Resources\Kyc\KycActivityResource;
use App\Http\Resources\Kyc\KycDocumentResource;
use App\Http\Resources\Kyc\KycRequestResource;
use App\Models\KycActivity;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\User;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminKycController extends Controller
{
    public function __construct(
        private readonly KycReviewService $reviewService,
        private readonly KycDocumentService $documentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $reviewer = $this->resolveCurrentAdmin($request);

        if (!$reviewer) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'status' => ['nullable', 'string', 'in:' . implode(',', KycRequest::statuses())],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'reviewed_by' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $perPage = (int) $request->input('per_page', 20);

            $query = KycRequest::query()
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id,reject_reason',
                    'role:id,name',
                    'reviewer:id,first_name,last_name,email',
                ])
                ->withCount('documents')
                ->latest('id');

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('role_id')) {
                $query->where('role_id', (int) $request->input('role_id'));
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->input('user_id'));
            }

            if ($request->filled('reviewed_by')) {
                $query->where('reviewed_by', (int) $request->input('reviewed_by'));
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));

                $query->where(function (Builder $query) use ($search) {
                    $query
                        ->where('aadhaar_number', 'like', '%' . $search . '%')
                        ->orWhere('gst_number', 'like', '%' . $search . '%')
                        ->orWhere('rera_number', 'like', '%' . $search . '%')
                        ->orWhere('business_name', 'like', '%' . $search . '%')
                        ->orWhereHas('user', function (Builder $userQuery) use ($search) {
                            $userQuery
                                ->where('first_name', 'like', '%' . $search . '%')
                                ->orWhere('last_name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%')
                                ->orWhere('unique_id', 'like', '%' . $search . '%');
                        });
                });
            }

            $kycRequests = $query->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'KYC requests fetched successfully.',
                'data' => KycRequestResource::collection($kycRequests),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC requests.', $e);
        }
    }

    public function show(Request $request, KycRequest $kycRequest): JsonResponse
    {
        $reviewer = $this->resolveCurrentAdmin($request);

        if (!$reviewer) {
            return $this->unauthenticatedResponse();
        }

        try {
            $kycRequest->load([
                'user:id,first_name,last_name,email,phone,role_id,reject_reason',
                'role:id,name',
                'reviewer:id,first_name,last_name,email',
                'documents.uploader:id,first_name,last_name,email',
                'documents.reviewer:id,first_name,last_name,email',
                'activities.performer:id,first_name,last_name,email',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'KYC request fetched successfully.',
                'data' => new KycRequestResource($kycRequest),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC request.', $e);
        }
    }

    public function startReview(KycReviewRequest $request, KycRequest $kycRequest): JsonResponse
    {
        return $this->handleReviewAction(
            $request,
            $kycRequest,
            'KYC review started successfully.'
        );
    }

    public function approve(KycReviewRequest $request, KycRequest $kycRequest): JsonResponse
    {
        return $this->handleReviewAction(
            $request,
            $kycRequest,
            'KYC approved successfully.'
        );
    }

    public function reject(KycReviewRequest $request, KycRequest $kycRequest): JsonResponse
    {
        return $this->handleReviewAction(
            $request,
            $kycRequest,
            'KYC rejected successfully.'
        );
    }

    public function documents(Request $request, KycRequest $kycRequest): JsonResponse
    {
        $reviewer = $this->resolveCurrentAdmin($request);

        if (!$reviewer) {
            return $this->unauthenticatedResponse();
        }

        try {
            $perPage = max(1, min((int) $request->input('per_page', 20), 100));

            $documents = KycDocument::query()
                ->with([
                    'uploader:id,first_name,last_name,email',
                    'reviewer:id,first_name,last_name,email',
                ])
                ->where('kyc_request_id', (int) $kycRequest->id)
                ->latest('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'KYC documents fetched successfully.',
                'data' => KycDocumentResource::collection($documents),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC documents.', $e);
        }
    }

    public function timeline(Request $request, KycRequest $kycRequest): JsonResponse
    {
        $reviewer = $this->resolveCurrentAdmin($request);

        if (!$reviewer) {
            return $this->unauthenticatedResponse();
        }

        try {
            $perPage = max(1, min((int) $request->input('per_page', 20), 100));

            $activities = KycActivity::query()
                ->with('performer:id,first_name,last_name,email')
                ->where('kyc_request_id', (int) $kycRequest->id)
                ->latest('created_at')
                ->latest('id')
                ->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'KYC timeline fetched successfully.',
                'data' => KycActivityResource::collection($activities),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC timeline.', $e);
        }
    }

    public function viewDocument(Request $request, KycDocument $document): StreamedResponse|JsonResponse
    {
        $reviewer = $this->resolveCurrentAdmin($request);

        if (!$reviewer) {
            return $this->unauthenticatedResponse();
        }

        try {
            if (empty($document->file_disk) || empty($document->file_path)) {
                return response()->json([
                    'status' => false,
                    'message' => 'KYC document file not available.',
                ], 404);
            }

            if (!$this->documentService->fileExists($document)) {
                return response()->json([
                    'status' => false,
                    'message' => 'KYC document file not found.',
                    'debug' => [
                        'document_id' => (int) $document->id,
                        'file_disk' => $document->file_disk,
                        'file_path' => $document->file_path,
                    ],
                ], 404);
            }

            return Storage::disk($document->file_disk)->response(
                $document->file_path,
                $document->file_original_name ?: basename($document->file_path),
                [
                    'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                    'Content-Disposition' => 'inline; filename="' . ($document->file_original_name ?: basename($document->file_path)) . '"',
                    'X-Content-Type-Options' => 'nosniff',
                ]
            );
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to view KYC document.', $e);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        $reviewer = $this->resolveCurrentAdmin($request);

        if (!$reviewer) {
            return $this->unauthenticatedResponse();
        }

        try {
            $stats = Cache::store('redis')->remember(
                'kyc:admin:stats',
                now()->addMinutes(5),
                function () {
                    return [
                        'total' => KycRequest::query()->count(),
                        'draft' => KycRequest::query()->where('status', KycRequest::STATUS_DRAFT)->count(),
                        'submitted' => KycRequest::query()->where('status', KycRequest::STATUS_SUBMITTED)->count(),
                        'under_review' => KycRequest::query()->where('status', KycRequest::STATUS_UNDER_REVIEW)->count(),
                        'approved' => KycRequest::query()->where('status', KycRequest::STATUS_APPROVED)->count(),
                        'rejected' => KycRequest::query()->where('status', KycRequest::STATUS_REJECTED)->count(),
                        'resubmitted' => KycRequest::query()->where('status', KycRequest::STATUS_RESUBMITTED)->count(),
                    ];
                }
            );

            return response()->json([
                'status' => true,
                'message' => 'KYC stats fetched successfully.',
                'data' => $stats,
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC stats.', $e);
        }
    }

    private function handleReviewAction(
        KycReviewRequest $request,
        KycRequest $kycRequest,
        string $successMessage
    ): JsonResponse {
        $reviewer = $this->resolveCurrentAdmin($request);

        if (!$reviewer) {
            return $this->unauthenticatedResponse();
        }

        try {
            $updatedRequest = $this->reviewService->handleReview(
                kycRequest: $kycRequest,
                reviewer: $reviewer,
                request: $request
            );

            $updatedRequest->load([
                'user:id,first_name,last_name,email,phone,role_id,reject_reason',
                'role:id,name',
                'reviewer:id,first_name,last_name,email',
                'documents.uploader:id,first_name,last_name,email',
                'documents.reviewer:id,first_name,last_name,email',
                'activities.performer:id,first_name,last_name,email',
            ]);

            Cache::store('redis')->forget('kyc:admin:stats');

            return response()->json([
                'status' => true,
                'message' => $successMessage,
                'data' => new KycRequestResource($updatedRequest),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to process KYC review action.', $e);
        }
    }

    private function resolveCurrentAdmin(Request $request): ?User
    {
        $authUser = Auth::user();

        if ($authUser instanceof User) {
            return $authUser;
        }

        $token = $request->bearerToken()
            ?: $request->header('api-token')
            ?: $request->header('api_token')
            ?: $request->input('api_token');

        if (!$token || !Schema::hasColumn('users', 'api_token')) {
            return null;
        }

        return User::query()
            ->where('api_token', $token)
            ->first();
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Invalid or expired admin token.',
        ], 401);
    }

    private function validationResponse($validator): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function serverErrorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }
}