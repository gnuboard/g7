<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Extension\HookManager;
use Plugins\Sirsoft\PayKginicis\Listeners\ValidateCbtSettingsListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class AdminPluginSettingsValidationTest extends PluginTestCase
{
    public function test_live_cbt_settings_validation_returns_field_errors_on_save(): void
    {
        app()->setLocale('ko');

        $admin = $this->createAdminUser(['core.plugins.update']);
        $originalBeforeSaveHooks = HookManager::getHooks()['core.plugin_settings.before_save'] ?? [];

        HookManager::addAction(
            'core.plugin_settings.before_save',
            fn (string $identifier, array $settings) => app(ValidateCbtSettingsListener::class)
                ->validateBeforeSave($identifier, $settings),
            1
        );

        try {
            $response = $this->actingAs($admin)
                ->withHeaders(['Accept-Language' => 'ko'])
                ->putJson('/api/admin/plugins/sirsoft-pay_kginicis/settings', [
                    'is_test_mode' => false,
                    'japan_enabled' => true,
                    'test_japan_sign_key' => 'test-cbt-hash-key',
                    'live_japan_mid' => '',
                    'live_japan_sign_key' => '',
                    'japan_merchant_name' => 'サンプルストア',
                    'japan_merchant_name_kana' => 'サンプルストア',
                    'japan_merchant_name_alphabet' => 'Sample Store',
                    'japan_merchant_name_short' => 'サンプル',
                    'japan_contact_name' => 'サポート窓口',
                    'japan_contact_email' => 'support@example.com',
                    'japan_contact_phone' => '0120-123-456',
                    'japan_contact_opening_hours' => '10:00-18:00',
                ]);

            $response->assertStatus(422)
                ->assertJsonPath(
                    'errors.live_japan_mid.0',
                    '운영 모드에서 일본 결제(CBT)를 사용하려면 라이브 일본 MID가 필요합니다.'
                )
                ->assertJsonPath(
                    'errors.japan_contract_info.0',
                    '운영 모드에서는 일본 가맹점 표시 정보의 샘플값을 실제 계약 정보로 변경하세요.'
                );

            $errors = $response->json('errors');
            $this->assertCount(1, $errors['japan_contract_info'] ?? []);
            $this->assertArrayNotHasKey('japan_merchant_name', $errors);
            $this->assertArrayNotHasKey('japan_merchant_name_alphabet', $errors);
            $this->assertArrayNotHasKey('japan_contact_email', $errors);
        } finally {
            $this->restoreBeforeSaveHooks($originalBeforeSaveHooks);
        }
    }

    /**
     * @param  array<int, array<int, callable>>  $hooks
     */
    private function restoreBeforeSaveHooks(array $hooks): void
    {
        HookManager::clearAction('core.plugin_settings.before_save');

        foreach ($hooks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                HookManager::addAction('core.plugin_settings.before_save', $callback, (int) $priority);
            }
        }
    }
}
