<?php

namespace App\Http\Middleware;

use App\Services\ApiAbuseProtectionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockSuspiciousApiIp
{
    public function __construct(
        private readonly ApiAbuseProtectionService $apiAbuseProtectionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $block = $this->apiAbuseProtectionService->activeBlockForIp($request->ip());

        if ($block) {
            return response()->json([
                'success' => false,
                'message' => 'This IP has been temporarily blocked due to suspicious API activity.',
                'blocked_until' => $block->blocked_until,
            ], 403);
        }

        return $next($request);
    }
}