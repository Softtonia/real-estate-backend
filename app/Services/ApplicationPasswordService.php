<?php

namespace App\Services;

use App\Events\ApplicationPasswordCreated;
use App\Events\ApplicationPasswordRevoked;
use App\Models\ApiClient;
use App\Models\ApplicationPassword;
use App\Repositories\ApplicationPasswordRepository;
use Illuminate\Support\Facades\DB;

class ApplicationPasswordService
{
    public function __construct(
        private readonly ApplicationPasswordRepository $applicationPasswordRepository
    ) {}


    public function paginateByClient(ApiClient $client, int $perPage = 20)
    {
        return $this->applicationPasswordRepository->paginateByClient($client, $perPage);
    }

    public function create(ApiClient $client, array $data): array
    {
        $plainToken = $this->generatePlainToken();

        $password = $this->applicationPasswordRepository->createForClient($client, [
            'name' => $data['name'],
            'token_prefix' => substr($plainToken, 0, 18),
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => $data['abilities'] ?? ['*'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        if (class_exists(ApplicationPasswordCreated::class)) {
            event(new ApplicationPasswordCreated(
                $client,
                $password,
                [
                    'name' => $password->name,
                    'abilities' => $password->abilities,
                    'expires_at' => optional($password->expires_at)->toDateTimeString(),
                ]
            ));
        }

        return [
            'password' => $password,
            'plain_token' => $plainToken,
        ];
    }

    public function revoke(ApplicationPassword $password): ApplicationPassword
    {
        if ($password->revoked_at !== null) {
            return $password->fresh();
        }

        $password = $this->applicationPasswordRepository->revoke($password);

        if (class_exists(ApplicationPasswordRevoked::class)) {
            event(new ApplicationPasswordRevoked(
                $password->apiClient,
                $password,
                [
                    'name' => $password->name,
                    'revoked_at' => optional($password->revoked_at)->toDateTimeString(),
                ]
            ));
        }

        return $password;
    }

    public function rotate(ApiClient $client, ApplicationPassword $oldPassword, array $data = []): array
    {
        return DB::transaction(function () use ($client, $oldPassword, $data) {
            $this->revoke($oldPassword);

            return $this->create($client, [
                'name' => $data['name'] ?? $oldPassword->name . ' rotated',
                'abilities' => $data['abilities'] ?? $oldPassword->abilities ?? ['*'],
                'expires_at' => $data['expires_at'] ?? null,
            ]);
        });
    }

    private function generatePlainToken(): string
    {
        $prefix = config(
            'api_security.token_prefix',
            app()->environment('production') ? 'sk_live_' : 'sk_test_'
        );

        $random = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return $prefix . $random;
    }
}