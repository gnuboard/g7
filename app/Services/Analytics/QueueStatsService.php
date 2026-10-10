<?php

namespace App\Services\Analytics;

use App\Contracts\Repositories\Analytics\QueueStatsRepositoryInterface;

/**
 * 큐 적체 현황
 *
 * 방문 수집은 큐를 타지 않지만(#682 S3 라운드 6) 집계·익명화 같은 무거운 배치는
 * 여전히 큐로 갑니다. 워커가 멈추면 그 배치가 영영 돌지 않아 화면의 숫자가 어느
 * 시점에 멈춘 채 그럴듯하게 남습니다 — 이 현황이 진단 화면에서 그 사실을 드러내는
 * 자리입니다.
 */
class QueueStatsService
{
    /**
     * 현황 조회기를 생성합니다.
     *
     * @param  QueueStatsRepositoryInterface  $queue  큐 적체 저장소
     */
    public function __construct(
        private readonly QueueStatsRepositoryInterface $queue,
    ) {}

    /**
     * 큐 현황을 돌려줍니다.
     *
     * @return array{pending_jobs: int, failed_jobs: int, countable: bool} 큐 현황
     */
    public function stats(): array
    {
        if (! $this->queue->isCountable()) {
            // 데이터베이스 큐가 아니면 여기서는 셀 수 없다 — 0 을 「비어 있다」로 읽지
            // 않도록 화면이 countable 로 갈라 적는다.
            return ['pending_jobs' => 0, 'failed_jobs' => 0, 'countable' => false];
        }

        return [
            'pending_jobs' => $this->queue->pendingJobs(),
            'failed_jobs' => $this->queue->failedJobs(),
            'countable' => true,
        ];
    }
}
