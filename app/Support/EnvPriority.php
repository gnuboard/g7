<?php

namespace App\Support;

/**
 * `.env` 키 단위 우선 규약 (G7_ENV_PRIORITY 옵트인).
 *
 * G7 은 관리자 환경설정(`storage/app/settings/*.json`)을 운영 SSoT 로 삼고,
 * `SettingsServiceProvider` 가 그 값을 `config()` 에 주입해 `.env` 유래 값을 덮는다.
 * 공유호스팅 1차 대상 설계에서는 그것이 옳지만, `.env` 를 배포 기준값으로 관리하는
 * 설치(컨테이너·IaC·다중 서버)에서는 `.env` 에 적은 값이 조용히 사문화된다.
 *
 * 이 클래스는 그 소유권을 **키 단위**로 되돌리는 판정을 단독으로 소유한다:
 *
 * - 스위치(`G7_ENV_PRIORITY`)가 꺼져 있으면 전 경로가 조기 return 이라 현행 동작과 100% 동일하다.
 * - 켜진 설치에서는 `.env` 에 값이 **명시된** 키만 잠긴다 — settings 주입을 건너뛰고(`.env` 권위),
 *   관리자 화면은 그 필드를 편집 불가로 표시하며, 저장 API 는 그 키를 서버측에서 필터한다.
 *
 * 명시 여부 판별은 반드시 `config('env-priority.explicit')`(빌드 시점 캡처)를 읽는다.
 * `env()` 직접 호출은 `config:cache` 환경에서 null 로 고정되므로 판별이 영구 미발동한다
 * (`config/attachment.php` 의 `disk_explicit` 와 동형 함정).
 *
 * `SettingsServiceProvider::register()` 단계(DI 컨테이너 사용 전)에서 호출되므로 정적 클래스다.
 *
 * @since 7.0.10
 */
final class EnvPriority
{
    /**
     * settings 저장소 키 ↔ env 변수 ↔ config 키 매핑 (단일 SSoT).
     *
     * 소비자 3곳: ① Provider 주입 스킵 ② 설정 API `_meta.env_locked` + 표시값 ③ 계약 테스트.
     *
     * 각 항목의 형태:
     * - `env`: 그 설정을 결정하는 env 변수 목록. **하나라도** 명시되면 잠긴다(any-of) —
     *   env 두 개가 한 설정을 나누는 `drivers.redis_database` 는 하나만 명시돼도 주입이
     *   그것을 덮으므로 any 가 안전측이다.
     * - `config`: 그 설정이 파생시키는 config 키 전부(문서·표시값 근거). 주입 스킵은
     *   배열 필터 방식이라 이 목록을 순회하지 않는다 — 첫 키가 표시값 조회 대상이다.
     * - `display`: 표시값 변환 지시자 (아래 `effectiveValue()` 참조). 없으면 config 값 그대로.
     * - `sensitive`: true 면 잠금 표시만 하고 유효값을 화면·응답에 싣지 않는다
     *   (`.env` 비밀값이 admin UI 로 유출되는 것을 차단).
     *
     * @var array<string, array{env: array<int, string>, config: array<int, string>, display?: string, sensitive?: bool}>
     */
    public const MAP = [
        // --- general ---
        'general.site_name' => ['env' => ['APP_NAME'], 'config' => ['app.name']],
        'general.site_url' => ['env' => ['APP_URL'], 'config' => ['app.url']],
        'general.timezone' => ['env' => ['APP_DEFAULT_USER_TIMEZONE'], 'config' => ['app.default_user_timezone', 'app.schedule_timezone']],
        'general.language' => ['env' => ['APP_LOCALE'], 'config' => ['app.locale']],

        // --- mail ---
        'mail.mailer' => ['env' => ['MAIL_MAILER'], 'config' => ['mail.default']],
        'mail.host' => ['env' => ['MAIL_HOST'], 'config' => ['mail.mailers.smtp.host']],
        'mail.port' => ['env' => ['MAIL_PORT'], 'config' => ['mail.mailers.smtp.port']],
        'mail.username' => ['env' => ['MAIL_USERNAME'], 'config' => ['mail.mailers.smtp.username']],
        'mail.password' => ['env' => ['MAIL_PASSWORD'], 'config' => ['mail.mailers.smtp.password'], 'sensitive' => true],
        'mail.mailgun_domain' => ['env' => ['MAILGUN_DOMAIN'], 'config' => ['services.mailgun.domain']],
        'mail.mailgun_secret' => ['env' => ['MAILGUN_SECRET'], 'config' => ['services.mailgun.secret'], 'sensitive' => true],
        'mail.mailgun_endpoint' => ['env' => ['MAILGUN_ENDPOINT'], 'config' => ['services.mailgun.endpoint']],
        'mail.ses_key' => ['env' => ['AWS_ACCESS_KEY_ID'], 'config' => ['services.ses.key'], 'sensitive' => true],
        'mail.ses_secret' => ['env' => ['AWS_SECRET_ACCESS_KEY'], 'config' => ['services.ses.secret'], 'sensitive' => true],
        'mail.ses_region' => ['env' => ['AWS_DEFAULT_REGION'], 'config' => ['services.ses.region']],
        'mail.from_address' => ['env' => ['MAIL_FROM_ADDRESS'], 'config' => ['mail.from.address']],
        'mail.from_name' => ['env' => ['MAIL_FROM_NAME'], 'config' => ['mail.from.name']],

        // --- debug ---
        'debug.mode' => ['env' => ['APP_DEBUG'], 'config' => ['app.debug']],
        'debug.log_level' => ['env' => ['LOG_LEVEL'], 'config' => ['logging.channels.single.level', 'logging.channels.daily.level', 'logging.level']],

        // --- drivers ---
        'drivers.cache_driver' => ['env' => ['CACHE_STORE'], 'config' => ['cache.default']],
        'drivers.session_driver' => ['env' => ['SESSION_DRIVER'], 'config' => ['session.driver']],
        'drivers.session_lifetime' => ['env' => ['SESSION_LIFETIME'], 'config' => ['session.lifetime']],
        'drivers.queue_driver' => ['env' => ['QUEUE_CONNECTION'], 'config' => ['queue.default']],
        'drivers.storage_driver' => ['env' => ['FILESYSTEM_DISK'], 'config' => ['filesystems.default']],
        'drivers.redis_host' => ['env' => ['REDIS_HOST'], 'config' => ['database.redis.default.host', 'database.redis.cache.host']],
        'drivers.redis_port' => ['env' => ['REDIS_PORT'], 'config' => ['database.redis.default.port', 'database.redis.cache.port']],
        'drivers.redis_password' => ['env' => ['REDIS_PASSWORD'], 'config' => ['database.redis.default.password', 'database.redis.cache.password'], 'sensitive' => true],
        'drivers.redis_database' => ['env' => ['REDIS_DB', 'REDIS_CACHE_DB'], 'config' => ['database.redis.default.database', 'database.redis.cache.database']],
        'drivers.memcached_host' => ['env' => ['MEMCACHED_HOST'], 'config' => ['cache.stores.memcached.servers.0.host']],
        'drivers.memcached_port' => ['env' => ['MEMCACHED_PORT'], 'config' => ['cache.stores.memcached.servers.0.port']],
        'drivers.s3_bucket' => ['env' => ['AWS_BUCKET'], 'config' => ['filesystems.disks.s3.bucket']],
        'drivers.s3_region' => ['env' => ['AWS_DEFAULT_REGION'], 'config' => ['filesystems.disks.s3.region']],
        'drivers.s3_access_key' => ['env' => ['AWS_ACCESS_KEY_ID'], 'config' => ['filesystems.disks.s3.key'], 'sensitive' => true],
        'drivers.s3_secret_key' => ['env' => ['AWS_SECRET_ACCESS_KEY'], 'config' => ['filesystems.disks.s3.secret'], 'sensitive' => true],
        'drivers.s3_url' => ['env' => ['AWS_URL'], 'config' => ['filesystems.disks.s3.url']],
        'drivers.s3_endpoint' => ['env' => ['AWS_ENDPOINT'], 'config' => ['filesystems.disks.s3.endpoint']],
        'drivers.s3_use_path_style' => ['env' => ['AWS_USE_PATH_STYLE_ENDPOINT'], 'config' => ['filesystems.disks.s3.use_path_style_endpoint']],
        'drivers.log_driver' => ['env' => ['LOG_STACK'], 'config' => ['logging.channels.stack.channels'], 'display' => 'log_stack_first'],
        'drivers.log_level' => ['env' => ['LOG_LEVEL'], 'config' => ['logging.channels.single.level', 'logging.channels.daily.level']],
        'drivers.log_days' => ['env' => ['LOG_DAILY_DAYS'], 'config' => ['logging.channels.daily.days']],
        'drivers.search_engine_driver' => ['env' => ['SCOUT_DRIVER'], 'config' => ['scout.driver']],
        'drivers.websocket_enabled' => ['env' => ['BROADCAST_CONNECTION'], 'config' => ['broadcasting.default'], 'display' => 'broadcast_is_reverb'],
        'drivers.websocket_app_id' => ['env' => ['REVERB_APP_ID'], 'config' => ['broadcasting.connections.reverb.app_id', 'reverb.apps.apps.0.app_id']],
        'drivers.websocket_app_key' => ['env' => ['REVERB_APP_KEY'], 'config' => ['broadcasting.connections.reverb.key', 'reverb.apps.apps.0.key']],
        'drivers.websocket_app_secret' => ['env' => ['REVERB_APP_SECRET'], 'config' => ['broadcasting.connections.reverb.secret', 'reverb.apps.apps.0.secret'], 'sensitive' => true],
        'drivers.websocket_server_host' => ['env' => ['REVERB_HOST'], 'config' => ['broadcasting.connections.reverb.options.host', 'reverb.apps.apps.0.options.host']],
        'drivers.websocket_server_port' => ['env' => ['REVERB_PORT'], 'config' => ['broadcasting.connections.reverb.options.port', 'reverb.apps.apps.0.options.port']],
        'drivers.websocket_server_scheme' => ['env' => ['REVERB_SCHEME'], 'config' => ['broadcasting.connections.reverb.options.scheme', 'reverb.apps.apps.0.options.scheme']],
        'drivers.websocket_verify_ssl' => ['env' => ['REVERB_VERIFY_SSL'], 'config' => ['broadcasting.connections.reverb.client_options.verify']],

        // --- core_update ---
        'core_update.github_url' => ['env' => ['G7_UPDATE_GITHUB_URL'], 'config' => ['app.update.github_url']],
        'core_update.github_token' => ['env' => ['G7_UPDATE_GITHUB_TOKEN'], 'config' => ['app.update.github_token'], 'sensitive' => true],

        // --- geoip ---
        'geoip.feature_enabled' => ['env' => ['GEOIP_ENABLED'], 'config' => ['geoip.enabled']],
        'geoip.license_key' => ['env' => ['GEOIP_LICENSE_KEY'], 'config' => ['geoip.license_key'], 'sensitive' => true],
        'geoip.auto_update_enabled' => ['env' => ['GEOIP_AUTO_UPDATE_ENABLED'], 'config' => ['geoip.auto_update_enabled']],

        // --- upload ---
        'upload.max_file_size' => ['env' => ['ATTACHMENT_MAX_FILE_SIZE'], 'config' => ['attachment.max_file_size'], 'display' => 'kb_to_mb'],
        'upload.image_max_width' => ['env' => ['ATTACHMENT_IMAGE_MAX_WIDTH'], 'config' => ['attachment.image_max_width']],
        'upload.image_max_height' => ['env' => ['ATTACHMENT_IMAGE_MAX_HEIGHT'], 'config' => ['attachment.image_max_height']],
        'upload.image_quality' => ['env' => ['ATTACHMENT_IMAGE_QUALITY'], 'config' => ['attachment.image_quality']],
    ];

    /**
     * env 대응이 존재하지 않아 매핑에서 의도적으로 제외한 settings 키와 그 사유.
     *
     * 계약 테스트가 "맵 누락"과 "의도적 제외"를 구분하는 근거다 — 이 목록이 없으면
     * `SettingsServiceProvider` 에 새 주입이 추가될 때 그것이 빠뜨린 것인지 대응이 없는
     * 것인지 판정할 수 없고, 결국 아무도 알아채지 못한 채 `.env` 가 다시 사문화된다.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        'general.site_description' => 'env 대응 없음 (설정 전용 값)',
        'general.admin_email' => 'env 대응 없음 (설정 전용 값)',
        'general.currency' => 'env 대응 없음 (설정 전용 값)',
        'general.maintenance_mode' => 'env 대응 없음 — APP_MAINTENANCE_DRIVER 는 저장소 종류이고 점검 모드 on/off 가 아니다',
        'general.site_logo' => 'env 대응 없음 (첨부 id 배열)',
        'general.asset_url_mode' => 'env 대응 없음 (설정 전용 값)',
        'mail.encryption' => 'config/mail.php 에 대응 키 없음 — Provider 가 쓰는 mail.mailers.smtp.encryption 은 env 유래가 아니다 (Laravel 12 의 env 대응 키는 MAIL_SCHEME → smtp.scheme 로 별개 축)',
        'debug.sql_query_log' => 'env 대응 없음 (g7.sql_query_log 는 설정 전용)',
        'debug.outbound_proxy' => 'env 대응 없음 (g7.outbound_proxy 는 설정 전용)',
        'debug.outbound_proxy_bypass' => 'env 대응 없음 (g7.outbound_proxy 는 설정 전용)',
        'drivers.public_asset_disk' => 'env 대응 없음 (core.storage.public_asset_disk 는 설정 전용)',
        'drivers.websocket_host' => '브라우저 클라이언트 endpoint (g7.websocket.client.host) — REVERB_HOST 는 서버 endpoint 축이라 대응이 아니다',
        'drivers.websocket_port' => '브라우저 클라이언트 endpoint (g7.websocket.client.port)',
        'drivers.websocket_scheme' => '브라우저 클라이언트 endpoint (g7.websocket.client.scheme)',
        'geoip.last_updated_at' => 'geoip:update 커맨드가 기록하는 런타임 상태값',
        'upload.allowed_extensions' => 'env 대응 없음 (AllowedExtensions 정규화를 거치는 설정 전용 값)',
        'upload.orphan_cleanup_enabled' => 'env 대응 없음 (설정 전용 값)',
        'upload.orphan_retention_days' => 'env 대응 없음 (설정 전용 값)',
        'identity.default_provider' => 'config(\'settings.identity\') 로 카테고리를 통째 주입 — 개별 env 대응이 없다',
        'identity.purpose_providers' => 'config(\'settings.identity\') 로 카테고리를 통째 주입 — 개별 env 대응이 없다',
        'identity.challenge_ttl_minutes' => 'config(\'settings.identity\') 로 카테고리를 통째 주입 — 개별 env 대응이 없다',
        'identity.max_attempts' => 'config(\'settings.identity\') 로 카테고리를 통째 주입 — 개별 env 대응이 없다',
    ];

    /**
     * 선언 파일(`config/*.php`)에 존재하지 않고 런타임에 생성되는 config 키.
     *
     * `logging.level` 은 G7 이 도입한 통합 로그 레벨 키로, `SettingsServiceProvider` 가
     * 만들고 `BrowserLogWriter` 가 기본값과 함께 읽는다 — Laravel 의 `config/logging.php`
     * 에는 선언이 없다. 계약 테스트가 "존재하지 않는 config 키" 로 오탐하지 않도록
     * 그 사유를 코드에 남긴다.
     *
     * @var array<int, string>
     */
    public const RUNTIME_CREATED_CONFIG_KEYS = [
        'logging.level',
    ];

    /**
     * `SettingsServiceProvider` 가 settings → config 주입을 수행하는 카테고리 목록.
     *
     * 계약 테스트가 "이 카테고리의 defaults 키는 MAP 이나 EXEMPT 중 하나에 반드시 등재"
     * 라는 전수 패리티를 강제하는 모집단입니다. 주입 대상이 아닌 카테고리(security·seo·
     * cache·pagination·notifications)는 `.env` 를 덮는 일이 없으므로 대상이 아닙니다.
     *
     * @var array<int, string>
     */
    public const INJECTED_CATEGORIES = [
        'general',
        'mail',
        'debug',
        'drivers',
        'core_update',
        'geoip',
        'upload',
        'identity',
    ];

    /**
     * 매핑에 등장하는 env 변수 전체를 중복 없이 반환합니다.
     *
     * `config/env-priority.php` 가 명시 여부를 캡처할 대상 목록입니다.
     *
     * @return array<int, string> env 변수명 목록
     */
    public static function envVars(): array
    {
        $vars = [];

        foreach (self::MAP as $entry) {
            foreach ($entry['env'] as $var) {
                $vars[$var] = true;
            }
        }

        return array_keys($vars);
    }

    /**
     * `.env` 우선 모드가 켜져 있는지 반환합니다.
     *
     * @return bool 켜져 있으면 true
     */
    public static function enabled(): bool
    {
        return config('env-priority.enabled') === true;
    }

    /**
     * 해당 settings 키가 `.env` 로 잠겼는지 판정합니다.
     *
     * 스위치가 켜져 있고, 그 키를 결정하는 env 변수 중 **하나라도** 명시되어 있으면 잠깁니다.
     *
     * @param  string  $categoryDotKey  settings 저장소 키 (예: `mail.port`)
     * @return bool 잠겼으면 true
     */
    public static function isLocked(string $categoryDotKey): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $entry = self::MAP[$categoryDotKey] ?? null;

        if ($entry === null) {
            return false;
        }

        $explicit = config('env-priority.explicit', []);

        foreach ($entry['env'] as $var) {
            if (($explicit[$var] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * 카테고리 설정 배열에서 잠긴 키를 제거합니다.
     *
     * 주입 지점(`SettingsServiceProvider`)이 이 배열을 받으므로, 기존 `!empty`/`isset`
     * 가드가 제거된 키를 자연히 건너뜁니다 — 한 설정이 파생시키는 config 키가 여러 개인
     * 경우(redis 2, reverb 2)도 지점마다 조건을 심지 않고 한 번에 처리됩니다.
     *
     * 스위치가 꺼져 있으면 입력을 그대로 반환합니다 (현행 동작 보존).
     *
     * @param  string  $category  settings 카테고리명 (예: `mail`)
     * @param  array<string, mixed>  $settings  카테고리 설정 배열
     * @return array<string, mixed> 잠긴 키가 제거된 배열
     */
    public static function filterLocked(string $category, array $settings): array
    {
        if (! self::enabled()) {
            return $settings;
        }

        foreach (array_keys($settings) as $key) {
            if (self::isLocked($category.'.'.$key)) {
                unset($settings[$key]);
            }
        }

        return $settings;
    }

    /**
     * 저장 입력에서 잠긴 키를 제거합니다 (서버측 게이트).
     *
     * 화면의 `disabled` 는 게이트가 아닙니다 — 저장 API 를 직접 호출하는 경로가 남으므로
     * 실질 차단은 이 지점입니다. abilities 필터와 동형으로 조용히 제거합니다(422 아님):
     * 운영자가 `.env` 로 소유권을 가져간 키는 "거부"가 아니라 "설정 대상이 아님"입니다.
     *
     * @param  string  $category  settings 카테고리명 (예: `mail`)
     * @param  array<string, mixed>  $input  저장 입력 (원본 저장소 키 기준)
     * @return array<string, mixed> 잠긴 키가 제거된 입력
     */
    public static function rejectLockedForSave(string $category, array $input): array
    {
        return self::filterLocked($category, $input);
    }

    /**
     * 잠긴 settings 키 목록을 반환합니다.
     *
     * @return array<string, bool> 저장소 키 => true (잠긴 키만)
     */
    public static function lockedKeys(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $locked = [];

        foreach (array_keys(self::MAP) as $key) {
            if (self::isLocked($key)) {
                $locked[$key] = true;
            }
        }

        return $locked;
    }

    /**
     * 해당 settings 키가 민감 정보인지 반환합니다.
     *
     * 민감 키는 잠금 표시만 하고 유효값을 화면·응답에 싣지 않습니다.
     *
     * @param  string  $categoryDotKey  settings 저장소 키
     * @return bool 민감 정보면 true
     */
    public static function isSensitive(string $categoryDotKey): bool
    {
        return (self::MAP[$categoryDotKey]['sensitive'] ?? false) === true;
    }

    /**
     * 잠긴 키의 유효값(런타임 config 값)을 반환합니다.
     *
     * 화면이 저장값 대신 이 값을 보여주어야 "진실"과 일치합니다 — 잠긴 필드에 사문화된
     * 저장값이 남아 있으면 운영자는 적용되지 않는 값을 읽게 됩니다.
     *
     * 민감 키는 호출자가 걸러야 합니다 (이 메서드는 값 자체를 판단하지 않습니다).
     *
     * @param  string  $categoryDotKey  settings 저장소 키
     * @return mixed 유효값 (매핑에 없으면 null)
     */
    public static function effectiveValue(string $categoryDotKey): mixed
    {
        $entry = self::MAP[$categoryDotKey] ?? null;

        if ($entry === null) {
            return null;
        }

        $value = config($entry['config'][0]);

        return match ($entry['display'] ?? null) {
            // config/attachment.* 는 KB, 관리자 화면은 MB — Provider 의 변환과 역방향.
            'kb_to_mb' => is_numeric($value) ? (int) ((int) $value / 1024) : $value,
            // 화면의 웹소켓 마스터 토글은 broadcasting 드라이버 선택으로 표현된다.
            'broadcast_is_reverb' => $value === 'reverb',
            // stack 채널 목록의 첫 항목이 화면의 단일 로그 드라이버 선택값이다.
            'log_stack_first' => is_array($value) ? ($value[0] ?? null) : $value,
            default => $value,
        };
    }
}
