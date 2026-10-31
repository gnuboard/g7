<?php

namespace App\Repositories\Analytics;

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\CollectFailureRepositoryInterface;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Repositories\Concerns\FiltersByDateRange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 적재 실패 로그 Repository
 */
class CollectFailureRepository implements CollectFailureRepositoryInterface
{
    use FiltersByDateRange;

    /**
     * 실패를 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  실패 속성
     * @return AnalyticsCollectFailure 생성된 실패 로그
     */
    public function create(array $attributes): AnalyticsCollectFailure
    {
        return AnalyticsCollectFailure::query()->create($attributes);
    }

    /**
     * 실패 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 조건 (reason)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션된 실패 목록
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        // payload_excerpt 는 JSON 이라 목록에서 읽으면 오버플로 페이지를 통째로 가져온다.
        // 목록이 실제로 보여 주는 컬럼만 고른다 — 본문은 상세 조회에서 읽는다.
        return AnalyticsCollectFailure::query()
            ->when(! empty($filters['reason']), fn (Builder $q) => $q->where('reason', $filters['reason']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'reason', 'message', 'url', 'ip', 'user_agent', 'occurred_at']);
    }

    /**
     * 최근 N 시간의 실패 건수를 셉니다.
     *
     * @param  int  $hours  기준 시간
     * @return int 실패 건수
     */
    public function countSince(int $hours = 24): int
    {
        return AnalyticsCollectFailure::query()
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->count();
    }

    /**
     * 보존기간이 지난 실패 로그를 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 로그가 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsCollectFailure::query()
                ->where('occurred_at', '<', $before)
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 기준 시각보다 오래된 적재 실패 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int
    {
        return AnalyticsCollectFailure::query()->where('occurred_at', '<', $before)->count();
    }

    /**
     * 기간 안의 실패 로그를 나눠서 순회합니다.
     *
     * @param  string|null  $from  시작 일자 (Y-m-d, null 이면 처음부터)
     * @param  string|null  $to  끝 일자 (Y-m-d, null 이면 끝까지)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkInDateRange(?string $from, ?string $to, int $chunkSize, callable $callback): void
    {
        $query = AnalyticsCollectFailure::query();

        // 같은 익명화 실행이 방문 원장을 `visit_date`(사이트 표준시 일자)로 자른다 — 곁 원장도
        // 사이트 표준시 하루로 잘라야 같은 기간을 가리킨다(UTC 하루면 한국 새벽 기록이 어긋난다).
        $this->applyDateRangeFilterInTimezone($query, 'occurred_at', $from, $to, AnalyticsClock::siteTimezone());

        $query->orderBy('id')->chunkById(max(1, $chunkSize), $callback);
    }

    /**
     * 실패 로그의 주소를 덮어씁니다.
     *
     * @param  AnalyticsCollectFailure  $failure  대상 로그
     * @param  string|null  $ip  새 주소 (null 이면 지움)
     * @return void 없음
     */
    public function updateIp(AnalyticsCollectFailure $failure, ?string $ip): void
    {
        $failure->forceFill(['ip' => $ip])->save();
    }
}
