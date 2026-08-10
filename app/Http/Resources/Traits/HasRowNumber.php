<?php

namespace App\Http\Resources\Traits;

use App\Contracts\Pagination\BoundedTotalAware;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * 컬렉션 항목에 순번(number)을 부여하는 Trait
 *
 * 페이지네이션 정보와 정렬 방향을 기반으로 각 항목에 순번을 계산합니다.
 * - 내림차순(desc): 역순 (total, total-1, ...)
 * - 오름차순(asc): 기본순 (1, 2, 3, ...)
 */
trait HasRowNumber
{
    /**
     * 순번이 포함된 데이터 매핑을 수행합니다.
     *
     * @param  callable  $callback  각 항목을 변환하는 콜백 함수
     * @param  string|null  $sortOrder  정렬 방향 (asc|desc), null인 경우 request에서 추출
     * @return Collection 순번이 포함된 변환된 컬렉션
     */
    protected function mapWithRowNumber(callable $callback, ?string $sortOrder = null): Collection
    {
        $sortOrder = $sortOrder ?? request()->input('sort_order', 'desc');

        // 페이지네이션이 아닌 경우 단순 인덱스 기반 순번 부여
        if (! $this->resource instanceof LengthAwarePaginator) {
            return $this->collection->map(function ($item, $index) use ($callback, $sortOrder) {
                $total = $this->collection->count();
                $number = $sortOrder === 'asc' ? $index + 1 : $total - $index;
                $data = $callback($item);

                return array_merge(['number' => $number], $data);
            });
        }

        // 페이지네이션 정보 추출
        $total = $this->resource->total();
        $perPage = $this->resource->perPage();
        $currentPage = $this->resource->currentPage();

        return $this->collection->map(function ($item, $index) use ($callback, $sortOrder, $total, $perPage, $currentPage) {
            $number = $this->calculateRowNumber($index, $sortOrder, $total, $perPage, $currentPage);
            $data = $callback($item);

            return array_merge(['number' => $number], $data);
        });
    }

    /**
     * 순번을 계산합니다.
     *
     * @param  int  $index  현재 항목의 인덱스 (0부터 시작)
     * @param  string  $sortOrder  정렬 방향 (asc|desc)
     * @param  int  $total  전체 항목 수
     * @param  int  $perPage  페이지당 항목 수
     * @param  int  $currentPage  현재 페이지 번호
     * @return int|null 계산된 순번 (총 건수가 잘린 내림차순이면 null)
     */
    protected function calculateRowNumber(
        int $index,
        string $sortOrder,
        int $total,
        int $perPage,
        int $currentPage
    ): ?int {
        if ($sortOrder === 'asc') {
            // 오름차순: 1, 2, 3, ... (페이지별 연속)
            // 예: 2페이지 → ((2-1) * 10) + 0 + 1 = 11, 12, 13, ...
            // offset 만으로 정해지므로 총 건수가 잘려도 그대로 정확하다.
            return (($currentPage - 1) * $perPage) + $index + 1;
        }

        // 내림차순 순번은 "전체 몇 건 중 몇 번째" 라 총 건수를 알아야 나온다. 총 건수가
        // 상한에 걸려 잘렸으면 그 값으로 역산한 순번은 첫 페이지부터 이미 틀리고, 상한을
        // 넘어선 페이지에서는 0 과 음수까지 내려간다. 틀린 숫자를 내보내는 것보다
        // 내보내지 않는 편이 낫으므로 `last_page` 와 같은 원칙으로 null 을 돌려준다.
        if ($this->resource instanceof BoundedTotalAware && ! $this->resource->totalRelation()->isExact()) {
            return null;
        }

        // 내림차순: total, total-1, ... (역순)
        // 예: 총 30개, 2페이지 → 30 - ((2-1) * 10) - 0 = 20, 19, 18, ...
        return $total - (($currentPage - 1) * $perPage) - $index;
    }
}
