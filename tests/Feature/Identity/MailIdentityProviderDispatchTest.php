<?php

namespace Tests\Feature\Identity;

use App\Mail\IdentityMessageMail;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    protected function setUp(): void
    {
        parent::setUp();
        // PermissionMiddleware 가 IDV 라우트에 권한 가드 적용 — guest 역할 권한 sync 필수
        $this->seed(RolePermissionSeeder::class);
        $this->seed(IdentityMessageDefinitionSeeder::class);
        Mail::fake();
    }

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
     * @param  IdentityMessageMail  $mail
     * @return string
     */
    private function extractBody(IdentityMessageMail $mail): string
    {
        $reflection = new \ReflectionClass($mail);
        $parent = $reflection->getParentClass();
        $property = $parent->getProperty('renderedBody');
        $property->setAccessible(true);

        return (string) $property->getValue($mail);
    }
}
