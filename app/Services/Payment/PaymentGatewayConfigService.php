<?php

namespace App\Services\Payment;

use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class PaymentGatewayConfigService
{
    private const CACHE_KEY = 'system:payment_gateway:razorpay';

    public function razorpayConfig(bool $masked = false): array
    {
        return Cache::store('redis')->remember(
            self::CACHE_KEY . ':' . ($masked ? 'masked' : 'plain'),
            600,
            function () use ($masked) {
                $mode = $this->setting('payment.razorpay.mode', config('services.razorpay.mode', 'test'));

                $config = [
                    'enabled' => (bool) $this->setting('payment.razorpay.enabled', true),
                    'mode' => $mode === 'live' ? 'live' : 'test',
                    'currency' => $this->setting('payment.razorpay.currency', 'INR'),

                    'test_key_id' => $this->setting('payment.razorpay.test.key_id', config('services.razorpay.test_key_id')),
                    'test_key_secret' => $this->setting('payment.razorpay.test.key_secret', config('services.razorpay.test_key_secret')),
                    'test_webhook_secret' => $this->setting('payment.razorpay.test.webhook_secret', config('services.razorpay.test_webhook_secret')),

                    'live_key_id' => $this->setting('payment.razorpay.live.key_id', config('services.razorpay.live_key_id')),
                    'live_key_secret' => $this->setting('payment.razorpay.live.key_secret', config('services.razorpay.live_key_secret')),
                    'live_webhook_secret' => $this->setting('payment.razorpay.live.webhook_secret', config('services.razorpay.live_webhook_secret')),
                ];

                if ($masked) {
                    $config['test_key_secret'] = $this->mask($config['test_key_secret']);
                    $config['test_webhook_secret'] = $this->mask($config['test_webhook_secret']);
                    $config['live_key_secret'] = $this->mask($config['live_key_secret']);
                    $config['live_webhook_secret'] = $this->mask($config['live_webhook_secret']);
                }

                return $config;
            }
        );
    }

    public function activeRazorpayCredentials(): array
    {
        $config = $this->razorpayConfig(false);

        $mode = $config['mode'] === 'live' ? 'live' : 'test';

        return [
            'enabled' => (bool) $config['enabled'],
            'mode' => $mode,
            'currency' => $config['currency'] ?: 'INR',
            'key_id' => $config[$mode . '_key_id'] ?? null,
            'key_secret' => $config[$mode . '_key_secret'] ?? null,
            'webhook_secret' => $config[$mode . '_webhook_secret'] ?? null,
        ];
    }

    public function updateRazorpayConfig(array $data, ?User $admin = null): array
    {
        if (array_key_exists('enabled', $data)) {
            $this->saveSetting('payment.razorpay.enabled', $data['enabled'], 'boolean', false, $admin);
        }

        if (array_key_exists('mode', $data)) {
            $this->saveSetting('payment.razorpay.mode', $data['mode'], 'string', false, $admin);
        }

        if (array_key_exists('currency', $data)) {
            $this->saveSetting('payment.razorpay.currency', strtoupper($data['currency'] ?? 'INR'), 'string', false, $admin);
        }

        foreach (['test', 'live'] as $mode) {
            if (array_key_exists($mode . '_key_id', $data)) {
                $this->saveSetting(
                    "payment.razorpay.{$mode}.key_id",
                    $data[$mode . '_key_id'],
                    'string',
                    false,
                    $admin
                );
            }

            if (! empty($data[$mode . '_key_secret']) && ! str_contains($data[$mode . '_key_secret'], '*')) {
                $this->saveSetting(
                    "payment.razorpay.{$mode}.key_secret",
                    $data[$mode . '_key_secret'],
                    'string',
                    true,
                    $admin
                );
            }

            if (! empty($data[$mode . '_webhook_secret']) && ! str_contains($data[$mode . '_webhook_secret'], '*')) {
                $this->saveSetting(
                    "payment.razorpay.{$mode}.webhook_secret",
                    $data[$mode . '_webhook_secret'],
                    'string',
                    true,
                    $admin
                );
            }
        }

        $this->clearCache();

        return $this->razorpayConfig(true);
    }

    public function clearCache(): void
    {
        Cache::store('redis')->forget(self::CACHE_KEY . ':plain');
        Cache::store('redis')->forget(self::CACHE_KEY . ':masked');
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable('system_settings')) {
            return $default;
        }

        $setting = SystemSetting::query()
            ->where('key', $key)
            ->where('status', true)
            ->first();

        return $setting ? $setting->formattedValue() : $default;
    }

    private function saveSetting(
        string $key,
        mixed $value,
        string $type,
        bool $encrypted,
        ?User $admin
    ): void {
        $storedValue = $encrypted
            ? Crypt::encryptString((string) $value)
            : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'group' => 'payment_gateway',
                'value' => $storedValue,
                'value_type' => $type,
                'is_encrypted' => $encrypted,
                'status' => true,
                'updated_by' => $admin?->id,
                'created_by' => $admin?->id,
            ]
        );
    }

    private function mask(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return str_repeat('*', max(strlen($value) - 4, 8)) . substr($value, -4);
    }
}