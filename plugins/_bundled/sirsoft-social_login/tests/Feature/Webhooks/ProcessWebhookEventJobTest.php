<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature\Webhooks;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Queue;
use Plugins\Sirsoft\SocialLogin\Jobs\ProcessWebhookEventJob;
use Plugins\Sirsoft\SocialLogin\Models\SocialLoginWebhookEvent;

/**
 * 웹훅 처리 잡 — 기본 큐·5회·간격·커밋 뒤 실행, 수신은 잡을 예약만 하고 처리는 잡이 한다, 마지막 실패 → `failed` + 관리자 알림 훅,
 * 이미 끝난 이벤트는 다시 처리하지 않는다.
 *
 * @since 1.0.0
 */
class ProcessWebhookEventJobTest extends WebhookTestCase
{
    /**
     * 카카오를 IP 확인 없이 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureProvider('kakao', [
            'client_id' => 'k-id', 'client_secret' => 'k-secret', 'admin_key' => 'kakao-admin-key',
            'extra' => ['webhook_ip_allowlist_enabled' => false],
        ]);
    }

    /**
     * 수신은 잡을 기본 큐에 예약하고(5회, 30초~1시간 간격, 커밋 뒤) 이벤트는 `received` 로 남는다. 잡을 돌리면 처리된다.
     *
     * @scenario queue=faked
     *
     * @effects job_queued_on_default, job_retry_contract, event_received_until_job_runs, job_processes_event
     *
     * @return void 반환값 없음
     */
    public function test_receive_queues_job(): void
    {
        Queue::fake();
        $this->linkedMember('kakao', 'q-1');

        $this->withHeaders(['Authorization' => 'KakaoAK kakao-admin-key'])
            ->post('/plugins/sirsoft-social_login/webhooks/kakao', ['user_id' => 'q-1', 'referrer_type' => 'UNLINK_FROM_APPS'])
            ->assertOk();

        $event = SocialLoginWebhookEvent::query()->where('provider_user_id', 'q-1')->firstOrFail();
        $this->assertSame('received', $event->status);

        $job = null;
        Queue::assertPushed(ProcessWebhookEventJob::class, function (ProcessWebhookEventJob $pushed) use ($event, &$job) {
            $job = $pushed;

            return $pushed->eventId === $event->id;
        });
        $this->assertSame([5, [30, 120, 600, 1800, 3600], true, null], [$job->tries, $job->backoff, $job->afterCommit, $job->queue]);

        app()->call([$job, 'handle']);
        $this->assertSame('processed', $event->refresh()->status);
        $this->assertSame(1, $event->attempts);

        app()->call([$job, 'handle']);
        $this->assertSame(1, $event->refresh()->attempts);
    }

    /**
     * 마지막 시도까지 실패하면 `failed` 와 오류 요약이 남고 관리자 알림 훅이 나간다. 처리 끝난 이벤트는 실패로 바꾸지 않는다.
     *
     * @scenario retries=exhausted|already_processed
     *
     * @effects failed_job_marks_event, webhook_failed_hook, processed_event_not_overwritten
     *
     * @return void 반환값 없음
     */
    public function test_failed_job_marks_event(): void
    {
        $fired = [];
        HookManager::addAction('sirsoft-social_login.webhook.failed', function (array $summary) use (&$fired) {
            $fired[] = $summary;
        });

        $event = SocialLoginWebhookEvent::query()->create([
            'provider_key' => 'kakao', 'event_key' => 'kakao:fail:'.uniqid(), 'event_type' => 'unlink', 'provider_user_id' => 'f-1',
            'payload' => [], 'status' => 'received', 'attempts' => 5, 'received_at' => now(),
        ]);

        (new ProcessWebhookEventJob($event->id))->failed(new \RuntimeException('provider gone'));

        $event->refresh();
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('provider gone', (string) $event->error);
        $this->assertSame([$event->id, 'kakao'], [$fired[0]['event_id'], $fired[0]['provider_key']]);

        $event->forceFill(['status' => 'processed', 'error' => null])->save();
        (new ProcessWebhookEventJob($event->id))->failed(new \RuntimeException('late'));
        $this->assertSame('processed', $event->refresh()->status);
    }
}
