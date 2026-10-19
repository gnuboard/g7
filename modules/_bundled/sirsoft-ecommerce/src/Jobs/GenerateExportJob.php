<?php

// audit:allow job-generator-needs-scale-test 대량 처리 경로는 시나리오 매니페스트 `export.yaml` 의 scale 축(ExportScaleTest)이 덮는다 — 잡 자신은 작업 ID 를 서비스에 넘기기만 한다.

namespace Modules\Sirsoft\Ecommerce\Jobs;

use App\Services\Queue\QueueWorkerRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Throwable;

/**
 * 내보내기 파일 생성 큐 작업
 *
 * 작업 ID 만 들고 다니고, 무엇을 만들지는 실행 시점에 작업 행에서 다시 읽는다(서비스가 담당).
 * 재시도하지 않는다 — 이미 「진행중」으로 바뀐 행을 다시 집지 못하므로 재시도는 아무것도 하지 않고
 * 성공으로 끝나 행이 버려진 채 남는다. 실패는 `failed()` 가 행을 닫고, 프로세스가 죽은 경우는
 * 정리 스케줄(`sirsoft-ecommerce:prune-exports`)이 시간 초과로 닫는다.
 *
 * 생성은 수 분이 걸릴 수 있어, 코어의 긴 작업 연결·큐(`{기본 연결}-long`, 재예약 시간이 길다)가 등록돼 있으면 그쪽으로
 * 보낸다. 없으면 기본 연결로 가는데, 기본 연결의 재예약 시간(보통 90초)을 넘기면 다른 워커가 같은 작업을 다시 집어
 * 시도 횟수 초과로 실패시킨다 — 그 실패는 첫 실행이 아직 도는 중이라 행을 닫지 않는다(`failed()`).
 */
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int 시간 제한(초) — ExportService::JOB_TIMEOUT_SECONDS 와 같은 값
     */
    public int $timeout = ExportService::JOB_TIMEOUT_SECONDS;

    /**
     * @var int 시도 횟수 — 재시도하지 않는다
     */
    public int $tries = 1;

    /**
     * @var bool 시간 초과를 재시도 없이 실패로
     */
    public bool $failOnTimeout = true;

    /**
     * @param  int  $exportId  작업 ID
     */
    public function __construct(public int $exportId)
    {
        if (($placement = app(QueueWorkerRegistry::class)->longPlacement()) !== null) {
            $this->onConnection($placement['connection']);
            $this->onQueue($placement['queue']);
        }
    }

    /**
     * 파일을 만듭니다.
     *
     * @param  ExportService  $service  내보내기 서비스
     * @return void
     */
    public function handle(ExportService $service): void
    {
        $service->generate($this->exportId);
    }

    /**
     * 작업이 끝내 실패했을 때(시간 초과 등) 작업 행을 실패로 닫습니다.
     *
     * @param  Throwable|null  $exception  실패 원인
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        // 재예약으로 생긴 시도 횟수 초과는 첫 실행이 아직 도는 중이다 — 끝나면 스스로 완료·실패를 남기고, 프로세스가
        // 죽었으면 정리 스케줄이 시간 초과로 닫는다. 진짜 시간 초과(TimeoutExceededException 은 그 하위 클래스)는 닫는다.
        if ($exception instanceof MaxAttemptsExceededException && ! $exception instanceof TimeoutExceededException) {
            return;
        }

        app(ExportService::class)->markFailed($this->exportId, 'timeout');
    }
}
