<?php

// audit:allow job-generator-needs-scale-test 이 잡은 데이터를 적재하지 않고 기록 1건의 대상 러너에 위임한다 — 대량 처리는
// 기존 보유기간 핸들러·휴면 서비스의 chunkById 순회(자동 배치와 같은 코드)가 담당하고, 규모 초과로 시간이 넘으면
// failOnTimeout 이 기록을 실패로 닫으며 처리가 멱등이라 재실행이 이어서 처리한다. 잡이 도입한 규모 의존 경로 없음.

namespace App\Jobs;

use App\Privacy\ManualRun\PrivacyManualRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 개인정보 조치 관리자 직권 실행 큐 잡
 *
 * 관리자 화면의 「지금 실행」이 접수한 기록 하나를 처리한다. 잡은 기록 ID 만 들고 다니고,
 * 무엇을 처리할지는 실행 시점에 대상 수집기에서 키로 다시 찾는다(서비스가 담당).
 *
 * 새벽 배치가 같은 대상을 처리하고 있으면(공유 락 경합) 잡을 되돌려 1분 뒤 다시 시도한다.
 * 재시도 마감({@see retryUntil()})이 지나면 실패로 기록된다.
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
     * @param  int  $runId  실행 기록 ID
     */
    public function __construct(public int $runId) {}

    /**
     * 재시도 마감 시각 — 락 경합으로 되돌리는 횟수는 이 시각까지로 제한한다.
     *
     * 실패 처리 후 버려진 기록 판정 기준({@see PrivacyManualRunService::STALE_AFTER_MINUTES})보다
     * 짧아야 한다 — 그래야 기록이 버려진 것으로 닫히기 전에 잡이 스스로 실패를 남긴다.
     *
     * @return \DateTime 재시도 마감 시각
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(120)->toDateTime();
    }

    /**
     * 직권 실행을 수행합니다.
     *
     * @param  PrivacyManualRunService  $service  직권 실행 서비스
     * @return void
     */
    public function handle(PrivacyManualRunService $service): void
    {
        if ($service->execute($this->runId) === 'busy') {
            $this->release(self::RETRY_DELAY_SECONDS);
        }
    }

    /**
     * 잡이 끝내 실패했을 때(시간 초과·재시도 마감) 기록을 실패로 닫습니다.
     *
     * @param  \Throwable  $exception  실패 원인
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        // 기록의 실패 사유는 관리자 화면에 그대로 보인다 — 운영 모드에서는 내부 예외 원문을 일반 문구로 바꾼다.
        app(PrivacyManualRunService::class)->failFromJob(
            $this->runId,
            PrivacyManualRunService::failureReason($exception, ['run_id' => $this->runId]),
        );
    }
}
