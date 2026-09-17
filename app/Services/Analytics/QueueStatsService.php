<?php

namespace App\Services\Analytics;

use App\Contracts\Repositories\Analytics\QueueStatsRepositoryInterface;

/**
 * 큐 적체 현황
 *
 * 기본 수집 방식이 큐라, 워커가 멈추면 비콘은 204 를 받고도 원장에 닿지 않습니다.
 * 이 현황이 진단 화면에서 그 사실을 드러내는 자리입니다.
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
