<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * Registered => SendEmailVerificationNotification 제거 (사문 코드: Registered 이벤트 미발생, MustVerifyEmail 미구현)
     * MessageSent => MailSendLogListener 제거 (훅 시스템으로 전환)
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [];

    /**
     * Register any events for your application.
     */
    public function register(): void
    {
        // Laravel 12 자동 발견(withEvents)으로 인한 app/Listeners/ 중복 등록 방지
        static::disableEventDiscovery();

        parent::register();
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
