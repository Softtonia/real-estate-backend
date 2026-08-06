<?php

namespace App\Http\Resources;

use App\Support\PropertyAvailabilityPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyAvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latestRevision =
            $this->resource->relationLoaded(
                'latestVerificationRevision'
            )
                ? $this->latestVerificationRevision
                : null;

        return array_merge([
            'id' => (int) $this->id,
            'listing_code' =>
                $this->listing_code ?? null,
            'title' => $this->title,
            'status' => $this->status,
            'live_status' => $this->live_status,

            'workflow_status' =>
                $latestRevision?->status,

            'review_status_label' =>
                $this->workflowLabel(
                    $latestRevision?->status
                ),
        ], PropertyAvailabilityPresenter::make(
            $this->resource
        ));
    }

    private function workflowLabel(
        ?string $status
    ): string {
        return match ($status) {
            'approve', 'approved' => 'Approved',
            'reject', 'rejected' => 'Rejected',
            'under_review' => 'Under Review',
            'resubmission' => 'Resubmitted',
            'assigned' => 'Assigned',
            'in_verification' =>
                'In Verification',
            default => 'Pending',
        };
    }
}
