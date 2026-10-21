<?php

// audit:allow job-generator-needs-scale-test 웹훅 1건(이벤트 ID 하나)만 처리하는 잡 — 반복·일괄 처리가 없어 대용량 축이 성립하지 않는다(대량 정리는 정리 배치가 chunkById 로 맡는다)

namespace Plugins\Sirsoft\SocialLogin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Plugins\Sirsoft\SocialLogin\Services\WebhookProcessor;
use Throwable;

/**
 * 받은 제공자 웹훅 1건을 처리하는 큐 잡 (기본 큐).
 *
 * 잡에는 이벤트 ID 만 싣는다 — 본문·제공자 사용자 ID 는 표에서 다시 읽는다(큐 표에 사본을 남기지 않는다). 처음 시도를 포함해 5번까지
 * 실행하고(다시 시도는 30초·2분·10분·30분 간격 4번 — `$backoff` 의 마지막 값은 쓰이지 않는다), 5번째도 실패하면 이벤트를 `failed` 로 남기고 관리자 알림 훅을 쏜다.
 *
 * @since 1.0.0
 */
class ProcessWebhookEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var int 최대 시도 횟수 */
    public int $tries = 5;

    /** @var array<int, int> 다시 시도 간격(초) */
    public array $backoff = [30, 120, 600, 1800, 3600];

    /**
     * @param  int  $eventId  웹훅 이벤트 ID
     */
    public function __construct(public readonly int $eventId)
    {
        $this->afterCommit = true;
    }

    /**
     * 이벤트를 처리합니다.
     *
     * @param  WebhookProcessor  $processor  처리 서비스
     * @return void 반환값 없음
     */
    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->eventId);
    }

    /**
     * 마지막 시도까지 실패했을 때 호출됩니다.
     *
     * @param  Throwable  $e  마지막 예외
     * @return void 반환값 없음
     */
    public function failed(Throwable $e): void
    {
        app(WebhookProcessor::class)->markFailed($this->eventId, class_basename($e).': '.mb_substr($e->getMessage(), 0, 200));
    }
}
