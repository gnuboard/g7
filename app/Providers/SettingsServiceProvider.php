<?php

namespace App\Providers;

use App\Repositories\JsonConfigRepository;
use App\Support\AllowedExtensions;
use App\Support\ExtensionSettingsMirror;
use App\Support\OutboundProxy;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Predis\Client;

/**
 * 설정 서비스 프로바이더
 *
 * Laravel 부트스트랩 시 JSON 설정 파일을 로드하여
 * config()에 주입합니다. DB 연결 전에 실행됩니다.
 */
class SettingsServiceProvider extends ServiceProvider
{
    // 코어 설정 카테고리 목록은 ExtensionSettingsMirror::CORE_CATEGORIES 가 단독 소유한다.
    // 여기에 사본을 두면 미러가 읽는 목록과 갈라져도 아무도 알아채지 못한다.

    /**
     * 서비스를 등록합니다.
     *
     * DB 연결 전에 실행되므로 JSON 파일에서 직접 읽습니다.
     */
    public function register(): void
    {
        // 미러 채움 소유자를 컨테이너 싱글톤으로 등록한다 — 부팅/저장/테스트가
        // 같은 인스턴스를 해석해야 교체(스텁 주입)가 모든 경로에 통한다.
        $this->app->singleton(ExtensionSettingsMirror::class);

        // JsonConfigRepository를 직접 인스턴스화 (DI 컨테이너 사용 불가)
        $configRepository = new JsonConfigRepository;

        // 각 설정 파일이 존재하는 경우에만 해당 설정을 오버라이드
        $this->applyMailConfig($configRepository);
        $this->applyAppConfig($configRepository);
        $this->applyDebugConfig($configRepository);
        $this->applyDriverConfig($configRepository);
        $this->applyPublicAssetDiskConfig($configRepository);
        $this->applyUploadConfig($configRepository);
        $this->applyCoreUpdateConfig($configRepository);
        $this->applyGeoIpConfig($configRepository);
        $this->applyIdentityConfig($configRepository);

        // 코어 설정을 g7_settings.core prefix로 저장
        $this->loadCoreSettingsToConfig($configRepository);
    }

    /**
     * 코어 설정을 g7_settings.core prefix로 Config에 저장합니다.
     *
     * 모든 코어 설정을 통합하여 g7_settings('core.category.key') 형태로
     * 접근할 수 있도록 합니다.
     */
    private function loadCoreSettingsToConfig(JsonConfigRepository $configRepository): void
    {
        // 미러 채움 로직은 ExtensionSettingsMirror 가 단일 소유한다 —
        // 저장 시점 재채움(공개이슈 #109)과 같은 코드를 써야 부팅과 저장의 결과가 갈리지 않는다.
        $this->app->make(ExtensionSettingsMirror::class)->refreshCore($configRepository);
    }

    /**
     * 서비스를 부트스트랩합니다.
     */
    public function boot(): void
    {
        // 부트스트랩 후 추가 설정 적용 가능
    }

    /**
     * 메일 설정을 Laravel config에 적용합니다.
     */
    private function applyMailConfig(JsonConfigRepository $configRepository): void
    {
        $mailSettings = $configRepository->getCategory('mail');

        if (empty($mailSettings)) {
            return;
        }

        // 메일러 설정
        if (! empty($mailSettings['mailer'])) {
            Config::set('mail.default', $mailSettings['mailer']);
        }

        // SMTP 설정
        if (! empty($mailSettings['host'])) {
            Config::set('mail.mailers.smtp.host', $mailSettings['host']);
        }

        if (! empty($mailSettings['port'])) {
            Config::set('mail.mailers.smtp.port', (int) $mailSettings['port']);
        }

        if (! empty($mailSettings['username'])) {
            Config::set('mail.mailers.smtp.username', $mailSettings['username']);
        }

        if (! empty($mailSettings['password'])) {
            Config::set('mail.mailers.smtp.password', $mailSettings['password']);
        }

        if (isset($mailSettings['encryption'])) {
            Config::set('mail.mailers.smtp.encryption', $mailSettings['encryption'] ?: null);
        }

        // 드라이버별 설정
        $mailer = $mailSettings['mailer'] ?? '';

        if ($mailer === 'mailgun') {
            if (! empty($mailSettings['mailgun_domain'])) {
                Config::set('services.mailgun.domain', $mailSettings['mailgun_domain']);
            }
            if (! empty($mailSettings['mailgun_secret'])) {
                Config::set('services.mailgun.secret', $mailSettings['mailgun_secret']);
            }
            Config::set('services.mailgun.endpoint',
                ! empty($mailSettings['mailgun_endpoint']) ? $mailSettings['mailgun_endpoint'] : 'api.mailgun.net'
            );
        }

        if ($mailer === 'ses') {
            if (! empty($mailSettings['ses_key'])) {
                Config::set('services.ses.key', $mailSettings['ses_key']);
            }
            if (! empty($mailSettings['ses_secret'])) {
                Config::set('services.ses.secret', $mailSettings['ses_secret']);
            }
            Config::set('services.ses.region',
                ! empty($mailSettings['ses_region']) ? $mailSettings['ses_region'] : 'ap-northeast-2'
            );
        }

        // 발신자 설정
        if (! empty($mailSettings['from_address'])) {
            Config::set('mail.from.address', $mailSettings['from_address']);
        }

        if (! empty($mailSettings['from_name'])) {
            Config::set('mail.from.name', $mailSettings['from_name']);
        }
    }

    /**
     * 앱 설정을 Laravel config에 적용합니다.
     */
    private function applyAppConfig(JsonConfigRepository $configRepository): void
    {
        $generalSettings = $configRepository->getCategory('general');

        if (empty($generalSettings)) {
            return;
        }

        if (! empty($generalSettings['site_name'])) {
            // site_name 이 다국어 JSON array 일 수 있으므로 현재/폴백 로케일 string 으로 정규화한다
            // (공개#49). raw array 를 config('app.name') 에 넣으면 app.blade.php 의
            // {{ config('app.name') }} (Blade e() → htmlspecialchars) 에서 TypeError 가 발생해
            // SPA <title> 페이지가 깨진다. OG 경로(SeoMetaResolver)와 동일하게 안전화한다.
            Config::set('app.name', $this->localizeSettingValue($generalSettings['site_name']));
        }

        if (! empty($generalSettings['site_url'])) {
            Config::set('app.url', $generalSettings['site_url']);
        }

        if (! empty($generalSettings['timezone'])) {
            // 환경설정의 timezone은 사용자 표시용 기본 타임존
            // app.timezone(서버 저장 타임존)은 항상 UTC 유지
            Config::set('app.default_user_timezone', $generalSettings['timezone']);

            // 예약 작업의 시각 해석 기준도 사이트 설정 시간대를 따른다.
            // Laravel 의 Kernel::scheduleTimezone() 이 이 키를 읽어 Schedule 인스턴스
            // 전체에 일괄 적용하므로, 코어·확장이 등록한 모든 예약이 같은 기준을 공유한다.
            // (이벤트마다 ->timezone() 을 붙이는 방식은 나중에 추가되는 예약이
            //  조용히 UTC 기준으로 돌아가므로 채택하지 않는다.)
            // 미설정 시에는 Laravel 기본 폴백(app.timezone = UTC)이 그대로 적용된다.
            Config::set('app.schedule_timezone', $generalSettings['timezone']);
        }

        if (! empty($generalSettings['language'])) {
            Config::set('app.locale', $generalSettings['language']);
        }
    }

    /**
     * 설정값이 다국어 JSON array 면 현재/폴백 로케일 string 으로 정규화합니다 (공개#49).
     *
     * SeoMetaResolver/SeoRenderer 의 resolveLocalizedValue 와 동일 의미. 단, 이 Provider 는
     * register() 단계(DI 컨테이너 사용 전)에서 호출되므로 SEO 트레이트에 의존하지 않고 인라인한다.
     *
     * @param  mixed  $value  설정값 (string / 다국어 array / scalar)
     * @return string 현재/폴백 로케일 string
     */
    private function localizeSettingValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $locale = config('app.locale', 'ko');
            if (isset($value[$locale]) && is_string($value[$locale])) {
                return $value[$locale];
            }
            $fallback = config('app.fallback_locale', 'en');
            if (isset($value[$fallback]) && is_string($value[$fallback])) {
                return $value[$fallback];
            }
            // 첫 string 값 폴백
            foreach ($value as $candidate) {
                if (is_string($candidate)) {
                    return $candidate;
                }
            }

            return '';
        }

        return (string) ($value ?? '');
    }

    /**
     * 디버그 설정을 Laravel config에 적용합니다.
     *
     * testing 환경(PHPUnit, .env.testing)에서는 settings JSON 의 mode 값이 .env.testing 의
     * APP_DEBUG 를 덮어쓰지 않는다. 운영 PC 의 settings(mode=false) 때문에 PHPUnit / E2E
     * 인프라(PlaywrightIssueToken 등)가 차단되는 회귀를 방지한다. logging 레벨도 동일 사유로
     * 스킵한다.
     *
     * production 환경에서 Playwright E2E 가 production DB 에 토큰을 발급해야 하는 경우
     * (호스트 도메인이 .env.testing 의 testing DB 가 아니라 .env 의 production DB 를 사용하는 상황),
     * G7_PLAYWRIGHT_BYPASS=1 환경변수가 부여된 호출은 settings JSON 덮어쓰기를 건너뛰어
     * APP_DEBUG inline override 가 유지되도록 한다. CLI 한정 + 명시 옵트인이므로
     * 운영 admin UI 토글 SSoT 정책은 보존된다.
     */
    private function applyDebugConfig(JsonConfigRepository $configRepository): void
    {
        if (app()->environment('testing') || env('G7_PLAYWRIGHT_BYPASS') === '1') {
            return;
        }

        $debugSettings = $configRepository->getCategory('debug');

        if (empty($debugSettings)) {
            return;
        }

        $isDebugMode = isset($debugSettings['mode']) && (bool) $debugSettings['mode'];

        if (isset($debugSettings['mode'])) {
            Config::set('app.debug', $isDebugMode);
        }

        // debug 모드가 true이면 log_level을 debug로 강제 설정
        if ($isDebugMode) {
            $logLevel = 'debug';
        } elseif (! empty($debugSettings['log_level'])) {
            $logLevel = $debugSettings['log_level'];
        } else {
            $logLevel = null;
        }

        if ($logLevel) {
            Config::set('logging.level', $logLevel);
            Config::set('logging.channels.single.level', $logLevel);
            Config::set('logging.channels.daily.level', $logLevel);
        }

        // SQL 쿼리 로그 설정
        if (isset($debugSettings['sql_query_log'])) {
            Config::set('g7.sql_query_log', (bool) $debugSettings['sql_query_log']);
        }

        // 아웃바운드 HTTP 프록시 설정.
        // 적용 여부 판정은 OutboundProxy 가 단독으로 소유한다 — 디버그 모드가 꺼져 있으면
        // 저장값이 남아 있어도 null 이 되어 주입되지 않는다.
        Config::set('g7.outbound_proxy', OutboundProxy::resolve($debugSettings));
    }

    /**
     * 드라이버 설정을 Laravel config에 적용합니다.
     *
     * 캐시, 세션, 큐, 스토리지, Redis 드라이버 설정을 오버라이드합니다.
     *
     * **테스트 환경 격리**: `storage/app/settings/drivers.json`은 모든 환경이 공유하는
     * 파일이므로, testing 환경에서 cache/session/queue 드라이버를 덮어쓰면 phpunit.xml이
     * 지정한 테스트 전용 드라이버(array/array/sync)가 무시되고 dev의 Redis 등을 공유하게 됩니다.
     * 그 결과 테스트가 dev 캐시를 오염시켜 알림 훅 등록 같은 부팅 데이터가 silent하게
     * 망가지는 치명적 상태가 발생합니다. testing 환경에서는 드라이버 오버라이드를 건너뜁니다.
     */
    private function applyDriverConfig(JsonConfigRepository $configRepository): void
    {
        $driverSettings = $configRepository->getCategory('drivers');

        if (empty($driverSettings)) {
            return;
        }

        // testing 환경에서는 drivers.json의 cache/session/queue 오버라이드 차단 — 테스트 격리 보호
        // (storage/app/settings/drivers.json은 shared 파일이므로 dev의 Redis/DB 드라이버가
        //  testing으로 흘러들어가면 dev 캐시를 오염시킴)
        $isTestingEnv = env('APP_ENV') === 'testing';

        // 캐시 드라이버 설정
        if (! $isTestingEnv && ! empty($driverSettings['cache_driver'])) {
            Config::set('cache.default', $driverSettings['cache_driver']);
        }

        // 세션 드라이버 설정
        if (! $isTestingEnv && ! empty($driverSettings['session_driver'])) {
            Config::set('session.driver', $driverSettings['session_driver']);
        }

        // 세션 수명 설정
        if (! $isTestingEnv && ! empty($driverSettings['session_lifetime'])) {
            Config::set('session.lifetime', (int) $driverSettings['session_lifetime']);
        }

        // 큐 드라이버 설정
        if (! $isTestingEnv && ! empty($driverSettings['queue_driver'])) {
            Config::set('queue.default', $driverSettings['queue_driver']);
        }

        // Redis 설정 (캐시, 세션, 큐에서 공통 사용)
        $this->applyRedisConfig($driverSettings);

        // Memcached 설정
        $this->applyMemcachedConfig($driverSettings);

        // S3 스토리지 설정
        $this->applyS3Config($driverSettings);

        // 스토리지 드라이버 설정
        if (! empty($driverSettings['storage_driver'])) {
            Config::set('filesystems.default', $driverSettings['storage_driver']);
        }

        // 코어 첨부 업로드 디스크: ATTACHMENT_DISK env 명시가 항상 우선하고,
        // 미설정 시 storage_driver=s3 를 따른다. env() 직접 호출은 config:cache 환경에서
        // null 로 고정되므로 config 에 태운 attachment.disk_explicit 로 판별한다.
        // 빈 문자열도 미명시로 취급한다 — `ATTACHMENT_DISK=` 가 복사된 .env 에서
        // 빈 값을 명시로 읽으면 전환이 영구 미발동한다 (config 정규화의 2차 방어).
        // 기존 행은 행 disk 로 서빙되므로 신구 디스크 혼재는 안전하다.
        if (($driverSettings['storage_driver'] ?? null) === 's3' && in_array(config('attachment.disk_explicit'), [null, ''], true)) {
            Config::set('attachment.disk', 's3');
        }

        // 웹소켓 설정
        $this->applyWebsocketConfig($driverSettings);

        // 검색엔진 드라이버 설정
        if (! empty($driverSettings['search_engine_driver'])) {
            Config::set('scout.driver', $driverSettings['search_engine_driver']);
        }

        // 로그 설정
        $this->applyLogConfig($driverSettings);
    }

    /**
     * 공개 자산 디스크 설정을 적용합니다.
     *
     * testing 환경에서는 dev 공유 drivers.json 값이 테스트로 흘러들지 않도록
     * 주입을 건너뜁니다 (테스트 격리). 실제 주입/정규화는
     * injectPublicAssetDiskConfig() 가 담당합니다 — 가드와 분리해 두어야
     * 정규화 규칙('none' → '')이 테스트에서 단언 가능합니다.
     */
    private function applyPublicAssetDiskConfig(JsonConfigRepository $configRepository): void
    {
        if (env('APP_ENV') === 'testing') {
            return;
        }

        $this->injectPublicAssetDiskConfig($configRepository);
    }

    /**
     * drivers.public_asset_disk 저장값을 core.storage.public_asset_disk 로 주입합니다.
     *
     * 'none'(스트리밍 유지 선택)/빈값은 미설정('')으로 정규화합니다.
     * 테스트 격리 가드(applyPublicAssetDiskConfig)를 통과한 뒤에만 호출됩니다.
     *
     * @param  JsonConfigRepository  $configRepository  설정 저장소
     */
    private function injectPublicAssetDiskConfig(JsonConfigRepository $configRepository): void
    {
        $driverSettings = $configRepository->getCategory('drivers');

        $disk = (string) ($driverSettings['public_asset_disk'] ?? '');

        Config::set('core.storage.public_asset_disk', $disk === 'none' ? '' : $disk);
    }

    /**
     * Redis 연결 설정을 적용합니다.
     */
    private function applyRedisConfig(array $driverSettings): void
    {
        // phpredis 확장이 없는 서버에서 redis 드라이버 선택 시 `Class "Redis" not found` 로
        // 사이트 전면 다운되는 결함 방어 — 설정된 클라이언트가 phpredis 인데 확장이 없고
        // predis 가 있으면 predis 로 폴백한다. 확장이 있으면 기존 phpredis 경로 그대로다.
        // env('REDIS_CLIENT') 미명시 판별은 무효였다: .env.example 이 REDIS_CLIENT=phpredis
        // 를 활성 배포하므로 표준 설치에서 영구 미발동이었고, env() 직접 호출은
        // config:cache 환경에서 null 로 고정된다 (A8 disk_explicit 와 동형 함정).
        if ($this->shouldFallBackToPredis(extension_loaded('redis'))) {
            Config::set('database.redis.client', 'predis');
        }

        if (! empty($driverSettings['redis_host'])) {
            Config::set('database.redis.default.host', $driverSettings['redis_host']);
            Config::set('database.redis.cache.host', $driverSettings['redis_host']);
        }

        if (! empty($driverSettings['redis_port'])) {
            Config::set('database.redis.default.port', (int) $driverSettings['redis_port']);
            Config::set('database.redis.cache.port', (int) $driverSettings['redis_port']);
        }

        if (isset($driverSettings['redis_password']) && $driverSettings['redis_password'] !== '') {
            Config::set('database.redis.default.password', $driverSettings['redis_password']);
            Config::set('database.redis.cache.password', $driverSettings['redis_password']);
        }

        if (isset($driverSettings['redis_database'])) {
            Config::set('database.redis.default.database', (int) $driverSettings['redis_database']);
            Config::set('database.redis.cache.database', (int) $driverSettings['redis_database']);
        }
    }

    /**
     * Redis 클라이언트를 predis 로 폴백해야 하는지 판정합니다.
     *
     * 설정된 클라이언트(config — env 시점 값이 config:cache 에도 박제됨)가 phpredis 를
     * 가리키는데 확장이 로드되어 있지 않고 predis 가 존재하면 참. phpredis 명시 설정이라도
     * 확장이 없으면 어차피 동작 불가이므로 predis 전환이 유일한 동작 경로다 (#99 A2).
     * 확장 로드 여부는 인자로 받는다 — 확장 설치 머신에서 부재 상태를 재현할 수 없어
     * 판정 자체를 단위 검증 가능하게 분리한 것 (테스트가 양 분기를 주입 검증).
     *
     * @param  bool  $phpredisLoaded  phpredis 확장 로드 여부 (extension_loaded('redis'))
     * @return bool predis 로 폴백해야 하면 true
     */
    private function shouldFallBackToPredis(bool $phpredisLoaded): bool
    {
        return config('database.redis.client', 'phpredis') !== 'predis'
            && ! $phpredisLoaded
            && class_exists(Client::class);
    }

    /**
     * Memcached 연결 설정을 적용합니다.
     */
    private function applyMemcachedConfig(array $driverSettings): void
    {
        if (! empty($driverSettings['memcached_host'])) {
            Config::set('cache.stores.memcached.servers.0.host', $driverSettings['memcached_host']);
        }

        if (! empty($driverSettings['memcached_port'])) {
            Config::set('cache.stores.memcached.servers.0.port', (int) $driverSettings['memcached_port']);
        }
    }

    /**
     * S3 스토리지 설정을 적용합니다.
     */
    private function applyS3Config(array $driverSettings): void
    {
        if (! empty($driverSettings['s3_bucket'])) {
            Config::set('filesystems.disks.s3.bucket', $driverSettings['s3_bucket']);
        }

        if (! empty($driverSettings['s3_region'])) {
            Config::set('filesystems.disks.s3.region', $driverSettings['s3_region']);
        }

        if (! empty($driverSettings['s3_access_key'])) {
            Config::set('filesystems.disks.s3.key', $driverSettings['s3_access_key']);
        }

        if (! empty($driverSettings['s3_secret_key'])) {
            Config::set('filesystems.disks.s3.secret', $driverSettings['s3_secret_key']);
        }

        if (! empty($driverSettings['s3_url'])) {
            Config::set('filesystems.disks.s3.url', $driverSettings['s3_url']);
        }

        // S3 호환 스토리지(R2/MinIO/NCP 등)의 API 요청 대상 — s3_url(공개 URL base)과 별개 축이다.
        // endpoint 미주입 시 SDK 는 AWS 리전 도메인으로만 요청하므로 호환 스토리지 연결이 불가능하다.
        if (! empty($driverSettings['s3_endpoint'])) {
            Config::set('filesystems.disks.s3.endpoint', $driverSettings['s3_endpoint']);
        }

        if (! empty($driverSettings['s3_use_path_style'])) {
            Config::set('filesystems.disks.s3.use_path_style_endpoint', true);
        }
    }

    /**
     * 로그 드라이버 설정을 적용합니다.
     */
    private function applyLogConfig(array $driverSettings): void
    {
        // 로그 드라이버 설정 (single 또는 daily)
        if (! empty($driverSettings['log_driver'])) {
            Config::set('logging.channels.stack.channels', [$driverSettings['log_driver']]);
        }

        // 로그 레벨 설정
        if (! empty($driverSettings['log_level'])) {
            Config::set('logging.channels.single.level', $driverSettings['log_level']);
            Config::set('logging.channels.daily.level', $driverSettings['log_level']);
        }

        // daily 드라이버의 로그 보관 일수 설정
        if (! empty($driverSettings['log_days'])) {
            Config::set('logging.channels.daily.days', (int) $driverSettings['log_days']);
        }
    }

    /**
     * 웹소켓(Reverb) 설정을 적용합니다.
     *
     * 클라이언트(브라우저)와 서버(백엔드 broadcast HTTP API) endpoint를 분리합니다:
     * - 클라이언트: 외부에서 WebSocket으로 접속할 host/port (예: g7.dev:443)
     *   → g7.websocket.client.* 에 저장 → admin.blade.php가 브라우저로 전달
     * - 서버: 백엔드 Pusher SDK가 broadcast HTTP API를 호출할 내부 endpoint (예: 127.0.0.1:8080)
     *   → broadcasting.connections.reverb.options.* 에 저장 → Pusher SDK가 사용
     *   → reverb.apps.apps.0.options.* 에 저장 → Reverb 서버 자체 설정
     */
    private function applyWebsocketConfig(array $driverSettings): void
    {
        if (empty($driverSettings['websocket_enabled'])) {
            Config::set('broadcasting.default', 'null');
            // 프론트(admin/app.blade.php)가 @if(broadcasting.connections.reverb.key)로 연결을
            // 결정하므로, OFF 시 key 를 비워 .env REVERB_APP_KEY 가 살아 있어도 브라우저 WebSocket
            // 연결을 차단한다 (전 계층 SSoT — 공개#50). 송신(broadcasting.default='null')만으로는
            // 프론트 직접 연결을 못 막던 빈틈을 폐쇄한다.
            Config::set('broadcasting.connections.reverb.key', '');
            Config::set('g7.websocket.client.host', ''); // 잔재 endpoint 정리 (방어적)

            return;
        }

        $appId = $driverSettings['websocket_app_id'] ?? '';
        $appKey = $driverSettings['websocket_app_key'] ?? '';
        $appSecret = $driverSettings['websocket_app_secret'] ?? '';

        // 클라이언트 endpoint (외부 — 브라우저 접속용)
        $clientHost = $driverSettings['websocket_host'] ?? '';
        $clientPort = (int) ($driverSettings['websocket_port'] ?? 0);
        $clientScheme = $driverSettings['websocket_scheme'] ?? '';
        $verifySsl = isset($driverSettings['websocket_verify_ssl']) ? (bool) $driverSettings['websocket_verify_ssl'] : null;

        // 서버 endpoint (내부 — 백엔드 broadcast HTTP API용)
        // server 키가 비어있으면 클라이언트 값으로 fallback (단일 호스트 환경 호환)
        $serverHost = $driverSettings['websocket_server_host'] ?? '';
        $serverPort = (int) ($driverSettings['websocket_server_port'] ?? 0);
        $serverScheme = $driverSettings['websocket_server_scheme'] ?? '';
        if (empty($serverHost)) {
            $serverHost = $clientHost;
        }
        if ($serverPort <= 0) {
            $serverPort = $clientPort;
        }
        if (empty($serverScheme)) {
            $serverScheme = $clientScheme;
        }
        $serverUseTLS = $serverScheme === 'https';

        // 공통: 앱 자격증명
        if (! empty($appKey)) {
            Config::set('broadcasting.connections.reverb.key', $appKey);
            Config::set('reverb.apps.apps.0.key', $appKey);
        }
        if (! empty($appSecret)) {
            Config::set('broadcasting.connections.reverb.secret', $appSecret);
            Config::set('reverb.apps.apps.0.secret', $appSecret);
        }
        if (! empty($appId)) {
            Config::set('broadcasting.connections.reverb.app_id', $appId);
            Config::set('reverb.apps.apps.0.app_id', $appId);
        }

        // 백엔드 broadcast HTTP API용 endpoint (Pusher SDK가 사용)
        if (! empty($serverHost)) {
            Config::set('broadcasting.connections.reverb.options.host', $serverHost);
            Config::set('reverb.apps.apps.0.options.host', $serverHost);
        }
        if ($serverPort > 0) {
            Config::set('broadcasting.connections.reverb.options.port', $serverPort);
            Config::set('reverb.apps.apps.0.options.port', $serverPort);
        }
        if (! empty($serverScheme)) {
            Config::set('broadcasting.connections.reverb.options.scheme', $serverScheme);
            Config::set('broadcasting.connections.reverb.options.useTLS', $serverUseTLS);
            Config::set('reverb.apps.apps.0.options.scheme', $serverScheme);
            Config::set('reverb.apps.apps.0.options.useTLS', $serverUseTLS);
        }
        if ($verifySsl !== null) {
            Config::set('broadcasting.connections.reverb.client_options.verify', $verifySsl);
        }

        // 클라이언트(브라우저) 접속용 endpoint — Blade에서 읽어 G7Core에 전달
        if (! empty($clientHost)) {
            Config::set('g7.websocket.client.host', $clientHost);
        }
        if ($clientPort > 0) {
            Config::set('g7.websocket.client.port', $clientPort);
        }
        if (! empty($clientScheme)) {
            Config::set('g7.websocket.client.scheme', $clientScheme);
        }
    }

    /**
     * 코어 업데이트 설정을 Laravel config에 적용합니다.
     *
     * core_update.json의 값으로 config('app.update.github_url')과
     * config('app.update.github_token')을 오버라이드합니다.
     */
    private function applyCoreUpdateConfig(JsonConfigRepository $configRepository): void
    {
        $settings = $configRepository->getCategory('core_update');

        if (empty($settings)) {
            return;
        }

        if (! empty($settings['github_url'])) {
            Config::set('app.update.github_url', $settings['github_url']);
        }

        if (! empty($settings['github_token'])) {
            Config::set('app.update.github_token', $settings['github_token']);
        }
    }

    /**
     * GeoIP 설정을 Laravel config에 적용합니다.
     *
     * cache.json의 geoip_* 값으로 config('geoip.*')을 오버라이드합니다.
     * 마스터 스위치(geoip.feature_enabled)가 곧 config('geoip.enabled')의
     * 런타임 값이 됩니다. env('GEOIP_ENABLED')는 fallback 역할.
     *
     * @param  JsonConfigRepository  $configRepository  설정 저장소
     */
    private function applyGeoIpConfig(JsonConfigRepository $configRepository): void
    {
        $settings = $configRepository->getCategory('geoip');

        if (empty($settings)) {
            return;
        }

        if (isset($settings['feature_enabled'])) {
            Config::set('geoip.enabled', (bool) $settings['feature_enabled']);
        }

        if (! empty($settings['license_key'])) {
            Config::set('geoip.license_key', $settings['license_key']);
        }

        if (isset($settings['auto_update_enabled'])) {
            Config::set('geoip.auto_update_enabled', (bool) $settings['auto_update_enabled']);
        }

        if (! empty($settings['last_updated_at'])) {
            Config::set('geoip.last_updated_at', $settings['last_updated_at']);
        }
    }

    /**
     * 본인인증(IDV) 설정을 Laravel config 에 적용합니다.
     *
     * IdentityVerificationManager / MailIdentityProvider / InicisIdentityProvider 등이
     * config('settings.identity.*') dot-path 로 직접 read 하므로 이 경로로 주입합니다.
     *
     * 영향 항목 (admin UI 환경설정 > 본인인증):
     * - default_provider (기본 프로바이더)
     * - purpose_providers.{purpose} (목적별 기본 프로바이더)
     * - challenge_ttl_minutes (코드 유효시간)
     * - max_attempts (최대 시도 횟수)
     *
     * @param  JsonConfigRepository  $configRepository  설정 저장소
     */
    private function applyIdentityConfig(JsonConfigRepository $configRepository): void
    {
        $settings = $configRepository->getCategory('identity');

        if (empty($settings)) {
            return;
        }

        Config::set('settings.identity', $settings);
    }

    /**
     * 업로드 설정을 Laravel config에 적용합니다.
     */
    private function applyUploadConfig(JsonConfigRepository $configRepository): void
    {
        $uploadSettings = $configRepository->getCategory('upload');

        if (empty($uploadSettings)) {
            return;
        }

        // 관리자 설정은 MB, config/attachment.* 는 KB — 변환은 이 지점 단 한 곳에서만 수행한다.
        // (기존에는 존재하지 않는 키 `max_size` 를 읽어 설정이 어디에도 반영되지 않았다)
        if (! empty($uploadSettings['max_file_size'])) {
            Config::set('attachment.max_file_size', (int) $uploadSettings['max_file_size'] * 1024);
        }

        // 화면은 콤마 문자열을 보내고 API 직접 호출은 배열을 보낸다. 배열만 받아들이면
        // 문자열로 저장된 설정이 조용히 버려져 관리자가 확장자를 바꿔도 반영되지 않는다.
        if (isset($uploadSettings['allowed_extensions'])) {
            Config::set(
                'attachment.allowed_extensions',
                AllowedExtensions::normalize($uploadSettings['allowed_extensions'])
            );
        }

        // 이미지 축소 한계값·품질 — 픽셀/퍼센트라 단위 변환이 없다.
        // 미설정(빈 값)은 "축소하지 않음" 이므로 그대로 넘겨 ImageResizer 가 판정하게 둔다.
        foreach (['image_max_width', 'image_max_height', 'image_quality'] as $key) {
            if (isset($uploadSettings[$key]) && $uploadSettings[$key] !== '') {
                Config::set("attachment.{$key}", (int) $uploadSettings[$key]);
            }
        }
    }
}
