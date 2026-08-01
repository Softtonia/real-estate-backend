<?php

namespace App\Services\Payment;

use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentGatewayConfigService
{
    private const CACHE_KEY = 'system:payment_gateway:razorpay';

    private const GROUP = 'payment_gateway';

    private const MASKED_VALUE = '********';

    private const RAZORPAY_KEYS = [
        'payment.razorpay.enabled',
        'payment.razorpay.mode',
        'payment.razorpay.currency',

        'payment.razorpay.test.key_id',
        'payment.razorpay.test.key_secret',
        'payment.razorpay.test.webhook_secret',

        'payment.razorpay.live.key_id',
        'payment.razorpay.live.key_secret',
        'payment.razorpay.live.webhook_secret',
    ];

    public function razorpayConfig(bool $masked = false): array
    {
        $mode = $this->setting(
            key: 'payment.razorpay.mode',
            default: config('services.razorpay.mode', 'test')
        );

        $mode = $mode === 'live' ? 'live' : 'test';

        $config = [
            'enabled' => $this->setting('payment.razorpay.enabled', true),
            'mode' => $mode,
            'currency' => strtoupper((string) $this->setting('payment.razorpay.currency', 'INR')),

            'test_key_id' => $this->setting(
                'payment.razorpay.test.key_id',
                config('services.razorpay.test_key_id') ?: config('services.razorpay.key_id')
            ),
            'test_key_secret' => $this->setting(
                'payment.razorpay.test.key_secret',
                config('services.razorpay.test_key_secret') ?: config('services.razorpay.key_secret')
            ),
            'test_webhook_secret' => $this->setting(
                'payment.razorpay.test.webhook_secret',
                config('services.razorpay.test_webhook_secret') ?: config('services.razorpay.webhook_secret')
            ),

            'live_key_id' => $this->setting(
                'payment.razorpay.live.key_id',
                config('services.razorpay.live_key_id') ?: config('services.razorpay.key_id')
            ),
            'live_key_secret' => $this->setting(
                'payment.razorpay.live.key_secret',
                config('services.razorpay.live_key_secret') ?: config('services.razorpay.key_secret')
            ),
            'live_webhook_secret' => $this->setting(
                'payment.razorpay.live.webhook_secret',
                config('services.razorpay.live_webhook_secret') ?: config('services.razorpay.webhook_secret')
            ),
        ];

        $config['enabled'] = filter_var($config['enabled'], FILTER_VALIDATE_BOOLEAN);

        if ($masked) {
            $config['test_key_secret'] = $this->mask($config['test_key_secret']);
            $config['test_webhook_secret'] = $this->mask($config['test_webhook_secret']);
            $config['live_key_secret'] = $this->mask($config['live_key_secret']);
            $config['live_webhook_secret'] = $this->mask($config['live_webhook_secret']);
        }

        return $config;
    }

    public function activeRazorpayCredentials(bool $validate = true): array
    {
        $config = $this->razorpayConfig(false);

        $mode = $config['mode'] === 'live' ? 'live' : 'test';

        $credentials = [
            'enabled' => (bool) $config['enabled'],
            'mode' => $mode,
            'currency' => $config['currency'] ?: 'INR',
            'key_id' => $config[$mode . '_key_id'] ?? null,
            'key_secret' => $config[$mode . '_key_secret'] ?? null,
            'webhook_secret' => $config[$mode . '_webhook_secret'] ?? null,
        ];

        if ($validate) {
            if (! $credentials['enabled']) {
                throw ValidationException::withMessages([
                    'razorpay' => ['Razorpay payment gateway is disabled.'],
                ]);
            }

            if (! $credentials['key_id'] || ! $credentials['key_secret']) {
                throw ValidationException::withMessages([
                    'razorpay' => ["Razorpay {$mode} key id or key secret is missing."],
                ]);
            }
        }

        return $credentials;
    }

    public function updateRazorpayConfig(array $data, ?User $admin = null): array
    {
        if (array_key_exists('enabled', $data)) {
            $this->saveSetting(
                key: 'payment.razorpay.enabled',
                value: $data['enabled'],
                type: SystemSetting::TYPE_BOOLEAN,
                encrypted: false,
                admin: $admin
            );
        }

        if (array_key_exists('mode', $data)) {
            $this->saveSetting(
                key: 'payment.razorpay.mode',
                value: $data['mode'] === 'live' ? 'live' : 'test',
                type: SystemSetting::TYPE_STRING,
                encrypted: false,
                admin: $admin
            );
        }

        if (array_key_exists('currency', $data)) {
            $this->saveSetting(
                key: 'payment.razorpay.currency',
                value: strtoupper($data['currency'] ?: 'INR'),
                type: SystemSetting::TYPE_STRING,
                encrypted: false,
                admin: $admin
            );
        }

        foreach (['test', 'live'] as $mode) {
            if (array_key_exists($mode . '_key_id', $data)) {
                $this->saveSetting(
                    key: "payment.razorpay.{$mode}.key_id",
                    value: $data[$mode . '_key_id'],
                    type: SystemSetting::TYPE_STRING,
                    encrypted: false,
                    admin: $admin
                );
            }

            if ($this->shouldSaveSecret($data[$mode . '_key_secret'] ?? null)) {
                $this->saveSetting(
                    key: "payment.razorpay.{$mode}.key_secret",
                    value: $data[$mode . '_key_secret'],
                    type: SystemSetting::TYPE_STRING,
                    encrypted: true,
                    admin: $admin
                );
            }

            if ($this->shouldSaveSecret($data[$mode . '_webhook_secret'] ?? null)) {
                $this->saveSetting(
                    key: "payment.razorpay.{$mode}.webhook_secret",
                    value: $data[$mode . '_webhook_secret'],
                    type: SystemSetting::TYPE_STRING,
                    encrypted: true,
                    admin: $admin
                );
            }
        }

        $this->clearCache();

        return $this->razorpayConfig(true);
    }

    public function clearCache(): void
    {
        Cache::store('redis')->forget(self::CACHE_KEY);
    }

    private function settings(): array
    {
        if (! Schema::hasTable('system_settings')) {
            return [];
        }

        return Cache::store('redis')->remember(self::CACHE_KEY, 600, function () {
            return SystemSetting::query()
                ->whereIn('key', self::RAZORPAY_KEYS)
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

    private function mask(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = (string) $value;

        return str_repeat('*', max(strlen($value) - 4, 8)) . substr($value, -4);
    }
}