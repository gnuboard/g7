<?php

namespace Tests\Unit\Notifications\Auth;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Models\MailTemplate;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * ResetPasswordNotification 테스트
 *
 * 비밀번호 재설정 알림의 채널 결정 및 이메일 생성을 검증합니다.
 */
class ResetPasswordNotificationTest extends TestCase
{
    use RefreshDatabase;

    private string $testToken = 'test-reset-token-12345';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('app.name', 'G7 Test');
        Config::set('app.url', 'https://g7.test');
    }

    /**
     * via()가 기본적으로 mail 채널을 반환하는지 확인
     */
    public function test_via_returns_mail_channel_by_default(): void
    {
        $notification = new ResetPasswordNotification($this->testToken);
        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $channels = $notification->via($user);

        $this->assertContains('mail', $channels);
    }

    /**
     * toMail()이 DbTemplateMail을 반환하는지 확인
     */
    public function test_to_mail_returns_db_template_mail(): void
    {
        MailTemplate::factory()->withType('reset_password')->create();

        $notification = new ResetPasswordNotification($this->testToken);
        $user = User::factory()->create();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
    }

    /**
     * Mailable의 templateType이 reset_password인지 확인
     */
    public function test_mailable_template_type_is_reset_password(): void
    {
        MailTemplate::factory()->withType('reset_password')->create();

        $notification = new ResetPasswordNotification($this->testToken);
        $user = User::factory()->create();

        $mailable = $notification->toMail($user);

        $this->assertEquals('reset_password', $mailable->getTemplateType());
    }

    /**
     * Mailable의 module이 core인지 확인
     */
    public function test_mailable_module_is_core(): void
    {
        MailTemplate::factory()->withType('reset_password')->create();

        $notification = new ResetPasswordNotification($this->testToken);
        $user = User::factory()->create();

        $mailable = $notification->toMail($user);

        $this->assertEquals(ExtensionOwnerType::Core, $mailable->getExtensionType());
        $this->assertEquals('core', $mailable->getExtensionIdentifier());
    }

    /**
     * 비활성 템플릿에서 스킵 인스턴스 반환
     */
    public function test_to_mail_returns_skipped_when_template_inactive(): void
    {
        MailTemplate::factory()->withType('reset_password')->inactive()->create();

        $notification = new ResetPasswordNotification($this->testToken);
        $user = User::factory()->create();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());
    }

    /**
     * getToken()이 토큰을 반환하는지 확인
     */
    public function test_get_token_returns_token(): void
    {
        $notification = new ResetPasswordNotification($this->testToken);

        $this->assertEquals($this->testToken, $notification->getToken());
    }

    /**
     * action_url 변수를 포함하는 비밀번호 재설정 템플릿을 생성합니다.
     */
    private function createResetPasswordTemplate(): void
    {
        MailTemplate::factory()->withType('reset_password')->create([
            'subject' => [
                'ko' => '비밀번호 재설정',
                'en' => 'Reset Password',
            ],
            'body' => [
                'ko' => '<p><a href="{action_url}">비밀번호 재설정</a></p>',
                'en' => '<p><a href="{action_url}">Reset Password</a></p>',
            ],
        ]);
    }

    /**
     * redirect_prefix 없이 기본 /reset-password URL 생성
     */
    public function test_to_mail_generates_default_reset_url(): void
    {
        $this->createResetPasswordTemplate();

        $notification = new ResetPasswordNotification($this->testToken);
        $user = User::factory()->create(['email' => 'user@example.com']);

        $mailable = $notification->toMail($user);

        // renderedBody는 생성자 파라미터이므로 Reflection으로 접근
        $ref = new \ReflectionProperty($mailable, 'renderedBody');
        $body = $ref->getValue($mailable);
        $this->assertStringContainsString('/reset-password?', $body);
        $this->assertStringNotContainsString('/admin/reset-password?', $body);
    }

    /**
     * redirect_prefix='admin' 시 /admin/reset-password URL 생성
     */
    public function test_to_mail_generates_admin_reset_url_with_redirect_prefix(): void
    {
        $this->createResetPasswordTemplate();

        $notification = new ResetPasswordNotification($this->testToken, 'admin');
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $mailable = $notification->toMail($user);

        $ref = new \ReflectionProperty($mailable, 'renderedBody');
        $body = $ref->getValue($mailable);
        $this->assertStringContainsString('/admin/reset-password?', $body);
    }

    /**
     * redirect_prefix=null 시 기본 URL (하위 호환성)
     */
    public function test_to_mail_null_redirect_prefix_uses_default_url(): void
    {
        $this->createResetPasswordTemplate();

        $notification = new ResetPasswordNotification($this->testToken, null);
        $user = User::factory()->create(['email' => 'test@example.com']);

        $mailable = $notification->toMail($user);

        $ref = new \ReflectionProperty($mailable, 'renderedBody');
        $body = $ref->getValue($mailable);
        $this->assertStringContainsString('/reset-password?', $body);
        $this->assertStringNotContainsString('/admin/reset-password?', $body);
    }
}
