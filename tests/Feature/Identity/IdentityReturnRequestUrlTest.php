<?php

namespace Tests\Feature\Identity;

use App\Exceptions\IdentityVerificationRequiredException;
use App\Http\Middleware\EnforceIdentityPolicy;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\IdentityPolicy;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 본인인증 요구(428)의 재요청 주소가 요청 Host 없이 경로로만 실리는지 검증합니다.
 *
 * 클라이언트는 인증을 마치면 이 주소로 원 요청을 다시 보내며 인증 헤더와 본인인증 토큰을 싣는다.
 * 요청 Host·scheme 을 담은 절대 주소면 그 값이 어긋날 때 교차 출처로 인증 정보가 나간다.
 */
class IdentityReturnRequestUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 정책을 시드합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityPolicySeeder::class);
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * 위조 Host 변종.
     *
     * 사이트 주소 Host(canonical) 행이 함께 들어 있다 — 정상 Host 에서도 같은 값을 내는지 본다.
     *
     * @scenario host_header=canonical, sink=identity_retry
     *
     * @effects identity_retry_url_is_path_only
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 요청 루트와 추가 서버 변수
     */
    public static function forgedHosts(): array
    {
        return [
            'canonical' => ['http://localhost', []],
            'external' => ['https://attacker.example', []],
            'subdomain' => ['https://attacker.localhost', []],
            'port' => ['http://localhost:6666', []],
            'forwarded' => ['http://10.0.0.5', ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'attacker.example', 'HTTP_X_FORWARDED_PROTO' => 'https']],
        ];
    }

    /**
     * 위조 요청을 만듭니다.
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return Request 위조 요청
     */
    private function forged(string $root, array $server): Request
    {
        if (isset($server['HTTP_X_FORWARDED_HOST'])) {
            Request::setTrustedProxies(['10.0.0.5'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        }

        return Request::create($root.'/api/test?x=1', 'POST', ['email' => 'victim@example.com'], [], [], $server);
    }

    /**
     * 라우트 정책(미들웨어) 428 의 재요청 주소는 경로+쿼리다.
     *
     * @scenario host_header=forged, sink=identity_retry
     *
     * @effects identity_retry_url_is_path_only
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_route_policy_return_url_is_path_only(string $root, array $server): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => true]);

        try {
            $this->app->make(EnforceIdentityPolicy::class)
                ->handle($this->forged($root, $server), fn () => new Response('ok'), 'core.auth.signup_before_submit');
            $this->fail('정책이 428 을 던져야 합니다.');
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame('/api/test?x=1', $e->getPayload()['return_request']['url'] ?? null);
        }
    }

    /**
     * 훅 정책(리스너)의 재요청 주소도 경로+쿼리다.
     *
     * @scenario host_header=forged, sink=identity_retry
     *
     * @effects identity_retry_url_is_path_only
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_hook_policy_return_url_is_path_only(string $root, array $server): void
    {
        $this->app->instance('request', $this->forged($root, $server));

        $listener = $this->app->make(EnforceIdentityPolicyListener::class);
        $method = new \ReflectionMethod($listener, 'resolveReturnRequest');
        $method->setAccessible(true);

        $this->assertSame(['method' => 'POST', 'url' => '/api/test?x=1'], $method->invoke($listener));
    }
}
