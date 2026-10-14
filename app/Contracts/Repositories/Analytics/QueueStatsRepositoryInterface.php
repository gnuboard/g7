<?php

namespace App\Contracts\Repositories\Analytics;

/**
 * 큐 적체 Repository 인터페이스
 *
 * 큐에 일이 얼마나 쌓여 있는지 셉니다. 기본 수집 방식이 큐라, 워커가 멈추면 비콘은
 * 정상 응답을 받고도 원장에 닿지 않습니다 — 그 사실을 알 수 있는 유일한 곳입니다.
 */
interface QueueStatsRepositoryInterface
{
    /**
     * 처리를 기다리는 일의 수를 셉니다.
     *
     * @return int 대기 중인 일의 수 (데이터베이스 큐가 아니면 0)
     */
    public function pendingJobs(): int;

    /**
     * 실패한 일의 수를 셉니다.
     *
     * @return int 실패한 일의 수
     */
    public function failedJobs(): int;

    /**
     * 큐 적체를 셀 수 있는 상태인지 확인합니다.
     *
     * @return bool 데이터베이스 큐라서 셀 수 있으면 true
     */
    public function isCountable(): bool;

    /**
     * 큐 이름별로 처리를 기다리는 일의 수를 셉니다.
     *
     * 데이터베이스 큐 표(jobs)만 셀 수 있다 — 긴 작업 연결(database-long)도 같은 표를 쓰므로 큐 이름으로 갈린다.
     *
     * @return array<string, int> 큐 이름 => 대기 중인 일의 수 (셀 수 없으면 빈 배열)
     */
    public function pendingJobsByQueue(): array;
}
