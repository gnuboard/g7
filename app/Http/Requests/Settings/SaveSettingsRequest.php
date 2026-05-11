<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use App\Models\Attachment;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * 지원되는 S3 리전 목록
     */
    private const SUPPORTED_S3_REGIONS = ['ap-northeast-2', 'ap-northeast-1', 'us-east-1', 'us-west-2', 'eu-west-1'];

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
     * 지원되는 검색엔진 드라이버 기본 목록
     */
    private const DEFAULT_SEARCH_ENGINE_DRIVERS = ['mysql-fulltext'];

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
     * @return bool
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
            'upload.max_file_size' => $this->getTabRules($tab, 'upload', 'integer|min:1|max:1024'),
            'upload.allowed_extensions' => $this->getAllowedExtensionsRules($tab),
            'upload.image_max_width' => ['nullable', 'integer', 'min:100', 'max:10000'],
            'upload.image_max_height' => ['nullable', 'integer', 'min:100', 'max:10000'],
            'upload.image_quality' => ['nullable', 'integer', 'min:1', 'max:100'],

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
            'seo.og_image_default_width' => ['nullable', 'integer', 'min:0', 'max:8000'],
            'seo.og_image_default_height' => ['nullable', 'integer', 'min:0', 'max:8000'],
            'seo.twitter_default_card' => ['nullable', 'string', Rule::in(['summary', 'summary_large_image', 'app', 'player', ''])],
            'seo.twitter_default_site' => ['nullable', 'string', 'max:50'],
            'seo.cache_enabled' => ['nullable', 'boolean'],
            'seo.cache_ttl' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'seo.sitemap_enabled' => ['nullable', 'boolean'],
            'seo.sitemap_cache_ttl' => ['nullable', 'integer', 'min:3600', 'max:604800'],
            'seo.sitemap_schedule' => ['nullable', 'string', Rule::in(['hourly', 'daily', 'weekly'])],
            'seo.sitemap_schedule_time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'seo.generator_enabled' => ['nullable', 'boolean'],
            'seo.generator_content' => ['nullable', 'string', 'max:200'],

            // 보안 설정
            'security.force_https' => $this->getTabRules($tab, 'security', 'boolean'),
            'security.login_attempt_enabled' => $this->getTabRules($tab, 'security', 'boolean'),
            'security.auth_token_lifetime' => $this->getSecurityTabAuthTokenRules($tab),
            'security.max_login_attempts' => ['nullable', 'integer', 'min:0', 'max:100'],
            'security.login_lockout_time' => ['nullable', 'integer', 'min:0', 'max:1440'],

            // 캐시 설정 (advanced 탭)
            'advanced.cache_enabled' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.layout_cache_enabled' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.layout_cache_ttl' => $this->getAdvancedTabCacheTtlRules($tab),
            'advanced.stats_cache_enabled' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.stats_cache_ttl' => $this->getAdvancedTabCacheTtlRules($tab),
            'advanced.seo_cache_enabled' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.seo_cache_ttl' => $this->getAdvancedTabCacheTtlRules($tab),

            // 디버그 설정 (advanced 탭)
            'advanced.debug_mode' => $this->getTabRules($tab, 'advanced', 'boolean'),
            'advanced.sql_query_log' => $this->getTabRules($tab, 'advanced', 'boolean'),

            // 코어 업데이트 설정 (advanced 탭)
            'advanced.core_update_github_url' => ['nullable', 'url', 'max:500'],
            'advanced.core_update_github_token' => ['nullable', 'string', 'max:500'],

            // GeoIP 설정 (advanced 탭)
            // 마스터 토글 OFF 시 하위 필드(license_key, auto_update)는 collapse 되어 미전송됨
            // → nullable 필수 (기존 cache/debug 필드와 달리 조건부 렌더링 내부에 있음)
            'advanced.geoip_enabled' => ['nullable', 'boolean'],
            'advanced.geoip_license_key' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_]+$/'],
            'advanced.geoip_auto_update_enabled' => ['nullable', 'boolean'],

            // 드라이버 설정 (drivers 탭)
            'drivers.storage_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_STORAGE_DRIVERS)]),
            'drivers.s3_bucket' => ['nullable', 'string', 'max:255'],
            'drivers.s3_region' => ['nullable', Rule::in(self::SUPPORTED_S3_REGIONS)],
            'drivers.s3_access_key' => ['nullable', 'string', 'max:255'],
            'drivers.s3_secret_key' => ['nullable', 'string', 'max:255'],
            'drivers.s3_url' => ['nullable', 'url', 'max:500'],
            'drivers.cache_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_CACHE_DRIVERS)]),
            'drivers.redis_host' => ['nullable', 'string', 'max:255'],
            'drivers.redis_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'drivers.redis_password' => ['nullable', 'string', 'max:255'],
            'drivers.redis_database' => ['nullable', 'integer', 'min:0', 'max:15'],
            'drivers.memcached_host' => ['nullable', 'string', 'max:255'],
            'drivers.memcached_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'drivers.session_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_SESSION_DRIVERS)]),
            'drivers.session_lifetime' => ['nullable', 'integer', 'min:1', 'max:43200'], // 1분 ~ 30일
            'drivers.queue_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_QUEUE_DRIVERS)]),
            'drivers.websocket_enabled' => ['nullable', 'boolean'],
            'drivers.websocket_app_id' => [Rule::requiredIf(fn () => $this->boolean('drivers.websocket_enabled')), 'nullable', 'string', 'max:255'],
            'drivers.websocket_app_key' => [Rule::requiredIf(fn () => $this->boolean('drivers.websocket_enabled')), 'nullable', 'string', 'max:255'],
            'drivers.websocket_app_secret' => [Rule::requiredIf(fn () => $this->boolean('drivers.websocket_enabled')), 'nullable', 'string', 'max:255'],
            'drivers.websocket_host' => ['nullable', 'string', 'max:255'],
            'drivers.websocket_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'drivers.websocket_scheme' => ['nullable', Rule::in(self::SUPPORTED_WEBSOCKET_SCHEMES)],
            'drivers.websocket_verify_ssl' => ['nullable', 'boolean'],
            'drivers.websocket_server_host' => ['nullable', 'string', 'max:255'],
            'drivers.websocket_server_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'drivers.websocket_server_scheme' => ['nullable', Rule::in(self::SUPPORTED_WEBSOCKET_SCHEMES)],
            'drivers.search_engine_driver' => ['nullable', Rule::in($this->getSupportedSearchEngineDrivers())],
            'drivers.log_driver' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_LOG_DRIVERS)]),
            'drivers.log_level' => $this->getTabRules($tab, 'drivers', [Rule::in(self::SUPPORTED_LOG_LEVELS)]),
            'drivers.log_days' => ['nullable', 'integer', 'min:1', 'max:365'],

            // 본인인증(IDV) provider 기술 파라미터 — 정책 분기는 IdentityPolicy 로 흡수됨
            'identity.default_provider' => ['nullable', 'string', 'max:100'],
            'identity.purpose_providers' => ['sometimes', 'array'],
            'identity.purpose_providers.*' => ['nullable', 'string', 'max:100'],
            'identity.challenge_ttl_minutes' => $this->getTabRules($tab, 'identity', 'integer|min:1|max:1440'),
            'identity.max_attempts' => $this->getTabRules($tab, 'identity', 'integer|min:1|max:20'),
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
            'min:0',
            'max:3600',
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
            'min:0',
            'max:14400',
        ]);
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
            'advanced.seo_cache_enabled.required' => __('validation.settings.seo_cache_enabled_required'),
            'advanced.seo_cache_enabled.boolean' => __('validation.settings.seo_cache_enabled_boolean'),
            'advanced.seo_cache_ttl.required' => __('validation.settings.seo_cache_ttl_required'),
            'advanced.seo_cache_ttl.integer' => __('validation.settings.seo_cache_ttl_integer'),
            'advanced.seo_cache_ttl.min' => __('validation.settings.seo_cache_ttl_min'),
            'advanced.seo_cache_ttl.max' => __('validation.settings.seo_cache_ttl_max'),

            // 디버그 설정
            'advanced.debug_mode.required' => __('validation.settings.debug_mode_required'),
            'advanced.debug_mode.boolean' => __('validation.settings.debug_mode_boolean'),
            'advanced.sql_query_log.required' => __('validation.settings.sql_query_log_required'),
            'advanced.sql_query_log.boolean' => __('validation.settings.sql_query_log_boolean'),

            // 코어 업데이트 설정
            'advanced.core_update_github_url.url' => __('validation.settings.core_update_github_url_invalid'),
            'advanced.core_update_github_url.max' => __('validation.settings.core_update_github_url_max'),
            'advanced.core_update_github_token.max' => __('validation.settings.core_update_github_token_max'),

            // 드라이버 설정
            'drivers.storage_driver.required' => __('validation.settings.storage_driver_required'),
            'drivers.storage_driver.in' => __('validation.settings.storage_driver_invalid'),
            'drivers.s3_bucket.max' => __('validation.settings.s3_bucket_max'),
            'drivers.s3_region.in' => __('validation.settings.s3_region_invalid'),
            'drivers.s3_access_key.max' => __('validation.settings.s3_access_key_max'),
            'drivers.s3_secret_key.max' => __('validation.settings.s3_secret_key_max'),
            'drivers.s3_url.url' => __('validation.settings.s3_url_invalid'),
            'drivers.s3_url.max' => __('validation.settings.s3_url_max'),
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

            // 본인인증(IDV) 설정
            'identity.default_provider.string' => __('validation.settings.identity_default_provider_string'),
            'identity.default_provider.max' => __('validation.settings.identity_default_provider_max'),
            'identity.purpose_providers.array' => __('validation.settings.identity_purpose_providers_array'),
            'identity.purpose_providers.*.string' => __('validation.settings.identity_purpose_provider_string'),
            'identity.purpose_providers.*.max' => __('validation.settings.identity_purpose_provider_max'),
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
            'general.language' => __('validation.attributes.language'),
            // 본인인증(IDV) 필드
            'identity.default_provider' => __('validation.attributes.identity_default_provider'),
            'identity.purpose_providers' => __('validation.attributes.identity_purpose_providers'),
            'identity.challenge_ttl_minutes' => __('validation.attributes.identity_challenge_ttl_minutes'),
            'identity.max_attempts' => __('validation.attributes.identity_max_attempts'),
        ];
    }
}
