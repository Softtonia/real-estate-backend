<?php

namespace App\Services\Membership;

use App\Models\System\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MembershipTaxService
{
    private const CACHE_KEY = 'system:membership:tax';

    private const CACHE_TTL_SECONDS = 21600;

    private const GROUP = 'membership_tax';

    private const KEY_GST_ENABLED = 'membership.tax.gst_enabled';
    private const KEY_GST_PERCENTAGE = 'membership.tax.gst_percentage';
    private const KEY_TAX_LABEL = 'membership.tax.label';
    private const KEY_PRICES_INCLUDE_TAX = 'membership.tax.prices_include_tax';
    private const KEY_BUSINESS_STATE = 'membership.tax.business_state';
    private const KEY_GSTIN = 'membership.tax.gstin';

    public function config(): array
    {
        $settings = $this->settings();

        return [
            'gst_enabled' => filter_var(
                $settings[self::KEY_GST_ENABLED] ?? true,
                FILTER_VALIDATE_BOOLEAN
            ),

            'gst_percentage' => round((float) ($settings[self::KEY_GST_PERCENTAGE] ?? 18), 2),

            'tax_label' => (string) ($settings[self::KEY_TAX_LABEL] ?? 'GST'),

            'prices_include_tax' => filter_var(
                $settings[self::KEY_PRICES_INCLUDE_TAX] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),

            'business_state' => $settings[self::KEY_BUSINESS_STATE] ?? null,

            'gstin' => $settings[self::KEY_GSTIN] ?? null,
        ];
    }

    public function updateConfig(array $data, ?User $admin = null): array
    {
        DB::transaction(function () use ($data, $admin) {
            $this->saveSetting(
                key: self::KEY_GST_ENABLED,
                value: (bool) $data['gst_enabled'],
                type: SystemSetting::TYPE_BOOLEAN,
                admin: $admin
            );

            $this->saveSetting(
                key: self::KEY_GST_PERCENTAGE,
                value: round((float) $data['gst_percentage'], 2),
                type: $this->floatType(),
                admin: $admin
            );

            $this->saveSetting(
                key: self::KEY_TAX_LABEL,
                value: $data['tax_label'] ?? 'GST',
                type: SystemSetting::TYPE_STRING,
                admin: $admin
            );

            $this->saveSetting(
                key: self::KEY_PRICES_INCLUDE_TAX,
                value: (bool) $data['prices_include_tax'],
                type: SystemSetting::TYPE_BOOLEAN,
                admin: $admin
            );

            $this->saveSetting(
                key: self::KEY_BUSINESS_STATE,
                value: $data['business_state'] ?? null,
                type: SystemSetting::TYPE_STRING,
                admin: $admin
            );

            $this->saveSetting(
                key: self::KEY_GSTIN,
                value: $data['gstin'] ?? null,
                type: SystemSetting::TYPE_STRING,
                admin: $admin
            );
        });

        $this->clearCache();

        return $this->config();
    }

    public function calculate(float|int $amount): array
    {
        $config = $this->config();

        $amount = round(max((float) $amount, 0), 2);

        $gstEnabled = (bool) $config['gst_enabled'];
        $gstPercentage = $gstEnabled ? round((float) $config['gst_percentage'], 2) : 0.0;
        $pricesIncludeTax = (bool) $config['prices_include_tax'];

        if (! $gstEnabled || $gstPercentage <= 0 || $amount <= 0) {
            return [
                'tax_label' => $config['tax_label'],
                'gst_enabled' => $gstEnabled,
                'gst_percentage' => 0.0,
                'prices_include_tax' => $pricesIncludeTax,

                'subtotal' => $amount,
                'taxable_amount' => $amount,
                'tax_amount' => 0.0,
                'total_amount' => $amount,

                'business_state' => $config['business_state'],
                'gstin' => $config['gstin'],
            ];
        }

        if ($pricesIncludeTax) {
            $totalAmount = $amount;
            $taxableAmount = round($amount / (1 + ($gstPercentage / 100)), 2);
            $taxAmount = round($totalAmount - $taxableAmount, 2);
        } else {
            $taxableAmount = $amount;
            $taxAmount = round(($taxableAmount * $gstPercentage) / 100, 2);
            $totalAmount = round($taxableAmount + $taxAmount, 2);
        }

        return [
            'tax_label' => $config['tax_label'],
            'gst_enabled' => $gstEnabled,
            'gst_percentage' => $gstPercentage,
            'prices_include_tax' => $pricesIncludeTax,

            'subtotal' => $amount,
            'taxable_amount' => $taxableAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,

            'business_state' => $config['business_state'],
            'gstin' => $config['gstin'],
        ];
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function settings(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return SystemSetting::query()
                ->where('group', self::GROUP)
                ->where('status', true)
                ->get()
                ->mapWithKeys(function (SystemSetting $setting) {
                    return [
                        $setting->key => $setting->formattedValue(),
                    ];
                })
                ->all();
        });
    }

    private function saveSetting(string $key, mixed $value, string $type, ?User $admin = null): void
    {
        $setting = SystemSetting::query()->firstOrNew([
            'key' => $key,
        ]);

        if (! $setting->exists) {
            $setting->created_by = $admin?->id;
        }

        $setting->forceFill([
            'group' => self::GROUP,
            'value' => $this->prepareValue($value),
            'value_type' => $type,
            'is_encrypted' => false,
            'status' => true,
            'updated_by' => $admin?->id,
        ])->save();
    }

    private function prepareValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function floatType(): string
    {
        return defined(SystemSetting::class . '::TYPE_FLOAT')
            ? SystemSetting::TYPE_FLOAT
            : 'float';
    }
}