<?php

namespace App\Events;

use App\Models\ApiClient;
use App\Models\ApplicationPassword;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class ApiClientAccessGranted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ApiClient $apiClient,
        public ApplicationPassword $applicationPassword,
        public Request $request
    ) {}
}