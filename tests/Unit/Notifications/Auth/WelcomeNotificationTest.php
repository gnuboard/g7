<?php

namespace Tests\Unit\Notifications\Auth;

use App\Enums\ExtensionOwnerType;
use App\Mail\DbTemplateMail;
use App\Models\MailTemplate;
use App\Models\User;
use App\Notifications\Auth\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * WelcomeNotification 테스트
 *
 * 회원가입 완료 알림의 채널 결정 및 이메일 생성을 검증합니다.
 */
class WelcomeNotificationTest extends TestCase
{
    use RefreshDatabase;

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
        $notification = new WelcomeNotification();
        $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

        $channels = $notification->via($user);

        $this->assertContains('mail', $channels);
    }

    /**
     * toMail()이 DbTemplateMail을 반환하는지 확인
     */
    public function test_to_mail_returns_db_template_mail(): void
    {
        MailTemplate::factory()->withType('welcome')->create();

        $notification = new WelcomeNotification();
        $user = User::factory()->create(['email' => 'test@example.com', 'name' => 'Test User']);

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
    }

    /**
     * Mailable의 templateType이 welcome인지 확인
     */
    public function test_mailable_template_type_is_welcome(): void
    {
        MailTemplate::factory()->withType('welcome')->create();

        $notification = new WelcomeNotification();
        $user = User::factory()->create();

        $mailable = $notification->toMail($user);

        $this->assertEquals('welcome', $mailable->getTemplateType());
    }

    /**
     * Mailable의 module이 core인지 확인
     */
    public function test_mailable_module_is_core(): void
    {
        MailTemplate::factory()->withType('welcome')->create();

        $notification = new WelcomeNotification();
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
        MailTemplate::factory()->withType('welcome')->inactive()->create();

        $notification = new WelcomeNotification();
        $user = User::factory()->create();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());
    }

    /**
     * 템플릿 미존재 시 스킵 인스턴스 반환
     */
    public function test_to_mail_returns_skipped_when_no_template(): void
    {
        $notification = new WelcomeNotification();
        $user = User::factory()->create();

        $mailable = $notification->toMail($user);

        $this->assertInstanceOf(DbTemplateMail::class, $mailable);
        $this->assertTrue($mailable->isSkipped());
    }
}
