<?php

namespace App\Jobs\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\ArchiveRunStatus;
use App\Enums\Analytics\ArchiveRunType;
use App\Services\Analytics\ArchiveService;
use App\Services\Analytics\StaleRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * 기간 재집계 잡
 *
 * 관리자 화면의 「다시 집계」 버튼이 부릅니다. 몇 달치를 다시 계산하면 요청 하나가
 * 몇 분씩 걸리므로 워커에게 넘깁니다. 화면은 진행률 방송을 받아 실행 이력을 다시 읽고,
 * 방송이 꺼진 사이트에서는 실행 중인 동안만 이력을 주기적으로 다시 읽습니다.
 */
class ArchiveRangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 최대 실행 시간 (초)
     *
     * 몇 달치 재집계가 기본 타임아웃(60초)에 잘리면 이력이 running 인 채로 남는다.
     */
    public int $timeout = 3600;

    /**
     * 재시도 횟수
     *
     * 집계는 멱등이지만, 실패 원인이 대개 데이터·설정이라 자동 재시도로는 풀리지
     * 않는다. 실패를 이력에 남기고 운영자가 다시 누르게 한다.
     */
    public int $tries = 1;

    /**
     * 잡을 큐에 올린 시각 (UTC, Y-m-d H:i:s)
     *
     * 잡은 자기가 만든 실행 이력 번호를 모른다 — 실패했을 때 이 시각 이후에 같은 유형·같은
     * 요청자로 시작한 「실행 중」 행을 자기 몫으로 찾아 닫는다.
     */
    private readonly string $queuedAt;

    /**
     * 잡을 만듭니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  bool  $force  확정된 날도 다시 집계할지 여부
     * @param  int|null  $triggeredBy  수동 실행한 관리자 ID
     */
    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly bool $force = false,
        private readonly ?int $triggeredBy = null,
    ) {
        $this->queuedAt = AnalyticsClock::now()->toDateTimeString();
    }

    /**
     * 잡을 실행합니다.
     *
     * @param  ArchiveService  $service  집계 서비스
     * @return void 없음
     */
    public function handle(ArchiveService $service): void
    {
        $runs = $service->archiveRange($this->from, $this->to, [
            'run_type' => ArchiveRunType::Reaggregate->value,
            'trigger' => 'manual',
            'force' => $this->force,
            'triggered_by' => $this->triggeredBy,
        ]);

        $failed = 0;

        foreach ($runs as $run) {
            // `status` 는 열거형으로 캐스팅된다 — 문자열과 비교하면 영원히 거짓이다.
            $status = $run->status instanceof ArchiveRunStatus ? $run->status : ArchiveRunStatus::tryFrom((string) $run->status);

            if ($status === ArchiveRunStatus::Failed) {
                $failed++;
            }
        }

        // 서비스는 하루가 실패해도 다음 날로 넘어가고 예외를 올리지 않는다(이력에는
        // 남는다). 그대로 두면 90일 중 30일이 실패해도 잡은 성공으로 끝나 `failed_jobs`
        // 에 아무것도 남지 않는다 — 큐만 보는 운영자에게는 조용히 끝난 것으로 보인다.
        // 커맨드가 종료 코드로 알리는 것과 같은 신호를 잡에서도 낸다.
        if ($failed > 0) {
            throw new RuntimeException(sprintf(
                '기간 재집계 %s~%s 중 %d일이 실패했다. 실행 이력에서 사유를 확인하라.',
                $this->from,
                $this->to,
                $failed,
            ));
        }
    }

    /**
     * 잡이 실패했을 때(예외·제한시간 초과) 이 잡이 맡은 「실행 중」 이력을 실패로 닫습니다.
     *
     * 닫지 않으면 그 행이 남아 진단 화면의 수동 집계 버튼을 잠근다(#682 S3 라운드 13).
     * 워커가 강제 종료돼 이 훅조차 돌지 못한 행은 매시 집계가 기준 시간 뒤에 닫는다.
     *
     * @param  Throwable  $exception  실패 원인
     * @return void 없음
     */
    public function failed(Throwable $exception): void
    {
        // 이 잡이 맡은 기간의 행만 닫는다 — 같은 관리자가 다른 기간을 따로 재집계하는 중일 수 있다.
        [$periodStart] = AnalyticsClock::dayRangeUtc($this->from);
        [, $periodEnd] = AnalyticsClock::dayRangeUtc($this->to);

        app(StaleRunService::class)->failOrphansOf(
            ArchiveRunType::Reaggregate->value,
            Carbon::parse($this->queuedAt, 'UTC'),
            $this->triggeredBy,
            $exception->getMessage(),
            [$periodStart, $periodEnd],
        );
    }
}
