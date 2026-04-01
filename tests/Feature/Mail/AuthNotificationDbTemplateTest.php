<?php

namespace Tests\Feature\Mail;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Models\MailSendLog;
use App\Models\MailTemplate;
use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 인증 알림 → DbTemplateMail 통합 테스트
 *
 * Notification.toMail()이 DB 템플릿을 해석하여 DbTemplateMail을 올바르게 생성하는지,
 * 비활성 템플릿일 때 logSkipped가 기록되는지 검증합니다.
 */
class AuthNotificationDbTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('app.name', 'G7 Test');
        Config::set('app.url', 'https://g7.test');
    }

    // ========================================================================
    // WelcomeNotification + DbTemplateMail
    // ========================================================================

    /**
     * WelcomeNotification이 활성 템플릿으로 DbTemplateMail 생성
     */
    public function test_welcome_notification_creates_db_template_mail(): void
    {
        MailTemplate::factory()->withType('welcome')->withVariables()->create();

        $user = User::factory()->create(['name' => '홍길동', 'email' => 'hong@example.com']);
        $notification = new WelcomeNotification();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertEquals('welcome', $mailable->getTemplateType());
        $this->assertEquals(ExtensionOwnerType::Core, $mailable->getExtensionType());
        $this->assertEquals('core', $mailable->getExtensionIdentifier());
    }

    /**
     * WelcomeNotification의 DbTemplateMail에 수신자가 설정됨
     */
    public function test_welcome_notification_sets_recipient(): void
    {
        MailTemplate::factory()->withType('welcome')->withVariables()->create();

        $user = User::factory()->create(['name' => '홍길동', 'email' => 'hong@example.com']);
        $notification = new WelcomeNotification();

        $mailable = $notification->toMail($user);

        $to = collect($mailable->to)->first();
        $this->assertEquals('hong@example.com', $to['address']);
        $this->assertEquals('홍길동', $to['name']);
    }

    /**
     * WelcomeNotification이 비활성 템플릿에서 스킵 인스턴스 반환 + send() 시 logSkipped 기록
     */
    public function test_welcome_notification_returns_skipped_and_logs_when_inactive(): void
    {
        MailTemplate::factory()->withType('welcome')->inactive()->create();

        $user = User::factory()->create(['email' => 'test@example.com']);
        $notification = new WelcomeNotification();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());

        $mailable->send(app('mailer'));

        $log = MailSendLog::where('recipient_email', 'test@example.com')
            ->where('template_type', 'welcome')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('skipped', $log->status);
        $this->assertEquals('notification', $log->source);
    }

    /**
     * WelcomeNotification이 템플릿 미존재 시 스킵 인스턴스 반환 + send() 시 logSkipped 기록
     */
    public function test_welcome_notification_returns_skipped_when_no_template(): void
    {
        $user = User::factory()->create(['email' => 'notemplate@example.com']);
        $notification = new WelcomeNotification();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());

        $mailable->send(app('mailer'));

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'notemplate@example.com',
            'template_type' => 'welcome',
            'status' => 'skipped',
        ]);
    }

    // ========================================================================
    // ResetPasswordNotification + DbTemplateMail
    // ========================================================================

    /**
     * ResetPasswordNotification이 활성 템플릿으로 DbTemplateMail 생성
     */
    public function test_reset_password_notification_creates_db_template_mail(): void
    {
        MailTemplate::factory()->withType('reset_password')->create();

        $user = User::factory()->create();
        $notification = new ResetPasswordNotification('test-token-123');

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertEquals('reset_password', $mailable->getTemplateType());
    }

    /**
     * ResetPasswordNotification의 DbTemplateMail에 수신자가 설정됨
     */
    public function test_reset_password_notification_sets_recipient(): void
    {
        MailTemplate::factory()->withType('reset_password')->create();

        $user = User::factory()->create(['email' => 'reset@example.com', 'name' => 'Reset User']);
        $notification = new ResetPasswordNotification('test-token');

        $mailable = $notification->toMail($user);

        $to = collect($mailable->to)->first();
        $this->assertEquals('reset@example.com', $to['address']);
    }

    /**
     * ResetPasswordNotification이 비활성 시 스킵 인스턴스 반환 + send() 시 logSkipped 기록
     */
    public function test_reset_password_notification_logs_skipped_when_inactive(): void
    {
        MailTemplate::factory()->withType('reset_password')->inactive()->create();

        $user = User::factory()->create(['email' => 'reset@example.com']);
        $notification = new ResetPasswordNotification('token');

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());

        $mailable->send(app('mailer'));

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'reset@example.com',
            'template_type' => 'reset_password',
            'status' => 'skipped',
        ]);
    }

    // ========================================================================
    // PasswordChangedNotification + DbTemplateMail
    // ========================================================================

    /**
     * PasswordChangedNotification이 활성 템플릿으로 DbTemplateMail 생성
     */
    public function test_password_changed_notification_creates_db_template_mail(): void
    {
        MailTemplate::factory()->withType('password_changed')->create();

        $user = User::factory()->create();
        $notification = new PasswordChangedNotification();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertEquals('password_changed', $mailable->getTemplateType());
    }

    /**
     * PasswordChangedNotification이 비활성 시 스킵 인스턴스 반환 + send() 시 logSkipped 기록
     */
    public function test_password_changed_notification_logs_skipped_when_inactive(): void
    {
        MailTemplate::factory()->withType('password_changed')->inactive()->create();

        $user = User::factory()->create(['email' => 'changed@example.com']);
        $notification = new PasswordChangedNotification();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());

        $mailable->send(app('mailer'));

        $this->assertDatabaseHas('mail_send_logs', [
            'recipient_email' => 'changed@example.com',
            'template_type' => 'password_changed',
            'status' => 'skipped',
        ]);
    }

    // ========================================================================
    // DbTemplateMail 속성 검증
    // ========================================================================

    /**
     * DbTemplateMail의 커스텀 헤더가 올바르게 설정됨
     */
    public function test_db_template_mail_has_custom_headers(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Test Subject',
            renderedBody: '<p>Test</p>',
            recipientEmail: 'test@example.com',
            templateType: 'welcome',
            extensionType: ExtensionOwnerType::Core,
            extensionIdentifier: 'core',
            source: 'notification',
        );

        $headers = $mail->headers();
        $textHeaders = $headers->text;

        $this->assertEquals('welcome', $textHeaders['X-G7-Template-Type']);
        $this->assertEquals('core', $textHeaders['X-G7-Extension-Type']);
        $this->assertEquals('core', $textHeaders['X-G7-Extension-Id']);
        $this->assertEquals('notification', $textHeaders['X-G7-Source']);
    }

    /**
     * DbTemplateMail의 getter 메서드 검증
     */
    public function test_db_template_mail_getters(): void
    {
        $mail = new DbTemplateMail(
            renderedSubject: 'Subject',
            renderedBody: '<p>Body</p>',
            recipientEmail: 'test@example.com',
            templateType: 'reset_password',
            extensionType: ExtensionOwnerType::Module,
            extensionIdentifier: 'sirsoft-board',
            source: 'test_mail',
        );

        $this->assertEquals('reset_password', $mail->getTemplateType());
        $this->assertEquals(ExtensionOwnerType::Module, $mail->getExtensionType());
        $this->assertEquals('sirsoft-board', $mail->getExtensionIdentifier());
        $this->assertEquals('test_mail', $mail->getSource());
    }
}
