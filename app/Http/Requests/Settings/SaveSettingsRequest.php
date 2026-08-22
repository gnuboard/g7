<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use App\Models\Attachment;
use App\Rules\ValidOutboundProxyUrl;
use App\Search\Engines\DatabaseFulltextEngine;
use App\Services\DriverRegistryService;
use App\Support\AllowedExtensions;
use App\Support\AssetUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSettingsRequest extends FormRequest
{
    /**
     * 지원되는 메일러 목록
     */
    private const SUPPORTED_MAILERS = ['smtp', 'mailgun', 'ses'];

    /**
     * 지원되는 암호화 방식 목록
     */
    private const SUPPORTED_ENCRYPTIONS = ['tls', 'ssl', ''];

    /**
     * 지원되는 스토리지 드라이버 목록
     */
    private const SUPPORTED_STORAGE_DRIVERS = ['local', 's3'];

    /**
     * S3 리전 형식 (AWS 리전 코드 + S3 호환 스토리지 임의값 허용 — R2 의 `auto` 등)
     */
    private const S3_REGION_FORMAT = 'regex:/^[a-z0-9-]+$/';

    /**
     * 지원되는 캐시 드라이버 목록
     */
    private const SUPPORTED_CACHE_DRIVERS = ['file', 'redis', 'memcached'];

    /**
     * 지원되는 세션 드라이버 목록
     */
    private const SUPPORTED_SESSION_DRIVERS = ['file', 'database', 'redis'];

    /**
     * 지원되는 큐 드라이버 목록
     */
    private const SUPPORTED_QUEUE_DRIVERS = ['sync', 'database', 'redis'];

    /**
     * 지원되는 웹소켓 프로토콜 목록
     */
    private const SUPPORTED_WEBSOCKET_SCHEMES = ['http', 'https'];

    /**
     * 지원되는 로그 드라이버 목록
     */
    private const SUPPORTED_LOG_DRIVERS = ['single', 'daily'];

    /**
     * 지원되는 로그 레벨 목록
     */
    private const SUPPORTED_LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 권한 체크는 permission 미들웨어 체인이 담당하므로 항상 true 반환
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 입력 데이터를 정규화합니다.
     *
     * site_logo 필드가 Attachment 객체 배열로 전송될 수 있으므로
     * 정수 ID 배열로 변환합니다.
     */
    protected function prepareForValidation(): void
    {
        $siteLogo = $this->input('general.site_logo');

        if (is_array($siteLogo)) {
            $this->merge([
                'general' => array_merge($this->input('general', []), [
                    'site_logo' => array_map(
                        fn ($item) => is_array($item) ? ($item['id'] ?? $item) : $item,
                        $siteLogo
                    ),
                ]),
            ]);
        }

        // GeoIP 토글 필드: 신규 필드라 초기값이 settings에 없을 수 있음.
        // Toggle 컴포넌트가 undefined → true 전환 시 비표준 타입을 보낼 수 있으므로
        // boolean으로 명시 캐스팅하여 Laravel boolean 검증 호환 보장.
        $this->castAdvancedBooleanFields([
            'advanced.geoip_enabled',
            'advanced.geoip_auto_update_enabled',
        ]);

        $this->normalizeAllowedExtensions();
    }

    /**
     * 허용 확장자를 저장 형태(소문자 확장자 배열)로 정규화합니다.
     *
     * 화면 입력 칸은 콤마로 구분한 한 줄 텍스트라 이 값은 문자열로 들어온다. 문자열 그대로
     * 저장하면 업로드 제한을 적용하는 지점이 그 값을 쓰지 못해, 저장은 성공하는데 제한은
     * 종전 목록으로 남는다. 형태 판정은 저장 경로 진입 전 이 한 곳에서만 수행한다.
     */
    private function normalizeAllowedExtensions(): void
    {
        $upload = $this->input('upload', []);

        if (! is_array($upload) || ! array_key_exists('allowed_extensions', $upload)) {
            return;
        }

        $upload['allowed_extensions'] = AllowedExtensions::normalize($upload['allowed_extensions']);

        $this->merge(['upload' => $upload]);
    }

    /**
     * advanced 탭의 boolean 필드를 PHP boolean으로 캐스팅합니다.
     *
     * Toggle 컴포넌트가 초기값 미존재(undefined) 상태에서 전환 시
     * 비표준 타입(문자열 "true", null 등)을 전송할 수 있으므로
     * prepareForValidation 단계에서 명시 캐스팅합니다.
     *
     * @param  array<int, string>  $fields  dot-notation 필드 경로 배열
     */
    private function castAdvancedBooleanFields(array $fields): void
    {
        $advanced = $this->input('advanced', []);
        $changed = false;

        foreach ($fields as $dotPath) {
            $key = str_replace('advanced.', '', $dotPath);
            if (array_key_exists($key, $advanced)) {
                $advanced[$key] = filter_var($advanced[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                $changed = true;
            }
        }

        if ($changed) {
            $this->merge(['advanced' => $advanced]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * nested 구조로 요청됩니다:
     * { _tab: 'general', general: { site_name: '...', ... } }
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tab = $this->input('_tab', 'general');

        $rules = [
            // 탭 식별자
            '_tab' => ['nullable', 'string', Rule::in(['general', 'mail', 'upload', 'seo', 'security', 'drivers', 'advanced', 'notifications', 'identity'])],

            // 각 탭의 컨테이너
            'general' => ['sometimes', 'array'],
            'mail' => ['sometimes', 'array'],
            'upload' => ['sometimes', 'array'],
            'seo' => ['sometimes', 'array'],
            'security' => ['sometimes', 'array'],
            'drivers' => ['sometimes', 'array'],
            'advanced' => ['sometimes', 'array'],
            'notifications' => ['sometimes', 'array'],
            'identity' => ['sometimes', 'array'],
            'notifications.channels' => ['sometimes', 'array'],
            'notifications.channels.*.id' => ['required_with:notifications.channels', 'string', 'max:50'],
            'notifications.channels.*.is_active' => ['required_with:notifications.channels', 'boolean'],
            'notifications.channels.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // 일반 설정
            'general.site_name' => $this->getTabRules($tab, 'general', 'string|max:100'),
            'general.site_url' => $this->getTabRules($tab, 'general', 'url|max:255'),
            'general.site_description' => ['nullable', 'string', 'max:500'],
            'general.admin_email' => $this->getTabRules($tab, 'general', 'email|max:255'),
            'general.timezone' => $this->getTabRules($tab, 'general', ['timezone']),
            'general.language' => $this->getTabRules($tab, 'general', [Rule::in(config('app.supported_locales', ['ko', 'en']))]),
            'general.currency' => ['nullable', 'string', 'max:10'],
            'general.maintenance_mode' => ['nullable', 'boolean'],
            // 자산 URL 방식 — 정적 최적화 서버 대응 (이슈 #486).
            // 임의 문자열이 들어오면 AssetUrl::mode() 가 기본값으로 폴백하지만,
            // 저장 단계에서 막아 설정 파일에 뜻 모를 값이 남지 않게 한다.
            'general.asset_url_mode' => ['nullable', 'string', Rule::in([
                AssetUrl::MODE_EXTENSION,
                AssetUrl::MODE_EXTENSIONLESS,
            ])],
            'general.site_logo' => ['nullable', 'array'],
            'general.site_logo.*' => ['integer', Rule::exists(Attachment::class, 'id')],

            // 메일 설정
            'mail.mailer' => $this->getTabRules($tab, 'mail', [Rule::in(self::SUPPORTED_MAILERS)]),
            'mail.host' => $this->getMailerRules($tab, 'smtp', 'string|max:255'),
            'mail.port' => $this->getMailerRules($tab, 'smtp', 'integer|min:1|max:65535'),
            'mail.username' => ['nullable', 'string', 'max:255'],
            'mail.password' => ['nullable', 'string', 'max:255'],
            'mail.encryption' => ['nullable', Rule::in(self::SUPPORTED_ENCRYPTIONS)],
            'mail.mailgun_domain' => $this->getMailerRules($tab, 'mailgun', 'string|max:255'),
            'mail.mailgun_secret' => $this->getMailerRules($tab, 'mailgun', 'string|max:255'),
            'mail.mailgun_endpoint' => ['nullable', 'string', 'max:255'],
            'mail.ses_key' => $this->getMailerRules($tab, 'ses', 'string|max:255'),
            'mail.ses_secret' => $this->getMailerRules($tab, 'ses', 'string|max:255'),
            'mail.ses_region' => $this->getMailerRules($tab, 'ses', 'string|max:255'),
            'mail.from_address' => $this->getTabRules($tab, 'mail', 'email|max:255'),
            'mail.from_name' => $this->getTabRules($tab, 'mail', 'string|max:255'),

            // 업로드 설정
            'upload.max_file_size' => $this->getTabRules($tab, 'upload', 'integer|min:'.config('core.settings_limits.upload_max_file_size_min', 1).'|max:'.config('core.settings_limits.upload_max_file_size_max', 1024)),
            'upload.allowed_extensions' => $this->getAllowedExtensionsRules($tab),
            'upload.image_max_width' => ['nullable', 'integer', 'min:'.config('core.settings_limits.upload_image_max_width_min', 100), 'max:'.config('core.settings_limits.upload_image_max_width_max', 10000)],
            'upload.image_max_height' => ['nullable', 'integer', 'min:'.config('core.settings_limits.upload_image_max_height_min', 100), 'max:'.config('core.settings_limits.upload_image_max_height_max', 10000)],
            'upload.image_quality' => ['nullable', 'integer', 'min:'.config('core.settings_limits.upload_image_quality_min', 1), 'max:'.config('core.settings_limits.upload_image_quality_max', 100)],
            // 고아 첨부 정리 — 사용자 파일을 파기하므로 보존기간 하한을 서버가 강제한다.
            'upload.orphan_cleanup_enabled' => ['nullable', 'boolean'],
            'upload.orphan_retention_days' => ['nullable', 'integer', 'min:'.config('core.settings_limits.upload_orphan_retention_days_min', 1), 'max:'.config('core.settings_limits.upload_orphan_retention_days_max', 3650)],

            // SEO 설정
            'seo.meta_title_suffix' => ['nullable', 'string', 'max:100'],
            'seo.meta_description' => ['nullable', 'string', 'max:160'],
            'seo.meta_keywords' => ['nullable', 'string', 'max:255'],
            'seo.google_analytics_id' => ['nullable', 'string', 'max:50'],
            'seo.google_site_verification' => ['nullable', 'string', 'max:100'],
            'seo.naver_site_verification' => ['nullable', 'string', 'max:100'],
            'seo.bot_user_agents' => ['nullable', 'array'],
            'seo.bot_user_agents.*' => ['string', 'max:100'],
            'seo.bot_detection_enabled' => ['nullable', 'boolean'],
            'seo.bot_detection_library_enabled' => ['nullable', 'boolean'],
            'seo.og_default_site_name' => ['nullable', 'string', 'max:200'],
            'seo.og_image_default_width' => ['nullable', 'integer', 'min:'.config('core.settings_limits.seo_og_image_default_width_min', 0), 'max:'.config('core.settings_limits.seo_og_image_default_width_max', 8000)],
            'seo.og_image_default_height' => ['nullable', 'integer', 'min:'.config('core.settings_limits.seo_og_image_default_height_min', 0), 'max:'.config('core.settings_limits.seo_og_image_default_height_max', 8000)],
            'seo.twitter_default_card' => ['nullable', 'string', Rule::in(['summary', 'summary_large_image', 'app', 'player', ''])],
            'seo.twitter_default_site' => ['nullable', 'string', 'max:50'],
            'seo.cache_enabled' => ['nullable', 'boolean'],
            'seo.cache_ttl' => ['nullable', 'integer', 'min:'.config('core.settings_limits.seo_cache_ttl_min', 60), 'max:'.config('core.settings_limits.seo_cache_ttl_max', 86400)],
            'seo.sitemap_enabled' => ['nullable', 'boolean'],
            'seo.sitemap_cache_ttl' => ['nullable', 'integer', 'min:'.config('core.settings_limits.seo_sitemap_cache_ttl_min', 3600), 'max:'.config('core.settings_limits.seo_sitemap_cache_ttl_max', 604800)],
            // 분할 기준. 상한 50000 은 sitemaps.org 프로토콜 제한이며 SitemapWriter 가 동일 값으로 클램프합니다.
            'seo.sitemap_urls_per_file' => ['nullable', 'integer', 'min:'.config('core.settings_limits.seo_sitemap_urls_per_file_min', 1000), 'max:'.config('core.settings_limits.seo_sitemap_urls_per_file_max', 50000)],
            'seo.sitemap_gzip' => ['nullable', 'boolean'],
            'seo.sitemap_serve_stale_on_miss' => ['nullable', 'boolean'],
            'seo.sitemap_max_urls_per_contributor' => ['nullable', 'integer', 'min:0'],
            'seo.sitemap_hreflang_enabled' => ['nullable', 'boolean'],
            'seo.sitemap_schedule' => ['nullable', 'string', Rule::in(['hourly', 'daily', 'weekly'])],
            'seo.sitemap_schedule_time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'seo.generator_enabled' => ['nullable', 'boolean'],
            'seo.generator_content' => ['nullable', 'string', 'max:200'],

            // 보안 설정
            'security.force_https' => $this->getTabRules($tab, 'security', 'boolean'),
            'security.login_attempt_enabled' => $this->getTabRules($tab, 'security', 'boolean'),
            'security.auth_token_lifetime' => $this->getSecurityTabAuthTokenRules($tab),
            'security.max_login_attempts' => ['nullable', 'integer', 'min:'.config('core.settings_limits.security_max_login_attempts_min', 0), 'max:'.config('core.settings_limits.security_max_login_attempts_max', 100)],
            'security.login_lockout_time' => ['nullable', 'integer', 'min:'.config('core.settings_limits.security_login_lockout_time_min', 0), 'max:'.config('core.settings_limits.security_login_lockout_time_max', 1440)],
            // 비밀번호 정책 — 저장 규칙이 없어 whitelist 에서 탈락하던 고아 설정을 배선.
            // 경계값은 config('core.settings_limits') 가 SSoT — 설정 화면도 같은 값을 받아 쓴다.
            'security.password_min_length' => [
                'nullable',
                'integer',
                'min:'.config('core.settings_limits.security_password_min_length_min', 6),
                'max:'.config('core.settings_limits.security_password_min_length_max', 64),
            ],
            'security.require_password_special_char' => ['nullable', 'boolean'],
            'security.two_factor_auth' => ['nullable', 'boolean'],
            // 신규 설정이므로 미전송(기존 클라이언트)을 허용한다 — 미전송 시 기본값 false 유지
            'security.allow_internal_outbound_urls' => ['nullable', 'boolean'],

            // 캐시 설정 (advanced 탭)
            'advanced.cache_enabled' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.layout_cache_enabled' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.layout_cache_ttl' => $this->getAdvancedTabCacheTtlRules($tab),
            'advanced.stats_cache_enabled' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.stats_cache_ttl' => $this->getAdvancedTabCacheTtlRules($tab),
            'advanced.seo_cache_enabled' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.seo_cache_ttl' => $this->getAdvancedTabCacheTtlRules($tab),
            // 신규 설정이므로 미전송(기존 클라이언트)을 허용한다 — 미전송/미지정 시 코드 기본값 유지.
            // 범위는 SEO 탭의 오버라이드 칸(seo.sitemap_cache_ttl)과 동일하게 맞춘다.
            'advanced.seo_sitemap_cache_ttl' => ['nullable', 'integer', 'min:'.config('core.settings_limits.advanced_seo_sitemap_cache_ttl_min', 3600), 'max:'.config('core.settings_limits.advanced_seo_sitemap_cache_ttl_max', 604800)],

            // 디버그 설정 (advanced 탭)
            'advanced.debug_mode' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.sql_query_log' => $this->getTabRules($tab, 'advanced', 'boolean'),

            // 아웃바운드 HTTP 프록시 (advanced 탭)
            // 디버그 모드 OFF 시 하위 필드는 collapse 되어 미전송됨 → nullable 필수
            // (geoip 하위 필드와 같은 사유 — 조건부 렌더링 내부에 있다)
            'advanced.outbound_proxy' => ['nullable', 'string', 'max:500', new ValidOutboundProxyUrl],
            'advanced.outbound_proxy_bypass' => ['nullable', 'array'],
            'advanced.outbound_proxy_bypass.*' => ['string', 'max:255'],

            // 코어 업데이트 설정 (advanced 탭)
            'advanced.core_update_github_url' => ['nullable', 'url', 'max:500'],
            'advanced.core_update_github_token' => ['nullable', 'string', 'max:500'],

            // GeoIP 설정 (advanced 탭)
            // 마스터 토글 OFF 시 하위 필드(license_key, auto_update)는 collapse 되어 미전송됨
            // → nullable 필수 (기존 cache/debug 필드와 달리 조건부 렌더링 내부에 있음)
            'advanced.geoip_enabled' => ['nullable', 'boolean'],
            'advanced.geoip_license_key' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_]+$/'],
            'advanced.geoip_auto_update_enabled' => ['nullable', 'boolean'],

            // 목록 한계값 (advanced 탭) — 0 은 무제한
            'advanced.pagination_result_cap' => ['nullable', 'integer', 'min:'.config('core.settings_limits.advanced_pagination_result_cap_min', 0), 'max:'.config('core.settings_limits.advanced_pagination_result_cap_max', 1000000)],
            'advanced.pagination_max_page' => ['nullable', 'integer', 'min:'.config('core.settings_limits.advanced_pagination_max_page_min', 0), 'max:'.config('core.settings_limits.advanced_pagination_max_page_max', 100000)],

            // 드라이버 설정 (drivers 탭)
            'drivers.storage_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_STORAGE_DRIVERS), $this->getDriverUsabilityRule('storage')]),
            'drivers.s3_bucket' => ['nullable', 'string', 'max:255'],
            'drivers.s3_region' => ['nullable', 'string', 'max:64', self::S3_REGION_FORMAT],
            'drivers.s3_access_key' => ['nullable', 'string', 'max:255'],
            'drivers.s3_secret_key' => ['nullable', 'string', 'max:255'],
            'drivers.s3_url' => ['nullable', 'url', 'max:500'],
            'drivers.s3_endpoint' => ['nullable', 'url', 'max:500'],
            'drivers.s3_use_path_style' => ['nullable', 'boolean'],
            'drivers.cache_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_CACHE_DRIVERS), $this->getDriverUsabilityRule('cache')]),
            'drivers.redis_host' => ['nullable', 'string', 'max:255'],
            'drivers.redis_port' => ['nullable', 'integer', 'min:'.config('core.settings_limits.drivers_redis_port_min', 1), 'max:'.config('core.settings_limits.drivers_redis_port_max', 65535)],
            'drivers.redis_password' => ['nullable', 'string', 'max:255'],
            'drivers.redis_database' => ['nullable', 'integer', 'min:'.config('core.settings_limits.drivers_redis_database_min', 0), 'max:'.config('core.settings_limits.drivers_redis_database_max', 15)],
            'drivers.memcached_host' => ['nullable', 'string', 'max:255'],
            'drivers.memcached_port' => ['nullable', 'integer', 'min:'.config('core.settings_limits.drivers_memcached_port_min', 1), 'max:'.config('core.settings_limits.drivers_memcached_port_max', 65535)],
            'drivers.session_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_SESSION_DRIVERS), $this->getDriverUsabilityRule('session')]),
            'drivers.session_lifetime' => ['nullable', 'integer', 'min:'.config('core.settings_limits.drivers_session_lifetime_min', 1), 'max:'.config('core.settings_limits.drivers_session_lifetime_max', 43200)], // 1분 ~ 30일
            'drivers.queue_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_QUEUE_DRIVERS), $this->getDriverUsabilityRule('queue')]),
            'drivers.websocket_enabled' => ['nullable', 'boolean'],
            'drivers.websocket_app_id' => [Rule::requiredIf(fn () => $this->boolean('drivers.websocket_enabled')), 'nullable', 'string', 'max:255'],
            'drivers.websocket_app_key' => [Rule::requiredIf(fn () => $this->boolean('drivers.websocket_enabled')), 'nullable', 'string', 'max:255'],
            'drivers.websocket_app_secret' => [Rule::requiredIf(fn () => $this->boolean('drivers.websocket_enabled')), 'nullable', 'string', 'max:255'],
            'drivers.websocket_host' => ['nullable', 'string', 'max:255'],
            'drivers.websocket_port' => ['nullable', 'integer', 'min:'.config('core.settings_limits.drivers_websocket_port_min', 1), 'max:'.config('core.settings_limits.drivers_websocket_port_max', 65535)],
            'drivers.websocket_scheme' => ['nullable', Rule::in(self::SUPPORTED_WEBSOCKET_SCHEMES)],
            'drivers.websocket_verify_ssl' => ['nullable', 'boolean'],
            'drivers.websocket_server_host' => ['nullable', 'string', 'max:255'],
            'drivers.websocket_server_port' => ['nullable', 'integer', 'min:'.config('core.settings_limits.drivers_websocket_server_port_min', 1), 'max:'.config('core.settings_limits.drivers_websocket_server_port_max', 65535)],
            'drivers.websocket_server_scheme' => ['nullable', Rule::in(self::SUPPORTED_WEBSOCKET_SCHEMES)],
            'drivers.search_engine_driver' => ['nullable', Rule::in($this->getSupportedSearchEngineDrivers())],
            'drivers.log_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_LOG_DRIVERS)]),
            'drivers.log_level' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_LOG_LEVELS)]),
            'drivers.log_days' => ['nullable', 'integer', 'min:'.config('core.settings_limits.drivers_log_days_min', 1), 'max:'.config('core.settings_limits.drivers_log_days_max', 365)],
            // 공개 자산 디스크 — 코어 3종(none/public/s3) + 플러그인 훅 등록 디스크.
            // 카탈로그가 동적(플러그인 훅)이라 정적 Rule::in 불가 → 레지스트리 조회 closure
            'drivers.public_asset_disk' => $this->getPublicAssetDiskRules(),

            // 본인인증(IDV) provider 기술 파라미터 — 정책 분기는 IdentityPolicy 로 흡수됨
            'identity.default_provider' => ['nullable', 'string', 'max:100'],
            'identity.purpose_providers' => ['sometimes', 'array'],
            'identity.challenge_ttl_minutes' => $this->getTabRules($tab, 'identity', 'integer|min:'.config('core.settings_limits.identity_challenge_ttl_minutes_min', 1).'|max:'.config('core.settings_limits.identity_challenge_ttl_minutes_max', 1440)),
            'identity.max_attempts' => $this->getTabRules($tab, 'identity', 'integer|min:'.config('core.settings_limits.identity_max_attempts_min', 1).'|max:'.config('core.settings_limits.identity_max_attempts_max', 20)),
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.settings.save_validation_rules', $rules, $this);
    }

    /**
     * 특정 탭의 필수 필드에 대한 규칙을 반환합니다.
     *
     * @param  string  $currentTab  현재 활성 탭
     * @param  string  $targetTab  규칙이 적용될 타겟 탭
     * @param  string|array  $additionalRules  추가 규칙
     * @return array 검증 규칙 배열
     */
    private function getTabRules(string $currentTab, string $targetTab, string|array $additionalRules): array
    {
        $baseRules = $currentTab === $targetTab ? ['required'] : ['nullable'];

        if (is_string($additionalRules)) {
            return array_merge($baseRules, explode('|', $additionalRules));
        }

        return array_merge($baseRules, $additionalRules);
    }

    /**
     * 메일러 드라이버별 조건부 필드 규칙을 반환합니다.
     *
     * mail 탭이 활성 상태이고 현재 선택된 메일러가 대상 메일러와 일치하면 필수,
     * 그렇지 않으면 nullable로 처리합니다.
     *
     * @param  string  $currentTab  현재 활성 탭
     * @param  string  $targetMailer  규칙이 적용될 대상 메일러 (smtp, mailgun, ses)
     * @param  string|array  $additionalRules  추가 규칙
     * @return array 검증 규칙 배열
     */
    private function getMailerRules(string $currentTab, string $targetMailer, string|array $additionalRules): array
    {
        $mailer = $this->input('mail.mailer', 'smtp');
        $isRequired = $currentTab === 'mail' && $mailer === $targetMailer;
        $baseRules = $isRequired ? ['required'] : ['nullable'];

        if (is_string($additionalRules)) {
            return array_merge($baseRules, explode('|', $additionalRules));
        }

        return array_merge($baseRules, $additionalRules);
    }

    /**
     * 인증 토큰 유지시간에 대한 검증 규칙을 반환합니다.
     * 0이면 무한대, 30~3600 분 사이 값만 허용
     *
     * @param  string  $currentTab  현재 탭
     * @return array 검증 규칙 배열
     */
    private function getSecurityTabAuthTokenRules(string $currentTab): array
    {
        return [
            'nullable',
            'integer',
            'min:'.config('core.settings_limits.security_auth_token_lifetime_min', 0),
            'max:'.config('core.settings_limits.security_auth_token_lifetime_max', 3600),
            function ($attribute, $value, $fail) {
                // 0은 무한대를 의미하므로 허용, 그 외에는 30 이상이어야 함
                if ($value !== null && $value !== 0 && $value < 30) {
                    $fail(__('validation.settings.auth_token_lifetime_range'));
                }
            },
        ];
    }

    /**
     * 허용 확장자에 대한 검증 규칙을 반환합니다.
     * 문자열(콤마 구분) 또는 배열 형태 모두 허용합니다.
     *
     * @param  string  $currentTab  현재 탭
     * @return array 검증 규칙 배열
     */
    private function getAllowedExtensionsRules(string $currentTab): array
    {
        $baseRules = $currentTab === 'upload' ? ['required'] : ['nullable'];

        return array_merge($baseRules, [
            function ($attribute, $value, $fail) {
                // 배열인 경우
                if (is_array($value)) {
                    $joined = implode(',', $value);
                    if (strlen($joined) > 500) {
                        $fail(__('validation.settings.allowed_extensions_max'));
                    }

                    return;
                }

                // 문자열인 경우
                if (is_string($value)) {
                    if (strlen($value) > 500) {
                        $fail(__('validation.settings.allowed_extensions_max'));
                    }

                    return;
                }

                // 그 외 타입은 에러
                $fail(__('validation.settings.allowed_extensions_invalid_type'));
            },
        ]);
    }

    /**
     * 캐시 TTL에 대한 검증 규칙을 반환합니다.
     * 0이면 무한대, 1~14400 초 사이 값만 허용
     *
     * @param  string  $currentTab  현재 탭
     * @return array 검증 규칙 배열
     */
    private function getAdvancedTabCacheTtlRules(string $currentTab): array
    {
        $baseRules = $currentTab === 'advanced' ? ['required'] : ['nullable'];

        return array_merge($baseRules, [
            'integer',
            'min:'.config('core.settings_limits.advanced_cache_ttl_min', 0),
            'max:'.config('core.settings_limits.advanced_cache_ttl_max', 14400),
        ]);
    }

    /**
     * 드라이버 가용성 검증 rule 을 반환합니다.
     *
     * 선택된 드라이버가 현재 서버에서 실제로 동작 가능한지(어댑터 클래스·PHP 확장 존재)를
     * 저장 시점에 검사합니다. 사용 불능 드라이버가 저장되면 사이트 전면 다운으로 이어질 수
     * 있으므로(예: phpredis 확장 없는 서버의 redis) 서버 게이트로 차단합니다.
     *
     * @param  string  $category  드라이버 카테고리 (storage, cache, session, queue)
     * @return \Closure 검증 클로저
     */
    private function getDriverUsabilityRule(string $category): \Closure
    {
        return function ($attribute, $value, $fail) use ($category) {
            if (empty($value)) {
                return;
            }

            $registry = app(DriverRegistryService::class);

            if (! $registry->isDriverUsable($category, $value)) {
                $fail(__('validation.settings.driver_unusable', [
                    'driver' => $value,
                    'reason' => $registry->usabilityFailureReason($category, $value),
                ]));
            }
        };
    }

    /**
     * 공개 자산 디스크에 대한 검증 규칙을 반환합니다.
     *
     * 선택지가 코어 3종 + 플러그인 훅 등록 디스크로 동적이라 정적 Rule::in 을 쓸 수 없고,
     * DriverRegistryService 카탈로그 조회로 판정합니다.
     *
     * @return array 검증 규칙 배열
     */
    private function getPublicAssetDiskRules(): array
    {
        return [
            'nullable',
            'string',
            'max:100',
            function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }

                if (! app(DriverRegistryService::class)->isDriverAvailable('public_asset', $value)) {
                    $fail(__('validation.settings.public_asset_disk_invalid'));
                }
            },
        ];
    }

    /**
     * 추가 검증을 위해 validator after 콜백을 등록합니다.
     *
     * 목적별 프로바이더(purpose_providers) 매핑은 purpose id 에 점(.)이 포함될 수
     * 있어 단일 깊이 와일드카드 룰로는 leaf 값을 검증할 수 없습니다.
     * 임의 깊이의 leaf 값을 재귀 순회하며 string|max:100 으로 검증합니다.
     *
     * @param  Validator  $validator  검증기 인스턴스
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $purposeProviders = $this->input('identity.purpose_providers');

            if (is_array($purposeProviders)) {
                $this->validatePurposeProviderLeaves(
                    $purposeProviders,
                    'identity.purpose_providers',
                    $validator
                );
            }
        });
    }

    /**
     * 목적별 프로바이더 매핑을 재귀 순회하며 leaf 값을 검증합니다.
     *
     * purpose id 에 점(.)이 포함될 수 있습니다 (예: KG이니시스 플러그인의
     * `inicis.adult_verification`). 프론트엔드 폼 자동 바인딩이 dot-notation name
     * (`identity.purpose_providers.inicis.adult_verification`) 을 중첩 객체로 풀어
     * `purpose_providers.inicis = { adult_verification: '...' }` 형태로 전송하므로,
     * 깊이에 무관하게 각 leaf 값을 검증하고 위반 시 전체 dot-path 키로 에러를 부착합니다.
     * 백엔드 조회(IdentityVerificationManager::resolveForPurpose) 가 config dot-path
     * 라 이 중첩 구조 자체는 정상입니다.
     *
     * @param  array<string, mixed>  $node  검증할 노드 (purpose_providers 또는 중첩 하위)
     * @param  string  $path  현재 노드의 dot-path (에러 키 prefix)
     * @param  Validator  $validator  검증기 인스턴스
     */
    private function validatePurposeProviderLeaves(array $node, string $path, $validator): void
    {
        foreach ($node as $key => $value) {
            $childPath = $path.'.'.$key;

            if (is_array($value)) {
                $this->validatePurposeProviderLeaves($value, $childPath, $validator);

                continue;
            }

            // null / '' (기본 프로바이더 사용) 은 허용
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($value)) {
                $validator->errors()->add($childPath, __('validation.settings.identity_purpose_provider_string'));

                continue;
            }

            if (mb_strlen($value) > 100) {
                $validator->errors()->add($childPath, __('validation.settings.identity_purpose_provider_max'));
            }
        }
    }

    /**
     * 지원되는 검색엔진 드라이버 목록을 반환합니다.
     *
     * 플러그인이 `core.search.engine_drivers` 필터 훅으로 등록한 드라이버를 포함합니다.
     *
     * @return array<string>
     */
    private function getSupportedSearchEngineDrivers(): array
    {
        $drivers = HookManager::applyFilters(
            'core.search.engine_drivers',
            ['mysql-fulltext' => DatabaseFulltextEngine::class]
        );

        return array_keys($drivers);
    }

    /**
     * Get custom messages for validator errors.
     *
     * nested 구조에 맞게 'category.field.rule' 형식으로 정의합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // 일반 설정 - 필수 필드
            'general.site_name.required' => __('validation.settings.site_name_required'),
            'general.site_name.max' => __('validation.settings.site_name_max'),
            'general.site_url.required' => __('validation.settings.site_url_required'),
            'general.site_url.url' => __('validation.settings.site_url_invalid'),
            'general.site_url.max' => __('validation.settings.site_url_max'),
            'general.site_description.max' => __('validation.settings.site_description_max'),
            'general.admin_email.required' => __('validation.settings.admin_email_required'),
            'general.admin_email.email' => __('validation.settings.admin_email_invalid'),
            'general.admin_email.max' => __('validation.settings.admin_email_max'),
            'general.timezone.required' => __('validation.settings.timezone_required'),
            'general.timezone.in' => __('validation.settings.timezone_invalid'),
            'general.language.required' => __('validation.settings.language_required'),
            'general.language.in' => __('validation.settings.language_invalid'),
            'general.currency.max' => __('validation.settings.currency_max'),
            'general.maintenance_mode.boolean' => __('validation.settings.maintenance_mode_boolean'),

            // 메일 설정
            'mail.mailer.required' => __('validation.settings.mailer_required'),
            'mail.mailer.in' => __('validation.settings.mailer_invalid'),
            'mail.host.required' => __('validation.settings.host_required'),
            'mail.host.max' => __('validation.settings.host_max'),
            'mail.port.required' => __('validation.settings.port_required'),
            'mail.port.integer' => __('validation.settings.port_integer'),
            'mail.port.min' => __('validation.settings.port_min'),
            'mail.port.max' => __('validation.settings.port_max'),
            'mail.username.max' => __('validation.settings.username_max'),
            'mail.password.max' => __('validation.settings.password_max'),
            'mail.encryption.in' => __('validation.settings.encryption_invalid'),
            'mail.from_address.required' => __('validation.settings.from_address_required'),
            'mail.from_address.email' => __('validation.settings.from_address_invalid'),
            'mail.from_address.max' => __('validation.settings.from_address_max'),
            'mail.from_name.required' => __('validation.settings.from_name_required'),
            'mail.from_name.max' => __('validation.settings.from_name_max'),

            // 업로드 설정
            'upload.max_file_size.required' => __('validation.settings.max_file_size_required'),
            'upload.max_file_size.integer' => __('validation.settings.max_file_size_integer'),
            'upload.max_file_size.min' => __('validation.settings.max_file_size_min'),
            'upload.max_file_size.max' => __('validation.settings.max_file_size_max'),
            'upload.allowed_extensions.required' => __('validation.settings.allowed_extensions_required'),
            'upload.allowed_extensions.max' => __('validation.settings.allowed_extensions_max'),
            'upload.image_max_width.integer' => __('validation.settings.image_max_width_integer'),
            'upload.image_max_width.min' => __('validation.settings.image_max_width_min'),
            'upload.image_max_width.max' => __('validation.settings.image_max_width_max'),
            'upload.image_max_height.integer' => __('validation.settings.image_max_height_integer'),
            'upload.image_max_height.min' => __('validation.settings.image_max_height_min'),
            'upload.image_max_height.max' => __('validation.settings.image_max_height_max'),
            'upload.image_quality.integer' => __('validation.settings.image_quality_integer'),
            'upload.image_quality.min' => __('validation.settings.image_quality_min'),
            'upload.image_quality.max' => __('validation.settings.image_quality_max'),
            'upload.orphan_cleanup_enabled.boolean' => __('validation.settings.orphan_cleanup_enabled_boolean'),
            'upload.orphan_retention_days.integer' => __('validation.settings.orphan_retention_days_integer'),
            'upload.orphan_retention_days.min' => __('validation.settings.orphan_retention_days_min'),
            'upload.orphan_retention_days.max' => __('validation.settings.orphan_retention_days_max'),

            // SEO 설정
            'seo.meta_title_suffix.max' => __('validation.settings.meta_title_suffix_max'),
            'seo.meta_description.max' => __('validation.settings.meta_description_max'),
            'seo.meta_keywords.max' => __('validation.settings.meta_keywords_max'),
            'seo.google_analytics_id.max' => __('validation.settings.google_analytics_id_max'),
            'seo.google_site_verification.max' => __('validation.settings.google_site_verification_max'),
            'seo.naver_site_verification.max' => __('validation.settings.naver_site_verification_max'),
            'seo.generator_enabled.boolean' => __('validation.settings.generator_enabled_boolean'),
            'seo.generator_content.string' => __('validation.settings.generator_content_string'),
            'seo.generator_content.max' => __('validation.settings.generator_content_max'),
            'seo.bot_user_agents.array' => __('validation.settings.bot_user_agents_array'),
            'seo.bot_user_agents.*.string' => __('validation.settings.bot_user_agents_item_string'),
            'seo.bot_user_agents.*.max' => __('validation.settings.bot_user_agents_item_max'),
            'seo.bot_detection_enabled.boolean' => __('validation.settings.bot_detection_enabled_boolean'),
            'seo.bot_detection_library_enabled.boolean' => __('validation.settings.bot_detection_library_enabled_boolean'),
            'seo.og_default_site_name.string' => __('validation.settings.og_default_site_name_string'),
            'seo.og_default_site_name.max' => __('validation.settings.og_default_site_name_max'),
            'seo.og_image_default_width.integer' => __('validation.settings.og_image_default_width_integer'),
            'seo.og_image_default_width.min' => __('validation.settings.og_image_default_width_min'),
            'seo.og_image_default_width.max' => __('validation.settings.og_image_default_width_max'),
            'seo.og_image_default_height.integer' => __('validation.settings.og_image_default_height_integer'),
            'seo.og_image_default_height.min' => __('validation.settings.og_image_default_height_min'),
            'seo.og_image_default_height.max' => __('validation.settings.og_image_default_height_max'),
            'seo.twitter_default_card.string' => __('validation.settings.twitter_default_card_string'),
            'seo.twitter_default_card.in' => __('validation.settings.twitter_default_card_in'),
            'seo.twitter_default_site.string' => __('validation.settings.twitter_default_site_string'),
            'seo.twitter_default_site.max' => __('validation.settings.twitter_default_site_max'),
            'seo.cache_enabled.boolean' => __('validation.settings.seo_cache_enabled_boolean'),
            'seo.cache_ttl.integer' => __('validation.settings.seo_cache_ttl_integer'),
            'seo.cache_ttl.min' => __('validation.settings.seo_cache_ttl_min'),
            'seo.cache_ttl.max' => __('validation.settings.seo_cache_ttl_max'),
            'seo.sitemap_enabled.boolean' => __('validation.settings.sitemap_enabled_boolean'),
            'seo.sitemap_cache_ttl.integer' => __('validation.settings.sitemap_cache_ttl_integer'),
            'seo.sitemap_cache_ttl.min' => __('validation.settings.sitemap_cache_ttl_min'),
            'seo.sitemap_cache_ttl.max' => __('validation.settings.sitemap_cache_ttl_max'),
            'seo.sitemap_urls_per_file.integer' => __('validation.settings.sitemap_urls_per_file_integer'),
            'seo.sitemap_urls_per_file.min' => __('validation.settings.sitemap_urls_per_file_min'),
            'seo.sitemap_urls_per_file.max' => __('validation.settings.sitemap_urls_per_file_max'),
            'seo.sitemap_gzip.boolean' => __('validation.settings.sitemap_gzip_boolean'),
            'seo.sitemap_serve_stale_on_miss.boolean' => __('validation.settings.sitemap_serve_stale_on_miss_boolean'),
            'seo.sitemap_max_urls_per_contributor.integer' => __('validation.settings.sitemap_max_urls_per_contributor_integer'),
            'seo.sitemap_max_urls_per_contributor.min' => __('validation.settings.sitemap_max_urls_per_contributor_min'),
            'seo.sitemap_hreflang_enabled.boolean' => __('validation.settings.sitemap_hreflang_enabled_boolean'),
            'seo.sitemap_schedule.in' => __('validation.settings.sitemap_schedule_invalid'),
            'seo.sitemap_schedule_time.regex' => __('validation.settings.sitemap_schedule_time_invalid'),

            // 보안 설정
            'security.force_https.required' => __('validation.settings.force_https_required'),
            'security.force_https.boolean' => __('validation.settings.force_https_boolean'),
            'security.login_attempt_enabled.required' => __('validation.settings.login_attempt_enabled_required'),
            'security.login_attempt_enabled.boolean' => __('validation.settings.login_attempt_enabled_boolean'),
            'security.auth_token_lifetime.integer' => __('validation.settings.auth_token_lifetime_integer'),
            'security.auth_token_lifetime.min' => __('validation.settings.auth_token_lifetime_min'),
            'security.auth_token_lifetime.max' => __('validation.settings.auth_token_lifetime_max'),
            'security.allow_internal_outbound_urls.boolean' => __('validation.settings.allow_internal_outbound_urls_boolean'),
            'security.max_login_attempts.integer' => __('validation.settings.max_login_attempts_integer'),
            'security.max_login_attempts.min' => __('validation.settings.max_login_attempts_min'),
            'security.max_login_attempts.max' => __('validation.settings.max_login_attempts_max'),
            'security.login_lockout_time.integer' => __('validation.settings.login_lockout_time_integer'),
            'security.login_lockout_time.min' => __('validation.settings.login_lockout_time_min'),
            'security.login_lockout_time.max' => __('validation.settings.login_lockout_time_max'),

            // 캐시 설정
            'advanced.cache_enabled.required' => __('validation.settings.cache_enabled_required'),
            'advanced.cache_enabled.boolean' => __('validation.settings.cache_enabled_boolean'),
            'advanced.layout_cache_enabled.required' => __('validation.settings.layout_cache_enabled_required'),
            'advanced.layout_cache_enabled.boolean' => __('validation.settings.layout_cache_enabled_boolean'),
            'advanced.layout_cache_ttl.required' => __('validation.settings.layout_cache_ttl_required'),
            'advanced.layout_cache_ttl.integer' => __('validation.settings.layout_cache_ttl_integer'),
            'advanced.layout_cache_ttl.min' => __('validation.settings.layout_cache_ttl_min'),
            'advanced.layout_cache_ttl.max' => __('validation.settings.layout_cache_ttl_max'),
            'advanced.stats_cache_enabled.required' => __('validation.settings.stats_cache_enabled_required'),
            'advanced.stats_cache_enabled.boolean' => __('validation.settings.stats_cache_enabled_boolean'),
            'advanced.stats_cache_ttl.required' => __('validation.settings.stats_cache_ttl_required'),
            'advanced.stats_cache_ttl.integer' => __('validation.settings.stats_cache_ttl_integer'),
            'advanced.stats_cache_ttl.min' => __('validation.settings.stats_cache_ttl_min'),
            'advanced.stats_cache_ttl.max' => __('validation.settings.stats_cache_ttl_max'),
            'advanced.seo_cache_enabled.required' => __('validation.settings.advanced_seo_cache_enabled_required'),
            'advanced.seo_cache_enabled.boolean' => __('validation.settings.advanced_seo_cache_enabled_boolean'),
            'advanced.seo_cache_ttl.required' => __('validation.settings.advanced_seo_cache_ttl_required'),
            'advanced.seo_cache_ttl.integer' => __('validation.settings.advanced_seo_cache_ttl_integer'),
            'advanced.seo_cache_ttl.min' => __('validation.settings.advanced_seo_cache_ttl_min'),
            'advanced.seo_cache_ttl.max' => __('validation.settings.advanced_seo_cache_ttl_max'),
            'advanced.seo_sitemap_cache_ttl.integer' => __('validation.settings.seo_sitemap_cache_ttl_integer'),
            'advanced.seo_sitemap_cache_ttl.min' => __('validation.settings.seo_sitemap_cache_ttl_min'),
            'advanced.seo_sitemap_cache_ttl.max' => __('validation.settings.seo_sitemap_cache_ttl_max'),

            // 디버그 설정
            'advanced.debug_mode.required' => __('validation.settings.debug_mode_required'),
            'advanced.debug_mode.boolean' => __('validation.settings.debug_mode_boolean'),
            'advanced.sql_query_log.required' => __('validation.settings.sql_query_log_required'),
            'advanced.sql_query_log.boolean' => __('validation.settings.sql_query_log_boolean'),

            // 아웃바운드 HTTP 프록시
            'advanced.outbound_proxy.string' => __('validation.settings.outbound_proxy_string'),
            'advanced.outbound_proxy.max' => __('validation.settings.outbound_proxy_max'),
            'advanced.outbound_proxy_bypass.array' => __('validation.settings.outbound_proxy_bypass_array'),
            'advanced.outbound_proxy_bypass.*.string' => __('validation.settings.outbound_proxy_bypass_item_string'),
            'advanced.outbound_proxy_bypass.*.max' => __('validation.settings.outbound_proxy_bypass_item_max'),

            // 목록 한계값
            'advanced.pagination_result_cap.integer' => __('validation.settings.pagination_result_cap_integer'),
            'advanced.pagination_result_cap.min' => __('validation.settings.pagination_result_cap_min'),
            'advanced.pagination_result_cap.max' => __('validation.settings.pagination_result_cap_max'),
            'advanced.pagination_max_page.integer' => __('validation.settings.pagination_max_page_integer'),
            'advanced.pagination_max_page.min' => __('validation.settings.pagination_max_page_min'),
            'advanced.pagination_max_page.max' => __('validation.settings.pagination_max_page_max'),

            // 코어 업데이트 설정
            'advanced.core_update_github_url.url' => __('validation.settings.core_update_github_url_invalid'),
            'advanced.core_update_github_url.max' => __('validation.settings.core_update_github_url_max'),
            'advanced.core_update_github_token.max' => __('validation.settings.core_update_github_token_max'),

            // 드라이버 설정
            'drivers.storage_driver.required' => __('validation.settings.storage_driver_required'),
            'drivers.storage_driver.in' => __('validation.settings.storage_driver_invalid'),
            'drivers.s3_bucket.max' => __('validation.settings.s3_bucket_max'),
            'drivers.s3_region.regex' => __('validation.settings.s3_region_invalid'),
            'drivers.s3_region.max' => __('validation.settings.s3_region_max'),
            'drivers.s3_access_key.max' => __('validation.settings.s3_access_key_max'),
            'drivers.s3_secret_key.max' => __('validation.settings.s3_secret_key_max'),
            'drivers.s3_url.url' => __('validation.settings.s3_url_invalid'),
            'drivers.s3_url.max' => __('validation.settings.s3_url_max'),
            'drivers.s3_endpoint.url' => __('validation.settings.s3_endpoint_invalid'),
            'drivers.s3_endpoint.max' => __('validation.settings.s3_endpoint_max'),
            'drivers.s3_use_path_style.boolean' => __('validation.settings.s3_use_path_style_boolean'),
            'drivers.cache_driver.required' => __('validation.settings.cache_driver_required'),
            'drivers.cache_driver.in' => __('validation.settings.cache_driver_invalid'),
            'drivers.redis_host.max' => __('validation.settings.redis_host_max'),
            'drivers.redis_port.integer' => __('validation.settings.redis_port_integer'),
            'drivers.redis_port.min' => __('validation.settings.redis_port_min'),
            'drivers.redis_port.max' => __('validation.settings.redis_port_max'),
            'drivers.redis_password.max' => __('validation.settings.redis_password_max'),
            'drivers.redis_database.integer' => __('validation.settings.redis_database_integer'),
            'drivers.redis_database.min' => __('validation.settings.redis_database_min'),
            'drivers.redis_database.max' => __('validation.settings.redis_database_max'),
            'drivers.memcached_host.max' => __('validation.settings.memcached_host_max'),
            'drivers.memcached_port.integer' => __('validation.settings.memcached_port_integer'),
            'drivers.memcached_port.min' => __('validation.settings.memcached_port_min'),
            'drivers.memcached_port.max' => __('validation.settings.memcached_port_max'),
            'drivers.session_driver.required' => __('validation.settings.session_driver_required'),
            'drivers.session_driver.in' => __('validation.settings.session_driver_invalid'),
            'drivers.session_lifetime.integer' => __('validation.settings.session_lifetime_integer'),
            'drivers.session_lifetime.min' => __('validation.settings.session_lifetime_min'),
            'drivers.session_lifetime.max' => __('validation.settings.session_lifetime_max'),
            'drivers.queue_driver.required' => __('validation.settings.queue_driver_required'),
            'drivers.queue_driver.in' => __('validation.settings.queue_driver_invalid'),
            'drivers.websocket_enabled.boolean' => __('validation.settings.websocket_enabled_boolean'),
            'drivers.websocket_app_id.required' => __('validation.settings.websocket_app_id_required'),
            'drivers.websocket_app_id.max' => __('validation.settings.websocket_app_id_max'),
            'drivers.websocket_app_key.required' => __('validation.settings.websocket_app_key_required'),
            'drivers.websocket_app_key.max' => __('validation.settings.websocket_app_key_max'),
            'drivers.websocket_app_secret.required' => __('validation.settings.websocket_app_secret_required'),
            'drivers.websocket_app_secret.max' => __('validation.settings.websocket_app_secret_max'),
            'drivers.websocket_host.max' => __('validation.settings.websocket_host_max'),
            'drivers.websocket_port.integer' => __('validation.settings.websocket_port_integer'),
            'drivers.websocket_port.min' => __('validation.settings.websocket_port_min'),
            'drivers.websocket_port.max' => __('validation.settings.websocket_port_max'),
            'drivers.websocket_scheme.in' => __('validation.settings.websocket_scheme_invalid'),
            'drivers.websocket_verify_ssl.boolean' => __('validation.settings.websocket_verify_ssl_boolean'),
            'drivers.websocket_server_host.max' => __('validation.settings.websocket_server_host_max'),
            'drivers.websocket_server_port.integer' => __('validation.settings.websocket_server_port_integer'),
            'drivers.websocket_server_port.min' => __('validation.settings.websocket_server_port_min'),
            'drivers.websocket_server_port.max' => __('validation.settings.websocket_server_port_max'),
            'drivers.websocket_server_scheme.in' => __('validation.settings.websocket_server_scheme_invalid'),
            'drivers.search_engine_driver.in' => __('validation.settings.search_engine_driver_invalid'),
            'drivers.log_driver.required' => __('validation.settings.log_driver_required'),
            'drivers.log_driver.in' => __('validation.settings.log_driver_invalid'),
            'drivers.log_level.required' => __('validation.settings.log_level_required'),
            'drivers.log_level.in' => __('validation.settings.log_level_invalid'),
            'drivers.log_days.integer' => __('validation.settings.log_days_integer'),
            'drivers.log_days.min' => __('validation.settings.log_days_min'),
            'drivers.log_days.max' => __('validation.settings.log_days_max'),
            'drivers.public_asset_disk.string' => __('validation.settings.public_asset_disk_invalid'),
            'drivers.public_asset_disk.max' => __('validation.settings.public_asset_disk_invalid'),

            // 본인인증(IDV) 설정
            'identity.default_provider.string' => __('validation.settings.identity_default_provider_string'),
            'identity.default_provider.max' => __('validation.settings.identity_default_provider_max'),
            'identity.purpose_providers.array' => __('validation.settings.identity_purpose_providers_array'),
            // 목적별 프로바이더 leaf 값(점 포함 purpose id 대응)은 withValidator() 에서
            // validation.settings.identity_purpose_provider_string / _max 를 직접 부착합니다.
            'identity.challenge_ttl_minutes.required' => __('validation.settings.identity_challenge_ttl_required'),
            'identity.challenge_ttl_minutes.integer' => __('validation.settings.identity_challenge_ttl_integer'),
            'identity.challenge_ttl_minutes.min' => __('validation.settings.identity_challenge_ttl_min'),
            'identity.challenge_ttl_minutes.max' => __('validation.settings.identity_challenge_ttl_max'),
            'identity.max_attempts.required' => __('validation.settings.identity_max_attempts_required'),
            'identity.max_attempts.integer' => __('validation.settings.identity_max_attempts_integer'),
            'identity.max_attempts.min' => __('validation.settings.identity_max_attempts_min'),
            'identity.max_attempts.max' => __('validation.settings.identity_max_attempts_max'),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * nested 구조에 맞게 'category.field' 형식으로 정의합니다.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'general.site_name' => __('validation.attributes.site_name'),
            'general.site_url' => __('validation.attributes.site_url'),
            'general.site_description' => __('validation.attributes.site_description'),
            'general.admin_email' => __('validation.attributes.admin_email'),
            'general.timezone' => __('validation.attributes.timezone'),
            'general.language' => __('validation.attributes.default_language'),
            // 본인인증(IDV) 필드
            'identity.default_provider' => __('validation.attributes.identity_default_provider'),
            'identity.purpose_providers' => __('validation.attributes.identity_purpose_providers'),
            'identity.challenge_ttl_minutes' => __('validation.attributes.identity_challenge_ttl_minutes'),
            'identity.max_attempts' => __('validation.attributes.identity_max_attempts'),
            // notifications
            'notifications.channels' => __('validation.attributes.notification_channels'),
            // general
            'general.currency' => __('validation.attributes.default_currency'),
            'general.maintenance_mode' => __('validation.attributes.maintenance_mode'),
            'general.asset_url_mode' => __('validation.attributes.asset_url_mode'),
            'general.site_logo' => __('validation.attributes.site_logo'),
            // mail
            'mail.mailer' => __('validation.attributes.mailer'),
            'mail.host' => __('validation.attributes.smtp_host'),
            'mail.port' => __('validation.attributes.smtp_port'),
            'mail.username' => __('validation.attributes.smtp_username'),
            'mail.password' => __('validation.attributes.smtp_password'),
            'mail.encryption' => __('validation.attributes.encryption'),
            'mail.mailgun_domain' => __('validation.attributes.mailgun_domain'),
            'mail.mailgun_secret' => __('validation.attributes.mailgun_secret'),
            'mail.mailgun_endpoint' => __('validation.attributes.mailgun_endpoint'),
            'mail.ses_key' => __('validation.attributes.ses_key'),
            'mail.ses_secret' => __('validation.attributes.ses_secret'),
            'mail.ses_region' => __('validation.attributes.ses_region'),
            'mail.from_address' => __('validation.attributes.from_address'),
            'mail.from_name' => __('validation.attributes.from_name'),
            // upload
            'upload.max_file_size' => __('validation.attributes.max_file_size'),
            'upload.allowed_extensions' => __('validation.attributes.allowed_extensions'),
            'upload.image_max_width' => __('validation.attributes.image_max_width'),
            'upload.image_max_height' => __('validation.attributes.image_max_height'),
            'upload.image_quality' => __('validation.attributes.image_quality'),
            'upload.orphan_cleanup_enabled' => __('validation.attributes.orphan_cleanup_enabled'),
            'upload.orphan_retention_days' => __('validation.attributes.orphan_retention_days'),
            // seo
            'seo.meta_title_suffix' => __('validation.attributes.meta_title_suffix'),
            'seo.meta_description' => __('validation.attributes.meta_description'),
            'seo.meta_keywords' => __('validation.attributes.meta_keywords'),
            'seo.google_analytics_id' => __('validation.attributes.google_analytics_id'),
            'seo.google_site_verification' => __('validation.attributes.google_site_verification'),
            'seo.naver_site_verification' => __('validation.attributes.naver_site_verification'),
            'seo.bot_user_agents' => __('validation.attributes.bot_user_agents'),
            'seo.bot_detection_enabled' => __('validation.attributes.bot_detection_enabled'),
            'seo.bot_detection_library_enabled' => __('validation.attributes.bot_detection_library_enabled'),
            'seo.og_default_site_name' => __('validation.attributes.og_default_site_name'),
            'seo.og_image_default_width' => __('validation.attributes.og_image_default_width'),
            'seo.og_image_default_height' => __('validation.attributes.og_image_default_height'),
            'seo.twitter_default_card' => __('validation.attributes.twitter_default_card'),
            'seo.twitter_default_site' => __('validation.attributes.twitter_default_site'),
            'seo.cache_enabled' => __('validation.attributes.seo_page_cache_enabled'),
            'seo.cache_ttl' => __('validation.attributes.seo_page_cache_ttl'),
            'seo.sitemap_enabled' => __('validation.attributes.sitemap_enabled'),
            'seo.sitemap_cache_ttl' => __('validation.attributes.sitemap_cache_ttl'),
            'seo.sitemap_urls_per_file' => __('validation.attributes.sitemap_urls_per_file'),
            'seo.sitemap_gzip' => __('validation.attributes.sitemap_gzip'),
            'seo.sitemap_serve_stale_on_miss' => __('validation.attributes.sitemap_serve_stale_on_miss'),
            'seo.sitemap_max_urls_per_contributor' => __('validation.attributes.sitemap_max_urls_per_contributor'),
            'seo.sitemap_hreflang_enabled' => __('validation.attributes.sitemap_hreflang_enabled'),
            'seo.sitemap_schedule' => __('validation.attributes.sitemap_schedule'),
            'seo.sitemap_schedule_time' => __('validation.attributes.sitemap_schedule_time'),
            'seo.generator_enabled' => __('validation.attributes.generator_enabled'),
            'seo.generator_content' => __('validation.attributes.generator_content'),
            // security
            'security.force_https' => __('validation.attributes.force_https'),
            'security.login_attempt_enabled' => __('validation.attributes.login_attempt_enabled'),
            'security.auth_token_lifetime' => __('validation.attributes.auth_token_lifetime'),
            'security.max_login_attempts' => __('validation.attributes.max_login_attempts'),
            'security.login_lockout_time' => __('validation.attributes.login_lockout_time'),
            'security.password_min_length' => __('validation.attributes.password_min_length'),
            'security.require_password_special_char' => __('validation.attributes.require_password_special_char'),
            'security.two_factor_auth' => __('validation.attributes.two_factor_auth'),
            'security.allow_internal_outbound_urls' => __('validation.attributes.allow_internal_outbound_urls'),
            // advanced
            'advanced.cache_enabled' => __('validation.attributes.advanced_cache_enabled'),
            'advanced.layout_cache_enabled' => __('validation.attributes.layout_cache_enabled'),
            'advanced.layout_cache_ttl' => __('validation.attributes.layout_cache_ttl'),
            'advanced.stats_cache_enabled' => __('validation.attributes.stats_cache_enabled'),
            'advanced.stats_cache_ttl' => __('validation.attributes.stats_cache_ttl'),
            'advanced.seo_cache_enabled' => __('validation.attributes.seo_cache_enabled'),
            'advanced.seo_cache_ttl' => __('validation.attributes.seo_cache_ttl'),
            'advanced.seo_sitemap_cache_ttl' => __('validation.attributes.seo_sitemap_cache_ttl'),
            'advanced.debug_mode' => __('validation.attributes.debug_mode'),
            'advanced.sql_query_log' => __('validation.attributes.sql_query_log'),
            'advanced.outbound_proxy' => __('validation.attributes.outbound_proxy'),
            'advanced.outbound_proxy_bypass' => __('validation.attributes.outbound_proxy_bypass'),
            'advanced.core_update_github_url' => __('validation.attributes.core_update_github_url'),
            'advanced.core_update_github_token' => __('validation.attributes.core_update_github_token'),
            'advanced.geoip_enabled' => __('validation.attributes.geoip_enabled'),
            'advanced.geoip_license_key' => __('validation.attributes.geoip_license_key'),
            'advanced.geoip_auto_update_enabled' => __('validation.attributes.geoip_auto_update_enabled'),
            'advanced.pagination_result_cap' => __('validation.attributes.pagination_result_cap'),
            'advanced.pagination_max_page' => __('validation.attributes.pagination_max_page'),
            // drivers
            'drivers.storage_driver' => __('validation.attributes.storage_driver'),
            'drivers.s3_bucket' => __('validation.attributes.s3_bucket'),
            'drivers.s3_region' => __('validation.attributes.s3_region'),
            'drivers.s3_access_key' => __('validation.attributes.s3_access_key'),
            'drivers.s3_secret_key' => __('validation.attributes.s3_secret_key'),
            'drivers.s3_url' => __('validation.attributes.s3_url'),
            'drivers.s3_endpoint' => __('validation.attributes.s3_endpoint'),
            'drivers.s3_use_path_style' => __('validation.attributes.s3_use_path_style'),
            'drivers.cache_driver' => __('validation.attributes.cache_driver'),
            'drivers.redis_host' => __('validation.attributes.redis_host'),
            'drivers.redis_port' => __('validation.attributes.redis_port'),
            'drivers.redis_password' => __('validation.attributes.redis_password'),
            'drivers.redis_database' => __('validation.attributes.redis_database'),
            'drivers.memcached_host' => __('validation.attributes.memcached_host'),
            'drivers.memcached_port' => __('validation.attributes.memcached_port'),
            'drivers.session_driver' => __('validation.attributes.session_driver'),
            'drivers.session_lifetime' => __('validation.attributes.session_lifetime'),
            'drivers.queue_driver' => __('validation.attributes.queue_driver'),
            'drivers.websocket_enabled' => __('validation.attributes.websocket_enabled'),
            'drivers.websocket_app_id' => __('validation.attributes.websocket_app_id'),
            'drivers.websocket_app_key' => __('validation.attributes.websocket_app_key'),
            'drivers.websocket_app_secret' => __('validation.attributes.websocket_app_secret'),
            'drivers.websocket_host' => __('validation.attributes.websocket_host'),
            'drivers.websocket_port' => __('validation.attributes.websocket_port'),
            'drivers.websocket_scheme' => __('validation.attributes.websocket_scheme'),
            'drivers.websocket_verify_ssl' => __('validation.attributes.websocket_verify_ssl'),
            'drivers.websocket_server_host' => __('validation.attributes.websocket_server_host'),
            'drivers.websocket_server_port' => __('validation.attributes.websocket_server_port'),
            'drivers.websocket_server_scheme' => __('validation.attributes.websocket_server_scheme'),
            'drivers.search_engine_driver' => __('validation.attributes.search_engine_driver'),
            'drivers.log_driver' => __('validation.attributes.log_driver'),
            'drivers.log_level' => __('validation.attributes.log_level'),
            'drivers.log_days' => __('validation.attributes.log_days'),
            'drivers.public_asset_disk' => __('validation.attributes.public_asset_disk'),
        ];
    }
}
