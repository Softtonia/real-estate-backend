<?php

namespace App\Repositories;

use App\Models\ApiClient;
use App\Models\ApplicationPassword;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApplicationPasswordRepository
{
    public function paginateByClient(ApiClient $client, int $perPage = 20): LengthAwarePaginator
    {
        return $client->applicationPasswords()
            ->latest()
            ->paginate($perPage);
    }

    public function createForClient(ApiClient $client, array $data): ApplicationPassword
    {
        return $client->applicationPasswords()->create($data);
    }

    public function revoke(ApplicationPassword $password): ApplicationPassword
    {
        $password->forceFill([
            'revoked_at' => now(),
        ])->save();

        return $password->fresh();
    }

    public function findValidByPlainToken(string $plainToken): ?ApplicationPassword
    {
        return ApplicationPassword::query()
            ->with('apiClient')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
    }
}