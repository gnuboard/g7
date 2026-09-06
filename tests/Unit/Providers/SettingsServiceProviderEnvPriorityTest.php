<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `.env` 우선 모드에서의 settings → config 주입 스킵 검증.
 *
 * 잠긴 키는 Config::set 이 아예 발생하지 않아야 한다 — 그것이 `.env` 값이 살아남는
 * 유일한 조건이다. 스위치가 꺼진 축은 종전 주입이 그대로 일어나는지도 함께 고정한다.
 *
 * @effects switch_off_behaves_identically, explicit_env_skips_injection, false_env_is_explicit, websocket_enabled_lock_skips_off_branch, debug_mode_lock_corrects_outbound_gate
 */
class SettingsServiceProviderEnvPriorityTest extends TestCase
{
    /**
     * 스위치를 켜고 지정한 env 변수만 명시 상태로 만듭니다.
     *
     * @param  array<int, string>  $explicitVars  명시 상태로 만들 env 변수 목록
     */
    private function enableWith(array $explicitVars): void
    {
        Config::set('env-priority.enabled', true);
        Config::set('env-priority.explicit', array_fill_keys($explicitVars, true));
    }

    /**
     * 스위치를 끕니다 (현행 동작).
     */
    private function disableSwitch(): void
    {
        Config::set('env-priority.enabled', false);
        Config::set('env-priority.explicit', []);
    }

    /**
     * 앱 환경을 production 으로 바꿉니다.
     *
     * `applyDebugConfig` 는 testing 환경에서 통째로 조기 return 하므로(테스트 격리 가드),
     * 그 본문의 분기를 검증하려면 환경을 바꿔야 합니다. 컨테이너의 `env` 바인딩을 교체하면
     * `app()->environment()` 의 해석이 바뀝니다. 앱 인스턴스는 테스트마다 새로 만들어지므로
     * 이 변경은 그 테스트 안에만 머뭅니다.
     */
    private function forceNonTestingEnvironment(): void
    {
        $this->app->instance('env', 'production');
    }

    /**
     * Provider 의 private apply* 메서드를 리플렉션으로 호출합니다.
     *
     * @param  string  $method  메서드명 (예: applyMailConfig)
     * @param  string  $category  카테고리명
     * @param  array<string, mixed>  $settings  그 카테고리의 설정 데이터
     */
    private function callApply(string $method, string $category, array $settings): void
    {
        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')
            ->willReturnCallback(fn (string $requested) => $requested === $category ? $settings : []);

        $provider = new SettingsServiceProvider($this->app);
        (new ReflectionMethod($provider, $method))->invoke($provider, $configRepository);
    }

    // ---------------------------------------------------------------- general

    /**
     * 잠긴 general 키는 주입되지 않고 `.env` 유래 config 값이 살아남습니다.
     *
     * @scenario switch=on, key_state=unlocked, surface=inject
     */
    public function test_locked_general_key_is_not_injected(): void
    {
        $this->enableWith(['APP_NAME']);
        Config::set('app.name', 'env유래이름');
        Config::set('app.url', 'https://env.example.com');

        $this->callApply('applyAppConfig', 'general', [
            'site_name' => '저장값이름',
            'site_url' => 'https://saved.example.com',
        ]);

        $this->assertSame('env유래이름', config('app.name'), '잠긴 키는 주입 스킵');
        $this->assertSame('https://saved.example.com', config('app.url'), '미잠금 키는 종전대로 주입');
    }

    /**
     * 스위치가 꺼져 있으면 general 주입이 종전과 동일합니다.
     *
     * @scenario switch=off, key_state=unlocked, surface=inject
     */
    public function test_switch_off_injects_general_as_before(): void
    {
        $this->disableSwitch();
        Config::set('app.name', 'env유래이름');

        $this->callApply('applyAppConfig', 'general', ['site_name' => '저장값이름']);

        $this->assertSame('저장값이름', config('app.name'));
    }

    /**
     * timezone 이 잠기면 그 키가 파생시키는 config 2곳 모두 주입되지 않습니다.
     */
    public function test_locked_timezone_skips_both_derived_config_keys(): void
    {
        $this->enableWith(['APP_DEFAULT_USER_TIMEZONE']);
        Config::set('app.default_user_timezone', 'UTC');
        Config::set('app.schedule_timezone', 'UTC');

        $this->callApply('applyAppConfig', 'general', ['timezone' => 'Asia/Seoul']);

        $this->assertSame('UTC', config('app.default_user_timezone'));
        $this->assertSame('UTC', config('app.schedule_timezone'));
    }

    // ------------------------------------------------------------------- mail

    /**
     * 잠긴 mail 키만 주입에서 빠집니다.
     */
    public function test_locked_mail_keys_are_not_injected(): void
    {
        $this->enableWith(['MAIL_PORT', 'MAIL_PASSWORD']);
        Config::set('mail.mailers.smtp.port', 2599);
        Config::set('mail.mailers.smtp.password', 'env비밀');
        Config::set('mail.mailers.smtp.host', 'env.host');

        $this->callApply('applyMailConfig', 'mail', [
            'mailer' => 'smtp',
            'host' => 'saved.host',
            'port' => 587,
            'password' => '저장비밀',
        ]);

        $this->assertSame(2599, config('mail.mailers.smtp.port'));
        $this->assertSame('env비밀', config('mail.mailers.smtp.password'));
        $this->assertSame('saved.host', config('mail.mailers.smtp.host'));
    }

    /**
     * 잠긴 mailgun_endpoint 는 기본값 강제 주입도 하지 않습니다 (F3).
     *
     * 이 지점은 저장값이 비어도 기본값('api.mailgun.net')을 박는 무조건 주입이었다.
     * 잠금으로 키가 제거되면 그 강제까지 멈춰야 `.env` 값이 살아남는다.
     */
    public function test_locked_mailgun_endpoint_skips_unconditional_default(): void
    {
        $this->enableWith(['MAILGUN_ENDPOINT']);
        Config::set('services.mailgun.endpoint', 'api.eu.mailgun.net');

        $this->callApply('applyMailConfig', 'mail', [
            'mailer' => 'mailgun',
            'mailgun_domain' => 'mg.example.com',
            'mailgun_endpoint' => '',
        ]);

        $this->assertSame('api.eu.mailgun.net', config('services.mailgun.endpoint'));
    }

    /**
     * 스위치가 꺼져 있으면 mailgun_endpoint 의 기본값 강제가 종전대로 유지됩니다.
     */
    public function test_switch_off_keeps_mailgun_endpoint_default_forcing(): void
    {
        $this->disableSwitch();
        Config::set('services.mailgun.endpoint', 'api.eu.mailgun.net');

        $this->callApply('applyMailConfig', 'mail', [
            'mailer' => 'mailgun',
            'mailgun_endpoint' => '',
        ]);

        $this->assertSame('api.mailgun.net', config('services.mailgun.endpoint'));
    }

    /**
     * 잠긴 ses_region 도 기본값 강제 주입을 하지 않습니다 (F3).
     */
    public function test_locked_ses_region_skips_unconditional_default(): void
    {
        $this->enableWith(['AWS_DEFAULT_REGION']);
        Config::set('services.ses.region', 'us-west-2');

        $this->callApply('applyMailConfig', 'mail', [
            'mailer' => 'ses',
            'ses_region' => '',
        ]);

        $this->assertSame('us-west-2', config('services.ses.region'));
    }

    // ------------------------------------------------------------------ debug

    /**
     * debug.mode 잠금 4케이스: app.debug 주입 스킵 + 로그 레벨 2차 효과 보정.
     *
     * @param  bool  $modeLocked  APP_DEBUG 명시 여부
     * @param  bool  $levelLocked  LOG_LEVEL 명시 여부
     * @param  bool  $envDebug  `.env` 의 APP_DEBUG 유효값
     * @param  string  $expectedLogLevel  기대 logging.level
     */
    #[DataProvider('debugLockCases')]
    public function test_debug_lock_matrix(bool $modeLocked, bool $levelLocked, bool $envDebug, string $expectedLogLevel): void
    {
        $explicit = [];
        if ($modeLocked) {
            $explicit[] = 'APP_DEBUG';
        }
        if ($levelLocked) {
            $explicit[] = 'LOG_LEVEL';
        }
        $this->enableWith($explicit);
        $this->forceNonTestingEnvironment();

        Config::set('app.debug', $envDebug);
        Config::set('logging.level', 'env레벨');

        $this->callApply('applyDebugConfig', 'debug', [
            'mode' => false,
            'log_level' => 'warning',
        ]);

        $this->assertSame(
            $modeLocked ? $envDebug : false,
            config('app.debug'),
            'app.debug 는 잠기면 `.env` 유효값이 유지되어야 함'
        );
        $this->assertSame($expectedLogLevel, config('logging.level'));
    }

    /**
     * debug 잠금 매트릭스 케이스.
     *
     * @return array<string, array{bool, bool, bool, string}>
     */
    public static function debugLockCases(): array
    {
        return [
            // mode 미잠금 · level 미잠금 → 저장값(mode=false) → 저장 log_level 적용
            'both unlocked' => [false, false, true, 'warning'],
            // mode 잠금(APP_DEBUG=true) · level 미잠금 → 디버그 모드 강제 규칙이 살아나 debug
            'mode locked, debug on' => [true, false, true, 'debug'],
            // mode 잠금(APP_DEBUG=false) · level 미잠금 → 저장 log_level 적용
            'mode locked, debug off' => [true, false, false, 'warning'],
            // level 잠금 → logging.* 주입 자체를 하지 않음 (`.env` LOG_LEVEL 권위)
            'level locked' => [false, true, true, 'env레벨'],
            'both locked' => [true, true, true, 'env레벨'],
        ];
    }

    /**
     * debug.mode 가 잠기면 프록시 게이트도 `.env` 유효값으로 판정됩니다.
     *
     * 보정이 없으면 잠금으로 mode 키가 사라져 게이트가 항상 닫히고, 운영자가 저장한
     * 프록시가 오류·로그 없이 영구 미적용된다.
     */
    public function test_locked_debug_mode_corrects_outbound_proxy_gate(): void
    {
        $this->enableWith(['APP_DEBUG']);
        $this->forceNonTestingEnvironment();
        Config::set('app.debug', true);

        $this->callApply('applyDebugConfig', 'debug', [
            'mode' => false,
            'outbound_proxy' => 'http://proxy.example.com:3128',
        ]);

        $proxy = config('g7.outbound_proxy');

        $this->assertIsArray($proxy, '디버그 모드가 `.env` 로 켜져 있으면 프록시가 적용되어야 함');
        $this->assertSame('http://proxy.example.com:3128', $proxy['http']);
    }

    /**
     * `.env` 의 APP_DEBUG 가 꺼져 있으면 잠금 상태에서도 프록시는 적용되지 않습니다.
     */
    public function test_locked_debug_mode_off_keeps_proxy_gate_closed(): void
    {
        $this->enableWith(['APP_DEBUG']);
        $this->forceNonTestingEnvironment();
        Config::set('app.debug', false);

        $this->callApply('applyDebugConfig', 'debug', [
            'mode' => true,
            'outbound_proxy' => 'http://proxy.example.com:3128',
        ]);

        $this->assertNull(config('g7.outbound_proxy'));
    }

    // ---------------------------------------------------------------- drivers

    /**
     * redis 관련 키가 잠기면 default·cache 두 커넥션 모두 주입되지 않습니다.
     */
    public function test_locked_redis_keys_skip_both_connections(): void
    {
        $this->enableWith(['REDIS_HOST', 'REDIS_DB']);
        Config::set('database.redis.default.host', 'env-redis');
        Config::set('database.redis.cache.host', 'env-redis');
        Config::set('database.redis.default.database', 7);
        Config::set('database.redis.cache.database', 8);
        Config::set('database.redis.default.port', 6379);

        $this->callApply('applyDriverConfig', 'drivers', [
            'redis_host' => 'saved-redis',
            'redis_port' => 6400,
            'redis_database' => 3,
        ]);

        $this->assertSame('env-redis', config('database.redis.default.host'));
        $this->assertSame('env-redis', config('database.redis.cache.host'));
        $this->assertSame(7, config('database.redis.default.database'));
        $this->assertSame(8, config('database.redis.cache.database'));
        $this->assertSame(6400, config('database.redis.default.port'), '미잠금 키는 종전대로 주입');
    }

    /**
     * `.env` 로 잠긴 웹소켓 마스터 토글은 OFF 강제 3종을 오발동시키지 않습니다.
     *
     * 잠금으로 키가 제거되면 `empty()` 가 참이 되어 OFF 강제 분기로 오진입한다.
     * 그러면 `.env` 로 웹소켓을 켠 운영자에게 강제 OFF 가 걸린다.
     */
    public function test_locked_websocket_toggle_does_not_force_off(): void
    {
        $this->enableWith(['BROADCAST_CONNECTION']);
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'env-key');
        Config::set('g7.websocket.client.host', 'ws.example.com');

        $this->callApply('applyDriverConfig', 'drivers', [
            'websocket_enabled' => false,
            'websocket_app_key' => 'saved-key',
        ]);

        $this->assertSame('reverb', config('broadcasting.default'));
        $this->assertSame('env-key', config('broadcasting.connections.reverb.key'));
        $this->assertSame('ws.example.com', config('g7.websocket.client.host'));
    }

    /**
     * 스위치가 꺼져 있으면 웹소켓 OFF 강제 3종이 종전대로 동작합니다.
     */
    public function test_switch_off_keeps_websocket_off_forcing(): void
    {
        $this->disableSwitch();
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'env-key');
        Config::set('g7.websocket.client.host', 'ws.example.com');

        $this->callApply('applyDriverConfig', 'drivers', [
            'websocket_enabled' => false,
        ]);

        $this->assertSame('null', config('broadcasting.default'));
        $this->assertSame('', config('broadcasting.connections.reverb.key'));
        $this->assertSame('', config('g7.websocket.client.host'));
    }

    /**
     * 잠긴 log_driver 는 stack 채널 목록을 덮지 않습니다.
     */
    public function test_locked_log_driver_skips_stack_channels(): void
    {
        $this->enableWith(['LOG_STACK']);
        Config::set('logging.channels.stack.channels', ['stderr']);

        $this->callApply('applyDriverConfig', 'drivers', [
            'log_driver' => 'daily',
            'log_days' => 30,
        ]);

        $this->assertSame(['stderr'], config('logging.channels.stack.channels'));
        $this->assertSame(30, config('logging.channels.daily.days'), '미잠금 키는 종전대로 주입');
    }

    // ------------------------------------------------------- upload / geoip 등

    /**
     * 잠긴 upload 키는 KB 환산 주입도 하지 않습니다.
     */
    public function test_locked_upload_key_is_not_injected(): void
    {
        $this->enableWith(['ATTACHMENT_MAX_FILE_SIZE']);
        Config::set('attachment.max_file_size', 51200);
        Config::set('attachment.image_max_width', 1000);

        $this->callApply('applyUploadConfig', 'upload', [
            'max_file_size' => 10,
            'image_max_width' => 2000,
        ]);

        $this->assertSame(51200, config('attachment.max_file_size'));
        $this->assertSame(2000, config('attachment.image_max_width'), '미잠금 키는 종전대로 주입');
    }

    /**
     * 잠긴 core_update 키는 주입되지 않습니다.
     */
    public function test_locked_core_update_key_is_not_injected(): void
    {
        $this->enableWith(['G7_UPDATE_GITHUB_URL']);
        Config::set('app.update.github_url', 'https://github.com/env/repo');

        $this->callApply('applyCoreUpdateConfig', 'core_update', [
            'github_url' => 'https://github.com/saved/repo',
            'github_token' => 'saved-token',
        ]);

        $this->assertSame('https://github.com/env/repo', config('app.update.github_url'));
        $this->assertSame('saved-token', config('app.update.github_token'));
    }

    /**
     * 잠긴 geoip 불리언 키는 주입되지 않습니다 (falsy 명시 축).
     */
    public function test_locked_geoip_boolean_key_is_not_injected(): void
    {
        $this->enableWith(['GEOIP_ENABLED']);
        Config::set('geoip.enabled', true);
        Config::set('geoip.auto_update_enabled', true);

        $this->callApply('applyGeoIpConfig', 'geoip', [
            'feature_enabled' => false,
            'auto_update_enabled' => false,
        ]);

        $this->assertTrue(config('geoip.enabled'), '잠긴 키는 `.env` 유효값 유지');
        $this->assertFalse(config('geoip.auto_update_enabled'), '미잠금 키는 종전대로 주입');
    }

    // ------------------------------------------- 잠금이 게이트·폴백을 무너뜨리는 축

    /**
     * 마스터 메일 드라이버가 잠겨도 하위 저장값(mailgun)은 종전대로 주입됩니다.
     *
     * 잠금은 그 키의 주입만 건너뛰는 것이지, 그 키를 게이트로 쓰는 형제 키까지
     * 무력화하는 것이 아닙니다. 게이트를 유효값으로 보정하지 않으면 `$mailer` 가 빈
     * 문자열이 되어 어느 분기에도 들어가지 않고, mailgun 자격증명이 오류 없이 사라집니다.
     *
     * @scenario switch=on, key_state=locked_plain, surface=inject
     */
    public function test_locked_mailer_still_injects_unlocked_driver_children(): void
    {
        $this->enableWith(['MAIL_MAILER']);
        Config::set('mail.default', 'mailgun');
        Config::set('services.mailgun.domain', 'env.example.com');

        $this->callApply('applyMailConfig', 'mail', [
            'mailer' => 'smtp',
            'mailgun_domain' => 'saved.example.com',
            'mailgun_secret' => 'saved-secret',
            'mailgun_endpoint' => 'api.eu.mailgun.net',
        ]);

        $this->assertSame('mailgun', config('mail.default'), '잠긴 마스터 키는 `.env` 유효값 유지');
        $this->assertSame('saved.example.com', config('services.mailgun.domain'), '미잠금 하위 키는 주입');
        $this->assertSame('saved-secret', config('services.mailgun.secret'));
        $this->assertSame('api.eu.mailgun.net', config('services.mailgun.endpoint'));
    }

    /**
     * 잠긴 웹소켓 server endpoint 자리에 클라이언트 값이 폴백으로 덮이지 않습니다.
     *
     * 클라이언트 endpoint 는 env 대응이 없는 관리자 소유 값입니다. 폴백을 그대로 두면
     * 그 값이 `.env` 가 소유한 REVERB_HOST/PORT/SCHEME 자리에 실려 잠금이 무력해집니다.
     *
     * @scenario switch=on, key_state=locked_plain, surface=inject
     */
    public function test_locked_websocket_server_endpoint_is_not_overwritten_by_client_fallback(): void
    {
        $this->enableWith(['REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME']);
        Config::set('broadcasting.connections.reverb.options.host', 'ws.env.example.com');
        Config::set('broadcasting.connections.reverb.options.port', 8080);
        Config::set('broadcasting.connections.reverb.options.scheme', 'https');
        Config::set('reverb.apps.apps.0.options.host', 'ws.env.example.com');

        $this->callApply('applyDriverConfig', 'drivers', [
            'websocket_enabled' => true,
            'websocket_host' => 'admin.example.com',
            'websocket_port' => 6001,
            'websocket_scheme' => 'http',
        ]);

        $this->assertSame('ws.env.example.com', config('broadcasting.connections.reverb.options.host'));
        $this->assertSame(8080, config('broadcasting.connections.reverb.options.port'));
        $this->assertSame('https', config('broadcasting.connections.reverb.options.scheme'));
        $this->assertSame('ws.env.example.com', config('reverb.apps.apps.0.options.host'));
        $this->assertSame('admin.example.com', config('g7.websocket.client.host'), '클라이언트 endpoint 는 종전대로 주입');
    }

    /**
     * 미잠금 상태에서는 server endpoint 폴백이 종전대로 동작합니다 (음성 대조).
     */
    public function test_websocket_server_endpoint_falls_back_to_client_when_unlocked(): void
    {
        $this->disableSwitch();

        $this->callApply('applyDriverConfig', 'drivers', [
            'websocket_enabled' => true,
            'websocket_host' => 'admin.example.com',
            'websocket_port' => 6001,
            'websocket_scheme' => 'https',
        ]);

        $this->assertSame('admin.example.com', config('broadcasting.connections.reverb.options.host'));
        $this->assertSame(6001, config('broadcasting.connections.reverb.options.port'));
        $this->assertSame('https', config('broadcasting.connections.reverb.options.scheme'));
    }

    /**
     * 잠긴 storage_driver 의 유효값이 s3 면 첨부 디스크 파생이 그대로 발동합니다.
     *
     * 저장값 부재를 "s3 아님" 으로 읽으면 `FILESYSTEM_DISK=s3` 를 잠근 설치에서
     * 첨부 업로드만 로컬 디스크로 되돌아갑니다 (오류 없이 갈라집니다).
     *
     * @scenario switch=on, key_state=locked_plain, surface=inject
     */
    public function test_locked_storage_driver_still_derives_attachment_disk(): void
    {
        $this->enableWith(['FILESYSTEM_DISK']);
        Config::set('filesystems.default', 's3');
        Config::set('attachment.disk_explicit', null);
        Config::set('attachment.disk', 'attachments');

        $this->callApply('applyDriverConfig', 'drivers', [
            'storage_driver' => 'local',
        ]);

        $this->assertSame('s3', config('filesystems.default'), '잠긴 키는 `.env` 유효값 유지');
        $this->assertSame('s3', config('attachment.disk'), '유효값 기준으로 파생이 발동');
    }

    /**
     * ATTACHMENT_DISK 가 명시된 설치에서는 파생이 발동하지 않습니다 (음성 대조).
     */
    public function test_explicit_attachment_disk_still_wins_over_derivation(): void
    {
        $this->enableWith(['FILESYSTEM_DISK']);
        Config::set('filesystems.default', 's3');
        Config::set('attachment.disk_explicit', 'attachments');
        Config::set('attachment.disk', 'attachments');

        $this->callApply('applyDriverConfig', 'drivers', [
            'storage_driver' => 'local',
        ]);

        $this->assertSame('attachments', config('attachment.disk'));
    }
}
