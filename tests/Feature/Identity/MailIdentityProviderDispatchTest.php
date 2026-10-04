<?php

namespace Tests\Feature\Identity;

use App\Mail\IdentityMessageMail;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MailIdentityProvider + IdentityMessageDispatcher E2E 테스트.
 *
 * Challenge 요청 시 신규 IDV 메시지 시스템을 통해 메일이 발송되고
 * 변수 치환이 정상 동작하는지 검증.
 */
class MailIdentityProviderDispatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 권한·메일 메시지 정의를 시드하고 메일을 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityMessageDefinitionSeeder::class);
        Mail::fake();
    }

    /**
     * 가입 목적 발급은 코드가 치환된 메일을 보낸다.
     *
     * @scenario host_header=canonical, sink=mail_link
     *
     * @effects mail_identity_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_signup_purpose_sends_mail_with_code_substituted(): void
    {
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'signup',
            'target' => ['email' => 'newcomer@example.com'],
        ]);

        $response->assertStatus(201);

        Mail::assertSent(IdentityMessageMail::class, function (IdentityMessageMail $mail) {
            $envelope = $mail->envelope();
            // 시더의 signup 정의 subject 는 ko/en 둘 다 'Signup'/'회원가입' 키워드 포함
            $this->assertMatchesRegularExpression('/Signup|회원가입/u', $envelope->subject);

            // body 에 6자리 평문 코드가 들어가야 함
            $body = $this->extractBody($mail);
            $this->assertMatchesRegularExpression('/\b\d{6}\b/', $body, 'body 에 6자리 인증 코드가 포함되어야 합니다.');
            $this->assertStringNotContainsString('{code}', $body, '{code} 변수가 치환되어야 합니다.');
            $this->assertStringNotContainsString('{expire_minutes}', $body, '{expire_minutes} 변수가 치환되어야 합니다.');

            return true;
        });
    }

    /**
     * 비밀번호 재설정 목적 발급은 검증 링크가 든 메일을 보낸다.
     *
     * @scenario host_header=canonical, sink=mail_link
     *
     * @effects mail_identity_link_uses_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_password_reset_purpose_sends_mail_with_action_url(): void
    {
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'password_reset',
            'target' => ['email' => 'forgot@example.com'],
        ]);

        $response->assertStatus(201);

        Mail::assertSent(IdentityMessageMail::class, function (IdentityMessageMail $mail) {
            $body = $this->extractBody($mail);
            $this->assertStringNotContainsString('{action_url}', $body, '{action_url} 변수가 치환되어야 합니다.');
            $this->assertStringContainsString('http', $body, '본문에 검증 URL(http) 이 포함되어야 합니다.');

            return true;
        });
    }

    /**
     * 정의 없는 목적은 제공자 기본 메시지로 보낸다.
     *
     * @scenario host_header=canonical, sink=mail_link
     *
     * @effects mail_identity_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_unknown_purpose_falls_back_to_provider_default(): void
    {
        // 코어가 정의하지 않은 purpose 로 challenge 요청 → provider_default 정의로 fallback
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'sensitive_action',
            'target' => ['email' => 'fallback@example.com'],
        ]);

        $response->assertStatus(201);
        Mail::assertSent(IdentityMessageMail::class);
    }

    /**
     * 발송 성공은 인증 로그 상태를 sent 로 남긴다.
     *
     * @scenario host_header=canonical, sink=mail_link
     *
     * @effects mail_identity_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_dispatch_logs_status_sent_in_verification_logs(): void
    {
        $this->postJson('/api/identity/challenges', [
            'purpose' => 'signup',
            'target' => ['email' => 'sent@example.com'],
        ])->assertStatus(201);

        $this->assertDatabaseHas('identity_verification_logs', [
            'provider_id' => 'g7:core.mail',
            'purpose' => 'signup',
            'status' => 'sent',
        ]);
    }

    /**
     * IdentityMessageMail(부모 IdentityMessageMail) 의 렌더링된 본문을 추출합니다.
     *
     * @param  IdentityMessageMail  $mail  발송된 메일
     * @return string 렌더링된 본문
     */
    private function extractBody(IdentityMessageMail $mail): string
    {
        $reflection = new \ReflectionClass($mail);
        $parent = $reflection->getParentClass();
        $property = $parent->getProperty('renderedBody');
        $property->setAccessible(true);

        return (string) $property->getValue($mail);
    }

    /**
     * 위조 Host 요청 안에서 발송된 비밀번호 재설정 메일의 검증 링크는 사이트 주소를 쓴다.
     *
     * @scenario host_header=forged, sink=mail_link
     *
     * @effects mail_identity_link_uses_site_origin
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_password_reset_link_ignores_forged_host(string $root, array $server): void
    {
        if ($server !== []) {
            config(['trustedproxy.proxies' => '*']);
        }

        $this->withServerVariables($server)->postJson($root.'/api/identity/challenges', [
            'purpose' => 'password_reset',
            'target' => ['email' => 'forgot@example.com'],
        ])->assertStatus(201);

        Mail::assertSent(IdentityMessageMail::class, function (IdentityMessageMail $mail) {
            $body = $this->extractBody($mail);
            $this->assertSame(1, preg_match('#https?://[^\s"<>]*identity/verify[^\s"<>]*#', $body, $m), '본문에 검증 링크가 있어야 합니다.');
            $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/identity/verify?', $m[0]);

            return true;
        });
    }

    /**
     * 위조 Host 변종.
     *
     * @return array<string, array{0: string, 1: array<string, string>}> 요청 루트와 추가 서버 변수
     */
    public static function forgedHosts(): array
    {
        return [
            'external' => ['http://attacker.example', []],
            'subdomain' => ['http://attacker.localhost', []],
            'port' => ['http://localhost:6666', []],
            'forwarded' => ['http://localhost', ['HTTP_X_FORWARDED_HOST' => 'attacker.example', 'REMOTE_ADDR' => '10.0.0.5']],
        ];
    }
}
