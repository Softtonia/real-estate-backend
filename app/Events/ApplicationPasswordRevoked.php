<?php

namespace App\Events;

use App\Models\ApiClient;
use App\Models\ApplicationPassword;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApplicationPasswordRevoked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ApiClient $apiClient,
        public ApplicationPassword $applicationPassword,
        public array $metadata = []
    ) {}
}