<?php

namespace App\Repositories;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Models\ScheduleHistory;
use App\Repositories\Concerns\DeletesInBatches;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Support\Query\PaginationLimits;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ScheduleHistoryRepository implements ScheduleHistoryRepositoryInterface
{
    use DeletesInBatches;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    /**
     * ID로 실행 이력을 찾습니다.
     *
     * @param  int  $id  이력 ID
     * @return ScheduleHistory|null 찾은 이력 모델 또는 null
     */
    public function findById(int $id): ?ScheduleHistory
    {
        return ScheduleHistory::with(['schedule', 'triggeredBy'])->find($id);
    }

    /**
     * 새로운 실행 이력을 생성합니다.
     *
     * @param  array  $data  이력 생성 데이터
     * @return ScheduleHistory 생성된 이력 모델
     */
    public function create(array $data): ScheduleHistory
    {
        return ScheduleHistory::create($data);
    }

    /**
     * 기존 실행 이력을 업데이트합니다.
     *
     * @param  ScheduleHistory  $history  업데이트할 이력 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(ScheduleHistory $history, array $data): bool
    {
        return $history->update($data);
    }

    /**
     * 실행 이력을 삭제합니다.
     *
     * @param  ScheduleHistory  $history  삭제할 이력 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(ScheduleHistory $history): bool
    {
        return $history->delete();
    }

    /**
     * 특정 스케줄의 실행 이력을 페이지네이션하여 조회합니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @param  array  $filters  필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 이력 목록
     */
    public function getPaginatedByScheduleId(int $scheduleId, array $filters = []): LengthAwarePaginator
    {
        // 관계는 쿼리에 미리 붙이지 않는다 — 지연 조인 트레이트가 inner/outer 양쪽에서
        // setEagerLoads([]) 로 지우므로, 여기서 with() 하면 outer 에서도 사라져
        // ScheduleHistoryResource 의 whenLoaded('triggeredBy') 가 항상 비게 된다.
        // 로드는 relations: 인자로 넘긴다.
        $query = ScheduleHistory::query()
            ->where('schedule_id', $scheduleId);

        // 상태 필터
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 트리거 유형 필터
        if (! empty($filters['trigger_type'])) {
            $query->where('trigger_type', $filters['trigger_type']);
        }

        // 기간 필터 — whereDate 는 컬럼에 DATE() 를 씌워 인덱스를 못 쓰게 만든다.
        // 같은 결과를 내는 범위 조건으로 바꿔 started_at 인덱스를 살린다
        // (종료일은 그날 23:59:59.999999 까지 포함해야 whereDate 와 동일한 경계를 갖는다).
        if (! empty($filters['started_from'])) {
            $query->where('started_at', '>=', Carbon::parse($filters['started_from'])->startOfDay());
        }

        if (! empty($filters['started_to'])) {
            $query->where('started_at', '<=', Carbon::parse($filters['started_to'])->endOfDay());
        }

        // 정렬 — 요청 값은 허용 목록으로만 해석한다.
        // 허용 집합은 ScheduleHistoryListRequest 의 `in:` 규칙과 동일해야 한다. 저장소 목록이
        // 그보다 좁으면 게이트를 통과한 정렬이 조용히 기본값으로 되돌아간다 (종료시각/소요시간).
        $sort = $this->resolveSortSpec(
            $filters,
            ['started_at', 'ended_at', 'duration', 'status'],
            'started_at'
        );

        $perPage = (int) ($filters['per_page'] ?? 15);

        // 실행 이력은 계속 쌓이며 출력/에러 메시지를 그대로 노출하므로, 컬럼은 좁히지 않고
        // 지연 조인으로 넓은 컬럼을 읽는 행 수만 이번 페이지 분량으로 고정한다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: $sort,
            perPage: $perPage,
            relations: ['triggeredBy'],
            // 로그 테이블은 계속 쌓이기만 한다. 총 건수는 상한까지만 세고 "다음" 이동은
            // per_page + 1 실측으로 끝까지 열어 둔다 (계산 불가는 마지막 페이지 번호 하나뿐).
            resultCap: PaginationLimits::resultCap('admin.schedule_histories'),
        );
    }

    /**
     * 특정 스케줄의 최근 실행 이력을 조회합니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @param  int  $limit  조회 개수
     * @return Collection 최근 이력 컬렉션
     */
    public function getRecentByScheduleId(int $scheduleId, int $limit = 10): Collection
    {
        return ScheduleHistory::with('triggeredBy')
            ->where('schedule_id', $scheduleId)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 여러 이력을 일괄 삭제합니다.
     *
     * @param  array  $ids  이력 ID 배열
     * @return int 삭제된 레코드 수
     */
    public function bulkDelete(array $ids): int
    {
        return ScheduleHistory::whereIn('id', $ids)->delete();
    }

    /**
     * 특정 스케줄의 모든 이력을 삭제합니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByScheduleId(int $scheduleId): int
    {
        return ScheduleHistory::where('schedule_id', $scheduleId)->delete();
    }

    /**
     * 특정 기간 이전의 이력을 삭제합니다.
     *
     * @param  int  $days  보관 기간 (일)
     * @return int 삭제된 레코드 수
     */
    public function deleteOlderThan(int $days): int
    {
        // 보존 기간 하한(1일)은 이 계층이 소유한다 — 파기는 되돌릴 수 없으므로
        // 호출자마다 다시 막지 않고 실제로 지우는 자리에서 한 번 막는다.
        return $this->deleteInBatches(
            ScheduleHistory::where('started_at', '<', now()->subDays(max(1, $days)))
        );
    }
}
