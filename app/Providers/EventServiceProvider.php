<?php

namespace App\Providers;

use App\Extension\HookContextCapture;
use App\Listeners\Queue\RecordQueueJobLog;
use App\Listeners\Scheduling\RecordServerCronEventRun;
use App\Services\Queue\QueueWorkerRegistry;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * 이벤트 → 리스너 매핑 (비워 둔다 — 훅 시스템과 boot() 의 직접 등록을 쓴다).
     *
     * Registered => SendEmailVerificationNotification 제거 (사문 코드: Registered 이벤트 미발생, MustVerifyEmail 미구현)
     * MessageSent => MailSendLogListener 제거 (훅 시스템으로 전환)
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [];

    /**
     * 이벤트 서비스를 등록합니다 (자동 발견을 끄고 처리 기록 리스너를 싱글턴으로 둔다).
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        // Laravel 12 자동 발견(withEvents)으로 인한 app/Listeners/ 중복 등록 방지
        static::disableEventDiscovery();

        parent::register();

        // 처리 기록 리스너는 시작 시각·sync 버퍼를 이벤트 사이에 공유한다 (#17)
        $this->app->singleton(RecordQueueJobLog::class);
    }

    /**
     * 큐 워커 생존 신호(하트비트)·작업 처리 기록 리스너를 붙입니다 (#139·#17).
     *
     * 워커 루프(`Looping` — 쉼표 큐 목록 포함)와 잡 시작(`JobProcessing` — `--once` 워커는 루프 이벤트가 없다)마다
     * 「이 연결·큐를 보는 워커가 살아 있다」를 캐시에 남긴다. 드라이버 탭 「큐 워커」 구역이 큐별로 표시한다.
     * 기록은 프로세스당 30초에 한 번이고, 실패해도 워커를 멈추지 않는다.
     *
     * 작업 처리 기록(queue_job_logs)은 시작·완료·최종 실패 세 이벤트로 남긴다(RecordQueueJobLog).
     * 잡을 집을 때마다 요청 문맥(인증 사용자·요청·로케일)을 워커 기본 상태로 되돌린다(HookContextCapture::resetForQueueJob).
     *
     * 서버 크론(`schedule:run`)이 돌린 예약 작업은 웹 러너와 같은 실행 기록에 남긴다(RecordServerCronEventRun) —
     * 그래야 서버 크론과 웹 러너를 함께 쓰는 사이트에서 웹 러너의 소급 창이 같은 작업을 다시 돌리지 않는다.
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

        // 상주 워커는 컨테이너를 잡 사이에 재사용한다 — 잡을 집을 때마다 인증 사용자·요청·로케일을 워커 기본
        // 상태로 돌려, 앞 잡이 남긴 사용자가 다음 잡의 활동 로그 행위자·권한 판정에 쓰이지 않게 한다.
        // sync 연결 잡은 웹 요청 안에서 돌므로 그 요청의 문맥을 건드리지 않는다.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                HookContextCapture::resetForQueueJob();
            }
        });

        Event::listen(JobProcessing::class, [RecordQueueJobLog::class, 'handleProcessing']);
        Event::listen(JobProcessed::class, [RecordQueueJobLog::class, 'handleProcessed']);
        Event::listen(JobFailed::class, [RecordQueueJobLog::class, 'handleFailed']);

        Event::listen(ScheduledTaskFinished::class, [RecordServerCronEventRun::class, 'handleFinished']);
        Event::listen(ScheduledTaskFailed::class, [RecordServerCronEventRun::class, 'handleFailed']);
    }

    /**
     * 이벤트·리스너 자동 발견 여부를 반환합니다.
     *
     * @return bool 자동 발견 여부 (항상 false)
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
