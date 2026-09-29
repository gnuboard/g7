<?php

namespace App\Contracts\Repositories\Analytics;

use App\Models\Analytics\AnalyticsArchiveRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 배치 실행 이력 Repository 인터페이스
 */
interface ArchiveRunRepositoryInterface
{
    /**
     * 실행 이력을 시작 상태로 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  실행 속성
     * @return AnalyticsArchiveRun 생성된 실행 이력
     */
    public function create(array $attributes): AnalyticsArchiveRun;

    /**
     * 실행 이력을 갱신합니다.
     *
     * @param  AnalyticsArchiveRun  $run  대상 실행 이력
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return bool 갱신 성공 여부
     */
    public function update(AnalyticsArchiveRun $run, array $attributes): bool;

    /**
     * ID 로 실행 이력을 조회합니다.
     *
     * @param  int  $id  실행 이력 ID
     * @return AnalyticsArchiveRun|null 실행 이력 (없으면 null)
     */
    public function findById(int $id): ?AnalyticsArchiveRun;

    /**
     * 종류별 마지막 성공 실행을 조회합니다.
     *
     * 진단 화면이 「마지막 집계가 몇 시간 전인지」를 판단하는 근거입니다.
     *
     * @param  string  $runType  실행 종류
     * @return AnalyticsArchiveRun|null 마지막 성공 실행 (없으면 null)
     */
    public function findLastSuccessful(string $runType): ?AnalyticsArchiveRun;

    /**
     * 종류·실행 주체별 마지막 실행을 성공·실패와 무관하게 조회합니다.
     *
     * 스케줄러 생존 판정의 근거다 — 예약 실행이 실패로 끝났어도 스케줄러 자체는 돌고 있다.
     *
     * @param  string  $runType  실행 종류
     * @param  string  $trigger  실행 주체 (scheduled, manual, on_view)
     * @return AnalyticsArchiveRun|null 마지막 실행 (없으면 null)
     */
    public function findLastStartedByTrigger(string $runType, string $trigger): ?AnalyticsArchiveRun;

    /**
     * 가장 먼저 시작한 실행의 시작 시각을 돌려줍니다.
     *
     * @return Carbon|null 첫 실행 시작 시각 (UTC, 이력이 없으면 null)
     */
    public function firstStartedAt(): ?Carbon;

    /**
     * 실행 이력을 페이지네이션하여 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 조건 (run_type, status)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션된 실행 이력
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 보존기간이 지난 실행 이력을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 이력이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int;

    /**
     * 기준 시각보다 오래된 집계 실행 이력 수를 셉니다.
     *
     * 마른 실행이 「무엇을 얼마나 지울지」를 미리 보이는 데 씁니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int;

    /**
     * 지금 돌고 있는 집계 실행을 찾습니다.
     *
     * @param  Carbon|null  $startedSince  이 시각 이후에 시작한 행만 본다(그 전의 「실행 중」은 멈춘 행으로 친다). null 이면 제한 없음
     * @return AnalyticsArchiveRun|null 실행 중인 이력 (없으면 null)
     */
    public function findRunning(?Carbon $startedSince = null): ?AnalyticsArchiveRun;

    /**
     * 주어진 시각 이전에 시작해 아직 「실행 중」으로 남은 행을 찾습니다.
     *
     * @param  Carbon  $before  이 시각보다 앞서 시작한 행
     * @return Collection<int, AnalyticsArchiveRun> 멈춘 것으로 볼 실행 이력
     */
    public function getRunningStartedBefore(Carbon $before): Collection;

    /**
     * 한 잡이 맡은 「실행 중」 행을 찾습니다 — 같은 유형·같은 요청자로 그 시각 이후에 시작한 행.
     *
     * 기간을 주면 그 기간 안의 날짜 행만 고릅니다. 같은 관리자가 다른 기간을 따로 재집계하는
     * 중이면(워커 둘) 그 잡의 행까지 닫지 않도록 합니다.
     *
     * @param  string  $runType  실행 유형
     * @param  Carbon  $since  잡을 큐에 올린 시각
     * @param  int|null  $triggeredBy  요청한 관리자 ID (예약 실행이면 null)
     * @param  array{0: Carbon, 1: Carbon}|null  $period  잡이 맡은 기간 [시작(포함), 끝(미포함)] (UTC)
     * @return Collection<int, AnalyticsArchiveRun> 실행 중인 이력
     */
    public function getRunningOfTypeSince(string $runType, Carbon $since, ?int $triggeredBy, ?array $period = null): Collection;
}
