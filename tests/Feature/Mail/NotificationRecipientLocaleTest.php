<?php

namespace Tests\Feature\Mail;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\GenericNotification;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 알림 수신자 언어(locale) 결정 회귀 테스트 (A7)
 *
 * 알림 렌더는 요청자(app locale) 가 아닌 수신자 본인 언어(users.language)로
 * 결정되어야 합니다. User 가 HasLocalePreference 를 구현하고, 렌더 3지점
 * (toMail / toArray / Dispatcher) 이 BaseNotification::resolveNotifiableLocale()
 * 헬퍼로 수신자 선호 로케일을 우선 해석하는지 검증합니다.
 */
class NotificationRecipientLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('app.name', 'G7 Test');
        Config::set('app.url', 'https://g7.test');
        Config::set('app.supported_locales', ['ko', 'en']);
    }

    /**
     * User::preferredLocale() 이 users.language 를 SSoT 로 반환한다.
     */
    public function test_preferred_locale_returns_user_language(): void
    {
        $user = User::factory()->create(['language' => 'en']);

        $this->assertSame('en', $user->preferredLocale());
    }

    /**
     * language 가 미지원/빈값이면 사이트 기본 로케일로 폴백한다.
     */
    public function test_preferred_locale_falls_back_to_default(): void
    {
        Config::set('app.locale', 'ko');

        $unsupported = User::factory()->create(['language' => 'fr']);
        $this->assertSame('ko', $unsupported->preferredLocale());

        // language 가 빈값(null) 인 수신자 — language 컬럼은 NOT NULL 이므로 모델 객체로 검증
        $empty = new User(['language' => null]);
        $this->assertSame('ko', $empty->preferredLocale());
    }

    /**
     * 헬퍼는 HasLocalePreference 수신자의 preferredLocale() 을 우선한다.
     */
    public function test_helper_prefers_recipient_locale_over_app_locale(): void
    {
        App::setLocale('en'); // 요청자/관리자 컨텍스트 = en

        $recipient = User::factory()->create(['language' => 'ko']);

        $this->assertSame('ko', BaseNotification::resolveNotifiableLocale($recipient));
    }

    /**
     * contract 미구현 수신자는 현재 app locale 로 폴백한다.
     */
    public function test_helper_falls_back_for_non_contract_notifiable(): void
    {
        App::setLocale('en');

        $anonymous = new \Illuminate\Notifications\AnonymousNotifiable;

        $this->assertSame('en', BaseNotification::resolveNotifiableLocale($anonymous));
    }

    /**
     * toMail: 요청자 locale=en 이어도 수신자 language=ko 면 ko 본문으로 렌더.
     */
    public function test_to_mail_renders_in_recipient_language(): void
    {
        $this->createDefinitionWithMailChannel('order_test', 'sirsoft-ecommerce', 'module', 'sirsoft-ecommerce', [
            'subject' => ['ko' => '주문 안내', 'en' => 'Order notice'],
            'body' => ['ko' => '<p>한국어 본문</p>', 'en' => '<p>English body</p>'],
        ]);

        App::setLocale('en'); // 관리자(요청자) 컨텍스트

        $recipient = User::factory()->create(['language' => 'ko', 'email' => 'ko@example.com']);

        $notification = new GenericNotification('order_test', 'sirsoft-ecommerce', []);
        $mailable = $notification->toMail($recipient);

        $this->assertSame('주문 안내', $mailable->envelope()->subject);
        $this->assertStringContainsString('한국어 본문', $mailable->content()->with['body']);
    }

    /**
     * toArray(database): 수신자 language=ko 면 ko 본문으로 렌더.
     */
    public function test_to_array_renders_in_recipient_language(): void
    {
        $this->createDefinitionWithDatabaseChannel('order_test_db', 'sirsoft-ecommerce', 'module', 'sirsoft-ecommerce', [
            'subject' => ['ko' => '주문 알림', 'en' => 'Order alert'],
            'body' => ['ko' => '한국어 알림 본문', 'en' => 'English alert body'],
        ]);

        App::setLocale('en');

        $recipient = User::factory()->create(['language' => 'ko']);

        $notification = new GenericNotification('order_test_db', 'sirsoft-ecommerce', []);
        $payload = $notification->toArray($recipient);

        $this->assertSame('주문 알림', $payload['subject']);
        $this->assertStringContainsString('한국어 알림 본문', $payload['body']);
    }

    private function createDefinitionWithMailChannel(
        string $type,
        string $hookPrefix,
        string $extensionType,
        string $extensionIdentifier,
        array $templateData,
    ): NotificationDefinition {
        return $this->createDefinition($type, $hookPrefix, $extensionType, $extensionIdentifier, 'mail', $templateData);
    }

    private function createDefinitionWithDatabaseChannel(
        string $type,
        string $hookPrefix,
        string $extensionType,
        string $extensionIdentifier,
        array $templateData,
    ): NotificationDefinition {
        return $this->createDefinition($type, $hookPrefix, $extensionType, $extensionIdentifier, 'database', $templateData);
    }

    private function createDefinition(
        string $type,
        string $hookPrefix,
        string $extensionType,
        string $extensionIdentifier,
        string $channel,
        array $templateData,
    ): NotificationDefinition {
        $definition = NotificationDefinition::create([
            'type' => $type,
            'hook_prefix' => $hookPrefix,
            'extension_type' => $extensionType,
            'extension_identifier' => $extensionIdentifier,
            'name' => ['ko' => $type, 'en' => $type],
            'variables' => [],
            'channels' => [$channel],
            'hooks' => [],
            'is_active' => true,
            'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => $channel,
            'subject' => $templateData['subject'],
            'body' => $templateData['body'],
            'is_active' => true,
            'is_default' => true,
        ]);

        app(NotificationDefinitionService::class)->invalidateCache($type);
        app(NotificationTemplateService::class)->invalidateCache($type, $channel);

        return $definition;
    }
}
