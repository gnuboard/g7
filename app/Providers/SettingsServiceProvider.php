<?php

namespace App\Providers;

use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/**
 * 설정 서비스 프로바이더
 *
 * Laravel 부트스트랩 시 JSON 설정 파일을 로드하여
 * config()에 주입합니다. DB 연결 전에 실행됩니다.
 */
class SettingsServiceProvider extends ServiceProvider
{
    /**
     * 코어 설정 카테고리 목록
     */
    private const CORE_CATEGORIES = [
        'mail',
        'general',
        'security',
        'debug',
        'drivers',
        'cache',
        'upload',
        'core_update',
        'seo',
    ];

    /**
     * 서비스를 등록합니다.
     *
     * DB 연결 전에 실행되므로 JSON 파일에서 직접 읽습니다.
     */
    public function register(): void
    {
        // JsonConfigRepository를 직접 인스턴스화 (DI 컨테이너 사용 불가)
        $configRepository = new JsonConfigRepository;

        // 각 설정 파일이 존재하는 경우에만 해당 설정을 오버라이드
        $this->applyMailConfig($configRepository);
        $this->applyAppConfig($configRepository);
        $this->applyDebugConfig($configRepository);
        $this->applyDriverConfig($configRepository);
        $this->applyCacheConfig($configRepository);
        $this->applyUploadConfig($configRepository);
        $this->applyCoreUpdateConfig($configRepository);

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
        $coreSettings = [];

        foreach (self::CORE_CATEGORIES as $category) {
            $settings = $configRepository->getCategory($category);
            if (! empty($settings)) {
                $coreSettings[$category] = $settings;
            }
        }

        Config::set('g7_settings.core', $coreSettings);
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
            Config::set('app.name', $generalSettings['site_name']);
        }

        if (! empty($generalSettings['site_url'])) {
            Config::set('app.url', $generalSettings['site_url']);
        }

        if (! empty($generalSettings['timezone'])) {
            // 환경설정의 timezone은 사용자 표시용 기본 타임존
            // app.timezone(서버 저장 타임존)은 항상 UTC 유지
            Config::set('app.default_user_timezone', $generalSettings['timezone']);
        }

        if (! empty($generalSettings['language'])) {
            Config::set('app.locale', $generalSettings['language']);
        }
    }

    /**
     * 디버그 설정을 Laravel config에 적용합니다.
     */
    private function applyDebugConfig(JsonConfigRepository $configRepository): void
    {
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
    }

    /**
     * 드라이버 설정을 Laravel config에 적용합니다.
     *
     * 캐시, 세션, 큐, 스토리지, Redis 드라이버 설정을 오버라이드합니다.
     */
    private function applyDriverConfig(JsonConfigRepository $configRepository): void
    {
        $driverSettings = $configRepository->getCategory('drivers');

        if (empty($driverSettings)) {
            return;
        }

        // 캐시 드라이버 설정
        if (! empty($driverSettings['cache_driver'])) {
            Config::set('cache.default', $driverSettings['cache_driver']);
        }

        // 세션 드라이버 설정
        if (! empty($driverSettings['session_driver'])) {
            Config::set('session.driver', $driverSettings['session_driver']);
        }

        // 세션 수명 설정
        if (! empty($driverSettings['session_lifetime'])) {
            Config::set('session.lifetime', (int) $driverSettings['session_lifetime']);
        }

        // 큐 드라이버 설정
        if (! empty($driverSettings['queue_driver'])) {
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
     * Redis 연결 설정을 적용합니다.
     */
    private function applyRedisConfig(array $driverSettings): void
    {
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
     */
    private function applyWebsocketConfig(array $driverSettings): void
    {
        if (empty($driverSettings['websocket_enabled'])) {
            return;
        }

        if (! empty($driverSettings['websocket_app_key'])) {
            Config::set('broadcasting.connections.reverb.key', $driverSettings['websocket_app_key']);
        }

        if (! empty($driverSettings['websocket_host'])) {
            Config::set('broadcasting.connections.reverb.options.host', $driverSettings['websocket_host']);
        }

        if (! empty($driverSettings['websocket_port'])) {
            Config::set('broadcasting.connections.reverb.options.port', (int) $driverSettings['websocket_port']);
        }

        if (! empty($driverSettings['websocket_scheme'])) {
            Config::set('broadcasting.connections.reverb.options.scheme', $driverSettings['websocket_scheme']);
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
     * 캐시 설정을 Laravel config에 적용합니다.
     */
    private function applyCacheConfig(JsonConfigRepository $configRepository): void
    {
        $cacheSettings = $configRepository->getCategory('cache');

        if (empty($cacheSettings)) {
            return;
        }

        if (! empty($cacheSettings['driver'])) {
            Config::set('cache.default', $cacheSettings['driver']);
        }

        if (! empty($cacheSettings['prefix'])) {
            Config::set('cache.prefix', $cacheSettings['prefix']);
        }
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

        if (! empty($uploadSettings['disk'])) {
            Config::set('filesystems.default', $uploadSettings['disk']);
        }

        if (! empty($uploadSettings['max_size'])) {
            Config::set('g7.upload.max_size', (int) $uploadSettings['max_size']);
        }

        if (! empty($uploadSettings['allowed_extensions'])) {
            Config::set('g7.upload.allowed_extensions', $uploadSettings['allowed_extensions']);
        }
    }
}
