<?php

namespace App\Services;

use App\Models\ApiRequestNonce;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class RequestSignatureService
{
    public function generateSignature(
        Request $request,
        string $secret,
        string $timestamp,
        string $nonce
    ): string {
        $bodyHash = hash('sha256', $request->getContent() ?: '');

        $payload = implode("\n", [
            strtoupper($request->method()),
            $request->getRequestUri(),
            $timestamp,
            $nonce,
            $bodyHash,
        ]);

        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public function timestampIsValid(string $timestamp): bool
    {
        if (!ctype_digit($timestamp)) {
            return false;
        }

        $ttl = (int) config('api_security.signature_ttl_seconds', 300);

        return abs(now()->timestamp - (int) $timestamp) <= $ttl;
    }

    public function storeNonce(int $clientId, string $nonce): bool
    {
        $ttl = (int) config('api_security.signature_ttl_seconds', 300);

        ApiRequestNonce::query()
            ->where('expires_at', '<', now())
            ->delete();

        try {
            ApiRequestNonce::create([
                'api_client_id' => $clientId,
                'nonce' => $nonce,
                'expires_at' => now()->addSeconds($ttl),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }
}