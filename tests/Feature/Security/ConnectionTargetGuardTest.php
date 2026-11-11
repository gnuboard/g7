<?php

namespace Tests\Feature\Security;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Rules\ValidOutboundProxyUrl;
use App\Services\DriverConnectionTester;
use App\Services\OutboundProxyTester;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 관리자가 정하는 서버 연결 대상(연결 테스트·저장)의 주소 관문.
 *
 * 연결 테스트는 서버가 관리자가 넣은 주소로 직접 요청을 보낸다. 호스트 칸에 경로를 섞어 다른 주소를 만들거나,
 * 링크로컬·클라우드 메타데이터 주소를 넣거나, 응답의 리다이렉트로 다른 곳에 닿게 할 수 있었다. localhost·사내 IP
 * 는 정상 구성이라 그대로 허용하고, 실패 사유의 원문도 그대로 보여 준다.
 *
 * @scenario-manifest tests/scenarios/outbound-connection-target.yaml
 */
class ConnectionTargetGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /**
     * 설정 수정 권한을 가진 관리자를 준비하고 외부 요청을 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response('ok', 200)]);

        $user = User::factory()->create();
        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }
        $role = Role::create([
            'identifier' => 'target_guard_'.uniqid(),
            'name' => json_encode(['ko' => '설정 관리자', 'en' => 'Settings Admin']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        $this->token = $user->fresh()->createToken('settings')->plainTextToken;
    }

    /**
     * 관리자 토큰으로 설정 API 를 호출합니다.
     *
     * @param  string  $path  `/api/admin/settings` 뒤의 경로
     * @param  array<string, mixed>  $payload  요청 본문
     * @return TestResponse 응답
     */
    private function settingsPost(string $path, array $payload): TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])
            ->postJson('/api/admin/settings'.$path, $payload);
    }

    /**
     * 드라이버 탭 저장에 필요한 기본 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 저장 본문
     */
    private function driversSave(array $overrides): array
    {
        return ['_tab' => 'drivers', 'drivers' => $overrides + [
            'storage_driver' => 'local',
            'cache_driver' => 'file',
            'session_driver' => 'file',
            'queue_driver' => 'sync',
            'log_driver' => 'daily',
            'log_level' => 'error',
        ]];
    }

    /**
     * 웹소켓 호스트 칸의 경로 주입은 연결 테스트 요청에서 422 이고 서버는 아무 요청도 보내지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=path_injection__connection_test
     *
     * @effects host_field_rejects_path_query_userinfo
     */
    public function test_websocket_host_path_injection_is_rejected_before_any_request(): void
    {
        foreach (['websocket_host', 'websocket_server_host'] as $field) {
            $this->settingsPost('/test-driver', [
                'driver' => 'websocket',
                'websocket_enabled' => true,
                'websocket_host' => 'localhost',
                'websocket_port' => 80,
                'websocket_scheme' => 'http',
                $field => '169.254.169.254/latest/meta-data/iam/?',
            ])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        // 요청 검증을 거치지 않고 서비스를 직접 불러도 같은 판정으로 막는다.
        $result = app(DriverConnectionTester::class)->testWebsocket([
            'websocket_host' => '169.254.169.254/latest/meta-data/iam/?',
            'websocket_port' => 80,
            'websocket_scheme' => 'http',
        ]);
        $this->assertFalse($result['success']);

        Http::assertNothingSent();
    }

    /**
     * 메타데이터·링크로컬 주소는 「허용되지 않는 주소」 로 답하고 연결을 시도하지 않는다.
     *
     * @param  string  $host  메타데이터·링크로컬 호스트
     * @return void 반환값 없음
     *
     * @scenario case=metadata_address__connection_test
     *
     * @effects metadata_target_is_refused_without_connecting
     */
    #[DataProvider('metadataHosts')]
    public function test_metadata_hosts_are_refused_by_the_websocket_test(string $host): void
    {
        $this->settingsPost('/test-driver', [
            'driver' => 'websocket',
            'websocket_enabled' => true,
            'websocket_host' => $host,
            'websocket_port' => 80,
            'websocket_scheme' => 'http',
        ])->assertStatus(422)->assertJsonPath('errors.websocket_host.0', __('validation.settings.host_blocked'));

        $result = app(DriverConnectionTester::class)->testWebsocket([
            'websocket_host' => $host,
            'websocket_port' => 80,
            'websocket_scheme' => 'http',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(__('settings.connection_target_blocked'), $result['error'] ?? $result['message']);
        Http::assertNothingSent();
    }

    /**
     * 웹소켓 연결 테스트는 리다이렉트를 따라가지 않는다 — 응답이 가리키는 다른 주소에 닿지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=redirecting_endpoint__connection_test
     *
     * @effects connection_test_does_not_follow_redirects
     */
    public function test_websocket_test_does_not_follow_redirects(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options[] = $requestOptions;

            return Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/meta-data/']);
        });

        app(DriverConnectionTester::class)->testWebsocket([
            'websocket_host' => 'localhost',
            'websocket_port' => 8080,
            'websocket_scheme' => 'http',
        ]);

        $this->assertCount(1, $options);
        $this->assertFalse($options[0]['allow_redirects'] ?? null);
        Http::assertSentCount(1);
    }

    /**
     * S3·Redis·Memcached 연결 테스트도 메타데이터 주소를 막고, 캐시 서버 칸은 소켓 경로·TLS 접두를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=metadata_address__connection_test
     *
     * @effects metadata_target_is_refused_without_connecting
     */
    public function test_storage_and_cache_tests_refuse_metadata_targets(): void
    {
        $this->settingsPost('/test-driver', ['driver' => 's3', 'storage_driver' => 's3', 's3_endpoint' => 'http://169.254.169.254'])
            ->assertStatus(422)->assertJsonValidationErrors(['s3_endpoint']);
        $this->settingsPost('/test-driver', ['driver' => 's3', 'storage_driver' => 's3', 's3_url' => 'http://metadata.google.internal'])
            ->assertStatus(422)->assertJsonValidationErrors(['s3_url']);
        $this->settingsPost('/test-driver', ['driver' => 's3', 'storage_driver' => 's3', 's3_endpoint' => 'http://minio.local:9000/latest/meta-data'])
            ->assertStatus(422)->assertJsonValidationErrors(['s3_endpoint']);
        $this->settingsPost('/test-driver', ['driver' => 'redis', 'cache_driver' => 'redis', 'redis_host' => '169.254.169.254'])
            ->assertStatus(422)->assertJsonValidationErrors(['redis_host']);
        $this->settingsPost('/test-driver', ['driver' => 'memcached', 'cache_driver' => 'memcached', 'memcached_host' => '169.254.169.254'])
            ->assertStatus(422)->assertJsonValidationErrors(['memcached_host']);
        $this->settingsPost('/test-driver', ['driver' => 'redis', 'cache_driver' => 'redis', 'redis_host' => 'redis.local/0'])
            ->assertStatus(422)->assertJsonValidationErrors(['redis_host']);

        $tester = app(DriverConnectionTester::class);
        $blocked = __('settings.connection_target_blocked');

        $s3 = $tester->testS3([
            's3_bucket' => 'b', 's3_region' => 'ap-northeast-2', 's3_access_key' => 'k', 's3_secret_key' => 's',
            's3_endpoint' => 'http://169.254.169.254',
        ]);
        $redis = $tester->testRedis(['redis_host' => '169.254.169.254', 'redis_port' => 6379]);
        $memcached = $tester->testMemcached(['memcached_host' => '169.254.169.254', 'memcached_port' => 11211]);

        foreach ([$s3, $redis, $memcached] as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame($blocked, $result['error'] ?? $result['message']);
        }
    }

    /**
     * 드라이버 탭 저장에도 같은 검사를 한다 — 경로 주입·메타데이터는 422, 정상 값과 소켓·TLS 는 저장된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=path_injection__settings_save
     *
     * @effects settings_save_applies_the_same_host_rules
     */
    public function test_drivers_save_applies_the_same_rules(): void
    {
        $this->settingsPost('', $this->driversSave(['websocket_host' => '169.254.169.254/latest/meta-data/iam/?']))
            ->assertStatus(422)->assertJsonValidationErrors(['drivers.websocket_host']);
        $this->settingsPost('', $this->driversSave(['websocket_server_host' => '169.254.169.254']))
            ->assertStatus(422)->assertJsonValidationErrors(['drivers.websocket_server_host']);
        $this->settingsPost('', $this->driversSave(['redis_host' => 'metadata.google.internal']))
            ->assertStatus(422)->assertJsonValidationErrors(['drivers.redis_host']);
        $this->settingsPost('', $this->driversSave(['memcached_host' => 'cache.local?x=1']))
            ->assertStatus(422)->assertJsonValidationErrors(['drivers.memcached_host']);
        $this->settingsPost('', $this->driversSave(['s3_endpoint' => 'http://169.254.169.254']))
            ->assertStatus(422)->assertJsonValidationErrors(['drivers.s3_endpoint']);
        // 웹소켓 칸은 소켓 경로·TLS 접두를 받지 않는다.
        $this->settingsPost('', $this->driversSave(['websocket_host' => 'tls://redis.local']))
            ->assertStatus(422)->assertJsonValidationErrors(['drivers.websocket_host']);

        $this->settingsPost('', $this->driversSave([
            'websocket_host' => 'localhost',
            'websocket_port' => 8080,
            'websocket_server_host' => '192.168.0.10',
            'redis_host' => '/var/run/redis/redis.sock',
            'memcached_host' => 'tls://cache.local',
            's3_endpoint' => 'http://127.0.0.1:9000',
            's3_url' => 'https://cdn.example.com/assets',
        ]))->assertStatus(200);

        $this->settingsPost('', $this->driversSave(['redis_host' => 'tls://redis.local', 'memcached_host' => '127.0.0.1']))
            ->assertStatus(200);
    }

    /**
     * 메일 서버 호스트·Mailgun 주소는 저장과 발송 테스트 양쪽에서 같은 검사를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=metadata_address__mail
     *
     * @effects mail_host_follows_the_same_host_rules
     */
    public function test_mail_host_is_checked_on_save_and_on_test_send(): void
    {
        Mail::fake();

        $testMail = [
            'to_email' => 'to@example.com',
            'mailer' => 'smtp',
            'from_address' => 'from@example.com',
            'from_name' => 'G7',
            'port' => 587,
        ];

        $this->settingsPost('/test-mail', $testMail + ['host' => '169.254.169.254'])
            ->assertStatus(422)->assertJsonPath('errors.host.0', __('validation.settings.host_blocked'));
        $this->settingsPost('/test-mail', $testMail + ['host' => 'smtp.example.com/x'])
            ->assertStatus(422)->assertJsonPath('errors.host.0', __('validation.settings.host_format_invalid'));
        $this->settingsPost('/test-mail', ['mailer' => 'mailgun', 'mailgun_domain' => 'mg.example.com', 'mailgun_secret' => 'k', 'mailgun_endpoint' => '169.254.169.254'] + $testMail)
            ->assertStatus(422)->assertJsonValidationErrors(['mailgun_endpoint']);

        $mailSave = fn (array $overrides) => ['_tab' => 'mail', 'mail' => $overrides + [
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'from@example.com',
            'from_name' => 'G7',
        ]];

        $this->settingsPost('', $mailSave(['host' => 'smtp.example.com/x']))
            ->assertStatus(422)->assertJsonValidationErrors(['mail.host']);
        $this->settingsPost('', $mailSave(['host' => '169.254.169.254']))
            ->assertStatus(422)->assertJsonValidationErrors(['mail.host']);
        $this->settingsPost('', $mailSave(['mailgun_endpoint' => 'metadata.google.internal']))
            ->assertStatus(422)->assertJsonValidationErrors(['mail.mailgun_endpoint']);
        $this->settingsPost('', $mailSave(['host' => '127.0.0.1', 'mailgun_endpoint' => 'api.eu.mailgun.net']))
            ->assertStatus(200);

        // 서비스를 직접 불러도 막는다.
        $direct = app(SettingsService::class)->sendTestMail('to@example.com', ['mailer' => 'smtp', 'host' => '169.254.169.254', 'port' => 25]);
        $this->assertFalse($direct['success']);
        $this->assertSame(__('settings.connection_target_blocked'), $direct['error'] ?? $direct['message']);
    }

    /**
     * 프록시 주소가 메타데이터 주소면 저장 검증과 연결 테스트 양쪽에서 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=metadata_address__proxy
     *
     * @effects proxy_url_refuses_metadata_host
     */
    public function test_proxy_url_refuses_metadata_host(): void
    {
        $failures = [];
        (new ValidOutboundProxyUrl)->validate('outbound_proxy', 'http://169.254.169.254:3128', function (string $message) use (&$failures) {
            $failures[] = $message;
        });
        $this->assertSame([__('validation.settings.host_blocked')], $failures);

        $passes = [];
        (new ValidOutboundProxyUrl)->validate('outbound_proxy', 'http://10.0.0.8:3128', function (string $message) use (&$passes) {
            $passes[] = $message;
        });
        $this->assertSame([], $passes);

        $this->settingsPost('/test-outbound-proxy', ['outbound_proxy' => 'http://169.254.169.254:3128'])
            ->assertStatus(422)->assertJsonValidationErrors(['outbound_proxy']);

        $result = app(OutboundProxyTester::class)->test('http://169.254.169.254:3128');
        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }

    /**
     * 프록시 연결 테스트는 리다이렉트를 따라가지 않는다 — 프록시가 돌려준 응답이 가리키는 다른 주소에 닿지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=redirecting_endpoint__proxy_test
     *
     * @effects connection_test_does_not_follow_redirects
     */
    public function test_proxy_test_does_not_follow_redirects(): void
    {
        config(['core.outbound_proxy.egress_lookup_urls' => ['https://lookup.example.test/ip']]);

        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options[] = $requestOptions;

            return Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']);
        });

        app(OutboundProxyTester::class)->test('http://10.0.0.8:3128');

        // 응답 내용은 setUp 의 기본 가짜 응답이 정한다 — 여기서는 요청이 어떤 옵션으로 몇 번 나갔는지만 본다.
        $this->assertCount(1, $options);
        $this->assertFalse($options[0]['allow_redirects'] ?? null);
        Http::assertSentCount(1);
    }

    /**
     * 주소(URL) 칸도 숫자로 감춘 메타데이터 주소를 받지 않는다 — 호스트 칸과 같은 판정이다.
     *
     * `http://2852039166` 처럼 IP 를 숫자 하나·축약 표기·16진수로 쓰면 주소 대역 대조에 걸리지 않지만
     * 연결 계층은 그것을 IP 로 풀어 메타데이터 주소에 닿는다. 영역 표기가 붙은 IPv6 링크로컬도 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=numeric_disguised_address__url_field
     *
     * @effects url_fields_refuse_numeric_disguised_hosts
     */
    public function test_url_fields_refuse_numeric_disguised_metadata_hosts(): void
    {
        foreach (['http://2852039166', 'http://169.254.43518', 'http://0xA9FEA9FE', 'http://0251.0376.0251.0376'] as $url) {
            $this->settingsPost('/test-driver', ['driver' => 's3', 'storage_driver' => 's3', 's3_endpoint' => $url])
                ->assertStatus(422)->assertJsonValidationErrors(['s3_endpoint']);
            $this->settingsPost('/test-driver', ['driver' => 's3', 'storage_driver' => 's3', 's3_url' => $url.'/assets'])
                ->assertStatus(422)->assertJsonValidationErrors(['s3_url']);
            $this->settingsPost('', $this->driversSave(['s3_endpoint' => $url]))
                ->assertStatus(422)->assertJsonValidationErrors(['drivers.s3_endpoint']);

            $s3 = app(DriverConnectionTester::class)->testS3([
                's3_bucket' => 'b', 's3_region' => 'ap-northeast-2', 's3_access_key' => 'k', 's3_secret_key' => 's',
                's3_endpoint' => $url,
            ]);
            $this->assertFalse($s3['success'], $url);
            $this->assertSame(__('settings.connection_target_blocked'), $s3['error'] ?? $s3['message'], $url);
        }

        foreach (['http://2852039166:3128', 'http://169.254.43518:3128', 'http://0xA9FEA9FE:3128', 'http://[fe80::1%25eth0]:3128'] as $proxy) {
            $failures = [];
            (new ValidOutboundProxyUrl)->validate('outbound_proxy', $proxy, function (string $message) use (&$failures) {
                $failures[] = $message;
            });
            $this->assertCount(1, $failures, $proxy);

            $this->assertFalse(app(OutboundProxyTester::class)->test($proxy)['success'], $proxy);
        }
        Http::assertNothingSent();

        // 정상 주소는 그대로 통과한다 (사내 IP·한 단어 이름·0x 로 시작하는 도메인).
        foreach (['http://127.0.0.1:9000', 'http://minio:9000', 'https://0xcafe.example.com', 'https://s3.ap-northeast-2.amazonaws.com'] as $url) {
            $this->settingsPost('', $this->driversSave(['s3_endpoint' => $url]))->assertStatus(200);
        }
        foreach (['http://10.0.0.8:3128', 'http://proxy:3128'] as $proxy) {
            $passes = [];
            (new ValidOutboundProxyUrl)->validate('outbound_proxy', $proxy, function (string $message) use (&$passes) {
                $passes[] = $message;
            });
            $this->assertSame([], $passes, $proxy);
        }
    }

    /**
     * 정상 구성(localhost·사내 IP·공개 도메인)의 연결 테스트 요청은 형식 검사를 그대로 통과한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=private_or_public_address__connection_test
     *
     * @effects private_and_public_hosts_stay_allowed
     */
    public function test_ordinary_targets_still_pass_the_request_validation(): void
    {
        foreach (['localhost', '127.0.0.1', '192.168.0.10', 'ws.example.com'] as $host) {
            $response = $this->settingsPost('/test-driver', [
                'driver' => 'websocket',
                'websocket_enabled' => true,
                'websocket_host' => $host,
                'websocket_port' => 8080,
                'websocket_scheme' => 'http',
            ]);

            $this->assertNotSame(422, $response->status(), $host);
        }
    }

    /**
     * 메타데이터·링크로컬 호스트 목록.
     *
     * @return array<string, array{string}> 호스트 목록
     */
    public static function metadataHosts(): array
    {
        return [
            '메타데이터 IP' => ['169.254.169.254'],
            'IPv6 링크로컬' => ['[fe80::1]'],
            '알리바바 메타데이터' => ['100.100.100.200'],
            'GCP 메타데이터 이름' => ['metadata.google.internal'],
        ];
    }
}
