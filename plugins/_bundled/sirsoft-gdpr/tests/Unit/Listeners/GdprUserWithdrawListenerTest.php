<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Listeners;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Listeners\GdprUserWithdrawListener;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 회원탈퇴 리스너 단위 테스트
 *
 * 코어 `core.user.after_withdraw` 훅 구독 동작 검증:
 * - 활성 동의 모두 철회 (status UPDATE + history INSERT, source=withdraw)
 */
class GdprUserWithdrawListenerTest extends PluginTestCase
{
    private GdprUserWithdrawListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $pluginSettings = $this->createMock(PluginSettingsService::class);
        $pluginSettings->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => match ($key) {
                'cookie_policy_version' => '1.0',
                default => $default,
            }
        );
        $this->app->instance(PluginSettingsService::class, $pluginSettings);

        $consentService = new GdprConsentService(
            new GdprUserConsentRepository(),
            new GdprUserConsentHistoryRepository(),
            $pluginSettings,
            new CookieCategoryService($pluginSettings),
        );

        $this->listener = new GdprUserWithdrawListener($consentService);
    }

    public function test_get_subscribed_hooks_returns_after_withdraw(): void
    {
        $hooks = GdprUserWithdrawListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.user.after_withdraw', $hooks);
        $this->assertSame('handleWithdraw', $hooks['core.user.after_withdraw']['method']);
    }

    public function test_handle_withdraw_revokes_active_consents(): void
    {
        $user = User::factory()->create();

        // 활성 동의 2건 사전 생성
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1.0',
            'last_source' => 'banner',
        ]);

        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1.0',
            'last_source' => 'banner',
        ]);

        $this->listener->handleWithdraw($user);

        // status: 활성 동의 모두 철회 처리
        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => false,
        ]);
        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => false,
        ]);

        // history: source=withdraw 의 revoked 행 INSERT
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'revoked',
            'source' => 'withdraw',
        ]);
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'action' => 'revoked',
            'source' => 'withdraw',
        ]);
    }

    public function test_handle_withdraw_is_noop_when_no_active_consents(): void
    {
        $user = User::factory()->create();

        // 사전에 활성 동의가 전혀 없는 사용자
        $this->listener->handleWithdraw($user);

        // 새로 생성되는 history 레코드가 없어야 함
        $this->assertDatabaseMissing('gdpr_user_consent_histories', [
            'user_id' => $user->id,
        ]);
    }
}
