<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\Public;

use App\Models\User;
use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 공개 쿠키 동의 + 주문 필수 항목 API 테스트
 *
 * - POST /api/plugins/sirsoft-gdpr/consent/cookie
 * - GET  /api/plugins/sirsoft-gdpr/order-consent/required
 */
class GdprCookieConsentControllerTest extends PluginTestCase
{
    /**
     * @param array<string, mixed> $values
     * @return void
     */
    private function mockSettings(array $values): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $values[$key] ?? $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    public function test_guest_can_store_cookie_consent(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $response = $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => [
                'cookie_necessary' => true,
                'cookie_analytics' => true,
                'cookie_marketing' => false,
            ],
            'source' => 'banner',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
            'source' => 'banner',
        ]);

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'consent_key' => 'cookie_marketing',
            'action' => 'revoked',
            'source' => 'banner',
        ]);
    }

    public function test_authenticated_user_consent_creates_status_row(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_analytics' => true],
            'source' => 'banner',
        ])->assertOk();

        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
        ]);
    }

    public function test_required_category_revoke_is_blocked(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => false],
            'source' => 'banner',
        ])->assertStatus(422);
    }

    public function test_invalid_consent_key_is_rejected(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_unknown_xyz' => true],
            'source' => 'banner',
        ])->assertStatus(422);
    }

    public function test_invalid_source_is_rejected(): void
    {
        $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_analytics' => true],
            'source' => 'invalid_source',
        ])->assertStatus(422);
    }

    public function test_status_returns_false_for_new_visitor(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $this->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status')
            ->assertOk()
            ->assertJsonPath('data.has_consented', false);
    }

    public function test_status_exposes_is_member_false_for_guest(): void
    {
        // 회귀 가드: 배너 layout 의 회원 한정 keep_consent 버튼 분기에 사용되는 is_member SSoT.
        // 게스트 (인증 미사용) 호출 시 is_member=false 반환.
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $this->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status')
            ->assertOk()
            ->assertJsonPath('data.is_member', false);
    }

    public function test_status_exposes_is_member_true_for_authenticated_user(): void
    {
        // 회귀 가드: 회원 (sanctum 토큰) 호출 시 is_member=true. 본 필드가 없어 layout 의 옛
        // auth?.user?.id 패턴이 영구 undefined 로 평가되던 회귀 회피.
        $this->mockSettings(['cookie_policy_version' => '1.0']);
        $user = \App\Models\User::factory()->create();

        $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status')
            ->assertOk()
            ->assertJsonPath('data.is_member', true);
    }

    public function test_store_issues_gdpr_session_cookie_for_new_guest(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $response = $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
            'source' => 'banner',
        ]);

        $response->assertOk();

        // 응답에 session_id UUID 가 포함되어야 함 (게스트 신규 발급)
        $sessionId = $response->json('data.session_id');
        $this->assertNotNull($sessionId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $sessionId,
            '신규 게스트 session_id 는 UUID v4 형식이어야 함'
        );

        // 응답에 gdpr_session 쿠키가 첨부되어야 함 — 값은 위조 방지를 위해 HMAC 서명이 붙어
        // "{session_id}|{signature}" 형식이므로 session_id 는 접두 일치로 확인한다.
        $cookies = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'gdpr_session');
        $this->assertNotNull($cookies, '게스트 신규 동의 시 gdpr_session 쿠키가 발급되어야 함');
        $this->assertStringStartsWith($sessionId.'|', (string) $cookies->getValue());
    }

    /**
     * 회귀 가드: gdpr_session 쿠키 값을 임의로 조작해 보내면 서버가 그 값을 신뢰하지 않고
     * 새로운(다른) session_id 로 취급해야 한다 — 서명 없는 값을 그대로 신뢰하던 결함 회귀 방지.
     */
    public function test_tampered_gdpr_session_cookie_is_not_trusted(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $forgedSessionId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $response = $this->withCookie('gdpr_session', $forgedSessionId)
            ->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
                'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
                'source' => 'banner',
            ]);

        $response->assertOk();
        $this->assertNotSame($forgedSessionId, $response->json('data.session_id'), '서명 없는 위조 session_id 는 신뢰되면 안 됨');
    }

    public function test_guest_consent_persists_via_service_with_session_id(): void
    {
        // 회귀 가드: 게스트 동의 저장 후 Service 단에서 sessionId 로 조회 시 has_consented:true.
        // (Controller 단 status() 는 EncryptCookies 미들웨어 영향으로 PHPUnit 환경에서 raw 쿠키 전달이 까다로워
        //  Service 단 검증으로 백엔드 로직 무결성을 보장. 실 브라우저 동작은 별도 수동 검증으로 확인됨.)
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $store = $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
            'source' => 'banner',
        ])->assertOk();

        $sessionId = $store->json('data.session_id');
        $this->assertNotNull($sessionId);

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'session_id' => $sessionId,
            'consent_key' => 'cookie_analytics',
        ]);

        $service = $this->app->make(\Plugins\Sirsoft\Gdpr\Services\GdprConsentService::class);
        $this->assertTrue(
            $service->hasCurrentCookieConsent(null, $sessionId),
            'Service.hasCurrentCookieConsent 가 게스트 sessionId 로 조회 시 true 여야 함'
        );
    }

    public function test_status_returns_true_for_authenticated_user_with_consent(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $user = User::factory()->create();

        // 동의 저장 → status status:true
        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
            'source' => 'banner',
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status')
            ->assertOk()
            ->assertJsonPath('data.has_consented', true);
    }

    public function test_status_returns_false_when_policy_version_changed(): void
    {
        // 동의 시점 정책 버전 v1 (마이그레이션 시드)
        $this->mockSettings([]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
            'source' => 'banner',
        ])->assertOk();

        // 정책 버전이 v2 로 bump 되면 has_consented:false (재동의 필요)
        \Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => \Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType::Material->value,
            'snapshot' => [],
        ]);

        $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status')
            ->assertOk()
            ->assertJsonPath('data.has_consented', false);
    }

    /**
     * 회귀 가드: 실제 Sanctum Bearer 토큰으로 동의를 저장할 때 user_id 가 인식되어
     * gdpr_user_consents 테이블에 status 행이 생성되어야 한다.
     *
     * 이 테스트가 이전 구현(/consent/cookie 라우트가 auth 미들웨어 그룹 밖에 정의)에서는
     * 실패한다 — Bearer 토큰이 검증되지 않아 $request->user() 가 null 이 되고
     * histories 만 INSERT 되며 gdpr_user_consents 는 0행으로 남는다.
     *
     * actingAs() 를 사용하는 기존 test_authenticated_user_consent_creates_status_row 는
     * 미들웨어를 우회하므로 이 회귀를 잡지 못한다 — 반드시 실제 토큰으로 검증할 것.
     *
     * @return void
     */
    public function test_consent_with_real_sanctum_token_persists_user_id(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
                'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
                'source' => 'banner',
            ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id);

        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => true,
        ]);

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'granted',
        ]);
    }

    /**
     * 회귀 가드: 위조/존재하지 않는 토큰으로 호출 시 401 반환 (optional.sanctum 미들웨어).
     *
     * 미들웨어가 없으면 위조 토큰이 그냥 게스트로 통과해 history 에만 기록되는 무결성 결함이 발생.
     *
     * @return void
     */
    public function test_consent_with_forged_token_is_rejected(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $this->withHeader('Authorization', 'Bearer not-a-real-token-1234567890')
            ->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
                'consents' => ['cookie_necessary' => true],
                'source' => 'banner',
            ])
            ->assertStatus(401);
    }

    /**
     * 회귀 가드: 토큰 없이 호출하면 게스트로 통과 (optional.sanctum 의 정상 동작).
     *
     * @return void
     */
    public function test_consent_without_token_falls_back_to_guest(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $response = $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
            'source' => 'banner',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.user_id'));
        $this->assertNotNull($response->json('data.session_id'));
    }

    /**
     * 회귀 가드: status 도 실제 Sanctum Bearer 토큰으로 호출 시 회원 동의 상태를 반환해야 한다.
     *
     * 미들웨어 누락 회귀 시 토큰을 보내도 게스트로 처리되어 has_consented:false 가 된다.
     *
     * @return void
     */
    public function test_status_with_real_sanctum_token_reflects_user_consent(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
                'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
                'source' => 'banner',
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status')
            ->assertOk()
            ->assertJsonPath('data.has_consented', true);
    }

    /**
     * 작업 5 (B-2) — 신규 게스트 (이력 0건) 의 status 응답에 needs_renewal=false + current_policy_version 포함.
     */
    public function test_status_response_includes_needs_renewal_false_for_new_guest(): void
    {
        $response = $this->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status');

        $response->assertOk()
            ->assertJsonPath('data.has_consented', false)
            ->assertJsonPath('data.needs_renewal', false)
            ->assertJsonPath('data.current_policy_version', '1');
    }

    /**
     * 작업 5 (B-2) — 옛 정책 동의 회원의 status 응답에 needs_renewal=true.
     *
     * 시드 v1 시점에 회원이 동의 → v2 발행 → 회원의 v1 동의가 옛 버전이 됨.
     * has_consented=false + status row 는 존재 → needs_renewal=true (보수적 차단 + A-6 안내 트리거)
     *
     * 게스트 시나리오는 EncryptCookies 미들웨어와 테스트 환경 cookie 처리 불일치로 별도 검증
     * 어려움 → 작업 12 시나리오 매니페스트 + 수동 검증으로 위임. Service::hasAnyConsentHistory
     * 자체는 게스트/회원 동일 로직이라 회원 검증으로 동작 보장.
     */
    public function test_status_response_includes_needs_renewal_true_for_outdated_user_consent(): void
    {
        // 1) 회원이 v1 (시드) 시점에 동의
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
            'source' => 'banner',
        ])->assertOk();

        // 2) 정책 v2 발행 → 회원의 v1 동의가 옛 버전이 됨
        \Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => \Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType::Material->value,
            'snapshot' => [],
        ]);

        // 3) 회원이 status 조회 → has_consented=false (옛 버전), needs_renewal=true (이력 있음)
        $response = $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status');

        $response->assertOk()
            ->assertJsonPath('data.has_consented', false)
            ->assertJsonPath('data.needs_renewal', true)
            ->assertJsonPath('data.current_policy_version', '2');
    }

    /**
     * 회귀: 정책 v2 bump 후 회원이 "모두 동의" 로 같은 값 재제출 → status 응답에서 needs_renewal=false.
     *
     * 결함 시점: updateConsent 의 noop 가드가 is_consented 만 비교 → 같은 값 재제출이 즉시 return →
     * status.policy_version 이 v1 에 머물러 needs_renewal 영구 true (새로고침해도 배너 사라지지 않음).
     * 가드는 (is_consented 동일) AND (policy_version 동일) 일 때만 noop 이어야 함.
     */
    public function test_status_returns_needs_renewal_false_after_re_accept_for_new_policy_version(): void
    {
        // 1) 회원이 v1 시점에 모두 동의
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
            'source' => 'banner',
        ])->assertOk();

        // 2) 정책 v2 발행
        \Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion::create([
            'version' => 2,
            'change_type' => \Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType::Material->value,
            'snapshot' => [],
        ]);

        // 3) needs_renewal=true 확인 (옛 동의)
        $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status')
            ->assertOk()
            ->assertJsonPath('data.needs_renewal', true);

        // 4) 회원이 같은 값으로 "모두 동의" 재제출 (배너 클릭 시나리오)
        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true, 'cookie_analytics' => true],
            'source' => 'banner',
        ])->assertOk();

        // 5) status 재조회 → has_consented=true + needs_renewal=false
        $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status')
            ->assertOk()
            ->assertJsonPath('data.has_consented', true)
            ->assertJsonPath('data.needs_renewal', false)
            ->assertJsonPath('data.current_policy_version', '2');
    }

    /**
     * Phase 2 단순화: 컨트롤러는 functional 거부 시 cookie 추가 발송하지 않는다.
     *
     * 동의 철회 시 cookie 파기는 클라이언트 functionalCleaner 가 단독 담당 (allowlist 외 전체 파기).
     * 서버는 후속 응답에서 CookieConsentMiddleware 가 allowlist 외 모든 Set-Cookie 를 제거.
     *
     * @return void
     */
    public function test_consent_response_does_not_dispatch_extra_cookies_on_functional_decline(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $response = $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => [
                'cookie_necessary' => true,
                'cookie_functional' => false,
            ],
            'source' => 'banner',
        ]);

        $response->assertOk();

        // 응답에 functional 관련 추가 cookie 가 없어야 함 (gdpr_session 등 정상 세션 cookie 만 허용).
        // Phase 2 등록 표 제거에 따라 컨트롤러가 더 이상 functional cookie 를 Max-Age=0 발송하지 않음.
        $unexpectedNames = ['app_pref', '_ga', '_fbp'];
        $cookieNames = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());
        foreach ($unexpectedNames as $name) {
            $this->assertNotContains($name, $cookieNames, "응답에 functional cookie ({$name}) 가 발송되면 안 됨 — Phase 2 단순화");
        }
    }

    // ===== 이슈 #430 — intent:'reject' 거부 저장 (컨트롤러 경로) =====

    /**
     * @scenario entry=reject, subject=member, category=optional
     * @effects member_rejection_intent_marks_optional_as_rejected, required_category_excluded_from_status, reject_controller_response_uses_rejected_saved_message
     */
    public function test_member_rejection_intent_marks_optional_as_rejected(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => [
                'cookie_necessary' => true,
                'cookie_analytics' => false,
                'cookie_marketing' => false,
            ],
            'source' => 'banner',
            'intent' => 'reject',
        ])->assertOk();

        // 거부 응답 메시지 = rejected_saved (거부인데 "동의가 저장" 오표기 정정, 이슈 #430)
        $response->assertJsonPath('message', __('sirsoft-gdpr::messages.consent.rejected_saved'));

        // 필수 → 동의 축 제외: status 에 저장되지 않음 (이슈 #430 후속)
        $this->assertDatabaseMissing('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_necessary',
        ]);

        // 선택형 → is_rejected=true, history action=rejected
        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => false,
            'is_rejected' => true,
        ]);
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'rejected',
            'source' => 'banner',
        ]);
    }

    /**
     * @scenario entry=reject, subject=guest, category=optional
     * @effects guest_rejection_intent_records_history_as_rejected
     */
    public function test_guest_rejection_intent_records_history_as_rejected(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => [
                'cookie_necessary' => true,
                'cookie_marketing' => false,
            ],
            'source' => 'banner',
            'intent' => 'reject',
        ])->assertOk();

        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'consent_key' => 'cookie_marketing',
            'action' => 'rejected',
            'source' => 'banner',
        ]);
    }

    /**
     * @scenario entry=reject, subject=guest, category=required
     * @effects rejection_intent_invalid_value_is_rejected_by_validation
     */
    public function test_rejection_intent_invalid_value_is_rejected_by_validation(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);

        // intent 는 'reject' 만 허용 — 다른 값은 422
        $this->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => ['cookie_necessary' => true],
            'source' => 'banner',
            'intent' => 'accept',
        ])->assertStatus(422);
    }

    /**
     * @scenario entry=save_selection, subject=member, category=optional
     * @effects without_intent_regression_stores_revoked_not_rejected
     */
    public function test_without_intent_regression_stores_revoked_not_rejected(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);
        $user = User::factory()->create();

        // intent 미전달 (기존 배너 동작) — 미동의는 revoked 로 기록, is_rejected=false
        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => [
                'cookie_necessary' => true,
                'cookie_analytics' => false,
            ],
            'source' => 'banner',
        ])->assertOk();

        $this->assertDatabaseHas('gdpr_user_consents', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'is_consented' => false,
            'is_rejected' => false,
        ]);
        $this->assertDatabaseHas('gdpr_user_consent_histories', [
            'user_id' => $user->id,
            'consent_key' => 'cookie_analytics',
            'action' => 'revoked',
        ]);
    }

    /**
     * 거부 후 배너 미재출현 고정 — 거부해도 현재 정책 버전으로 동의(필수) 이력이 남아
     * has_consented=true 가 되어 배너가 다시 뜨지 않는다 (이슈 요구: 쿠키 배너 띄우지 않음).
     *
     * @scenario entry=reject, subject=member, category=required
     * @effects rejection_makes_banner_not_reappear_for_member
     */
    public function test_rejection_makes_banner_not_reappear_for_member(): void
    {
        $this->mockSettings(['cookie_policy_version' => '1.0']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/plugins/sirsoft-gdpr/consent/cookie', [
            'consents' => [
                'cookie_necessary' => true,
                'cookie_analytics' => false,
                'cookie_marketing' => false,
            ],
            'source' => 'banner',
            'intent' => 'reject',
        ])->assertOk();

        $status = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/consent/cookie/status');
        $status->assertOk();
        $status->assertJsonPath('data.has_consented', true);
    }
}
