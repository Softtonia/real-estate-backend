<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PropertyWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $propertyId,
        public readonly ?string $propertyTitle,
        public readonly string $event,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'property_workflow',
            'screen' => 'property_verification_detail',
            'route' => '/admin/property-verifications/' . $this->propertyId,
            'property_id' => $this->propertyId,
            'property_title' => $this->propertyTitle,
            'event' => $this->event,
            'message' => $this->message(),
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }

    private function message(): string
    {
        $title = $this->propertyTitle ?: ('Property #' . $this->propertyId);

        return match ($this->event) {
            'property_submitted' =>
                "{$title} was submitted successfully and is under review.",

            'property_resubmitted' =>
                "{$title} was resubmitted for verification.",

            'live_property_updated' =>
                "Updates to {$title} were submitted for verification.",

            'property_assigned' =>
                "{$title} was assigned for verification.",

            'verification_started' =>
                "Verification started for {$title}.",

            'property_rejected' =>
                "{$title} was rejected."
                . ($this->reason ? " Reason: {$this->reason}" : ''),

            'property_approved' =>
                "{$title} was approved and published successfully.",

            'property_republished' =>
                "The approved updates to {$title} are now live.",

            default =>
                "The status of {$title} was updated.",
        };
    }
}
