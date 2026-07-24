<?php

namespace App\Http\Resources\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'kyc_request_id' => (int) $this->kyc_request_id,
            'user_id' => (int) $this->user_id,

            'document_type' => $this->document_type,
            'document_number' => $this->maskDocumentNumber($this->document_number, $this->document_type),

            'file_original_name' => $this->file_original_name,
            'mime_type' => $this->mime_type,
            'file_size' => (int) $this->file_size,
            'file_size_human' => $this->humanFileSize((int) $this->file_size),

            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,

            'version' => (int) $this->version,
            'metadata' => $this->metadata,

            'uploaded_by' => $this->uploaded_by ? (int) $this->uploaded_by : null,
            'reviewed_by' => $this->reviewed_by ? (int) $this->reviewed_by : null,

            'uploader' => $this->whenLoaded('uploader', function () {
                return $this->userMini($this->uploader);
            }),

            'reviewer' => $this->whenLoaded('reviewer', function () {
                return $this->userMini($this->reviewer);
            }),

            /*
             * Private file endpoint will be added in routes later.
             * Frontend should use this document id to call protected view/download API.
             */
            'private_file_available' => !empty($this->file_path),
            'private_file_endpoint' => '/api/admin/kyc/documents/' . $this->id . '/view',

            'uploaded_at' => optional($this->uploaded_at)->toDateTimeString(),
            'reviewed_at' => optional($this->reviewed_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
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