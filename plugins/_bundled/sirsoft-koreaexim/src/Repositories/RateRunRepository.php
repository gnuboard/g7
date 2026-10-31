<?php

namespace Plugins\Sirsoft\Koreaexim\Repositories;

use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRun;
use Plugins\Sirsoft\Koreaexim\Models\KoreaeximRateRunItem;
use Plugins\Sirsoft\Koreaexim\Repositories\Contracts\RateRunRepositoryInterface;

/** 이력 조회·통화 스냅샷 저장·보존기간 정리. */
class RateRunRepository implements RateRunRepositoryInterface
{
    use PaginatesWithDeferredJoin;

    private const SUMMARY = ['id', 'trigger', 'status', 'search_date', 'rate_type', 'base_currency', 'base_unit', 'request_count', 'applied_count', 'unchanged_count', 'skipped_count', 'message', 'source_run_id', 'started_at', 'finished_at', 'created_at'];

    /**
     * 조건에 맞는 실행 요약을 페이지 단위로 반환합니다.
     *
     * @param  array  $filters  filters 입력값
     * @return LengthAwarePaginator 처리 결과
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = KoreaeximRateRun::query()->select(self::SUMMARY);
        foreach (['status', 'trigger'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->where('search_date', $operator, $filters[$key]);
            }
        }

        return $this->paginateWithDeferredJoin(query: $query, columns: self::SUMMARY, sort: [['column' => 'id', 'direction' => 'desc']], perPage: (int) ($filters['per_page'] ?? 20), page: isset($filters['page']) ? (int) $filters['page'] : null);
    }

    /**
     * 실행 한 건과 통화별 스냅샷을 조회합니다.
     *
     * @param  int  $id  id 입력값
     * @return KoreaeximRateRun 처리 결과
     */
    public function find(int $id): KoreaeximRateRun
    {
        return KoreaeximRateRun::query()->with('items')->findOrFail($id);
    }

    /**
     * 가장 최근 실행의 요약을 조회합니다.
     *
     * @return ?KoreaeximRateRun 처리 결과
     */
    public function latest(): ?KoreaeximRateRun
    {
        return KoreaeximRateRun::query()->select(self::SUMMARY)->orderByDesc('id')->first();
    }

    /**
     * 재적용 가능한 최신 성공 응답을 조회합니다.
     *
     * @return ?KoreaeximRateRun 처리 결과
     */
    public function latestSuccessful(): ?KoreaeximRateRun
    {
        return KoreaeximRateRun::query()->whereIn('status', [RunStatus::Success->value, RunStatus::Partial->value])
            ->whereNotNull('raw_response')->orderByDesc('id')->first();
    }

    /**
     * 한국시간 당일 실행을 호출 예산·재시도 판정용으로 조회합니다.
     *
     * @return Collection 처리 결과
     */
    public function todayRuns(): Collection
    {
        // audit:allow datetime-display-user-timezone reason: 한국시간 고시일·재시도·일일 호출 및 알림 예산 정책이며 화면 표시 시각이 아닙니다.
        $start = now('Asia/Seoul')->startOfDay()->setTimezone(config('app.timezone'));
        // audit:allow datetime-display-user-timezone reason: 한국시간 고시일·재시도·일일 호출 및 알림 예산 정책이며 화면 표시 시각이 아닙니다.
        $end = now('Asia/Seoul')->addDay()->startOfDay()->setTimezone(config('app.timezone'));

        // audit:allow query-unbounded-get reason: KST 하루 구간의 호출 예산과 재시도 상태를 함께 판정한다. 관리자 수동 실행은 일 20회, 정기 재시도는 최대 13회이며 임의 CLI 실행도 예산에서 누락되면 안 된다.
        return KoreaeximRateRun::query()->select(self::SUMMARY)->where('started_at', '>=', $start)->where('started_at', '<', $end)->orderBy('id')->get();
    }

    /**
     * 당일 실제 외부 API 요청 횟수를 합산합니다.
     *
     * @return int 처리 결과
     */
    public function sumTodayRequestCount(): int
    {
        return (int) $this->todayRuns()->sum('request_count');
    }

    /**
     * 실행과 항목을 하나의 트랜잭션으로 저장합니다.
     *
     * @param  array  $data  data 입력값
     * @param  array  $items  items 입력값
     * @return KoreaeximRateRun 처리 결과
     */
    public function create(array $data, array $items = []): KoreaeximRateRun
    {
        return DB::transaction(function () use ($data, $items) {
            $run = KoreaeximRateRun::query()->create($data);
            $run->items()->createMany($items);

            return $run;
        });
    }

    /**
     * 최신 성공 응답을 보존하고 만료된 이력을 삭제합니다.
     *
     * @param  int  $days  days 입력값
     * @return int 처리 결과
     */
    public function prune(int $days): int
    {
        $keep = $this->latestSuccessful()?->id;
        $deleted = 0;
        KoreaeximRateRun::query()->where('created_at', '<', now()->subDays($days))
            ->when($keep, fn ($query) => $query->where('id', '!=', $keep))->select('id')
            ->chunkById(100, function ($runs) use (&$deleted) {
                $ids = $runs->pluck('id');
                $deleted += DB::transaction(function () use ($ids) {
                    KoreaeximRateRunItem::query()->whereIn('run_id', $ids)->delete();

                    return KoreaeximRateRun::query()->whereIn('id', $ids)->delete();
                });
            });

        return $deleted;
    }
}
