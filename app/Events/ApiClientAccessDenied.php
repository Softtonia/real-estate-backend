<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class ApiClientAccessDenied
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Request $request,
        public string $reason,
        public ?int $apiClientId = null,
        public ?string $plainToken = null,
        public array $debug = []
    ) {}
}