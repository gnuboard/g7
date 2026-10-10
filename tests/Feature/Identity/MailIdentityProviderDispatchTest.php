<?php

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * MailIdentityProvider + IdentityMessageDispatcher E2E 테스트.
 *
 * Challenge 요청 시 본인인증 메일이 알림 시스템(알림 정의 category=identity → GenericNotification)을
 * 거쳐 실제로 발송되고 변수 치환이 정상 동작하는지 검증한다. 메일은 array 전송으로 수집한다 —
 * 알림 메일 채널의 DbTemplateMail 은 Mail::fake() 에 기록되지 않는다.
 */
class MailIdentityProviderDispatchTest extends TestCase
{
    use CapturesSentMail;
    use RefreshDatabase;

    /**
     * 권한·본인인증 알림 정의를 시드하고 array 메일러로 발송을 수집합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        $this->prepareIdentityNotifications();
        $this->useArrayMailer();
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

        $mail = $this->onlyMailTo('newcomer@example.com');
        // 시더의 signup 정의 subject 는 ko/en 둘 다 'Signup'/'회원가입' 키워드 포함
        $this->assertMatchesRegularExpression('/Signup|회원가입/u', $mail['subject']);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', $mail['html'], 'body 에 6자리 인증 코드가 포함되어야 합니다.');
        $this->assertStringNotContainsString('{code}', $mail['html'], '{code} 변수가 치환되어야 합니다.');
        $this->assertStringNotContainsString('{expire_minutes}', $mail['html'], '{expire_minutes} 변수가 치환되어야 합니다.');
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

        $mail = $this->onlyMailTo('forgot@example.com');
        $this->assertStringNotContainsString('{action_url}', $mail['html'], '{action_url} 변수가 치환되어야 합니다.');
        $this->assertStringContainsString('http', $mail['html'], '본문에 검증 URL(http) 이 포함되어야 합니다.');
    }

    /**
     * 목적 정의가 없는 목적은 기본(identity.default) 정의로 보낸다.
     *
     * @scenario host_header=canonical, sink=mail_link
     *
     * @effects mail_identity_existing_flow_preserved
     *
     * @return void 반환값 없음
     */
    public function test_unknown_purpose_falls_back_to_provider_default(): void
    {
        // 코어가 정의하지 않은 purpose 로 challenge 요청 → identity.default 정의로 폴백
        $response = $this->postJson('/api/identity/challenges', [
            'purpose' => 'adult_verification',
            'target' => ['email' => 'fallback@example.com'],
            'provider_id' => 'g7:core.mail',
        ]);

        $response->assertStatus(201);
        // identity.default 의 제목 (게스트는 요청 로케일로 렌더 — 테스트 기본 로케일에 따라 ko/en)
        $this->assertMatchesRegularExpression(
            '/본인 확인 인증 코드|\] Verification Code$/u',
            $this->onlyMailTo('fallback@example.com')['subject']
        );
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

        $body = html_entity_decode($this->onlyMailTo('forgot@example.com')['html']);
        $this->assertSame(1, preg_match('#https?://[^\s"<>]*identity/verify[^\s"<>]*#', $body, $m), '본문에 검증 링크가 있어야 합니다.');
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/identity/verify?', $m[0]);
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

    /**
     * 수신자에게 간 메일이 정확히 1통인지 확인하고 그 메일을 반환합니다.
     *
     * @param  string  $to  수신 이메일
     * @return array{to: string, subject: string, html: string} 메일
     */
    private function onlyMailTo(string $to): array
    {
        $mails = array_values(array_filter($this->sentMails(), fn (array $m) => $m['to'] === $to));
        $this->assertCount(1, $mails, "{$to} 로 간 메일이 1통이어야 합니다.");

        return $mails[0];
    }
}
