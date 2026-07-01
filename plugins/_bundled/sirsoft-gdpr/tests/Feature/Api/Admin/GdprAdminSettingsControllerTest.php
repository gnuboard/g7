<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\Admin;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 관리자 GDPR 설정 API 테스트
 *
 * - GET /api/plugins/sirsoft-gdpr/admin/settings
 * - PUT /api/plugins/sirsoft-gdpr/admin/settings
 */
class GdprAdminSettingsControllerTest extends PluginTestCase
{
    public function test_show_requires_auth(): void
    {
        $this->getJson('/api/plugins/sirsoft-gdpr/admin/settings')
            ->assertUnauthorized();
    }

    public function test_show_returns_settings_for_privacy_operator(): void
    {
        $values = [
            'banner_enabled' => true,
            'cookie_categories' => json_encode([['key' => 'necessary', 'required' => true]]),
        ];

        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            function (string $id, ?string $key = null, mixed $default = null) use ($values) {
                if ($key === null) {
                    return $values;
                }

                return $values[$key] ?? $default;
            }
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/settings');

        $response->assertOk()
            ->assertJsonPath('data.settings.banner_enabled', true)
            ->assertJsonPath('data.settings.cookie_categories.0.key', 'necessary')
            ->assertJsonMissingPath('data.settings.master_switch');
    }

    /**
     * 마이페이지 카드 노출 토글 제거 회귀 — GDPR Art.7(3) 대칭성 의무에 따라
     * mypage_privacy_tab_visible 키는 settings 스키마에서 완전히 제거되었다.
     * 운영자가 PUT 으로 해당 키를 보내도 검증을 통과하지만 저장되지 않으며,
     * GET 응답 settings 에도 노출되지 않는다.
     *
     * @return void
     */
    public function test_mypage_privacy_tab_visible_key_is_completely_removed(): void
    {
        $captured = null;
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('save')->willReturnCallback(function (string $id, array $settings) use (&$captured) {
            $captured = $settings;

            return true;
        });
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'mypage_privacy_tab_visible' => true,
        ])->assertOk();

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('mypage_privacy_tab_visible', $captured);
    }

    /**
     * auto_blocking_enabled 토글 제거 회귀 — GDPR Art.6 "동의 전 처리 금지" 의 강제
     * 메커니즘인 차단을 운영자가 단독 OFF 할 수 있으면 위반 조합 (배너 ON + 차단 OFF)
     * 가능 → CNIL 처벌 패턴과 동일. banner_enabled (쿠키 배너 노출) 단일 토글
     * 로 통합되어 auto_blocking_enabled 키는 settings 스키마에서 완전히 제거됨.
     * 운영자가 PUT 으로 해당 키를 보내도 stripped 되며 GET 응답에도 노출 안 됨.
     *
     * @return void
     */
    public function test_auto_blocking_enabled_key_is_completely_removed(): void
    {
        $captured = null;
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('save')->willReturnCallback(function (string $id, array $settings) use (&$captured) {
            $captured = $settings;

            return true;
        });
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'auto_blocking_enabled' => false,
        ])->assertOk();

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('auto_blocking_enabled', $captured);
    }

    public function test_update_requires_auth(): void
    {
        $this->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'banner_enabled' => true,
        ])->assertUnauthorized();
    }

    public function test_update_persists_settings_for_privacy_operator(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->expects($this->atLeastOnce())
            ->method('save')
            ->willReturn(true);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'banner_enabled' => true,
            'cookie_policy_version' => '1.1',
        ])->assertOk();
    }

    public function test_update_rejects_invalid_banner_position(): void
    {
        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'banner_position' => 'invalid_position',
        ])->assertStatus(422);
    }

    /**
     * F-02 도메인 차단 — textarea 줄바꿈 문자열을 배열로 정규화하여 저장하는지 검증.
     *
     * @return void
     */
    public function test_update_normalizes_blocked_domains_textarea_string_to_array(): void
    {
        $captured = null;
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('save')->willReturnCallback(function (string $id, array $settings) use (&$captured) {
            $captured = $settings;

            return true;
        });
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'blocked_domains' => [
                'analytics' => "google-analytics.com\n*.hotjar.com\n  wcs.naver.net  \n\n",
                'marketing' => "facebook.com\n",
            ],
        ])->assertOk();

        $this->assertIsArray($captured);
        $this->assertArrayHasKey('blocked_domains', $captured);
        $this->assertSame(
            ['google-analytics.com', '*.hotjar.com', 'wcs.naver.net'],
            $captured['blocked_domains']['analytics'],
        );
        $this->assertSame(['facebook.com'], $captured['blocked_domains']['marketing']);
    }

    /**
     * F-02 도메인 차단 — 정확 매칭 도메인과 와일드카드 매칭 도메인 모두 통과.
     *
     * @return void
     */
    public function test_update_accepts_exact_and_wildcard_domains(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('save')->willReturn(true);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'blocked_domains' => [
                'analytics' => ['google-analytics.com', '*.hotjar.com', '*.sub.example.com'],
                'marketing' => ['*.facebook.com'],
            ],
        ])->assertOk();
    }

    /**
     * F-02 도메인 차단 — 잘못된 도메인 형식은 422.
     *
     * @return void
     */
    public function test_update_rejects_invalid_domain_format(): void
    {
        $user = $this->createPrivacyOperatorUser();

        // 단일 라벨 (FQDN 아님) — 거부
        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'blocked_domains' => [
                'analytics' => ['localhost'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['blocked_domains.analytics.0']);

        // 프로토콜 포함 — 거부
        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'blocked_domains' => [
                'analytics' => ['https://example.com'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['blocked_domains.analytics.0']);

        // 라벨 시작/끝 하이픈 — 거부
        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'blocked_domains' => [
                'analytics' => ['-bad.example.com'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['blocked_domains.analytics.0']);
    }

    /**
     * Phase 1: functional 카테고리 저장 — `in:` 룰에 functional 통과 + functional 차단 도메인 저장.
     *
     * ICO/CNIL 4분류 체계 (necessary/functional/analytics/marketing) 부합.
     * UpdateAdminSettingsRequest 의 `in:necessary,functional,analytics,marketing` 룰이
     * functional 키를 허용하는지 회귀 가드.
     */
    public function test_update_accepts_functional_category_and_domains(): void
    {
        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'cookie_categories' => [
                ['key' => 'necessary', 'required' => true, 'label' => ['ko' => '필수', 'en' => 'Necessary']],
                ['key' => 'functional', 'required' => false, 'label' => ['ko' => '기능', 'en' => 'Functional']],
                ['key' => 'analytics', 'required' => false, 'label' => ['ko' => '분석', 'en' => 'Analytics']],
                ['key' => 'marketing', 'required' => false, 'label' => ['ko' => '마케팅', 'en' => 'Marketing']],
            ],
            'blocked_domains' => [
                'functional' => ['*.crisp.chat', 'widget.intercom.io'],
                'analytics' => ['google-analytics.com'],
                'marketing' => ['facebook.com'],
            ],
        ])->assertOk();
    }

    /**
     * Phase 1: functional 차단 도메인 형식 검증 — 잘못된 도메인은 functional 전용 메시지로 거부.
     *
     * messages() 의 `blocked_domains.functional.*.regex` 키가 매핑되어 운영자가
     * 어느 카테고리 도메인이 문제인지 즉시 식별 가능.
     */
    public function test_update_rejects_invalid_functional_domain(): void
    {
        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'blocked_domains' => [
                'functional' => ['localhost'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['blocked_domains.functional.0']);
    }

    /**
     * 검증 에러 메시지의 :attribute placeholder 가 사용자 친화 한국어 라벨로 치환되는지 확인.
     *
     * Laravel 기본 :attribute 는 underscore 만 공백으로 치환한 영문 키 (예: "privacy policy slug") 를
     * 노출하여 사용자가 어느 필드를 의미하는지 인지하기 어려움. FormRequest::attributes() 메서드로
     * 각 필드에 lang 의 사용자 친화 라벨을 매핑하여 메시지 안에 노출되도록 함.
     *
     * 검토 #10d — PO 가 사용자에게 영문 키 노출이 부적절하다는 피드백 후 추가.
     */
    public function test_update_validation_messages_use_friendly_attribute_names(): void
    {
        // 테스트 환경 기본 로케일이 en 일 수 있으므로 한국어 라벨 검증을 위해
        // Accept-Language 헤더로 ko 강제 (코어 SetLocale 미들웨어가 헤더에서 locale 추출)
        app()->setLocale('ko');

        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
                'privacy_policy_slug' => '개인정보처리방침', // 한글 — regex 위반
                'legal_entity_name' => str_repeat('가', 201), // max:200 위반
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['privacy_policy_slug', 'legal_entity_name']);

        // :attribute 가 "개인정보처리방침 페이지 슬러그" 로 치환되어야 함 (영문 키 "privacy policy slug" 가 아님)
        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors['privacy_policy_slug'] ?? null);
        $this->assertStringContainsString('개인정보처리방침 페이지 슬러그', $errors['privacy_policy_slug'][0]);
        $this->assertStringNotContainsString('privacy policy slug', $errors['privacy_policy_slug'][0]);

        $this->assertNotEmpty($errors['legal_entity_name'] ?? null);
        $this->assertStringContainsString('법인명 / 운영 주체', $errors['legal_entity_name'][0]);
        $this->assertStringNotContainsString('legal entity name', $errors['legal_entity_name'][0]);
    }

    /**
     * 데이터 저장 위치에 보안 민감 식별자 (IP / CIDR / AWS 리전 코드) 가 입력되면 422 검증 실패.
     *
     * GDPR Art.13(1)(f) / PIPA 28조의8 은 「국가 단위」 표기를 요구하며, 클라우드 리전 코드나
     * IP 대역은 처리방침 본문에 적시할 의무가 없을뿐더러 공격 표면 reconnaissance 단서가 되므로
     * FormRequest 에서 not_regex 2종으로 사전 차단한다.
     *
     * @return void
     */
    public function test_update_rejects_sensitive_format_in_data_storage_location(): void
    {
        app()->setLocale('ko');

        $user = $this->createPrivacyOperatorUser();

        $invalidValues = [
            '192.168.1.1',                          // IPv4
            '10.0.0.0/24',                          // CIDR
            'AWS ap-northeast-2 (Seoul)',           // AWS 리전 코드
            'GCP asia-northeast3',                  // GCP 리전 코드
        ];

        foreach ($invalidValues as $invalid) {
            $response = $this->actingAs($user)
                ->withHeaders(['Accept-Language' => 'ko'])
                ->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
                    'data_storage_location' => $invalid,
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['data_storage_location']);

            $errors = $response->json('errors');
            $this->assertNotEmpty($errors['data_storage_location'] ?? null, "Should reject: {$invalid}");
            // 사용자 친화 메시지 — IP/CIDR/리전 코드 안내 + 국가명 예시
            $this->assertStringContainsString('국가명', $errors['data_storage_location'][0]);
        }
    }

    /**
     * 데이터 저장 위치에 국가 단위 표기는 정상 통과한다 (Art.13 권고 형식).
     *
     * @return void
     */
    public function test_update_accepts_country_level_data_storage_location(): void
    {
        $captured = null;
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('save')->willReturnCallback(function (string $id, array $settings) use (&$captured) {
            $captured = $settings;

            return true;
        });
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $validValues = [
            '대한민국 (자체 데이터센터)',
            '미국 (AWS)',
            'Korea (self-hosted IDC)',
            '대한민국, 미국',
        ];

        foreach ($validValues as $valid) {
            $this->actingAs($user)
                ->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
                    'data_storage_location' => $valid,
                ])
                ->assertOk();

            $this->assertSame($valid, $captured['data_storage_location'] ?? null, "Should accept: {$valid}");
        }
    }

    /**
     * 설정 저장은 정책 버전을 자동 발행하지 않는다 (수동 발행 모델).
     *
     * 옛 자동 발행 흐름이 운영자에게 "내가 안 누른 발행" 을 인지시키지 못해
     * GDPR Art.30 처리 기록 의무의 *변경 사유* 입증을 약화시키므로 폐기됨.
     * 정책 버전 발행은 운영자가 「+ 새 버전 발행」 (POST /admin/policy-versions) 으로 명시 트리거.
     */
    public function test_update_does_not_auto_publish_policy_version_on_material_change(): void
    {
        // 현재 settings: necessary 카테고리만
        $currentSettings = [
            'cookie_categories' => json_encode([
                ['key' => 'necessary', 'required' => true, 'label' => ['ko' => '필수', 'en' => 'Necessary']],
            ], JSON_UNESCAPED_UNICODE),
        ];
        $this->mockPluginSettings($currentSettings);

        $user = $this->createPrivacyOperatorUser();

        // 새 입력: analytics 카테고리 추가 — 옛 모델에서는 Material 자동 발행 트리거였으나 이제는 발행 없음
        $response = $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'cookie_categories' => [
                ['key' => 'necessary', 'required' => true, 'label' => ['ko' => '필수', 'en' => 'Necessary']],
                ['key' => 'analytics', 'required' => false, 'label' => ['ko' => '분석', 'en' => 'Analytics']],
            ],
        ]);

        $response->assertOk();

        // 응답에 옛 자동 발행 흐름의 키가 더 이상 노출되지 않는다
        $response->assertJsonMissingPath('data.change_type');
        $response->assertJsonMissingPath('data.published_version');

        // DB 에 정책 버전 row 는 initial(v1) 1건만 — 새 발행 없음
        $this->assertSame(1, GdprPolicyVersion::count());
        $this->assertSame(1, GdprPolicyVersion::first()->version);
    }

    /**
     * 비-Material 변경 (도메인만 추가) 시에도 동일하게 정책 버전 발행 없음 (수동 발행 모델 — 모든 저장 흐름 공통).
     */
    public function test_update_does_not_publish_policy_version_on_non_material_change(): void
    {
        $currentSettings = [
            'cookie_categories' => json_encode([
                ['key' => 'necessary', 'required' => true, 'label' => ['ko' => '필수', 'en' => 'Necessary']],
                ['key' => 'analytics', 'required' => false, 'label' => ['ko' => '분석', 'en' => 'Analytics']],
            ], JSON_UNESCAPED_UNICODE),
            'blocked_domains' => ['analytics' => ['google-analytics.com']],
        ];
        $this->mockPluginSettings($currentSettings);

        $user = $this->createPrivacyOperatorUser();

        // 새 입력: 같은 카테고리 + 도메인 1개 추가 (Non-material)
        $response = $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'cookie_categories' => [
                ['key' => 'necessary', 'required' => true, 'label' => ['ko' => '필수', 'en' => 'Necessary']],
                ['key' => 'analytics', 'required' => false, 'label' => ['ko' => '분석', 'en' => 'Analytics']],
            ],
            'blocked_domains' => [
                'analytics' => ['google-analytics.com', 'hotjar.com'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonMissingPath('data.change_type');
        $response->assertJsonMissingPath('data.published_version');

        // 마이그레이션 시 initial 행 (version=1) 만 존재 — 저장 흐름은 발행 없음
        $this->assertSame(1, GdprPolicyVersion::count());
        $this->assertSame(1, GdprPolicyVersion::first()->version);
    }

    /**
     * Phase 2 단순화: admin 응답에도 functional 등록 표 필드는 노출되지 않는다.
     *
     * 운영자 등록 표 자체가 제거되어 functional_storage_keys / functional_cookies /
     * functional_allow_user_initiated 모두 settings 응답에서 사라짐.
     *
     * @return void
     */
    public function test_show_does_not_expose_phase2_registration_fields(): void
    {
        $operator = $this->createPrivacyOperatorUser();
        $this->mockPluginSettings([]);

        $this->actingAs($operator)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/settings')
            ->assertOk()
            ->assertJsonMissingPath('data.settings.functional_storage_keys')
            ->assertJsonMissingPath('data.settings.functional_cookies')
            ->assertJsonMissingPath('data.settings.functional_allow_user_initiated');
    }

    /**
     * PluginSettingsService::get 을 통제 가능한 Mock 으로 교체.
     *
     * @param array<string, mixed> $values
     * @return void
     */
    private function mockPluginSettings(array $values): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            function (string $id, ?string $key = null, mixed $default = null) use (&$values) {
                if ($key === null) {
                    return $values;
                }

                return $values[$key] ?? $default;
            }
        );
        // save 호출 시 내부 values 갱신 (saveAdminSettings 가 save 직후 다시 get 으로 응답 구성)
        $mock->method('save')->willReturnCallback(
            function (string $id, array $data) use (&$values): bool {
                $values = array_merge($values, $data);

                return true;
            }
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }

}
