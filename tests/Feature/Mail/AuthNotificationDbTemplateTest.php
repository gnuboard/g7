<?php

namespace Tests\Feature\Mail;

use App\Mail\DbTemplateMail;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 인증 알림 → DbTemplateMail 통합 테스트
 *
 * GenericNotification.toMail()이 notification_templates에서 템플릿을 조회하여
 * DbTemplateMail을 올바르게 생성하는지, 비활성/미존재 시 스킵되는지 검증합니다.
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
    // Welcome 알림 + DbTemplateMail
    // ========================================================================

    /**
     * welcome 알림이 활성 템플릿으로 DbTemplateMail 생성
     */
    public function test_welcome_notification_creates_db_template_mail(): void
    {
        $this->createDefinitionWithMailChannel('welcome', 'core.auth', 'core', 'core', [
            'subject' => ['ko' => '[{app_name}] 환영합니다', 'en' => '[{app_name}] Welcome'],
            'body' => ['ko' => '<p>{name}님 환영합니다</p>', 'en' => '<p>Welcome {name}</p>'],
        ]);

        $user = User::factory()->create(['name' => '홍길동', 'email' => 'hong@example.com']);

        $notification = new GenericNotification('welcome', 'core.auth', [
            'name' => $user->name,
            'app_name' => config('app.name'),
            'action_url' => config('app.url') . '/login',
            'site_url' => config('app.url'),
        ]);

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertEquals('welcome', $mailable->getTemplateType());
        $this->assertFalse($mailable->isSkipped());
    }

    /**
     * welcome 알림의 DbTemplateMail에 수신자가 설정됨
     */
    public function test_welcome_notification_sets_recipient(): void
    {
        $this->createDefinitionWithMailChannel('welcome', 'core.auth', 'core', 'core', [
            'subject' => ['ko' => '환영', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>환영</p>', 'en' => '<p>Welcome</p>'],
        ]);

        $user = User::factory()->create(['name' => '홍길동', 'email' => 'hong@example.com']);

        $notification = new GenericNotification('welcome', 'core.auth', [
            'name' => '홍길동',
            'app_name' => 'G7 Test',
            'action_url' => 'https://g7.test/login',
            'site_url' => 'https://g7.test',
        ]);

        $mailable = $notification->toMail($user);

        $to = collect($mailable->to)->first();
        $this->assertEquals('hong@example.com', $to['address']);
        $this->assertEquals('홍길동', $to['name']);
    }

    /**
     * welcome 알림이 템플릿 미존재 시 스킵 인스턴스 반환
     */
    public function test_welcome_notification_returns_skipped_when_no_template(): void
    {
        $user = User::factory()->create(['email' => 'notemplate@example.com']);

        $notification = new GenericNotification('welcome', 'core.auth', [
            'name' => $user->name ?? '',
            'app_name' => 'G7 Test',
            'action_url' => 'https://g7.test/login',
            'site_url' => 'https://g7.test',
        ]);

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());
    }

    // ========================================================================
    // Reset Password 알림 + DbTemplateMail
    // ========================================================================

    /**
     * reset_password 알림이 활성 템플릿으로 DbTemplateMail 생성
     */
    public function test_reset_password_notification_creates_db_template_mail(): void
    {
        $this->createDefinitionWithMailChannel('reset_password', 'core.auth', 'core', 'core', [
            'subject' => ['ko' => '비밀번호 재설정', 'en' => 'Password Reset'],
            'body' => ['ko' => '<p>{name}님, 비밀번호 재설정</p>', 'en' => '<p>Reset password, {name}</p>'],
        ]);

        $user = User::factory()->create();

        $notification = new GenericNotification('reset_password', 'core.auth', [
            'name' => $user->name ?? '',
            'app_name' => 'G7 Test',
            'action_url' => 'https://g7.test/reset-password?token=abc',
            'expire_minutes' => '60',
            'site_url' => 'https://g7.test',
        ]);

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertEquals('reset_password', $mailable->getTemplateType());
    }

    // ========================================================================
    // Password Changed 알림 + DbTemplateMail
    // ========================================================================

    /**
     * password_changed 알림이 활성 템플릿으로 DbTemplateMail 생성
     */
    public function test_password_changed_notification_creates_db_template_mail(): void
    {
        $this->createDefinitionWithMailChannel('password_changed', 'core.auth', 'core', 'core', [
            'subject' => ['ko' => '비밀번호 변경', 'en' => 'Password Changed'],
            'body' => ['ko' => '<p>{name}님, 비밀번호 변경됨</p>', 'en' => '<p>Password changed, {name}</p>'],
        ]);

        $user = User::factory()->create();

        $notification = new GenericNotification('password_changed', 'core.auth', [
            'name' => $user->name ?? '',
            'app_name' => 'G7 Test',
            'action_url' => 'https://g7.test/login',
            'site_url' => 'https://g7.test',
        ]);

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertEquals('password_changed', $mailable->getTemplateType());
    }

    /**
     * password_changed 알림이 비활성 템플릿에서 스킵 반환
     */
    public function test_password_changed_notification_returns_skipped_when_inactive(): void
    {
        $this->createDefinitionWithMailChannel('password_changed', 'core.auth', 'core', 'core', [
            'subject' => ['ko' => '비밀번호 변경', 'en' => 'Password Changed'],
            'body' => ['ko' => '<p>변경됨</p>', 'en' => '<p>Changed</p>'],
        ], templateActive: false);

        $user = User::factory()->create(['email' => 'changed@example.com']);

        $notification = new GenericNotification('password_changed', 'core.auth', [
            'name' => $user->name ?? '',
            'app_name' => 'G7 Test',
            'action_url' => 'https://g7.test/login',
            'site_url' => 'https://g7.test',
        ]);

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());
    }

    // ========================================================================
    // 헬퍼 메서드
    // ========================================================================

    /**
     * NotificationDefinition + NotificationTemplate(mail 채널) 생성 헬퍼.
     *
     * @param string $type
     * @param string $hookPrefix
     * @param string $extensionType
     * @param string $extensionIdentifier
     * @param array $templateData ['subject' => [...], 'body' => [...]]
     * @param bool $templateActive
     * @return NotificationDefinition
     */
    private function createDefinitionWithMailChannel(
        string $type,
        string $hookPrefix,
        string $extensionType,
        string $extensionIdentifier,
        array $templateData,
        bool $templateActive = true,
    ): NotificationDefinition {
        $definition = NotificationDefinition::create([
            'type' => $type,
            'hook_prefix' => $hookPrefix,
            'extension_type' => $extensionType,
            'extension_identifier' => $extensionIdentifier,
            'name' => ['ko' => $type, 'en' => $type],
            'variables' => [],
            'channels' => ['mail'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => $templateData['subject'],
            'body' => $templateData['body'],
            'is_active' => $templateActive,
            'is_default' => true,
        ]);

        app(NotificationDefinitionService::class)->invalidateCache($type);
        app(NotificationTemplateService::class)->invalidateCache($type, 'mail');

        return $definition;
    }
}
