<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Services\PluginSettingsService;
use Illuminate\Validation\ValidationException;

class ValidateCbtSettingsListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const LIVE_REQUIRED_FIELDS = [
        'live_japan_mid' => 'sirsoft-pay_kginicis::messages.settings_validation.live_japan_mid_required',
        'live_japan_sign_key' => 'sirsoft-pay_kginicis::messages.settings_validation.live_japan_sign_key_required',
        'japan_merchant_name' => 'sirsoft-pay_kginicis::messages.settings_validation.japan_merchant_name_required',
        'japan_merchant_name_kana' => 'sirsoft-pay_kginicis::messages.settings_validation.japan_merchant_name_kana_required',
        'japan_merchant_name_alphabet' => 'sirsoft-pay_kginicis::messages.settings_validation.japan_merchant_name_alphabet_required',
        'japan_merchant_name_short' => 'sirsoft-pay_kginicis::messages.settings_validation.japan_merchant_name_short_required',
        'japan_contact_name' => 'sirsoft-pay_kginicis::messages.settings_validation.japan_contact_name_required',
        'japan_contact_email' => 'sirsoft-pay_kginicis::messages.settings_validation.japan_contact_email_required',
        'japan_contact_phone' => 'sirsoft-pay_kginicis::messages.settings_validation.japan_contact_phone_required',
        'japan_contact_opening_hours' => 'sirsoft-pay_kginicis::messages.settings_validation.japan_contact_opening_hours_required',
    ];

    private const SAMPLE_VALUES = [
        'japan_merchant_name' => 'サンプルストア',
        'japan_merchant_name_kana' => 'サンプルストア',
        'japan_merchant_name_alphabet' => 'Sample Store',
        'japan_merchant_name_short' => 'サンプル',
        'japan_contact_name' => 'サポート窓口',
        'japan_contact_email' => 'support@example.com',
        'japan_contact_phone' => '0120-123-456',
        'japan_contact_opening_hours' => '10:00-18:00',
    ];

    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.before_save' => [
                'method' => 'validateBeforeSave',
                'priority' => 10,
            ],
        ];
    }

    public function handle(...$args): void {}

    public function validateBeforeSave(string $identifier, array $settings): void
    {
        if ($identifier !== self::PLUGIN_IDENTIFIER) {
            return;
        }

        $settings = array_merge($this->currentSettings($identifier), $settings);

        if (! $this->bool($settings['japan_enabled'] ?? false)) {
            return;
        }

        $errors = [];

        if ($this->bool($settings['is_test_mode'] ?? true)) {
            if ($this->blank($settings['test_japan_sign_key'] ?? '')) {
                $errors['test_japan_sign_key'][] = __('sirsoft-pay_kginicis::messages.settings_validation.test_japan_sign_key_required');
            }
        } else {
            foreach (self::LIVE_REQUIRED_FIELDS as $field => $messageKey) {
                if ($this->blank($settings[$field] ?? '')) {
                    $errors[$field][] = __($messageKey);
                }
            }

            foreach (self::SAMPLE_VALUES as $field => $sampleValue) {
                if (trim((string) ($settings[$field] ?? '')) === $sampleValue) {
                    $errors[$field][] = __('sirsoft-pay_kginicis::messages.settings_validation.replace_sample_value');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function currentSettings(string $identifier): array
    {
        try {
            return app(PluginSettingsService::class)->get($identifier) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function blank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }
}
