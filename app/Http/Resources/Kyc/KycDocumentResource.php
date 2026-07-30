<?php

namespace App\Http\Resources\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class KycDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdminResponse = $this->isAdminResponse($request);

        $data = [
            'id' => (int) $this->id,
            'kyc_request_id' => (int) $this->kyc_request_id,

            'document_type' => $this->document_type,
            'document_number' => $this->maskDocumentNumber($this->document_number, $this->document_type),

            'file_original_name' => $this->file_original_name,
            'mime_type' => $this->mime_type,
            'file_size' => (int) $this->file_size,
            'file_size_human' => $this->humanFileSize((int) $this->file_size),

            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,

            'version' => (int) $this->version,

            'private_file_available' => !empty($this->file_path),

            'uploaded_at' => optional($this->uploaded_at)->toDateTimeString(),
            'reviewed_at' => optional($this->reviewed_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];

        /*
         * USER RESPONSE
         * Only this endpoint should show for user.
         * Do not expose user_id, metadata, uploader, reviewer, admin URL.
         */
        if (!$isAdminResponse) {
            $data['private_file_endpoint'] = '/api/kyc/documents/' . $this->id . '/view';

            return $data;
        }

        /*
         * ADMIN RESPONSE
         * Admin can see uploader/reviewer/metadata and full file URL.
         */
        $data['user_id'] = (int) $this->user_id;
        $data['metadata'] = $this->metadata;

        $data['uploaded_by'] = $this->uploaded_by ? (int) $this->uploaded_by : null;
        $data['reviewed_by'] = $this->reviewed_by ? (int) $this->reviewed_by : null;

        $data['uploader'] = $this->whenLoaded('uploader', function () {
            return $this->userMini($this->uploader);
        });

        $data['reviewer'] = $this->whenLoaded('reviewer', function () {
            return $this->userMini($this->reviewer);
        });

        if (!$isAdminResponse) {
            $data['private_file_endpoint'] =
                '/api/kyc/documents/' . $this->id . '/view';

            return $data;
        }

        /*
 * Admin receives a temporary signed URL.
 * Existing React <img src={doc.url}> will work without changes.
 */
        $data['user_id'] = (int) $this->user_id;
        $data['metadata'] = $this->metadata;

        $data['uploaded_by'] = $this->uploaded_by
            ? (int) $this->uploaded_by
            : null;

        $data['reviewed_by'] = $this->reviewed_by
            ? (int) $this->reviewed_by
            : null;

        $data['uploader'] = $this->whenLoaded(
            'uploader',
            fn() => $this->userMini($this->uploader)
        );

        $data['reviewer'] = $this->whenLoaded(
            'reviewer',
            fn() => $this->userMini($this->reviewer)
        );

        $data['private_file_url'] = URL::temporarySignedRoute(
            'admin.kyc.documents.preview',
            now()->addMinutes(30),
            [
                'document' => (int) $this->id,
            ]
        );


        return $data;
    }

    private function isAdminResponse(Request $request): bool
    {
        $path = trim($request->path(), '/');

        return str_starts_with($path, 'api/admin/kyc')
            || str_starts_with($path, 'admin/kyc');
    }

    private function adminFileUrl(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }

        return url(
            '/api/admin/kyc/' .
                $this->user_id .
                '/' .
                basename((string) $this->file_path)
        );
    }

    private function userMini($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: null,
            'email' => $user->email,
        ];
    }

    private function maskDocumentNumber(?string $number, ?string $documentType): ?string
    {
        if (empty($number)) {
            return null;
        }

        $number = trim($number);

        if (in_array($documentType, ['aadhaar_front', 'aadhaar_back'], true)) {
            return strlen($number) >= 4
                ? 'XXXXXXXX' . substr($number, -4)
                : $number;
        }

        return $number;
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }
}
