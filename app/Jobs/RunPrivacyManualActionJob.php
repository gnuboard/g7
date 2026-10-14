<?php

// audit:allow job-generator-needs-scale-test 이 잡은 데이터를 적재하지 않고 기록 1건의 대상 러너에 위임한다 — 대량 처리는
// 기존 보유기간 핸들러·휴면 서비스의 chunkById 순회(자동 배치와 같은 코드)가 담당하고, 규모 초과로 시간이 넘으면
// failOnTimeout 이 기록을 실패로 닫으며 처리가 멱등이라 재실행이 이어서 처리한다. 잡이 도입한 규모 의존 경로 없음.
// #139 에서 더한 것은 큐 배치(긴 작업 큐)·단일 실행 가드뿐이다 — 처리 규모 경로 무변경.

namespace App\Jobs;

use App\Privacy\ManualRun\PrivacyManualRunService;
use App\Services\Queue\QueueWorkerRegistry;
use App\Support\Queue\JobSingleFlight;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 개인정보 조치 관리자 직권 실행 큐 잡
 *
 * 관리자 화면의 「지금 실행」이 접수한 기록 하나를 처리한다. 잡은 기록 ID 만 들고 다니고,
 * 무엇을 처리할지는 실행 시점에 대상 수집기에서 키로 다시 찾는다(서비스가 담당).
 *
 * 새벽 배치가 같은 대상을 처리하고 있으면(공유 락 경합) 잡을 되돌려 1분 뒤 다시 시도한다.
 * 재시도 마감({@see retryUntil()})이 지나면 실패로 기록된다.
 *
 * 다른 워커가 같은 잡을 다시 집으면(실행이 retry_after 를 넘긴 경우) 그 사본은 단일 실행 잠금에 막혀
 * 서비스를 부르지 않고 조용히 끝난다(#139). 긴 작업 연결·큐가 등록돼 있으면 그쪽으로 보낸다.
 */
class RunPrivacyManualActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int 타임아웃 (초) — 대량 파기·발송에 대비해 넉넉히 둔다
     */
    public int $timeout = 1800;

    /**
     * @var bool 시간 초과를 재시도 없이 실패로 — 재시도하면 이미 「처리 중」인 기록을 건너뛰고 성공으로
     *           끝나 기록이 버려진 채 남는다. 실패로 두어야 {@see failed()} 가 기록을 닫는다.
     */
    public bool $failOnTimeout = true;

    /**
     * @var int 락 경합 시 다시 시도하기까지 기다리는 시간 (초)
     */
    public const RETRY_DELAY_SECONDS = 60;

    /**
     * @var int 기본 큐에서의 재시도 마감 (분)
     */
    public const RETRY_UNTIL_MINUTES = 120;

    /**
     * @var int 긴 작업 큐에서의 재시도 마감 (분) — 긴 작업 뒤에서 기다리는 시간까지 넉넉히 둔다(#139).
     *          버려진 기록 판정({@see PrivacyManualRunService::STALE_AFTER_MINUTES} = 360)보다 짧아야 한다.
     */
    public const LONG_QUEUE_RETRY_UNTIL_MINUTES = 240;

    /**
     * @var string 디스패치 때 만든 단일 실행 ID — 옛 payload 는 '' 로 읽혀 가드 없이 종전처럼 돈다
     */
    public string $dispatchId = '';

    /**
     * @var bool 긴 작업 큐로 배치됐는지 — 재시도 마감 길이를 정한다
     */
    public bool $longPlaced = false;

    /**
     * @param  int  $runId  실행 기록 ID
     */
    public function __construct(public int $runId)
    {
        $this->dispatchId = (string) Str::uuid();

        if (($placement = app(QueueWorkerRegistry::class)->longPlacement()) !== null) {
            $this->onConnection($placement['connection']);
            $this->onQueue($placement['queue']);
            $this->longPlaced = true;
        }
    }

    /**
     * 재시도 마감 시각 — 락 경합으로 되돌리는 횟수는 이 시각까지로 제한한다.
     *
     * 라라벨은 이 값을 **디스패치 시점**에 payload 에 굳힌다. 실패 처리 후 버려진 기록 판정 기준
     * ({@see PrivacyManualRunService::STALE_AFTER_MINUTES})보다 짧아야 한다 — 그래야 기록이 버려진 것으로 닫히기
     * 전에 잡이 스스로 실패를 남긴다. 긴 작업 큐는 앞선 긴 작업(최장 1시간) 뒤에서 기다릴 수 있어 더 길게 준다.
     *
     * @return \DateTime 재시도 마감 시각
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes($this->longPlaced ? self::LONG_QUEUE_RETRY_UNTIL_MINUTES : self::RETRY_UNTIL_MINUTES)->toDateTime();
    }

    /**
     * 직권 실행을 수행합니다.
     *
     * 새벽 배치와 락이 겹치면(busy) 단일 실행 잠금을 먼저 놓은 뒤 잡을 되돌린다 — 잠금을 쥔 채 되돌리면
     * 다음 시도가 자기 잠금에 막힌다.
     *
     * @param  PrivacyManualRunService  $service  직권 실행 서비스
     * @return void
     */
    public function handle(PrivacyManualRunService $service): void
    {
        $result = null;

        if ($this->dispatchId === '') {
            $result = $service->execute($this->runId);
        } else {
            $ran = JobSingleFlight::run(
                JobSingleFlight::key(self::class, $this->dispatchId),
                $this->timeout + 120,
                function () use ($service, &$result): void {
                    $result = $service->execute($this->runId);
                },
            );

            if (! $ran) {
                Log::info('개인정보 직권 실행 잡: 다른 워커가 아직 실행 중인 사본이라 조용히 끝냅니다', [
                    'run_id' => $this->runId,
                    'dispatch_id' => $this->dispatchId,
                ]);

                return;
            }
        }

        if ($result === 'busy') {
            $this->release(self::RETRY_DELAY_SECONDS);
        }
    }

    /**
     * 잡이 끝내 실패했을 때(시간 초과·재시도 마감) 기록을 실패로 닫습니다.
     *
     * 재예약이 거듭돼 시도가 다 찬 사본은 원본이 아직 도는 중이라 닫지 않는다(#139).
     *
     * @param  \Throwable  $exception  실패 원인
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        if ($exception instanceof MaxAttemptsExceededException
            && ! $exception instanceof TimeoutExceededException
            && $this->dispatchId !== ''
            && JobSingleFlight::isHeld(JobSingleFlight::key(self::class, $this->dispatchId))) {
            Log::info('개인정보 직권 실행 잡: 다른 워커가 아직 실행 중인 사본의 시도 초과라 기록을 닫지 않습니다', [
                'run_id' => $this->runId,
                'dispatch_id' => $this->dispatchId,
            ]);

            return;
        }

        // 기록의 실패 사유는 관리자 화면에 그대로 보인다 — 운영 모드에서는 내부 예외 원문을 일반 문구로 바꾼다.
        app(PrivacyManualRunService::class)->failFromJob(
            $this->runId,
            PrivacyManualRunService::failureReason($exception, ['run_id' => $this->runId]),
        );
    }
}
