<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Listeners;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Gdpr\Enums\PrivacyNoticeStatus;
use Plugins\Sirsoft\Gdpr\Listeners\GdprUserDeleteListener;
use Plugins\Sirsoft\Gdpr\Models\GdprPrivacyNoticeLog;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPrivacyNoticeRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;
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

    /**
     * 기존 테스트 DB를 트랜잭션으로 격리하고 전체 DDL 초기화를 피합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 동의와 통지 이력 저장소를 사용하는 삭제 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $pluginSettings = $this->createMock(PluginSettingsService::class);
        $pluginSettings->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $default
        );
        $this->app->instance(PluginSettingsService::class, $pluginSettings);

        $consentService = new GdprConsentService(
            new GdprUserConsentRepository,
            new GdprUserConsentHistoryRepository,
            $pluginSettings,
            new CookieCategoryService($pluginSettings),
        );

        $this->listener = new GdprUserDeleteListener(
            $consentService,
            new GdprAnnualNoticeService(new GdprPrivacyNoticeRepository, $pluginSettings),
        );
    }

    /**
     * 완전 삭제 전 훅에 개인정보 정리 메서드가 연결되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects history_anonymized
     */
    public function test_get_subscribed_hooks_returns_before_delete(): void
    {
        $hooks = GdprUserDeleteListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.user.before_delete', $hooks);
        $this->assertSame('cascadePluginData', $hooks['core.user.before_delete']['method']);
    }

    /**
     * 완전 삭제 전에 회원의 현재 동의 상태가 제거되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects history_anonymized
     */
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

    /**
     * 동의 증적 두 행을 보존하면서 직접 식별정보를 익명화하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects history_anonymized
     */
    public function test_cascade_anonymizes_history_rather_than_delete(): void
    {
        $user = User::factory()->create();

        // history: 변경 이력 2건 사전 생성 (감사 추적용)
        $first = GdprUserConsentHistory::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1.0',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $second = GdprUserConsentHistory::create([
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
            ->whereIn('id', [$first->id, $second->id])
            ->count();
        $this->assertSame(2, $remaining);

        // 단, user_id / ip_address / user_agent 는 NULL 로 익명화되어야 함
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'id' => $first->id,
            'consent_key' => 'cookie_analytics',
            'user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'id' => $second->id,
            'consent_key' => 'cookie_marketing',
            'user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
        ]);
    }

    /**
     * 동의와 통지 증적이 없는 회원도 안전하게 정리되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects history_anonymized
     */
    public function test_cascade_is_safe_when_no_data_exists(): void
    {
        $user = User::factory()->create();

        // 사전 데이터 0건
        $this->listener->cascadePluginData($user);

        $this->assertDatabaseMissing('gdpr_user_consents', ['user_id' => $user->id]);
        // 예외 없이 종료
        $this->assertTrue(true);
    }

    /**
     * 삭제 리스너가 실제 통지 증적을 보존하고 회원 연결만 익명화하는지 검증합니다.
     *
     * @scenario flow=scheduled
     *
     * @effects history_anonymized
     *
     * @return void 반환값 없음
     */
    public function test_cascade_anonymizes_annual_notice_without_removing_evidence(): void
    {
        $user = User::factory()->create();
        $log = GdprPrivacyNoticeLog::create([
            'user_id' => $user->id,
            'attempt_id' => (string) Str::uuid(),
            'status' => PrivacyNoticeStatus::Sent,
            'channels' => ['database'],
            'is_test' => false,
            'sent_at' => now(),
            'snapshot' => ['locale' => 'ko', 'sections' => []],
        ]);

        $this->listener->cascadePluginData($user);

        $preserved = $log->fresh();
        $this->assertNotNull($preserved);
        $this->assertNull($preserved->user_id);
        $this->assertSame(PrivacyNoticeStatus::Sent, $preserved->status);
        $this->assertSame($log->snapshot, $preserved->snapshot);
    }
}
