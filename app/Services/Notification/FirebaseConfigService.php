<?php

namespace App\Services\Notification;

use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class FirebaseConfigService
{
    private const CACHE_KEY = 'system:notification:firebase';

    private const TOKEN_CACHE_KEY = 'system:notification:firebase:access_token';

    private const GROUP = 'notification_config';

    private const MASKED_VALUE = '********';

    private const FIREBASE_KEYS = [
        'notification.firebase.enabled',
        'notification.firebase.project_id',
        'notification.firebase.client_email',
        'notification.firebase.private_key',
        'notification.firebase.private_key_id',
    ];

    public function firebaseConfig(bool $masked = false): array
    {
        $config = [
            'enabled' => $this->setting('notification.firebase.enabled', false),
            'project_id' => $this->setting('notification.firebase.project_id'),
            'client_email' => $this->setting('notification.firebase.client_email'),
            'private_key' => $this->setting('notification.firebase.private_key'),
            'private_key_id' => $this->setting('notification.firebase.private_key_id'),
        ];

        $config['enabled'] = filter_var($config['enabled'], FILTER_VALIDATE_BOOLEAN);

        if ($masked) {
            $config['private_key'] = $this->mask($config['private_key']);
        }

        return $config;
    }

    public function activeFirebaseConfig(bool $validate = true): array
    {
        $config = $this->firebaseConfig(false);

        if (! $validate) {
            return $config;
        }

        if (! $config['enabled']) {
            throw ValidationException::withMessages([
                'firebase' => ['Firebase notification is disabled.'],
            ]);
        }

        if (! $config['project_id']) {
            throw ValidationException::withMessages([
                'project_id' => ['Firebase project ID is missing.'],
            ]);
        }

        if (! $config['client_email']) {
            throw ValidationException::withMessages([
                'client_email' => ['Firebase client email is missing.'],
            ]);
        }

        if (! $config['private_key']) {
            throw ValidationException::withMessages([
                'private_key' => ['Firebase private key is missing.'],
            ]);
        }

        return $config;
    }

    public function updateFirebaseConfig(array $data, ?User $admin = null): array
    {
        $serviceAccount = $data['service_account_json'] ?? null;

        if (is_array($serviceAccount)) {
            $data['project_id'] = $serviceAccount['project_id'] ?? ($data['project_id'] ?? null);
            $data['client_email'] = $serviceAccount['client_email'] ?? ($data['client_email'] ?? null);
            $data['private_key'] = $serviceAccount['private_key'] ?? ($data['private_key'] ?? null);
            $data['private_key_id'] = $serviceAccount['private_key_id'] ?? ($data['private_key_id'] ?? null);
        }

        if (array_key_exists('enabled', $data)) {
            $this->saveSetting(
                key: 'notification.firebase.enabled',
                value: $data['enabled'],
                type: SystemSetting::TYPE_BOOLEAN,
                encrypted: false,
                admin: $admin
            );
        }

        if (array_key_exists('project_id', $data)) {
            $this->saveSetting(
                key: 'notification.firebase.project_id',
                value: $data['project_id'],
                type: SystemSetting::TYPE_STRING,
                encrypted: false,
                admin: $admin
            );
        }

        if (array_key_exists('client_email', $data)) {
            $this->saveSetting(
                key: 'notification.firebase.client_email',
                value: $data['client_email'],
                type: SystemSetting::TYPE_STRING,
                encrypted: false,
                admin: $admin
            );
        }

        if ($this->shouldSaveSecret($data['private_key'] ?? null)) {
            $this->saveSetting(
                key: 'notification.firebase.private_key',
                value: $this->normalizePrivateKey($data['private_key']),
                type: SystemSetting::TYPE_STRING,
                encrypted: true,
                admin: $admin
            );
        }

        if (array_key_exists('private_key_id', $data)) {
            $this->saveSetting(
                key: 'notification.firebase.private_key_id',
                value: $data['private_key_id'],
                type: SystemSetting::TYPE_STRING,
                encrypted: false,
                admin: $admin
            );
        }

        $this->clearCache();

        return $this->firebaseConfig(true);
    }

    public function accessToken(): string
    {
        return Cache::store('redis')->remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $config = $this->activeFirebaseConfig();

            $jwt = $this->createJwt(
                clientEmail: $config['client_email'],
                privateKey: $config['private_key']
            );

            $response = Http::asForm()
                ->timeout(20)
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    'firebase' => [
                        'Unable to generate Firebase access token: ' . $response->body(),
                    ],
                ]);
            }

            $token = $response->json('access_token');

            if (! $token) {
                throw ValidationException::withMessages([
                    'firebase' => ['Firebase access token missing from Google response.'],
                ]);
            }

            return $token;
        });
    }

    public function messagingUrl(): string
    {
        $config = $this->activeFirebaseConfig();

        return "https://fcm.googleapis.com/v1/projects/{$config['project_id']}/messages:send";
    }

    public function clearCache(): void
    {
        Cache::store('redis')->forget(self::CACHE_KEY);
        Cache::store('redis')->forget(self::TOKEN_CACHE_KEY);
    }

    private function settings(): array
    {
        if (! Schema::hasTable('system_settings')) {
            return [];
        }

        return Cache::store('redis')->remember(self::CACHE_KEY, 600, function () {
            return SystemSetting::query()
                ->whereIn('key', self::FIREBASE_KEYS)
                ->where('status', true)
                ->get()
                ->keyBy('key')
                ->all();
        });
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        $settings = $this->settings();

        /** @var SystemSetting|null $setting */
        $setting = $settings[$key] ?? null;

        return $setting ? $setting->formattedValue() : $default;
    }

    private function saveSetting(
        string $key,
        mixed $value,
        string $type,
        bool $encrypted,
        ?User $admin
    ): void {
        if (! Schema::hasTable('system_settings')) {
            throw ValidationException::withMessages([
                'system_settings' => ['system_settings table does not exist.'],
            ]);
        }

        $setting = SystemSetting::query()->firstOrNew([
            'key' => $key,
        ]);

        if (! $setting->exists) {
            $setting->created_by = $admin?->id;
        }

        $setting->group = self::GROUP;
        $setting->value = $this->prepareValue($value, $encrypted);
        $setting->value_type = $type;
        $setting->is_encrypted = $encrypted;
        $setting->status = true;
        $setting->updated_by = $admin?->id;

        $setting->save();
    }

    private function prepareValue(mixed $value, bool $encrypted): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($encrypted) {
            return Crypt::encryptString((string) $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    private function shouldSaveSecret(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return ! str_contains($value, '*');
    }

    private function normalizePrivateKey(string $privateKey): string
    {
        $privateKey = trim($privateKey);

        return str_replace('\\n', "\n", $privateKey);
    }

    private function createJwt(string $clientEmail, string $privateKey): string
    {
        $now = time();

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $claimSet = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $unsignedJwt = $this->base64UrlEncode(json_encode($header))
            . '.'
            . $this->base64UrlEncode(json_encode($claimSet));

        $privateKey = $this->normalizePrivateKey($privateKey);

        $success = openssl_sign(
            $unsignedJwt,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256
        );

        if (! $success) {
            throw ValidationException::withMessages([
                'private_key' => ['Unable to sign Firebase JWT. Please check private key.'],
            ]);
        }

        return $unsignedJwt . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string|false $value): string
    {
        if ($value === false) {
            return '';
        }

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function mask(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return self::MASKED_VALUE;
    }
}