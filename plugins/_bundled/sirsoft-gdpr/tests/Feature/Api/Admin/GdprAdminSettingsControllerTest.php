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
    /**
     * 비로그인 요청은 관리자 설정을 조회하지 못합니다 (401).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_show
     *
     * @effects admin_settings_requires_authentication
     */
    public function test_show_requires_auth(): void
    {
        $this->getJson('/api/plugins/sirsoft-gdpr/admin/settings')
            ->assertUnauthorized();
    }

    /**
     * 개인정보 운영 권한자는 저장된 설정(카테고리 JSON 디코드 포함)을 조회합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_show
     *
     * @effects admin_show_returns_saved_settings
     */
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
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_strips_removed_toggle_keys
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
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_strips_removed_toggle_keys
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

    /**
     * 비로그인 요청은 관리자 설정을 저장하지 못합니다 (401).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_settings_requires_authentication
     */
    public function test_update_requires_auth(): void
    {
        $this->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'banner_enabled' => true,
        ])->assertUnauthorized();
    }

    /**
     * 개인정보 운영 권한자의 저장 요청은 설정 저장소까지 도달합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_persists_validated_settings
     */
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

    /**
     * 허용 목록 밖의 배너 위치는 422 로 거부합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_rejects_invalid_banner_position
     */
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
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_normalizes_blocked_domains
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
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_validates_blocked_domain_format
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
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_validates_blocked_domain_format
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
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_accepts_four_category_catalog
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
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_validates_blocked_domain_format
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
     * Laravel 기본 :attribute 는 underscore 만 공백으로 치환한 영문 키 (예: "privacy policy url") 를
     * 노출하여 사용자가 어느 필드를 의미하는지 인지하기 어려움. FormRequest::attributes() 메서드로
     * 각 필드에 lang 의 사용자 친화 라벨을 매핑하여 메시지 안에 노출되도록 함.
     *
     * 검토 #10d — 사용자에게 영문 키 노출이 부적절하다는 피드백 후 추가.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects validation_message_uses_friendly_korean_label_when_locale_ko, privacy_policy_url_validation_names_the_field
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
                'privacy_policy_url' => 'javascript:alert(1)', // 허용 형식(사이트 경로·https) 위반
                'legal_entity_name' => str_repeat('가', 201), // max:200 위반
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['privacy_policy_url', 'legal_entity_name']);

        // :attribute 가 "개인정보처리방침 주소" 로 치환되어야 함 (영문 키 "privacy policy url" 가 아님)
        $errors = $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors['privacy_policy_url'] ?? null);
        $this->assertStringContainsString('개인정보처리방침 주소', $errors['privacy_policy_url'][0]);
        $this->assertStringNotContainsString('privacy policy url', $errors['privacy_policy_url'][0]);
        // 조사는 항목 이름에 붙인다 — 「개인정보처리방침 주소 에는」처럼 띄우면 문장이 어색하다.
        $this->assertStringStartsWith('개인정보처리방침 주소에는 ', $errors['privacy_policy_url'][0]);

        $this->assertNotEmpty($errors['legal_entity_name'] ?? null);
        $this->assertStringContainsString('법인명 / 운영 주체', $errors['legal_entity_name'][0]);
        $this->assertStringNotContainsString('legal entity name', $errors['legal_entity_name'][0]);
    }

    /**
     * 자동 통지를 켠 채 채널·상한을 비우면 「켜려면 무엇을 채워야 하는지」를 알리고, 코어 기본 문구의
     * 괄호 조사(「개인정보 연간 통지이(가) 있을 때 … 필수입니다」)를 남기지 않는다.
     *
     * 요청이 칸별 문구를 정하지 않은 규칙(in·required_with)도 괄호 조사 없는 플러그인 문구로 떨어진다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_validation_messages_read_naturally
     */
    public function test_update_annual_notice_and_choice_errors_read_without_bracketed_particles(): void
    {
        $user = $this->createPrivacyOperatorUser();
        $url = '/api/plugins/sirsoft-gdpr/admin/settings';

        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);
            $headers = ['Accept-Language' => $locale];

            $errors = $this->actingAs($user)->withHeaders($headers)->putJson($url, [
                'annual_notice' => ['enabled' => true, 'channels' => [], 'batch_size' => null],
            ])->assertStatus(422)->json('errors');
            $this->assertSame(
                __('sirsoft-gdpr::messages.settings.fields.annual_notice_channels.required_when_enabled'),
                $errors['annual_notice.channels'][0] ?? null,
            );
            $this->assertSame(
                __('sirsoft-gdpr::messages.settings.fields.annual_notice_batch_size.required_when_enabled'),
                $errors['annual_notice.batch_size'][0] ?? null,
            );

            // 키를 빠뜨린 요청(enabled 없음)·허용 밖 선택값(in) — 칸별 문구가 없는 규칙
            $missing = $this->actingAs($user)->withHeaders($headers)->putJson($url, [
                'annual_notice' => ['channels' => ['database'], 'batch_size' => 500],
                'banner_position' => 'top_banner',
            ])->assertStatus(422)->json('errors');
            $unknown = $this->actingAs($user)->withHeaders($headers)->putJson($url, [
                'annual_notice' => ['enabled' => true, 'channels' => ['sms'], 'batch_size' => 500],
            ])->assertStatus(422)->json('errors');

            foreach ([
                $missing['annual_notice.enabled'][0] ?? '(없음)',
                $missing['banner_position'][0] ?? '(없음)',
                $unknown['annual_notice.channels.0'][0] ?? '(없음)',
            ] as $message) {
                $this->assertNotSame('(없음)', $message);
                $this->assertDoesNotMatchRegularExpression('/[이을은과와]\([가를는과와]\)/u', $message, $message);
                $this->assertStringNotContainsString('annual notice', $message);
                $this->assertStringNotContainsString('is present', $message);
            }
        }
    }

    /**
     * 통지문 서문이 상한(2000자)을 넘으면 오류가 한 건만 나오고, 어느 언어 칸인지 알린다.
     *
     * 종전에는 다국어 규칙(「'ko' 번역은 2000자를 …」)과 언어별 길이 규칙(「통지문 서문 필드는 …」)이
     * 같은 뜻의 오류를 두 키(`annual_notice.introduction`·`.ko`)로 함께 냈다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_introduction_too_long_reports_single_error
     */
    public function test_update_too_long_introduction_reports_a_single_error_naming_the_language(): void
    {
        $user = $this->createPrivacyOperatorUser();
        config(['app.translatable_locales' => ['ko', 'en']]);

        // 언어 이름은 코어 언어 목록(config app.locale_names)의 자기 언어 표기를 쓴다 — 화면 언어 탭과 같다
        foreach (['ko' => '한국어', 'en' => '한국어'] as $locale => $language) {
            app()->setLocale($locale);

            $errors = $this->actingAs($user)->withHeaders(['Accept-Language' => $locale])
                ->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
                    'annual_notice' => [
                        'enabled' => true, 'channels' => ['database'], 'batch_size' => 500,
                        'introduction' => ['ko' => str_repeat('가', 2001), 'en' => 'Notice'],
                    ],
                ])->assertStatus(422)->json('errors');

            $keys = array_values(array_filter(array_keys($errors), fn (string $key) => str_starts_with($key, 'annual_notice.introduction')));
            $this->assertSame(['annual_notice.introduction.ko'], $keys);
            $this->assertCount(1, $errors['annual_notice.introduction.ko']);
            $this->assertStringContainsString(__('sirsoft-gdpr::messages.settings.fields.annual_notice_introduction.label'), $errors['annual_notice.introduction.ko'][0]);
            $this->assertStringContainsString($language, $errors['annual_notice.introduction.ko'][0]);
            $this->assertStringContainsString('2000', $errors['annual_notice.introduction.ko'][0]);
        }
    }

    /**
     * 데이터 저장 위치에 보안 민감 식별자 (IP / CIDR / AWS 리전 코드) 가 입력되면 422 검증 실패.
     *
     * GDPR Art.13(1)(f) / PIPA 28조의8 은 「국가 단위」 표기를 요구하며, 클라우드 리전 코드나
     * IP 대역은 처리방침 본문에 적시할 의무가 없을뿐더러 공격 표면 reconnaissance 단서가 되므로
     * FormRequest 에서 not_regex 2종으로 사전 차단한다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_validates_data_storage_location
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
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_validates_data_storage_location
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
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_never_publishes_policy_version
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
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects non_material_change_does_not_publish, admin_update_never_publishes_policy_version
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
     * @return void 반환값 없음
     *
     * @scenario surface=admin_show
     *
     * @effects admin_settings_does_not_expose_phase2_registration_fields
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
     * 처리방침 주소는 사이트 경로와 https 절대 주소를 받아 그대로 저장합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects privacy_policy_url_accepts_site_path_and_https_url
     */
    public function test_update_accepts_privacy_policy_site_path_and_https_url(): void
    {
        $captured = $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        foreach (['/privacy', '/page/privacy', 'https://example.com/privacy'] as $url) {
            $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
                'privacy_policy_url' => $url,
            ])->assertOk();

            $this->assertSame($url, $captured->value['privacy_policy_url'] ?? null, "저장해야 합니다: {$url}");
        }
    }

    /**
     * 처리방침 주소를 비우면(링크 숨김) 검증을 통과합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects privacy_policy_url_accepts_site_path_and_https_url
     */
    public function test_update_accepts_empty_privacy_policy_url(): void
    {
        $captured = $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'privacy_policy_url' => '',
        ])->assertOk();

        $this->assertArrayHasKey('privacy_policy_url', $captured->value);
        $this->assertEmpty($captured->value['privacy_policy_url']);
    }

    /**
     * 다른 스킴·http·프로토콜 상대 주소·백슬래시·공백이 든 처리방침 주소는 422 로 거부합니다.
     *
     * 배너가 이 값을 그대로 href 로 쓰므로, 여기서 새면 스크립트 실행이나 외부 사이트 이동으로 이어진다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects privacy_policy_url_rejects_unsafe_addresses
     */
    public function test_update_rejects_unsafe_privacy_policy_url(): void
    {
        $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        foreach ([
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'http://example.com/privacy',
            '//evil.com/privacy',
            '/\evil.com/privacy',
            '/pri vacy',
            'privacy',
            'https://user@evil.com/privacy',
        ] as $url) {
            $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
                'privacy_policy_url' => $url,
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['privacy_policy_url']);
        }
    }

    /**
     * 1.0.x 의 슬러그 키를 보내도 검증 규칙에 없으므로 저장되지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects privacy_policy_url_legacy_slug_key_is_not_saved
     */
    public function test_update_ignores_legacy_privacy_policy_slug(): void
    {
        $captured = $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'privacy_policy_slug' => 'privacy',
            'banner_enabled' => true,
        ])->assertOk();

        $this->assertArrayNotHasKey('privacy_policy_slug', $captured->value);
    }

    /**
     * PluginSettingsService::get 을 통제 가능한 Mock 으로 교체.
     *
     * @param  array<string, mixed>  $values  설정 키 => 저장값 (save 호출 시 갱신)
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated, request=valid_item
     *
     * @effects show_includes_default_catalog_previews
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
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=exact, locked=locked_item, settings_state=populated, request=valid_item
     *
     * @effects locked_set_absent_from_shipped_catalog
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
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=wildcard, locked=operator_item, settings_state=populated, request=valid_item
     *
     * @effects update_persists_allowlist_per_scope
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
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated, request=valid_item
     *
     * @effects update_normalizes_textarea_string_to_array
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
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated, request=key_absent
     *
     * @effects update_without_key_leaves_existing_value_untouched
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
     * @return void 반환값 없음
     *
     * @scenario scope=cookie, notation=wildcard, locked=operator_item, settings_state=populated, request=invalid_format
     *
     * @effects update_rejects_invalid_item_format_with_scope_indexed_error_key
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
     * @return void 반환값 없음
     *
     * @scenario scope=sessionStorage, notation=wildcard, locked=operator_item, settings_state=populated, request=valid_item
     *
     * @effects update_persists_allowlist_per_scope
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
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated, request=unknown_scope
     *
     * @effects update_rejects_unknown_scope
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
     * @return void 반환값 없음
     *
     * @scenario scope=localStorage, notation=exact, locked=locked_item, settings_state=populated, request=locked_key_in_body
     *
     * @effects update_ignores_locked_list_from_request
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
    /**
     * 쿠키 카테고리 키를 보내지 않은 저장은 저장된 카탈로그를 건드리지 않는다.
     *
     * 예전에는 키가 없어도 null 로 채워 저장해, 다른 항목만 고친 저장 한 번이 카탈로그를 지웠다.
     * 그 뒤 관리자 화면은 카테고리 카드와 차단 도메인 입력칸을 하나도 그리지 못했다.
     *
     * 트러블슈팅 `[case:backend-53]` 의 회귀 그물이다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_update
     *
     * @effects admin_update_without_cookie_categories_leaves_them_untouched
     */
    public function test_update_without_cookie_categories_key_leaves_them_untouched(): void
    {
        $captured = $this->captureSave();

        $user = $this->createPrivacyOperatorUser();

        $this->actingAs($user)->putJson('/api/plugins/sirsoft-gdpr/admin/settings', [
            'banner_enabled' => true,
        ])->assertOk();

        $this->assertArrayNotHasKey('cookie_categories', $captured->value);
    }

    /**
     * 저장된 쿠키 카테고리가 비어 있으면 관리자 조회도 공개 응답과 같은 기본 카탈로그를 싣는다.
     *
     * 관리자 화면은 이 값으로 카테고리 카드와 카테고리별 차단 도메인 입력칸을 그린다 — null 이 오면
     * 두 곳이 통째로 사라져 운영자가 차단 도메인을 고칠 길이 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin_show
     *
     * @effects admin_show_fills_default_categories_when_stored_value_empty
     */
    public function test_show_fills_default_categories_when_stored_value_is_empty(): void
    {
        foreach ([null, '[]', ''] as $stored) {
            $values = ['banner_enabled' => true, 'cookie_categories' => $stored];

            $mock = $this->createMock(PluginSettingsService::class);
            $mock->method('get')->willReturnCallback(
                function (string $id, ?string $key = null, mixed $default = null) use ($values) {
                    if ($key === null) {
                        return $values;
                    }

                    return array_key_exists($key, $values) ? $values[$key] : $default;
                }
            );
            $this->app->instance(PluginSettingsService::class, $mock);

            $user = $this->createPrivacyOperatorUser();

            $response = $this->actingAs($user)->getJson('/api/plugins/sirsoft-gdpr/admin/settings');

            $response->assertOk();
            $this->assertSame(
                ['necessary', 'functional', 'analytics', 'marketing'],
                array_column($response->json('data.settings.cookie_categories') ?? [], 'key'),
                '저장값 '.var_export($stored, true).' 일 때도 기본 카탈로그 4종이 실려야 한다',
            );
        }
    }

    /**
     * 설정 저장 서비스를 가짜로 바꿔, 저장에 넘어온 값을 잡아 둡니다.
     *
     * @return object 마지막 저장 값을 `value` 속성으로 담는 객체
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
