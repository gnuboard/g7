<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\User;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 사용자 동의 동기화/이력/철회 API 테스트
 *
 * - GET  /api/plugins/sirsoft-gdpr/consent/me
 * - GET  /api/plugins/sirsoft-gdpr/consent/history
 * - POST /api/plugins/sirsoft-gdpr/consent/revoke
 */
class GdprConsentControllerTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => match ($key) {
                'cookie_policy_version' => '1.0',
                default => $default,
            }
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    public function test_consent_me_requires_auth(): void
    {
        $this->getJson('/api/plugins/sirsoft-gdpr/consent/me')
            ->assertUnauthorized();
    }

    public function test_consent_me_returns_consent_matrix_for_all_catalog_categories(): void
    {
        // 회귀 가드: consent/me 가 활성 동의만이 아니라 카탈로그 전체 매트릭스를 반환해야 함
        // (Art.7(3) 대칭성 — 마이페이지에서 철회/재동의/신규 동의 모두 처리 가능).
        // 기본 카탈로그 = necessary + analytics + marketing (3종).
        $user = User::factory()->create();

        // analytics 만 활성, marketing 은 철회됨, necessary 는 row 없음 (가상 비활성으로 합성됨)
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1',
            'last_source' => 'banner',
        ]);

        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => false,
            'revoked_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1',
            'last_source' => 'mypage',
        ]);

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/me');

        // Phase 1: 카탈로그가 4종 (necessary/functional/analytics/marketing) 으로 확장됨
        $response->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonCount(4, 'data.consents');

        // 각 카테고리의 메타가 정확한지 회귀 가드
        $consents = collect($response->json('data.consents'))->keyBy('consent_key');

        // necessary: 카탈로그에만 있음 → 가상 비활성 + is_required=true → can_grant/can_revoke 둘 다 false
        $this->assertSame(false, $consents->get('cookie_necessary')['is_consented']);
        $this->assertSame(true, $consents->get('cookie_necessary')['is_required']);
        $this->assertSame(false, $consents->get('cookie_necessary')['can_revoke']);
        $this->assertSame(false, $consents->get('cookie_necessary')['can_grant']);

        // Phase 1: functional 카테고리도 카탈로그에 있음 → 가상 비활성 + is_required=false → can_grant=true
        $this->assertSame(false, $consents->get('cookie_functional')['is_consented']);
        $this->assertSame(false, $consents->get('cookie_functional')['is_required']);
        $this->assertSame(false, $consents->get('cookie_functional')['can_revoke']);
        $this->assertSame(true, $consents->get('cookie_functional')['can_grant']);

        // analytics: 활성 + 선택형 → can_revoke=true
        $this->assertSame(true, $consents->get('cookie_analytics')['is_consented']);
        $this->assertSame(false, $consents->get('cookie_analytics')['is_required']);
        $this->assertSame(true, $consents->get('cookie_analytics')['can_revoke']);
        $this->assertSame(false, $consents->get('cookie_analytics')['can_grant']);

        // marketing: 철회됨 + 선택형 → can_grant=true (Art.7(3) 대칭성)
        $this->assertSame(false, $consents->get('cookie_marketing')['is_consented']);
        $this->assertSame(false, $consents->get('cookie_marketing')['is_required']);
        $this->assertSame(false, $consents->get('cookie_marketing')['can_revoke']);
        $this->assertSame(true, $consents->get('cookie_marketing')['can_grant']);
    }

    public function test_consent_history_returns_user_history(): void
    {
        $user = User::factory()->create();

        GdprUserConsentHistory::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
            'policy_version' => '1',
        ]);

        $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/history')
            ->assertOk()
            ->assertJsonCount(1, 'data.histories')
            ->assertJsonPath('data.histories.0.consent_key', 'cookie_analytics')
            ->assertJsonPath('data.histories.0.action', 'granted');
    }

    public function test_revoke_creates_history_and_updates_status(): void
    {
        $user = User::factory()->create();

        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1',
            'last_source' => 'banner',
        ]);

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/revoke', [
            'consent_key' => 'cookie_analytics',
        ])->assertOk();

        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => false,
        ]);

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'revoked',
            'source' => 'mypage',
        ]);
    }

    public function test_revoke_required_category_is_blocked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/revoke', [
            'consent_key' => 'cookie_necessary',
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | 회원 동의 부여 (POST /consent/grant) — Art.7(3) 자유 변경권
    |--------------------------------------------------------------------------
    */

    public function test_grant_creates_active_consent_for_unsubscribed_key(): void
    {
        // 회귀 가드: 카탈로그에는 있지만 user_consents 에 row 없는 항목 → 신규 동의 (Art.7(3) 부여권).
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/grant', [
            'consent_key' => 'cookie_analytics',
        ])->assertOk();

        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
        ]);

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'mypage',
        ]);
    }

    public function test_grant_reactivates_revoked_consent(): void
    {
        // 회귀 가드: 철회한 항목을 다시 동의 — Art.7(3) 대칭성. is_consented=true 로 복원 + 이력 append.
        $user = User::factory()->create();

        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => false,
            'revoked_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1',
            'last_source' => 'mypage',
        ]);

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/grant', [
            'consent_key' => 'cookie_marketing',
        ])->assertOk();

        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => true,
        ]);

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'action' => 'granted',
            'source' => 'mypage',
        ]);
    }

    public function test_grant_rejects_invalid_consent_key(): void
    {
        // 회귀 가드: 카탈로그 화이트리스트 외 키는 422.
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/grant', [
            'consent_key' => 'cookie_unknown_xyz',
        ])->assertStatus(422);
    }

    public function test_grant_requires_authentication(): void
    {
        $this->postJson('/api/plugins/sirsoft-gdpr/consent/grant', [
            'consent_key' => 'cookie_analytics',
        ])->assertUnauthorized();
    }

    /**
     * consent/me 응답이 current_policy_version 메타를 노출.
     *
     * #23 — 사용자가 자신의 동의 시점 정책 버전 vs 현재 정책 버전을 비교 가능 (Art.7(1) 투명성).
     */
    public function test_consent_me_returns_current_policy_version(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/me');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['user_id', 'needs_renewal', 'current_policy_version', 'consents']]);

        $version = $response->json('data.current_policy_version');
        $this->assertIsString($version);
        $this->assertNotSame('', $version);
    }

    /**
     * needsRenewal 판정에서 *필수 쿠키 제외* 회귀 가드.
     *
     * #22 + 필수 쿠키 검토 — 필수 쿠키만 옛 버전이고 선택형은 모두 최신이면 needs_renewal=false.
     * 옛 동작 (모든 동의 검사) 으로 회귀하면 true 가 되어 amber 박스가 영구 노출되는 버그 재발.
     */
    public function test_needs_renewal_ignores_required_category_old_version(): void
    {
        $user = User::factory()->create();
        $currentVersion = app(\Plugins\Sirsoft\Gdpr\Services\GdprConsentService::class)->getCurrentPolicyVersion();

        // 필수 쿠키 — 옛 버전 (자동 갱신 안 함)
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_necessary',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '0.0', // 옛 버전
            'last_source' => 'banner',
        ]);

        // 선택형 — 현재 버전
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => $currentVersion,
            'last_source' => 'banner',
        ]);

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/me');

        $response->assertOk()
            ->assertJsonPath('data.needs_renewal', false);
    }

    /**
     * needsRenewal 은 *활성 동의* 만 검사한다 — 철회 상태 + 옛 버전 row 는 무시.
     *
     * 회귀 시나리오: 회원이 모든 선택형 동의를 철회한 뒤 정책 버전이 올라가면, 모든 선택형 row 가
     * "철회 상태 + 옛 버전" 이 된다. 이 때 needs_renewal=true 를 반환하면 마이페이지 amber
     * 박스가 *영구히* 노출됨 — 「전체 항목 다시 동의」 가 활성 동의만 갱신하므로 박스를 닫을
     * 수단이 없는 데드락 회귀.
     *
     * 정상 동작: 철회 상태는 사용자의 명시적 의사 표명이므로 *재동의 요구 대상 아님*. 활성 동의가
     * 모두 최신 버전이면 needs_renewal=false.
     */
    public function test_needs_renewal_ignores_revoked_optional_old_version(): void
    {
        $user = User::factory()->create();
        $currentVersion = app(\Plugins\Sirsoft\Gdpr\Services\GdprConsentService::class)->getCurrentPolicyVersion();

        // 분석 — 활성 + 최신 (재동의 불요)
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => $currentVersion,
            'last_source' => 'mypage_renew_all',
        ]);

        // 마케팅 — 철회 상태 + 옛 버전 (사용자가 명시적으로 철회한 의사. needs_renewal 무시 대상)
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => false,
            'revoked_at' => now(),
            'consent_count' => 1,
            'policy_version' => '0.0',
            'last_source' => 'mypage',
        ]);

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/me');

        $response->assertOk()
            ->assertJsonPath('data.needs_renewal', false);
    }

    /**
     * renew-all 엔드포인트 — 활성 선택형 동의의 policy_version 만 새 버전으로 bump.
     *
     * #19 — 필수 쿠키 / 철회 상태는 대상 외. 각 갱신 항목마다 history 행 누적.
     */
    public function test_renew_all_bumps_active_optional_consents_only(): void
    {
        $user = User::factory()->create();
        $currentVersion = app(\Plugins\Sirsoft\Gdpr\Services\GdprConsentService::class)->getCurrentPolicyVersion();

        // 필수 — 옛 버전, 활성 (대상 외)
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_necessary',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '0.0',
            'last_source' => 'banner',
        ]);

        // analytics — 옛 버전, 활성 (대상)
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '0.0',
            'last_source' => 'banner',
        ]);

        // marketing — 옛 버전, 철회 (대상 외 — 사용자 의사 보존)
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => false,
            'revoked_at' => now(),
            'consent_count' => 1,
            'policy_version' => '0.0',
            'last_source' => 'mypage',
        ]);

        $response = $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/renew-all');

        $response->assertOk()
            ->assertJsonPath('data.renewed', 1);

        // analytics 만 새 버전 + consent_count 증가
        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'policy_version' => $currentVersion,
            'last_source' => 'mypage_renew_all',
            'consent_count' => 2,
        ]);

        // 필수 / 철회 항목은 옛 버전 유지
        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_necessary',
            'policy_version' => '0.0',
        ]);
        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'policy_version' => '0.0',
            'is_consented' => false,
        ]);

        // history 행 누적 (granted)
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'mypage_renew_all',
        ]);
    }

    /**
     * acknowledge-policy 엔드포인트가 D4 결정 (즉시 제거) 으로 사라졌음을 확인하는 회귀 가드.
     *
     * 베타 단계에서 단순 dismiss 로 동작하던 acknowledgePolicy 가 GDPR 부적합으로 제거됨.
     * "현 상태 유지" 액션은 `renew-all` (적극적 동의 표명) 로 대체됨.
     */
    public function test_acknowledge_policy_endpoint_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/acknowledge-policy');

        $response->assertNotFound();
    }

    /**
     * Service::acknowledgeCurrentPolicy 메서드가 제거되었음을 보장하는 회귀 가드.
     *
     * 호출처가 사라진 dead code 였고, history 'acknowledged' 액션은 옛 행 호환을 위해 enum 만 잔존.
     */
    public function test_acknowledge_current_policy_service_method_no_longer_exists(): void
    {
        $service = app(\Plugins\Sirsoft\Gdpr\Services\GdprConsentService::class);

        $this->assertFalse(
            method_exists($service, 'acknowledgeCurrentPolicy'),
            'GdprConsentService::acknowledgeCurrentPolicy 는 D4 결정으로 제거되어야 합니다.'
        );
    }

    /**
     * Resource 의 needs_renewal_this_item 플래그 + can_grant 분기 회귀 가드.
     *
     * #21 — 활성 + 옛 버전 → can_grant=true (최신 정책으로 갱신 가능).
     */
    public function test_resource_exposes_needs_renewal_this_item_for_outdated_active(): void
    {
        $user = User::factory()->create();
        $currentVersion = app(\Plugins\Sirsoft\Gdpr\Services\GdprConsentService::class)->getCurrentPolicyVersion();

        // analytics — 활성 + 옛 버전 → needs_renewal_this_item=true / can_grant=true
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '0.0',
            'last_source' => 'banner',
        ]);

        // marketing — 활성 + 현재 버전 → needs_renewal_this_item=false / can_grant=false
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => $currentVersion,
            'last_source' => 'banner',
        ]);

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/me');

        $consents = collect($response->json('data.consents'))->keyBy('consent_key');

        // analytics — 옛 버전 활성
        $this->assertSame(true, $consents->get('cookie_analytics')['needs_renewal_this_item']);
        $this->assertSame(true, $consents->get('cookie_analytics')['can_grant']);
        $this->assertSame(true, $consents->get('cookie_analytics')['can_revoke']);

        // marketing — 현재 버전 활성
        $this->assertSame(false, $consents->get('cookie_marketing')['needs_renewal_this_item']);
        $this->assertSame(false, $consents->get('cookie_marketing')['can_grant']);
        $this->assertSame(true, $consents->get('cookie_marketing')['can_revoke']);

        // 필수 — 항상 needs_renewal_this_item=false / can_grant=false
        $this->assertSame(false, $consents->get('cookie_necessary')['needs_renewal_this_item']);
        $this->assertSame(false, $consents->get('cookie_necessary')['can_grant']);
    }

    /*
    |--------------------------------------------------------------------------
    | history.categories 매트릭스 자동 보존 — GDPR Art.7(1) 입증 책임
    | (마이페이지 grant/revoke/renewAll 모든 경로에서 변경 시점의 회원 의사 전체 매트릭스가
    |  history 에 immutable 기록되는지 검증. 이전: null 저장되어 admin 동의 이력 펼침 영역에서
    |  카테고리 스냅샷이 비어 보이던 회귀)
    |--------------------------------------------------------------------------
    */

    public function test_revoke_records_full_categories_snapshot_in_history(): void
    {
        $user = User::factory()->create();

        // 회원이 분석/마케팅 둘 다 활성 동의 상태로 시작
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1',
            'last_source' => 'banner',
        ]);
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1',
            'last_source' => 'banner',
        ]);

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/revoke', [
            'consent_key' => 'cookie_analytics',
        ])->assertOk();

        $history = \Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory::query()
            ->where('user_id', $user->id)
            ->where('source', 'mypage')
            ->latest('id')
            ->first();

        $this->assertNotNull($history, '마이페이지 출처 history row 가 생성되어야 한다.');
        $this->assertIsArray($history->categories, 'history.categories 가 매트릭스 배열로 저장되어야 한다.');
        $this->assertSame(false, $history->categories['cookie_analytics'], '방금 철회한 키는 false 로 반영되어야 한다.');
        $this->assertSame(true, $history->categories['cookie_marketing'], '변경하지 않은 키는 기존 값 그대로 보존되어야 한다.');
    }

    public function test_grant_records_full_categories_snapshot_in_history(): void
    {
        $user = User::factory()->create();

        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_marketing',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '1',
            'last_source' => 'banner',
        ]);

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/grant', [
            'consent_key' => 'cookie_analytics',
        ])->assertOk();

        $history = \Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory::query()
            ->where('user_id', $user->id)
            ->where('source', 'mypage')
            ->latest('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertIsArray($history->categories);
        $this->assertSame(true, $history->categories['cookie_analytics']);
        $this->assertSame(true, $history->categories['cookie_marketing']);
    }

    public function test_renew_all_records_full_categories_snapshot_in_history(): void
    {
        $user = User::factory()->create();

        // 활성 + 옛 버전 → renewAll 대상 (기존 테스트와 동일하게 '0.0' 으로 현재 버전과 확실히 분리)
        GdprUserConsent::create([
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
            'consented_at' => now(),
            'consent_count' => 1,
            'policy_version' => '0.0',
            'last_source' => 'banner',
        ]);

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/renew-all')
            ->assertOk();

        $history = \Plugins\Sirsoft\Gdpr\Models\GdprUserConsentHistory::query()
            ->where('user_id', $user->id)
            ->where('source', 'mypage_renew_all')
            ->latest('id')
            ->first();

        $this->assertNotNull($history, 'mypage_renew_all 출처 history row 가 생성되어야 한다.');
        $this->assertIsArray($history->categories, 'renewAll 도 매트릭스 보존 (이전: null 명시 저장 회귀)');
        $this->assertSame(true, $history->categories['cookie_analytics']);
    }

    /*
    |--------------------------------------------------------------------------
    | 마이페이지 동의 항목 컬럼에 consent_description 노출 — 회원이 영문 식별자 대신
    | 사용자 친화 설명을 즉시 인지하도록 카탈로그 description 을 응답에 포함.
    |--------------------------------------------------------------------------
    */

    public function test_consent_me_response_exposes_consent_description_for_each_item(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/me');

        $response->assertOk();

        $consents = collect($response->json('data.consents'));

        $this->assertNotEmpty($consents);
        foreach ($consents as $consent) {
            $this->assertArrayHasKey('consent_description', $consent, '응답에 consent_description 필드가 노출되어야 한다.');
            // 카탈로그 description 은 비어 있을 수 있으므로 string|null 만 검증.
            $this->assertTrue(
                $consent['consent_description'] === null || is_string($consent['consent_description']),
                'consent_description 은 string 또는 null 이어야 한다.'
            );
        }
    }
}
