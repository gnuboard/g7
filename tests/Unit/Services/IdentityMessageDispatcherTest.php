<?php

namespace Tests\Unit\Services;

use App\Mail\IdentityMessageMail;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use App\Services\IdentityMessageDispatcher;
use Database\Seeders\IdentityMessageDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * IdentityMessageDispatcher 단위 테스트.
 *
 * resolve 성공/실패 분기, 변수 치환, IdentityMessageMail 발송, 훅 발화 검증.
 */
class IdentityMessageDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private IdentityMessageDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityMessageDefinitionSeeder::class);
        $this->dispatcher = $this->app->make(IdentityMessageDispatcher::class);
        Mail::fake();
    }

    public function test_dispatch_sends_mail_when_resolved(): void
    {
        $result = $this->dispatcher->dispatch(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            renderHint: 'text_code',
            channel: 'mail',
            target: 'user@example.com',
            data: ['code' => '654321', 'expire_minutes' => 15, 'app_name' => 'TestApp'],
        );

        $this->assertTrue($result);
        Mail::assertSent(IdentityMessageMail::class, function (IdentityMessageMail $mail) {
            $envelope = $mail->envelope();
            $body = $this->extractBody($mail);
            $this->assertStringContainsString('654321', $body);
            $this->assertStringNotContainsString('{code}', $body);

            return true;
        });
    }

    public function test_dispatch_returns_false_when_no_definition_resolved(): void
    {
        IdentityMessageDefinition::query()->update(['is_active' => false]);
        $this->app->make(\App\Services\IdentityMessageDefinitionService::class)->invalidateAllCache();

        $result = $this->dispatcher->dispatch(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            renderHint: 'text_code',
            channel: 'mail',
            target: 'user@example.com',
            data: ['code' => '111111'],
        );

        $this->assertFalse($result);
        Mail::assertNothingSent();
    }

    public function test_dispatch_uses_purpose_definition_when_available(): void
    {
        $this->dispatcher->dispatch(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            renderHint: 'text_code',
            channel: 'mail',
            target: 'signup@example.com',
            data: ['code' => '999000', 'expire_minutes' => 15, 'app_name' => 'X'],
        );

        Mail::assertSent(IdentityMessageMail::class, function (IdentityMessageMail $mail) {
            $envelope = $mail->envelope();
            // signup purpose 시드 subject 에 'Signup' 또는 '회원가입' 포함
            $this->assertMatchesRegularExpression('/Signup|회원가입/u', $envelope->subject);

            return true;
        });
    }

    public function test_dispatch_falls_back_to_provider_default_when_purpose_inactive(): void
    {
        IdentityMessageDefinition::where('scope_value', 'signup')->update(['is_active' => false]);
        $this->app->make(\App\Services\IdentityMessageDefinitionService::class)->invalidateAllCache();

        $result = $this->dispatcher->dispatch(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            renderHint: 'text_code',
            channel: 'mail',
            target: 'fallback@example.com',
            data: ['code' => '222222', 'expire_minutes' => 15, 'app_name' => 'X'],
        );

        $this->assertTrue($result);
        Mail::assertSent(IdentityMessageMail::class);
    }

    public function test_dispatch_skips_when_template_inactive(): void
    {
        IdentityMessageTemplate::query()->update(['is_active' => false]);
        $this->app->make(\App\Services\IdentityMessageDefinitionService::class)->invalidateAllCache();

        $result = $this->dispatcher->dispatch(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            renderHint: 'text_code',
            channel: 'mail',
            target: 'user@example.com',
            data: ['code' => '111111'],
        );

        $this->assertFalse($result);
        Mail::assertNothingSent();
    }

    public function test_unsupported_channel_does_not_send_mail(): void
    {
        $result = $this->dispatcher->dispatch(
            providerId: 'g7:core.mail',
            purpose: 'signup',
            policyKey: null,
            renderHint: 'text_code',
            channel: 'sms', // mail 채널 외 — Filter 훅으로 위임, 발송 미이행
            target: '+821011112222',
            data: ['code' => '333333'],
        );

        // sms 정의 없음 → resolve 실패 → false
        $this->assertFalse($result);
        Mail::assertNothingSent();
    }

    private function extractBody(IdentityMessageMail $mail): string
    {
        $reflection = new \ReflectionClass($mail);

        // 부모 DbTemplateMail 의 private $renderedBody 추출
        $parent = $reflection->getParentClass();
        $property = $parent->getProperty('renderedBody');
        $property->setAccessible(true);

        return (string) $property->getValue($mail);
    }
}
