<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Services;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentHistoryRepository;
use Plugins\Sirsoft\Gdpr\Repositories\GdprUserConsentRepository;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

class GdprConsentServiceTest extends PluginTestCase
{
    private GdprConsentService $service;

    /** @var PluginSettingsService&\PHPUnit\Framework\MockObject\MockObject */
    private PluginSettingsService $pluginSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginSettings = $this->createMock(PluginSettingsService::class);
        // 정책 버전은 gdpr_policy_versions 테이블이 SSoT (마이그레이션 시 initial 행 자동 시드 → v1).
        // pluginSettings 의 cookie_policy_version 은 더 이상 출처가 아니지만 다른 키 호출 호환을 위해 Mock 유지.
        $this->pluginSettings->method('get')->willReturnCallback(
            fn (string $id, string $key, mixed $default = null) => $default
        );

        $this->service = new GdprConsentService(
            new GdprUserConsentRepository(),
            new GdprUserConsentHistoryRepository(),
            $this->pluginSettings,
            new \Plugins\Sirsoft\Gdpr\Services\CookieCategoryService($this->pluginSettings),
        );
    }

    public function test_update_consent_creates_status_and_history_for_member(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');

        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consent_count' => 1,
            'last_source' => 'banner',
            'policy_version' => '1',
        ]);

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1',
        ]);
    }

    public function test_update_consent_records_history_only_for_guest(): void
    {
        $sessionId = 'guest-session-abc';

        $this->service->updateConsent(null, $sessionId, 'cookie_marketing', true, 'banner');

        $this->assertDatabaseCount('gdpr_user_consents', 0);
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => null,
            'session_id' => $sessionId,
            'consent_key' => 'cookie_marketing',
            'action' => 'granted',
        ]);
    }

    public function test_update_consent_is_noop_when_state_unchanged(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');

        $this->assertSame(1, GdprUserConsent::where('user_id', $user->id)->count());
        $this->assertSame(1, GdprUserConsentHistory::where('user_id', $user->id)->count());

        $consent = GdprUserConsent::where('user_id', $user->id)->first();
        $this->assertSame(1, $consent->consent_count);
    }

    public function test_revoke_appends_history_and_flips_status(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_analytics', false, 'mypage');

        $consent = GdprUserConsent::where('user_id', $user->id)->first();
        $this->assertFalse($consent->is_consented);
        $this->assertNotNull($consent->revoked_at);
        $this->assertSame('mypage', $consent->last_source);

        $this->assertSame(2, GdprUserConsentHistory::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'action' => 'revoked',
            'source' => 'mypage',
        ]);
    }

    public function test_consent_count_increments_on_re_grant(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_analytics', false, 'mypage');
        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'mypage');

        $consent = GdprUserConsent::where('user_id', $user->id)->first();
        $this->assertTrue($consent->is_consented);
        $this->assertSame(2, $consent->consent_count);
    }

    public function test_revoke_all_on_withdraw_revokes_only_active_consents(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_marketing', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_marketing', false, 'mypage');

        $this->service->revokeAllOnWithdraw($user->id);

        $analytics = GdprUserConsent::where('user_id', $user->id)
            ->where('consent_key', 'cookie_analytics')->first();
        $marketing = GdprUserConsent::where('user_id', $user->id)
            ->where('consent_key', 'cookie_marketing')->first();

        $this->assertFalse($analytics->is_consented);
        $this->assertSame('withdraw', $analytics->last_source);
        $this->assertFalse($marketing->is_consented);
    }

    public function test_purge_on_user_delete_removes_status_and_anonymizes_history(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_marketing', true, 'banner');

        $userId = $user->id;

        $this->service->purgeOnUserDelete($userId);

        $this->assertDatabaseCount('gdpr_user_consents', 0);
        $this->assertSame(2, GdprUserConsentHistory::count());
        $this->assertSame(0, GdprUserConsentHistory::where('user_id', $userId)->count());
        $this->assertSame(2, GdprUserConsentHistory::whereNull('user_id')->count());
    }

    public function test_needs_renewal_detects_outdated_policy_version(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');

        // 정책 버전이 v2 로 bump — gdpr_policy_versions 에 새 row INSERT
        \Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => \Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType::Material->value,
            'snapshot' => [],
        ]);

        $this->assertTrue($this->service->needsRenewal($user->id));
    }

    public function test_needs_renewal_false_when_versions_match(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');

        $this->assertFalse($this->service->needsRenewal($user->id));
    }

    public function test_get_active_consents_excludes_revoked(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_marketing', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_marketing', false, 'mypage');

        $active = $this->service->getActiveConsents($user->id);

        $this->assertCount(1, $active);
        $this->assertSame('cookie_analytics', $active->first()->consent_key);
    }

    /**
     * v1.5.0 자동 차단 엔진 SSoT 회귀 가드:
     * 회원의 카테고리별 동의 상태가 cookie_ 접두사 제거된 카테고리 키로 매핑되어야 함.
     */
    public function test_get_current_cookie_consents_returns_category_map_for_member(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_necessary', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_marketing', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_marketing', false, 'mypage');

        $consents = $this->service->getCurrentCookieConsents($user->id, null);

        $this->assertEqualsCanonicalizing(
            ['necessary' => true, 'analytics' => true, 'marketing' => false],
            $consents
        );
        $this->assertCount(3, $consents);
    }

    /**
     * 게스트는 history append-only 이므로 가장 최근 action 으로 카테고리별 동의 결정.
     */
    public function test_get_current_cookie_consents_uses_latest_history_for_guest(): void
    {
        $sessionId = 'guest-session-zzz';

        $this->service->updateConsent(null, $sessionId, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent(null, $sessionId, 'cookie_marketing', true, 'banner');
        $this->service->updateConsent(null, $sessionId, 'cookie_marketing', false, 'preference_center');

        $consents = $this->service->getCurrentCookieConsents(null, $sessionId);

        $this->assertEqualsCanonicalizing(
            ['analytics' => true, 'marketing' => false],
            $consents
        );
        $this->assertCount(2, $consents);
    }

    /**
     * 정책 버전이 다른 동의는 결과에서 제외 (재동의 트리거).
     */
    public function test_get_current_cookie_consents_excludes_outdated_policy_version(): void
    {
        $user = User::factory()->create();

        // 과거 정책 버전 동의 직접 삽입
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '0.9',
            'last_source' => 'banner',
        ]);

        $consents = $this->service->getCurrentCookieConsents($user->id, null);

        $this->assertSame([], $consents);
    }

    /**
     * 식별자 미제공 또는 동의 없음 → 빈 배열 반환.
     */
    public function test_get_current_cookie_consents_returns_empty_when_no_identifier(): void
    {
        $this->assertSame([], $this->service->getCurrentCookieConsents(null, null));
        $this->assertSame([], $this->service->getCurrentCookieConsents(null, ''));
    }

    /**
     * 정책 버전 bump 후 같은 값으로 재호출 시 status.policy_version 갱신 + history INSERT.
     *
     * 회귀: noop 가드가 is_consented 만 비교하면 정책 v2 bump 후 "모두 동의" 클릭이
     * 즉시 return 되어 status.policy_version 이 v1 에 머무르고 history 도 미생성.
     * 결과: hasCurrentCookieConsent(v2) → false → needs_renewal 영구 true (Art.7(1) 입증 책임 위반).
     * 가드는 (is_consented 동일) AND (policy_version 동일) 일 때만 noop 이어야 함.
     */
    public function test_update_consent_renews_policy_version_when_value_unchanged_after_policy_bump(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');

        // v1 동의 상태 검증
        $beforeStatus = GdprUserConsent::where('user_id', $user->id)->first();
        $this->assertSame('1', (string) $beforeStatus->policy_version);
        $this->assertSame(1, $beforeStatus->consent_count);
        $this->assertSame(1, GdprUserConsentHistory::where('user_id', $user->id)->count());

        // 정책 버전 v2 bump
        \Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => \Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType::Material->value,
            'snapshot' => [],
        ]);

        // bump 후 같은 값(true) 으로 재요청 — "모두 동의" 클릭 시나리오
        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');

        $afterStatus = GdprUserConsent::where('user_id', $user->id)->first();
        $this->assertSame('2', (string) $afterStatus->policy_version, 'status.policy_version 이 v2 로 갱신되어야 함');
        $this->assertSame(2, $afterStatus->consent_count, '재동의는 consent_count 1 증가 (Art.7 입증 책임)');

        $this->assertSame(2, GdprUserConsentHistory::where('user_id', $user->id)->count(), 'v2 재동의 history 행 INSERT 되어야 함');
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'policy_version' => '2',
        ]);
    }

    /**
     * 같은 정책 버전 안에서 같은 값 재요청은 여전히 noop (중복 이력 방지 의도 유지).
     */
    public function test_update_consent_noop_still_holds_within_same_policy_version(): void
    {
        $user = User::factory()->create();

        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');
        $this->service->updateConsent($user->id, null, 'cookie_analytics', true, 'banner');

        $this->assertSame(1, GdprUserConsent::where('user_id', $user->id)->count());
        $this->assertSame(1, GdprUserConsentHistory::where('user_id', $user->id)->count());
        $this->assertSame(1, GdprUserConsent::where('user_id', $user->id)->first()->consent_count);
    }
}
