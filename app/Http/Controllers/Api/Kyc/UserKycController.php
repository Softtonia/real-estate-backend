<?php

namespace App\Http\Controllers\Api\Kyc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\KycSubmitRequest;
use App\Http\Resources\Kyc\KycActivityResource;
use App\Http\Resources\Kyc\KycDocumentResource;
use App\Http\Resources\Kyc\KycRequestResource;
use App\Http\Requests\Kyc\KycBatchUploadRequest;
use App\Models\KycDocument;
use App\Models\KycRequest;
use App\Models\User;
use App\Services\Kyc\KycAccessService;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Kyc\KycBatchUploadService;
use App\Services\Kyc\KycUploadProgressService;
use Throwable;

class UserKycController extends Controller
{
    public function __construct(
        private readonly KycSubmissionService $submissionService,
        private readonly KycAccessService $accessService,
        private readonly KycDocumentService $documentService,
        private readonly KycBatchUploadService $batchUploadService,
        private readonly KycUploadProgressService $uploadProgressService
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        try {
            return response()->json([
                'status' => true,
                'message' => 'KYC status fetched successfully.',
                'data' => $this->accessService->userKycStatus($user),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC status.', $e);
        }
    }

    public function details(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $kycRequest = KycRequest::query()
                ->with([
                    'user:id,first_name,last_name,email,phone,role_id,kyc,reject_reason',
                    'role:id,name',
                    'reviewer:id,first_name,last_name,email',
                    'documents.uploader:id,first_name,last_name,email',
                    'documents.reviewer:id,first_name,last_name,email',
                    'activities.performer:id,first_name,last_name,email',
                ])
                ->where('user_id', $user->id)
                ->latest('id')
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'KYC details fetched successfully.',
                'data' => $kycRequest ? new KycRequestResource($kycRequest) : null,
                'access' => $this->accessService->userKycStatus($user),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC details.', $e);
        }
    }

    public function submit(KycSubmitRequest $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        if (!empty($request->kycDocumentFiles())) {
            return response()->json([
                'status' => false,
                'message' => 'Please upload KYC documents using batch upload API before submitting KYC.',
                'error' => 'Use POST /api/kyc/uploads/start, then poll progress, then call POST /api/kyc/submit.',
            ], 422);
        }

        try {
            $kycRequest = $this->submissionService->submit($user, $request);

            return response()->json([
                'status' => true,
                'message' => 'KYC submitted successfully.',
                'data' => new KycRequestResource($kycRequest),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to submit KYC.', $e);
        }
    }

    public function resubmit(KycSubmitRequest $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $latestRequest = KycRequest::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->first();

            if (!$latestRequest || !$latestRequest->isRejected()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Only rejected KYC can be resubmitted.',
                ], 422);
            }

            $kycRequest = $this->submissionService->submit($user, $request);

            return response()->json([
                'status' => true,
                'message' => 'KYC resubmitted successfully.',
                'data' => new KycRequestResource($kycRequest),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to resubmit KYC.', $e);
        }
    }

    public function documents(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $documents = KycDocument::query()
                ->with([
                    'uploader:id,first_name,last_name,email',
                    'reviewer:id,first_name,last_name,email',
                ])
                ->where('user_id', $user->id)
                ->latest('id')
                ->paginate((int) $request->input('per_page', 20));

            return response()->json([
                'status' => true,
                'message' => 'KYC documents fetched successfully.',
                'data' => KycDocumentResource::collection($documents),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC documents.', $e);
        }
    }

    public function timeline(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $kycRequestId = $request->input('kyc_request_id');

            $query = \App\Models\KycActivity::query()
                ->with('performer:id,first_name,last_name,email')
                ->where('user_id', $user->id)
                ->latest('created_at');

            if ($kycRequestId) {
                $query->where('kyc_request_id', $kycRequestId);
            }

            $activities = $query->paginate((int) $request->input('per_page', 20));

            return response()->json([
                'status' => true,
                'message' => 'KYC timeline fetched successfully.',
                'data' => KycActivityResource::collection($activities),
            ]);
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to fetch KYC timeline.', $e);
        }
    }

    public function viewDocument(Request $request, int $documentId): StreamedResponse|JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $document = KycDocument::query()
                ->where('id', $documentId)
                ->where('user_id', $user->id)
                ->first();

            if (!$document) {
                return response()->json([
                    'status' => false,
                    'message' => 'KYC document not found.',
                ], 404);
            }

            if (!$this->documentService->fileExists($document)) {
                return response()->json([
                    'status' => false,
                    'message' => 'KYC document file not found.',
                ], 404);
            }

            return Storage::disk($document->file_disk)->response(
                $document->file_path,
                $document->file_original_name ?: basename($document->file_path),
                [
                    'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                    'X-Content-Type-Options' => 'nosniff',
                ]
            );
        } catch (Throwable $e) {
            return $this->serverErrorResponse('Unable to view KYC document.', $e);
        }
    }

    private function resolveCurrentUser(Request $request): ?User
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
            'message' => 'Invalid or expired token.',
        ], 401);
    }

    private function serverErrorResponse(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $e->getMessage(),
        ], 500);
    }
    public function startBatchUpload(
        KycBatchUploadRequest $request
    ): JsonResponse {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        try {
            $files = $request->kycDocumentFiles();

            if (empty($files)) {
                return response()->json([
                    'status' => false,
                    'message' => 'At least one KYC document is required.',
                ], 422);
            }

            $batch = $this->batchUploadService->start(
                $user,
                $request
            );

            return response()->json([
                'status' => true,
                'message' =>
                'KYC document batch upload started successfully.',
                'data' => $batch,
            ], 202);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            \Log::error('KYC batch upload start failed.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->serverErrorResponse(
                'Unable to start KYC batch upload.',
                $e
            );
        }
    }

    public function uploadProgress(Request $request, string $uploadId): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);

        if (!$user) {
            return $this->unauthenticatedResponse();
        }

        $progress = $this->uploadProgressService->get($uploadId);

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
            'message' => 'KYC upload progress fetched successfully.',
            'data' => $progress,
        ]);
    }
}
