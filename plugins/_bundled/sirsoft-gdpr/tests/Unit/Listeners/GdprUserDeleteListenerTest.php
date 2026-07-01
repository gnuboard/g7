<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Listeners;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Listeners\GdprUserDeleteListener;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * GDPR 사용자 완전 삭제 cascade 리스너 단위 테스트
 *
 * 코어 `core.user.before_delete` 훅 구독 동작 검증:
 * - status 테이블(gdpr_user_consents) 명시적 삭제
 * - history 테이블(gdpr_user_consent_histories)은 user_id / ip_address /
 *   user_agent NULL 익명화 (행 보존) → GDPR Art.17 + Art.7(1) 양립
 */
class GdprUserDeleteListenerTest extends PluginTestCase
{
    private GdprUserDeleteListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $pluginSettings = $this->createMock(PluginSettingsService::class);
        $pluginSettings->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $default
        );
        $this->app->instance(PluginSettingsService::class, $pluginSettings);

        $consentService = new GdprConsentService(
            new GdprUserConsentRepository(),
            new GdprUserConsentHistoryRepository(),
            $pluginSettings,
            new CookieCategoryService($pluginSettings),
        );

        $this->listener = new GdprUserDeleteListener($consentService);
    }

    public function test_get_subscribed_hooks_returns_before_delete(): void
    {
        $hooks = GdprUserDeleteListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.user.before_delete', $hooks);
        $this->assertSame('cascadePluginData', $hooks['core.user.before_delete']['method']);
    }

    public function test_cascade_deletes_status_table(): void
    {
        $user = User::factory()->create();

        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
        ]);

        $this->listener->cascadePluginData($user);

        $this->assertDatabaseMissing('gdpr_user_consents', ['user_id' => $user->id]);
    }

    public function test_cascade_anonymizes_history_rather_than_delete(): void
    {
        $user = User::factory()->create();

        // history: 변경 이력 2건 사전 생성 (감사 추적용)
        GdprUserConsentHistory::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1.0',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        GdprUserConsentHistory::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'action' => 'revoked',
            'source' => 'mypage',
            'policy_version' => '1.0',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->listener->cascadePluginData($user);

        // history 행 자체는 보존되어야 함 (총 2건 그대로 존재)
        $remaining = GdprUserConsentHistory::query()
            ->whereIn('consent_key', ['cookie_analytics', 'cookie_marketing'])
            ->count();
        $this->assertSame(2, $remaining);

        // 단, user_id / ip_address / user_agent 는 NULL 로 익명화되어야 함
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'consent_key' => 'cookie_analytics',
            'user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'consent_key' => 'cookie_marketing',
            'user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }

    public function test_cascade_is_safe_when_no_data_exists(): void
    {
        $user = User::factory()->create();

        // 사전 데이터 0건
        $this->listener->cascadePluginData($user);

        $this->assertDatabaseMissing('gdpr_user_consents', ['user_id' => $user->id]);
        // 예외 없이 종료
        $this->assertTrue(true);
    }
}
