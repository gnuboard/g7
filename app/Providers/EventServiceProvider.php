<?php

namespace App\Providers;

use App\Services\Queue\QueueWorkerRegistry;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

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
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        // Laravel 12 자동 발견(withEvents)으로 인한 app/Listeners/ 중복 등록 방지
        static::disableEventDiscovery();

        parent::register();
    }

    /**
     * 큐 워커 생존 신호(하트비트) 리스너를 붙입니다 (#139).
     *
     * 워커 루프(`Looping` — 쉼표 큐 목록 포함)와 잡 시작(`JobProcessing` — `--once` 워커는 루프 이벤트가 없다)마다
     * 「이 연결·큐를 보는 워커가 살아 있다」를 캐시에 남긴다. 드라이버 탭 「큐 워커」 구역이 큐별로 표시한다.
     * 기록은 프로세스당 30초에 한 번이고, 실패해도 워커를 멈추지 않는다.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        parent::boot();

        Event::listen(Looping::class, function (Looping $event): void {
            app(QueueWorkerRegistry::class)->recordHeartbeat($event->connectionName, $event->queue);
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            app(QueueWorkerRegistry::class)->recordHeartbeat($event->connectionName, $event->job->getQueue());
        });
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool 자동 발견 여부 (항상 false)
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
