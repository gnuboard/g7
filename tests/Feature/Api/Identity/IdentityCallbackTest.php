<?php

namespace Tests\Feature\Api\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * POST /api/identity/callback/{providerId} 외부 redirect 콜백 수신 테스트.
 *
 * 외부 IDV provider (PortOne/KCP/토스인증/Stripe Identity 등) 가 사용자 브라우저를 우리 서버로
 * 다시 보내는 콜백 진입점. provider 식별자 일치 검증, return URL 안전성 검증, verify 위임 동작 확인.
 *
 * @since engine-v1.46.0
 */
class IdentityCallbackTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    /**
     * 권한·메일 메시지 정의를 시드하고 알림·메일을 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 본인인증 메일도 알림이다(7.1.0) — Notification::fake() 는 그 발송 경로 자체를 건너뛰어
        // challenge 가 failed 가 된다. 메일 격리는 Mail::fake() 로 충분하다.
        // 발송 경로가 실제 메일 전송기(설정의 SMTP)를 타면 연결 실패로 발급이 502 가 된다 — 메일은 가짜로 받는다.
        Mail::fake();
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        // 메일 본인인증은 메시지 정의가 없으면 발송을 건너뛰고 502 로 끝난다 — 발급이 성공해야 콜백을 검증할 수 있다.
        $this->prepareIdentityNotifications();
    }

    /**
     * 알려진 코드로 해시 주입한 challenge 를 만들어 반환합니다 (verify 가능 상태).
     *
     * @param  string  $code  검증에 쓸 평문 코드
     * @return string 발급된 challenge ID
     */
    private function createReadyChallenge(string $code = '123456'): string
    {
        /** @var IdentityVerificationLogRepositoryInterface $repo */
        $repo = $this->app->make(IdentityVerificationLogRepositoryInterface::class);

        $request = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'user@example.com'],
        ]);
        $id = $request->json('data.id');

        $repo->updateById($id, [
            'metadata' => ['code_hash' => Hash::make($code)],
            'status' => IdentityVerificationStatus::Sent->value,
        ]);

        return $id;
    }

    /**
     * 존재하지 않는 challenge 콜백은 NOT_FOUND 실패로 끝난다.
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_callback_returns_404_for_unknown_challenge(): void
    {
        $response = $this->postJson('/api/identity/callback/g7:core.mail', [
            'challenge_id' => '00000000-0000-0000-0000-000000000000',
            'code' => '123456',
        ]);

        // failure → 422 (NOT_FOUND failure_code)
        $response->assertStatus(422)
            ->assertJsonPath('errors.failure_code', 'NOT_FOUND');
    }

    /**
     * challenge 의 provider 와 다른 provider 경로로 온 콜백은 거부된다.
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_callback_rejects_wrong_provider(): void
    {
        $id = $this->createReadyChallenge();

        $response = $this->postJson('/api/identity/callback/external.unknown', [
            'challenge_id' => $id,
            'code' => '123456',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.failure_code', 'WRONG_PROVIDER');
    }

    /**
     * 올바른 코드의 콜백은 검증 토큰을 JSON 으로 돌려준다.
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_callback_with_correct_code_returns_token_json(): void
    {
        $id = $this->createReadyChallenge();

        $response = $this->postJson('/api/identity/callback/g7:core.mail', [
            'challenge_id' => $id,
            'code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['challenge_id', 'provider_id', 'verified_at', 'verification_token'],
            ])
            ->assertJsonPath('success', true);

        $log = IdentityVerificationLog::find($id);
        $this->assertSame(IdentityVerificationStatus::Verified, $log->status);
    }

    /**
     * 상대 경로 복귀 주소로는 토큰을 쿼리에 실어 이동한다.
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_redirects_on_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_callback_with_safe_return_url_redirects_with_token_query(): void
    {
        $id = $this->createReadyChallenge();

        // 상대 경로 — 항상 안전 (same-origin)
        $response = $this->post(
            '/api/identity/callback/g7:core.mail?return=/auth/register',
            [
                'challenge_id' => $id,
                'code' => '123456',
            ],
        );

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/auth/register', $location);
        $this->assertStringContainsString('verification_token=', $location);
        $this->assertStringContainsString('challenge_id='.$id, $location);
    }

    /**
     * 프로토콜 상대 복귀 주소는 이동하지 않고 JSON 으로 응답한다.
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_refuses_foreign_return_url
     *
     * @return void 반환값 없음
     */
    public function test_callback_blocks_protocol_relative_redirect_open_redirect_attack(): void
    {
        $id = $this->createReadyChallenge();

        // 프로토콜 상대 URL (`//evil.example.com`) 은 open redirect 시도 — JSON 응답으로 폴백되어야 함
        $response = $this->post(
            '/api/identity/callback/g7:core.mail?return=//evil.example.com/phish',
            [
                'challenge_id' => $id,
                'code' => '123456',
            ],
        );

        // redirect 가 아닌 JSON 응답
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['verification_token']]);
    }

    /**
     * 실패한 콜백은 안전한 복귀 주소로 오류 코드를 실어 이동한다.
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_redirects_on_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_callback_failure_with_safe_return_url_redirects_with_error(): void
    {
        $id = $this->createReadyChallenge();

        $response = $this->post(
            '/api/identity/callback/g7:core.mail?return=/auth/register',
            [
                'challenge_id' => $id,
                'code' => 'wrong-code',
            ],
        );

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/auth/register', $location);
        $this->assertStringContainsString('identity_error=', $location);
    }

    /**
     * challenge_id 가 없으면 422 다.
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_callback_validates_required_challenge_id(): void
    {
        $response = $this->postJson('/api/identity/callback/g7:core.mail', [
            'code' => '123456',
        ]);

        $response->assertStatus(422);
    }

    /**
     * challenge_id 를 쿼리로 받아도 검증한다.
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_callback_accepts_challenge_id_from_query_string(): void
    {
        $id = $this->createReadyChallenge();

        // OAuth 스타일 — query 에 challenge_id 동봉
        $response = $this->postJson(
            "/api/identity/callback/g7:core.mail?challenge_id={$id}",
            ['code' => '123456'],
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * 브라우저와 PHP 해석이 갈리거나 사이트 출처가 아닌 복귀 주소.
     *
     * 사이트 주소는 테스트 환경 APP_URL(`http://localhost`)이다.
     *
     * @return array<string, array{0: string}> 거부돼야 하는 복귀 주소
     */
    public static function unsafeReturnUrls(): array
    {
        return [
            'backslash path' => ['/\\evil.example.com'],
            'decoded tab' => ["/\t/evil.example.com"],
            'protocol relative' => ['//evil.example.com'],
            'backslash userinfo' => ['http://evil.example.com\\@localhost/'],
            'userinfo' => ['http://user@localhost/'],
            'scheme differs' => ['https://localhost/'],
            'port differs' => ['http://localhost:8443/'],
            'subdomain' => ['http://attacker.localhost/'],
        ];
    }

    /**
     * 사이트 출처가 아닌 복귀 주소로는 토큰을 실어 보내지 않는다(JSON 폴백).
     *
     * @scenario host_header=canonical, sink=identity_callback
     *
     * @effects identity_callback_refuses_foreign_return_url
     *
     * @param  string  $returnUrl  복귀 주소
     * @return void 반환값 없음
     */
    #[DataProvider('unsafeReturnUrls')]
    public function test_callback_refuses_foreign_return_url(string $returnUrl): void
    {
        $id = $this->createReadyChallenge();

        $response = $this->post(
            '/api/identity/callback/g7:core.mail?'.http_build_query(['return' => $returnUrl]),
            ['challenge_id' => $id, 'code' => '123456'],
        );

        $this->assertFalse($response->isRedirection(), 'Location: '.$response->headers->get('Location'));
        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    /**
     * 위조 Host 변종(사이트 주소 Host 행 포함).
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 요청 루트와 추가 서버 변수
     */
    public static function forgedHosts(): array
    {
        return [
            'canonical' => ['http://localhost', []],
            'external' => ['http://attacker.example', []],
            'subdomain' => ['http://attacker.localhost', []],
            'port' => ['http://localhost:6666', []],
            'forwarded' => ['http://localhost', ['HTTP_X_FORWARDED_HOST' => 'attacker.example', 'REMOTE_ADDR' => '10.0.0.5']],
        ];
    }

    /**
     * 상대·같은 출처 복귀 주소는 요청 Host 가 아니라 사이트 주소로 절대화해 보낸다.
     *
     * @scenario host_header=forged, sink=identity_callback
     *
     * @effects identity_callback_redirects_on_site_origin
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_callback_return_redirect_uses_site_origin(string $root, array $server): void
    {
        if ($server !== []) {
            config(['trustedproxy.proxies' => '*']);
        }
        $id = $this->createReadyChallenge();

        $response = $this->withServerVariables($server)->post(
            $root.'/api/identity/callback/g7:core.mail?'.http_build_query(['return' => '/auth/register?x=1']),
            ['challenge_id' => $id, 'code' => '123456'],
        );

        $response->assertRedirect();
        $this->assertStringStartsWith('http://localhost/auth/register?x=1&verification_token=', (string) $response->headers->get('Location'));

        $id2 = $this->createReadyChallenge();
        $response = $this->withServerVariables($server)->post(
            $root.'/api/identity/callback/g7:core.mail?'.http_build_query(['return' => 'http://localhost/ok']),
            ['challenge_id' => $id2, 'code' => '123456'],
        );
        $this->assertStringStartsWith('http://localhost/ok?verification_token=', (string) $response->headers->get('Location'));
    }
}
