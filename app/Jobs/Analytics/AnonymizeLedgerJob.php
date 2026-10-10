<?php

namespace App\Jobs\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\ArchiveRunType;
use App\Services\Analytics\AnonymizeService;
use App\Services\Analytics\StaleRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 소급 익명화 잡
 *
 * 개인정보 수준을 엄격하게 바꾼 운영자가 「기존 기록에도 적용」을 고르면 부릅니다.
 * 쌓인 방문이 많으면 오래 걸리므로 워커에게 넘기고 진행률만 화면에 보입니다.
 */
class AnonymizeLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 최대 실행 시간 (초)
     */
    public int $timeout = 3600;

    /**
     * 재시도 횟수
     *
     * 익명화는 되돌릴 수 없는 변경이라 자동 재시도로 같은 구간을 두 번 훑지 않는다.
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
     * @param  int  $maskOctets  가릴 주소 옥텟 수 (0~3)
     * @param  bool  $hash  주소를 지우고 해시만 남길지 여부
     * @param  string|null  $from  시작일 (Y-m-d)
     * @param  string|null  $to  종료일 (Y-m-d)
     * @param  int|null  $triggeredBy  실행한 관리자 ID
     */
    public function __construct(
        private readonly int $maskOctets,
        private readonly bool $hash,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?int $triggeredBy = null,
    ) {
        $this->queuedAt = AnalyticsClock::now()->toDateTimeString();
    }

    /**
     * 잡을 실행합니다.
     *
     * @param  AnonymizeService  $service  익명화 서비스
     * @return void 없음
     */
    public function handle(AnonymizeService $service): void
    {
        $service->anonymize([
            'mask_octets' => $this->maskOctets,
            'hash' => $this->hash,
            'from' => $this->from,
            'to' => $this->to,
            'trigger' => 'manual',
            'triggered_by' => $this->triggeredBy,
        ]);
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
        app(StaleRunService::class)->failOrphansOf(
            ArchiveRunType::Anonymize->value,
            Carbon::parse($this->queuedAt, 'UTC'),
            $this->triggeredBy,
            $exception->getMessage(),
        );
    }
}
