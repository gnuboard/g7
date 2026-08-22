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
 *
 * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated, request=invalid_format
 *
 * @effects update_persists_allowlist_per_scope, update_normalizes_textarea_string_to_array, update_without_key_leaves_existing_value_untouched, update_rejects_invalid_item_format_with_scope_indexed_error_key, update_rejects_unknown_scope, update_ignores_locked_list_from_request, show_includes_default_catalog_previews
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
     * 검토 #10d — 사용자에게 영문 키 노출이 부적절하다는 피드백 후 추가.
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
     * @param  array<string, mixed>  $values
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

    /**
     * 관리자 응답에 출하 카탈로그 두 벌이 실린다 (추천 목록 결함 해소).
     *
     * 관리자 레이아웃의 TagInput 은 이 값을 자동완성 추천으로 쓴다. 응답에 없으면 드롭다운에
     * **이미 선택된 칩만** 다시 나타나므로 추천이 동작하는 것처럼 보이고, 오류도 빈 목록도
     * 남지 않아 증상만으로는 알 수 없다.
     *
     * @return void
     */
    public function test_show_includes_default_catalog_previews(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $key === null ? [] : $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/settings');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'default_blocked_domains_preview' => ['functional', 'analytics', 'marketing'],
                    'default_necessary_allowlist_preview' => ['localStorage', 'sessionStorage', 'cookie'],
                ],
            ]);

        $this->assertContains(
            'g7_color_scheme',
            $response->json('data.default_necessary_allowlist_preview.localStorage'),
            '출하 카탈로그가 실제 항목을 담아야 추천이 의미를 가진다'
        );
        $this->assertContains(
            'google-analytics.com',
            $response->json('data.default_blocked_domains_preview.analytics')
        );
    }

    /**
     * 잠금 항목은 출하 카탈로그(운영자 편집 대상)에 실리지 않는다.
     *
     * 실리면 운영자가 화면에서 지울 수 있고, 그 순간 잠금이 잠금이 아니게 된다.
     *
     * @return void
     */
    public function test_default_catalog_preview_excludes_locked_items(): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $key === null ? [] : $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        $user = $this->createPrivacyOperatorUser();

        $preview = $this->actingAs($user)
            ->getJson('/api/plugins/sirsoft-gdpr/admin/settings')
            ->json('data.default_necessary_allowlist_preview');

        $this->assertNotContains('auth_token', $preview['localStorage']);
        $this->assertNotContains('XSRF-TOKEN', $preview['cookie']);
        $this->assertNotContains('gdpr_session', $preview['cookie']);
    }

    /**
     * 필수 저장 항목 허용목록이 스코프별로 저장된다.
     *
     * @return void
     */
    public function test_update_persists_necessary_storage_allowlist(): void
    {
        $captured = $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7_locale', 'g7_filters_*'],
                'sessionStorage' => ['g7:sirsoft-pay_kginicis:pendingClose'],
                'cookie' => ['laravel_maintenance', 'myplugin_*'],
            ],
        ])->assertOk();

        $saved = $captured->value['necessary_storage_allowlist'] ?? null;
        $this->assertIsArray($saved);
        $this->assertSame(['g7_locale', 'g7_filters_*'], $saved['localStorage']);
        $this->assertSame(['g7:sirsoft-pay_kginicis:pendingClose'], $saved['sessionStorage']);
        $this->assertSame(['laravel_maintenance', 'myplugin_*'], $saved['cookie']);
    }

    /**
     * 줄바꿈 문자열로 들어와도 배열로 정규화된다 (blocked_domains 와 동일 규약).
     *
     * @return void
     */
    public function test_update_normalizes_necessary_storage_allowlist_textarea_string(): void
    {
        $captured = $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'necessary_storage_allowlist' => [
                'localStorage' => "g7_locale\n  g7_color_scheme  \n\n",
            ],
        ])->assertOk();

        $saved = $captured->value['necessary_storage_allowlist'];
        $this->assertSame(['g7_locale', 'g7_color_scheme'], $saved['localStorage']);
        // 전송하지 않은 스코프는 빈 배열로 보충되어 화면이 항상 세 카드를 그린다.
        $this->assertSame([], $saved['sessionStorage']);
        $this->assertSame([], $saved['cookie']);
    }

    /**
     * 키를 아예 보내지 않으면 기존 저장값을 건드리지 않는다.
     *
     * 빈 배열로 보충해 버리면 이 화면을 모르는 클라이언트의 저장 한 번이 허용목록을 통째로
     * 비우고, 그 사이트는 다음 방문부터 로그인 외 모든 설정을 잃는다.
     *
     * @return void
     */
    public function test_update_without_allowlist_key_leaves_it_untouched(): void
    {
        $captured = $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'banner_enabled' => true,
        ])->assertOk();

        $this->assertArrayNotHasKey('necessary_storage_allowlist', $captured->value);
    }

    /**
     * 항목 형식 위반은 스코프·인덱스가 드러나는 키로 422 를 낸다.
     *
     * 에러 키가 `necessary_storage_allowlist.{scope}.{index}` 여야 화면이 그 카드에
     * 메시지를 붙일 수 있다.
     *
     * @return void
     */
    public function test_update_rejects_invalid_allowlist_item_format(): void
    {
        $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'necessary_storage_allowlist' => [
                'localStorage' => ['bad key!'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['necessary_storage_allowlist.localStorage.0']);

        // `*` 는 끝에만 허용 — 중간/앞 표기는 거른다 (앞에 두면 전체 개방이 된다).
        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'necessary_storage_allowlist' => [
                'cookie' => ['*_suffix'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['necessary_storage_allowlist.cookie.0']);

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'necessary_storage_allowlist' => [
                'sessionStorage' => ['g7_*_middle'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['necessary_storage_allowlist.sessionStorage.0']);
    }

    /**
     * 와일드카드 표기와 실제 사용 키는 통과한다 (위 테스트의 대조군).
     *
     * @return void
     */
    public function test_update_accepts_valid_allowlist_items(): void
    {
        $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'necessary_storage_allowlist' => [
                'localStorage' => ['g7_locale', 'g7_filters_*', 'g7le.clipboard', 'g7_asset_url_mode*'],
                'sessionStorage' => ['g7:sirsoft-pay_nhnkcp:pendingClose', '__sirsoftKginicisMobilePaymentReturnPending'],
                'cookie' => ['laravel_maintenance', 'XSRF-TOKEN'],
            ],
        ])->assertOk();
    }

    /**
     * 스코프 키 화이트리스트 — 알 수 없는 스코프는 422.
     *
     * 조용히 버리면 그 항목은 어떤 판정에도 쓰이지 않은 채 저장되고, 운영자에게는
     * "등록했는데 안 되는" 상태로만 보인다.
     *
     * @return void
     */
    public function test_update_rejects_unknown_allowlist_scope(): void
    {
        $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'necessary_storage_allowlist' => [
                'localstorage' => ['g7_locale'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['necessary_storage_allowlist.localstorage']);
    }

    /**
     * 잠금 항목 목록은 저장되지 않는다 (요청에 섞여 와도 무시).
     *
     * 저장 가능하면 API 한 번으로 잠금을 무력화할 수 있다.
     *
     * @return void
     */
    public function test_update_ignores_locked_list_from_request(): void
    {
        $captured = $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'necessary_storage_locked' => [
                'localStorage' => [],
                'sessionStorage' => [],
                'cookie' => [],
            ],
        ])->assertOk();

        $this->assertArrayNotHasKey('necessary_storage_locked', $captured->value);
    }

    /**
     * 저장 페이로드를 가로채는 PluginSettingsService mock 을 등록합니다.
     *
     * @return object 저장된 페이로드를 담는 컨테이너 (`value` 프로퍼티)
     */
    private function captureSave(): object
    {
        $captured = new class
        {
            /** @var array<string, mixed> */
            public array $value = [];
        };

        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('save')->willReturnCallback(function (string $id, array $settings) use ($captured) {
            $captured->value = $settings;

            return true;
        });
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $key === null ? [] : $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);

        return $captured;
    }
}
