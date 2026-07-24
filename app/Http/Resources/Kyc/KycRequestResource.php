<?php

namespace App\Http\Resources\Kyc;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KycRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'role_id' => $this->role_id ? (int) $this->role_id : null,
            'parent_kyc_request_id' => $this->parent_kyc_request_id ? (int) $this->parent_kyc_request_id : null,

            'version' => (int) $this->version,
            'status' => $this->status,
            'status_label' => $this->statusLabel($this->status),

            'aadhaar_number' => $this->maskAadhaar($this->aadhaar_number),
            'gst_number' => $this->gst_number,
            'rera_number' => $this->rera_number,
            'business_name' => $this->business_name,

            'rejection_reason' => $this->rejection_reason,
            'reviewer_notes' => $this->reviewer_notes,
            'resubmission_count' => (int) $this->resubmission_count,

            'user' => $this->whenLoaded('user', function () {
                return $this->userMini($this->user);
            }),

            'role' => $this->whenLoaded('role', function () {
                return $this->role ? [
                    'id' => (int) $this->role->id,
                    'name' => $this->role->name,
                ] : null;
            }),

            'reviewer' => $this->whenLoaded('reviewer', function () {
                return $this->userMini($this->reviewer);
            }),

            'documents' => KycDocumentResource::collection(
                $this->whenLoaded('documents')
            ),

            'activities' => KycActivityResource::collection(
                $this->whenLoaded('activities')
            ),

            'submitted_at' => optional($this->submitted_at)->toDateTimeString(),
            'review_started_at' => optional($this->review_started_at)->toDateTimeString(),
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
            'phone' => $user->phone ?? null,
            'role_id' => $user->role_id ?? null,
            'kyc' => $user->kyc ?? null,
            'reject_reason' => $user->reject_reason ?? null,
        ];
    }

    private function maskAadhaar(?string $aadhaar): ?string
    {
        if (empty($aadhaar)) {
            return null;
        }

        return strlen($aadhaar) >= 4
            ? 'XXXXXXXX' . substr($aadhaar, -4)
            : $aadhaar;
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'under_review' => 'Under Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'resubmitted' => 'Resubmitted',
            default => 'Pending',
        };
    }
}