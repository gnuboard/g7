<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature\Api\Public;

use App\Services\PluginSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 공개 GDPR 설정 API 테스트
 *
 * GET /api/plugins/sirsoft-gdpr/settings
 *
 * 노출 토글: banner_enabled (쿠키 배너 노출 단일 토글).
 * - auto_blocking_enabled 키는 banner_enabled 와 통합되어 응답에서 제거됨 (위반 조합 구조적 차단)
 * - mypage_privacy_tab_visible 키는 Art.7(3) 대칭성 의무로 제거됨 (마이페이지 카드는 데이터 기반 가드)
 */
class GdprSettingsControllerTest extends PluginTestCase
{
    /**
     * PluginSettingsService 를 모킹하여 설정값을 주입합니다.
     *
     * @param  array<string, mixed>  $values  설정 키 => 저장값
     * @return void 반환값 없음
     */
    private function mockSettings(array $values): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $values[$key] ?? $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 인증 없이(게스트) 공개 설정을 조회할 수 있습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_accessible_without_auth
     */
    public function test_settings_is_publicly_accessible_without_auth(): void
    {
        $this->mockSettings([]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk();
    }

    /**
     * 공개 응답에 폐기된 마스터 스위치 키가 없습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_banner_toggle_only
     */
    public function test_settings_response_does_not_contain_master_switch(): void
    {
        $this->mockSettings(['banner_enabled' => true]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonMissingPath('data.master_switch');
    }

    /**
     * 저장된 배너 노출 토글(켜짐)을 그대로 내보냅니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_banner_toggle_only
     */
    public function test_settings_returns_banner_enabled_toggle(): void
    {
        $this->mockSettings([
            'banner_enabled' => true,
        ]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.banner_enabled', true);
    }

    /**
     * 배너 노출 토글(꺼짐)도 다른 설정과 무관하게 그대로 내보냅니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_banner_toggle_only
     */
    public function test_banner_enabled_passes_through_independently(): void
    {
        $this->mockSettings([
            'banner_enabled' => false,
        ]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.banner_enabled', false);
    }

    /**
     * 설정이 없으면 배너 노출 기본값은 켜짐입니다 (플러그인 활성화 = 즉시 작동).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_banner_toggle_only
     */
    public function test_default_toggle_value_is_true(): void
    {
        // 플러그인 활성화 = 운영자의 GDPR 컴플라이언스 의사 표명 → 기본값 true.
        // 시장 표준 CMP (OneTrust/Cookiebot/Iubenda/Klaro) 모두 "활성화 = 즉시 작동" 패턴.
        $this->mockSettings([]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.banner_enabled', true);
    }

    /**
     * auto_blocking_enabled 토글 제거 회귀 — banner_enabled 단일 토글로 통합되어
     * 공개 응답에 auto_blocking_enabled 필드가 존재하지 않는다. 설정 저장값이 있어도
     * 응답에 노출되지 않음.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_banner_toggle_only
     */
    public function test_settings_does_not_expose_auto_blocking_enabled(): void
    {
        $this->mockSettings([
            'auto_blocking_enabled' => true,
        ]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonMissingPath('data.auto_blocking_enabled');
    }

    /**
     * 마이페이지 카드 노출 토글 제거 회귀 — GDPR Art.7(3) 대칭성 의무에 따라
     * mypage_privacy_tab_visible 응답 필드가 완전히 제거되었음을 검증.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_banner_toggle_only
     */
    public function test_settings_does_not_expose_mypage_privacy_tab_visible(): void
    {
        $this->mockSettings([
            'mypage_privacy_tab_visible' => true,
        ]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonMissingPath('data.mypage_privacy_tab_visible');
    }

    /**
     * 운영자가 적은 처리방침 주소(사이트 경로·https 주소)를 그대로 내보내고 링크 노출 가능으로 표시합니다.
     *
     * 처리방침 페이지가 실제로 있는지는 보지 않는다 — 그 페이지를 어느 확장이 게시하는지 이 플러그인은
     * 모르며, 페이지 모듈의 주소 규칙에 기대지 않는 것이 이 설정의 목적이다.
     *
     * 경우마다 따로 실행한다 — 한 테스트 안에서 요청을 거듭하면 라우트가 첫 요청의 컨트롤러(첫 설정 목)를
     * 다시 써서 두 번째 값부터는 검증되지 않는다.
     *
     * @param  string  $url  저장된 처리방침 주소
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_privacy_policy_url_and_availability
     */
    #[DataProvider('operatorPrivacyPolicyUrlProvider')]
    public function test_settings_exposes_operator_privacy_policy_url(string $url): void
    {
        $this->mockSettings(['privacy_policy_url' => $url]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy_url', $url)
            ->assertJsonPath('data.privacy_policy_available', true)
            ->assertJsonMissingPath('data.privacy_policy_slug');
    }

    /**
     * 그대로 내보내야 하는 처리방침 주소 — 사이트 경로와 https 외부 주소.
     *
     * @return array<string, array{0: string}> 경우 이름 => [저장값]
     */
    public static function operatorPrivacyPolicyUrlProvider(): array
    {
        return [
            'site path' => ['/privacy'],
            'external https' => ['https://example.com/privacy'],
        ];
    }

    /**
     * 처리방침 주소가 비어 있으면 링크를 숨기도록 null + 노출 불가로 표시합니다 (기본값도 빈 값).
     *
     * @param  array<string, mixed>  $values  저장된 설정값
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_privacy_policy_url_and_availability
     */
    #[DataProvider('emptyPrivacyPolicyUrlProvider')]
    public function test_settings_marks_privacy_policy_unavailable_when_url_empty(array $values): void
    {
        $this->mockSettings($values);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy_available', false)
            ->assertJsonPath('data.privacy_policy_url', null);
    }

    /**
     * 링크를 숨겨야 하는 빈 처리방침 주소 — 빈 문자열·null·키 없음(기본값).
     *
     * @return array<string, array{0: array<string, mixed>}> 경우 이름 => [저장된 설정값]
     */
    public static function emptyPrivacyPolicyUrlProvider(): array
    {
        return [
            'empty string' => [['privacy_policy_url' => '']],
            'null' => [['privacy_policy_url' => null]],
            'missing key' => [[]],
        ];
    }

    /**
     * 허용 형식이 아닌 저장값(설정 파일 직접 수정 등)은 배너 href 로 새지 않도록 숨깁니다.
     *
     * @param  string  $url  허용 형식이 아닌 저장값
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_hides_unsafe_stored_privacy_policy_url
     */
    #[DataProvider('unsafePrivacyPolicyUrlProvider')]
    public function test_settings_hides_unsafe_stored_privacy_policy_url(string $url): void
    {
        $this->mockSettings(['privacy_policy_url' => $url]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy_url', null)
            ->assertJsonPath('data.privacy_policy_available', false);
    }

    /**
     * 공개 응답에서 숨겨야 하는 처리방침 저장값 — 스크립트 스킴·스킴 상대·역슬래시 우회·평문 http.
     *
     * @return array<string, array{0: string}> 경우 이름 => [저장값]
     */
    public static function unsafePrivacyPolicyUrlProvider(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'protocol relative' => ['//evil.com/privacy'],
            'backslash host' => ['/\evil.com'],
            'plain http' => ['http://example.com/privacy'],
        ];
    }

    /**
     * 1.0.x 슬러그 설정은 더 이상 링크를 만들지 않습니다 (페이지 모듈 주소 규칙 조합 제거).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_privacy_policy_url_and_availability
     */
    public function test_settings_ignores_legacy_privacy_policy_slug(): void
    {
        $this->mockSettings(['privacy_policy_slug' => 'privacy']);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.privacy_policy_url', null)
            ->assertJsonPath('data.privacy_policy_available', false)
            ->assertJsonMissingPath('data.privacy_policy_slug');
    }

    /**
     * F-02 도메인 차단 — 운영자 입력값이 공개 응답에 노출됨 (게스트도 차단 동작 가능).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_blocked_domains_catalog
     */
    public function test_settings_exposes_blocked_domains_to_guest(): void
    {
        $this->mockSettings([
            'blocked_domains' => [
                'analytics' => ['google-analytics.com', '*.hotjar.com'],
                'marketing' => ['facebook.com'],
            ],
        ]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.blocked_domains.analytics.0', 'google-analytics.com')
            ->assertJsonPath('data.blocked_domains.analytics.1', '*.hotjar.com')
            ->assertJsonPath('data.blocked_domains.marketing.0', 'facebook.com');
    }

    /**
     * F-02 도메인 차단 — 설정 미입력 시 카탈로그 도메인이 기본값으로 반환됨.
     *
     * 카탈로그 토글 (blocked_domains_default_catalog) 제거 + 신규 설치 시 카탈로그가
     * 기본값으로 채워지는 단일 개념 통합 후의 동작 검증.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_blocked_domains_catalog
     */
    public function test_settings_returns_default_catalog_when_unset(): void
    {
        $this->mockSettings([]);

        $response = $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.blocked_domains.analytics.0', 'google-analytics.com')
            ->assertJsonPath('data.blocked_domains.marketing.0', 'facebook.net');

        // Phase 2: functional 카탈로그가 외부 functional 도구 도메인으로 채워짐 (Crisp / Intercom / Tawk.to / Weglot / Usercentrics)
        $functional = $response->json('data.blocked_domains.functional');
        $this->assertIsArray($functional);
        $this->assertNotEmpty($functional, 'Phase 2 functional 카탈로그가 비어있음 — DEFAULT_BLOCKED_DOMAINS_CATALOG 시드 확인 필요');
        $this->assertContains('*.crisp.chat', $functional);
    }

    /**
     * Phase 1: functional 카테고리 차단 도메인 — 운영자 입력값이 공개 응답에 노출됨.
     *
     * ICO/CNIL 4분류 체계 부합 — functional 도구 (Crisp, Intercom 등) 도메인이
     * 게스트 차단 엔진(blocker.ts)에 전달되어 사용자 동의 전 차단됨.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_blocked_domains_catalog
     */
    public function test_settings_exposes_functional_blocked_domains_to_guest(): void
    {
        $this->mockSettings([
            'blocked_domains' => [
                'functional' => ['*.crisp.chat', 'widget.intercom.io'],
                'analytics' => ['google-analytics.com'],
                'marketing' => ['facebook.com'],
            ],
        ]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.blocked_domains.functional.0', '*.crisp.chat')
            ->assertJsonPath('data.blocked_domains.functional.1', 'widget.intercom.io');
    }

    /**
     * Phase 1: cookie_categories 응답에 4분류 (necessary/functional/analytics/marketing) 모두 포함.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_four_categories
     */
    public function test_settings_exposes_four_categories(): void
    {
        $this->mockSettings([]);

        $response = $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk();

        $categories = $response->json('data.cookie_categories');
        $this->assertIsArray($categories);
        $this->assertCount(4, $categories, 'Phase 1 부터 카테고리 4종 노출 (ICO/CNIL 4분류 부합)');

        $keys = array_column($categories, 'key');
        $this->assertSame(['necessary', 'functional', 'analytics', 'marketing'], $keys);
    }

    /**
     * F-02 도메인 차단 — JSON 문자열로 저장된 구버전 값도 응답에서 native array 로 정규화.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_blocked_domains_catalog
     */
    public function test_settings_normalizes_json_string_blocked_domains(): void
    {
        $this->mockSettings([
            'blocked_domains' => json_encode([
                'analytics' => ['google-analytics.com'],
                'marketing' => [],
            ]),
        ]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.blocked_domains.analytics.0', 'google-analytics.com')
            ->assertJsonPath('data.blocked_domains.marketing', []);
    }

    /**
     * F-02 도메인 차단 — 빈 문자열·whitespace 도메인은 응답에서 제거됨.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_blocked_domains_catalog
     */
    public function test_settings_strips_empty_strings_from_blocked_domains(): void
    {
        $this->mockSettings([
            'blocked_domains' => [
                'analytics' => ['google-analytics.com', '', '   ', 'wcs.naver.net'],
                'marketing' => [''],
            ],
        ]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonPath('data.blocked_domains.analytics', ['google-analytics.com', 'wcs.naver.net'])
            ->assertJsonPath('data.blocked_domains.marketing', []);
    }

    /**
     * F-02 카탈로그 미리보기 — 관리자 UI 추천 옵션용 카탈로그 도메인이 공개 응답에 노출됨.
     *
     * 차단 동작에는 사용되지 않으며 (차단은 클라이언트 코드 상수 기반), 관리자 UI 의
     * TagInput 자동완성 옵션 추천에만 사용됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_exposes_blocked_domains_catalog
     */
    public function test_settings_exposes_default_blocked_domains_preview(): void
    {
        $this->mockSettings([]);

        $response = $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk();

        $preview = $response->json('data.default_blocked_domains_preview');

        $this->assertIsArray($preview);
        $this->assertArrayHasKey('analytics', $preview);
        $this->assertArrayHasKey('marketing', $preview);
        $this->assertContains('google-analytics.com', $preview['analytics']);
        $this->assertContains('*.hotjar.com', $preview['analytics']);
        $this->assertContains('facebook.net', $preview['marketing']);
        $this->assertContains('doubleclick.net', $preview['marketing']);
    }

    /**
     * Phase 2 단순화: 공개 응답에 functional 등록 표 (functional_storage_keys / functional_cookies /
     * functional_allow_user_initiated) 는 더 이상 노출하지 않는다.
     *
     * 게이팅은 strictly necessary allowlist (코드 상수) 외 모든 비-필수 저장을 동의 시까지
     * 차단하는 4단계 단순화 규칙으로 처리되므로 운영자 등록 응답이 불필요.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=public_show
     *
     * @effects public_settings_does_not_expose_phase2_registration_fields
     */
    public function test_settings_does_not_expose_phase2_registration_fields(): void
    {
        $this->mockSettings(['banner_enabled' => true]);

        $this->getJson('/api/plugins/sirsoft-gdpr/settings')
            ->assertOk()
            ->assertJsonMissingPath('data.functional_storage_keys')
            ->assertJsonMissingPath('data.functional_cookies')
            ->assertJsonMissingPath('data.functional_allow_user_initiated');
    }
}
