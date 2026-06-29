<?php

namespace App\Http\Middleware;

use App\Services\RequestSignatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyRequestSignature
{
    public function __construct(
        private readonly RequestSignatureService $requestSignatureService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attributes->get('api_client');

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'API client context missing.',
            ], 401);
        }

        // Signature is enforced only for clients where requires_signature = 1
        if (!$client->requires_signature) {
            return $next($request);
        }

        $plainToken = $request->attributes->get('application_password_plain_token');

        if (!$plainToken) {
            return response()->json([
                'success' => false,
                'message' => 'Application password missing for signature verification.',
            ], 401);
        }

        $timestamp = $request->header('X-Timestamp');
        $nonce = $request->header('X-Nonce');
        $signature = $request->header('X-Signature');

        if (!$timestamp || !$nonce || !$signature) {
            return response()->json([
                'success' => false,
                'message' => 'Missing signature headers.',
            ], 401);
        }

        if (strlen($nonce) > 128) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid nonce.',
            ], 401);
        }

        if (!$this->requestSignatureService->timestampIsValid((string) $timestamp)) {
            return response()->json([
                'success' => false,
                'message' => 'Request timestamp expired or invalid.',
            ], 401);
        }

        $expectedSignature = $this->requestSignatureService->generateSignature(
            $request,
            $plainToken,
            (string) $timestamp,
            (string) $nonce
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request signature.',
            ], 401);
        }

        if (!$this->requestSignatureService->storeNonce($client->id, (string) $nonce)) {
            return response()->json([
                'success' => false,
                'message' => 'Replay request detected.',
            ], 409);
        }

        return $next($request);
    }
}