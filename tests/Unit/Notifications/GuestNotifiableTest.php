<?php

namespace Tests\Unit\Notifications;

use App\Contracts\Notifications\GuestRecipientInterface;
use App\Notifications\GuestNotifiable;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * GuestNotifiable 테스트
 *
 * 비회원 1급 수신자 값 객체의 계약(Notifiable + HasLocalePreference + GuestRecipientInterface),
 * fromContext 유효성, 합성 키, 로케일 폴백을 검증합니다.
 */
class GuestNotifiableTest extends TestCase
{
    public function test_implements_required_contracts(): void
    {
        $guest = new GuestNotifiable('guest@example.com', '홍길동', 'ko');

        $this->assertInstanceOf(HasLocalePreference::class, $guest);
        $this->assertInstanceOf(GuestRecipientInterface::class, $guest);
        $this->assertContains(Notifiable::class, class_uses($guest));
        $this->assertTrue($guest->isGuest());
    }

    public function test_from_context_builds_valid_recipient(): void
    {
        $guest = GuestNotifiable::fromContext([
            'email' => 'guest@example.com',
            'name' => '홍길동',
            'locale' => 'ko',
        ]);

        $this->assertNotNull($guest);
        $this->assertSame('guest@example.com', $guest->email);
        $this->assertSame('홍길동', $guest->name);
    }

    public function test_from_context_returns_null_for_empty_email(): void
    {
        $this->assertNull(GuestNotifiable::fromContext(['email' => '', 'name' => 'X']));
        $this->assertNull(GuestNotifiable::fromContext(['name' => 'X']));
    }

    public function test_from_context_returns_null_for_invalid_email(): void
    {
        $this->assertNull(GuestNotifiable::fromContext(['email' => 'not-an-email']));
    }

    public function test_get_key_is_deterministic_and_case_insensitive(): void
    {
        $a = new GuestNotifiable('Guest@Example.com');
        $b = new GuestNotifiable('guest@example.com');

        $this->assertSame($a->getKey(), $b->getKey());
        $this->assertStringStartsWith('guest:', $a->getKey());
    }

    public function test_route_notification_for_mail_uses_name_when_present(): void
    {
        $withName = new GuestNotifiable('guest@example.com', '홍길동');
        $this->assertSame(['guest@example.com' => '홍길동'], $withName->routeNotificationForMail());

        $withoutName = new GuestNotifiable('guest@example.com');
        $this->assertSame('guest@example.com', $withoutName->routeNotificationForMail());
    }

    public function test_preferred_locale_returns_supported_locale(): void
    {
        $guest = new GuestNotifiable('guest@example.com', null, 'en');
        $this->assertSame('en', $guest->preferredLocale());
    }

    public function test_preferred_locale_returns_null_for_unsupported_or_empty(): void
    {
        $this->assertNull((new GuestNotifiable('guest@example.com', null, 'xx'))->preferredLocale());
        $this->assertNull((new GuestNotifiable('guest@example.com'))->preferredLocale());
    }

    public function test_resolve_notifiable_locale_falls_back_to_app_locale(): void
    {
        App::setLocale('en');

        // locale 미지정 게스트 → preferredLocale() null → app locale 폴백
        $guest = new GuestNotifiable('guest@example.com');
        $this->assertSame('en', \App\Notifications\BaseNotification::resolveNotifiableLocale($guest));

        // locale 지정 게스트 → 그 값 우선
        $ko = new GuestNotifiable('guest@example.com', null, 'ko');
        $this->assertSame('ko', \App\Notifications\BaseNotification::resolveNotifiableLocale($ko));
    }
}
