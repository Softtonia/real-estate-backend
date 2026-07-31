<?php

namespace App\Enums;

final class PropertyWorkflowStatus
{
    public const DRAFT = 'draft';
    public const UNDER_REVIEW = 'under_review';
    public const RESUBMISSION = 'resubmission';
    public const ASSIGNED = 'assigned';
    public const IN_VERIFICATION = 'in_verification';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public const LIVE = 'approve';

    public const OPEN_STATUSES = [
        self::UNDER_REVIEW,
        self::RESUBMISSION,
        self::ASSIGNED,
        self::IN_VERIFICATION,
    ];

    private function __construct()
    {
    }
}
