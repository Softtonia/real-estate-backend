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
use App\Services\Kyc\KycAssignmentService;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycReviewService;
use Illuminate\Auth\Access\AuthorizationException;
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
        private readonly KycDocumentService $documentService,
        private readonly KycAssignmentService $assignmentService
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
            'assigned_to' => ['nullable', 'string'],
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
                    'assignedVerifier:id,first_name,last_name,email',
                    'assigner:id,first_name,last_name,email',
                ])
                ->withCount('documents')
                ->latest('id');

            $canAssign = $this->assignmentService->canAssign($reviewer);

            // Reviewers without assign permission only see their assigned KYC requests
            if (!$canAssign) {
                $query->where('assigned_to', (int) $reviewer->id);
            } elseif ($request->filled('assigned_to')) {
                $assignedToParam = trim((string) $request->input('assigned_to'));
                if ($assignedToParam === 'me') {
                    $query->where('assigned_to', (int) $reviewer->id);
                } elseif ($assignedToParam === 'unassigned' || $assignedToParam === 'none') {
                    $query->whereNull('assigned_to');
                } elseif (is_numeric($assignedToParam)) {
                    $query->where('assigned_to', (int) $assignedToParam);
                }
            }

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
            $this->assignmentService->assertCanView($kycRequest, $reviewer);

            $kycRequest->load([
                'user:id,first_name,last_name,email,phone,role_id,reject_reason',
                'role:id,name',
                'reviewer:id,first_name,last_name,email',
                'assignedVerifier:id,first_name,last_name,email',
                'assigner:id,first_name,last_name,email',
                'documents.uploader:id,first_name,last_name,email',
                'documents.reviewer:id,first_name,last_name,email',
                'activities.performer:id,first_name,last_name,email',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'KYC request fetched successfully.',
                'data' => new KycRequestResource($kycRequest),
            ]);
        } catch (AuthorizationException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC request.', $e);
        }
    }

    public function verifierRoles(Request $request): JsonResponse
    {
        $actor = $this->resolveCurrentAdmin($request);

        if (!$actor) {
            return $this->unauthenticatedResponse();
        }

        try {
            $roles = $this->assignmentService->getEligibleRoles();

            return response()->json([
                'status' => true,
                'message' => 'Eligible KYC verifier roles fetched successfully.',
                'data' => [
                    'count' => $roles->count(),
                    'options' => $roles,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch verifier roles.', $e);
        }
    }

    public function verifiers(Request $request): JsonResponse
    {
        $actor = $this->resolveCurrentAdmin($request);

        if (!$actor) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $roleId = $request->filled('role_id') ? (int) $request->input('role_id') : null;
            $search = $request->filled('search') ? (string) $request->input('search') : null;
            $limit = (int) $request->input('limit', 100);

            $verifiers = $this->assignmentService->getEligibleVerifiers($roleId, $search, $limit);

            return response()->json([
                'status' => true,
                'message' => 'Eligible KYC verifiers fetched successfully.',
                'data' => [
                    'count' => $verifiers->count(),
                    'selected_role_id' => $roleId,
                    'options' => $verifiers,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch eligible verifiers.', $e);
        }
    }

    public function myAssigned(Request $request): JsonResponse
    {
        $request->merge([
            'assigned_to' => 'me',
        ]);

        return $this->index($request);
    }

    public function assign(Request $request, KycRequest $kycRequest): JsonResponse
    {
        $actor = $this->resolveCurrentAdmin($request);

        if (!$actor) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'verifier_id' => ['nullable', 'integer', 'exists:users,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $verifierId = $request->input('verifier_id') ?? $request->input('user_id');
            $verifier = $verifierId ? User::findOrFail((int) $verifierId) : null;
            $notes = $request->input('notes');

            $updatedRequest = $this->assignmentService->assign(
                kycRequest: $kycRequest,
                verifier: $verifier,
                assigner: $actor,
                notes: $notes
            );

            return response()->json([
                'status' => true,
                'message' => $verifier ? 'KYC request assigned successfully.' : 'KYC assignment removed successfully.',
                'data' => new KycRequestResource($updatedRequest),
            ]);
        } catch (ValidationException $e) {
            return $this->validationResponse($e->validator);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to assign KYC request.', $e);
        }
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $actor = $this->resolveCurrentAdmin($request);

        if (!$actor) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'kyc_request_ids' => ['required', 'array', 'min:1', 'max:200'],
            'kyc_request_ids.*' => ['required', 'integer', 'distinct', 'exists:kyc_requests,id'],
            'verifier_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $verifier = User::findOrFail((int) $request->input('verifier_id'));
            $kycRequestIds = array_map('intval', (array) $request->input('kyc_request_ids'));
            $notes = $request->input('notes');

            $updatedRequests = $this->assignmentService->bulkAssign(
                kycRequestIds: $kycRequestIds,
                verifier: $verifier,
                assigner: $actor,
                notes: $notes
            );

            return response()->json([
                'status' => true,
                'message' => 'KYC requests assigned successfully.',
                'data' => [
                    'assigned_count' => $updatedRequests->count(),
                    'verifier' => [
                        'id' => (int) $verifier->id,
                        'name' => trim(($verifier->first_name ?? '') . ' ' . ($verifier->last_name ?? '')) ?: $verifier->email,
                        'email' => $verifier->email,
                    ],
                    'requests' => KycRequestResource::collection($updatedRequests),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to bulk assign KYC requests.', $e);
        }
    }

    public function assignAllOpen(Request $request): JsonResponse
    {
        $actor = $this->resolveCurrentAdmin($request);

        if (!$actor) {
            return $this->unauthenticatedResponse();
        }

        $validator = Validator::make($request->all(), [
            'verifier_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'status' => ['nullable', 'string', 'in:' . implode(',', KycRequest::statuses())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->validationResponse($validator);
        }

        try {
            $verifier = User::findOrFail((int) $request->input('verifier_id'));
            $notes = $request->input('notes');

            $filters = [];
            if ($request->filled('role_id')) {
                $filters['role_id'] = (int) $request->input('role_id');
            }
            if ($request->filled('status')) {
                $filters['status'] = (string) $request->input('status');
            }

            $updatedRequests = $this->assignmentService->assignAllOpen(
                verifier: $verifier,
                assigner: $actor,
                filters: $filters,
                notes: $notes
            );

            return response()->json([
                'status' => true,
                'message' => $updatedRequests->isEmpty()
                    ? 'No open unassigned KYC requests found.'
                    : 'All open KYC requests assigned successfully.',
                'data' => [
                    'assigned_count' => $updatedRequests->count(),
                    'verifier' => [
                        'id' => (int) $verifier->id,
                        'name' => trim(($verifier->first_name ?? '') . ' ' . ($verifier->last_name ?? '')) ?: $verifier->email,
                        'email' => $verifier->email,
                    ],
                    'requests' => KycRequestResource::collection($updatedRequests),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to assign all open KYC requests.', $e);
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
            $this->assignmentService->assertCanView($kycRequest, $reviewer);

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
        } catch (AuthorizationException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
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
            $this->assignmentService->assertCanView($kycRequest, $reviewer);

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
        } catch (AuthorizationException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
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
            if ($document->kyc_request_id) {
                $kycRequest = KycRequest::find($document->kyc_request_id);
                if ($kycRequest) {
                    $this->assignmentService->assertCanView($kycRequest, $reviewer);
                }
            }

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
        } catch (AuthorizationException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
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
            $isSuperAdmin = $this->assignmentService->isSystemAdmin($reviewer);
            $canAssign = $this->assignmentService->canAssign($reviewer);

            $statsQuery = KycRequest::query();
            if (!$canAssign && !$isSuperAdmin) {
                $statsQuery->where('assigned_to', (int) $reviewer->id);
            }

            $cacheKey = ($canAssign || $isSuperAdmin) ? 'kyc:admin:stats' : 'kyc:verifier:' . $reviewer->id . ':stats';

            $stats = Cache::store('redis')->remember(
                $cacheKey,
                now()->addMinutes(5),
                function () use ($statsQuery) {
                    return [
                        'total' => (clone $statsQuery)->count(),
                        'draft' => (clone $statsQuery)->where('status', KycRequest::STATUS_DRAFT)->count(),
                        'submitted' => (clone $statsQuery)->where('status', KycRequest::STATUS_SUBMITTED)->count(),
                        'under_review' => (clone $statsQuery)->where('status', KycRequest::STATUS_UNDER_REVIEW)->count(),
                        'approved' => (clone $statsQuery)->where('status', KycRequest::STATUS_APPROVED)->count(),
                        'rejected' => (clone $statsQuery)->where('status', KycRequest::STATUS_REJECTED)->count(),
                        'resubmitted' => (clone $statsQuery)->where('status', KycRequest::STATUS_RESUBMITTED)->count(),
                        'unassigned' => (clone $statsQuery)->whereNull('assigned_to')->count(),
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
            // Admin has unrestricted access; non-admin reviewer must be assigned to this request
            $this->assignmentService->assertCanReview($kycRequest, $reviewer);

            $updatedRequest = $this->reviewService->handleReview(
                kycRequest: $kycRequest,
                reviewer: $reviewer,
                request: $request
            );

            $updatedRequest->load([
                'user:id,first_name,last_name,email,phone,role_id,reject_reason',
                'role:id,name',
                'reviewer:id,first_name,last_name,email',
                'assignedVerifier:id,first_name,last_name,email',
                'assigner:id,first_name,last_name,email',
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
        } catch (AuthorizationException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 403);
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