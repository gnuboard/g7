<?php

namespace App\Contracts\Repositories;

use App\Models\QueueJobLog;
use App\Support\Query\BoundedPage;
use Carbon\CarbonInterface;

/**
 * 큐 작업 처리 기록(queue_job_logs) Repository 인터페이스
 */
interface QueueJobLogRepositoryInterface
{
    /**
     * 처리 기록 목록 정렬 허용 열 (`sort_by`). 지정이 없으면 최근 기록순(created_at 역순)이다.
     *
     * @var list<string>
     */
    public const SORT_COLUMNS = ['finished_at', 'duration_ms', 'attempts'];

    /**
     * 처리 기록 한 페이지를 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (search_field·search_keyword·status·queue·connection·created_start_date·created_end_date·sort_by·sort_order)
     * @param  int  $perPage  페이지당 건수
     * @return BoundedPage 처리 기록 페이지 (기본 최신순, sort_by 지정 시 그 열 + id 보조)
     */
    public function paginate(array $filters, int $perPage): BoundedPage;

    /**
     * 처리 기록 1건을 찾습니다.
     *
     * @param  int  $id  처리 기록 ID
     * @return QueueJobLog|null 처리 기록 (없으면 null)
     */
    public function find(int $id): ?QueueJobLog;

    /**
     * UUID 마다 가장 최근 처리 기록의 시도 횟수를 돌려줍니다.
     *
     * 실패 작업 목록·상세가 payload 에 시도 수가 없는 드라이버(database)의 시도 수를 채울 때 쓴다.
     *
     * @param  array<int, string>  $uuids  작업 UUID 목록
     * @return array<string, int> UUID => 시도 횟수 (기록이 없는 UUID 는 빠진다)
     */
    public function latestAttemptsByUuid(array $uuids): array;

    /**
     * 처리 기록 여러 건을 한 번에 넣습니다.
     *
     * @param  list<array<string, mixed>>  $rows  행 목록 (컬럼 => 값)
     * @return int 넣은 건수
     */
    public function insertMany(array $rows): int;

    /**
     * 기준 시각보다 오래된 처리 기록을 배치로 지웁니다.
     *
     * @param  CarbonInterface  $before  이 시각 이전(created_at)의 행을 지운다
     * @return int 지운 건수
     */
    public function deleteOlderThan(CarbonInterface $before): int;
}
