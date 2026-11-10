<?php

namespace Tests\Unit\Http\Resources;

use App\Enums\TotalRelation;
use App\Http\Resources\Traits\HasRowNumber;
use App\Support\Query\BoundedPage;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Tests\PureTestCase;

/**
 * 순번이 상한 총 건수로 역산되어 0·음수로 내려가는 회귀 테스트 (#519)
 *
 * 내림차순 순번은 "전체 몇 건 중 몇 번째" 라 총 건수를 알아야 나온다. 총 건수가 상한에
 * 걸려 잘리면 그 값으로 역산한 순번은 첫 페이지부터 이미 틀리고, 상한을 넘어선 페이지에서는
 * 0 과 음수까지 내려간다. 활동 로그(실측 88,792 건 / 상한 10,000)가 이 경로를 탄다.
 *
 * 틀린 숫자를 내보내는 것보다 내보내지 않는 편이 낫다. `last_page` 를 모를 때 null 을
 * 내보내는 것과 같은 원칙으로, 잘린 총 건수에서는 순번을 null 로 둔다.
 *
 * @scenario case=row_number_bounded_total
 *
 * @effects row_number_null_when_total_truncated,
 *          row_number_exact_when_total_exact,
 *          row_number_ascending_unaffected_by_truncation
 */
class HasRowNumberBoundedTotalTest extends PureTestCase
{
    /**
     * HasRowNumber 트레이트를 그대로 쓰는 최소 컬렉션을 만듭니다.
     *
     * @param  mixed  $paginator  순번 계산에 쓸 페이지네이터(LengthAwarePaginator 또는 BoundedPage)
     * @return ResourceCollection 순번만 뽑는 numbers() 를 가진 익명 컬렉션
     */
    private function collectionFor(mixed $paginator): ResourceCollection
    {
        return new class($paginator) extends ResourceCollection
        {
            use HasRowNumber;

            /**
             * mapWithRowNumber() 결과에서 순번만 뽑아냅니다.
             *
             * @param  string  $sortOrder  정렬 방향(asc 또는 desc)
             * @return array<int, mixed> 순번 목록
             */
            public function numbers(string $sortOrder): array
            {
                return $this->mapWithRowNumber(fn ($item) => ['id' => $item], $sortOrder)
                    ->pluck('number')
                    ->all();
            }
        };
    }

    /**
     * 결과 상한 10,000 건이 걸린 상한형 페이지를 만듭니다.
     *
     * @param  int  $page  현재 페이지 번호
     * @param  int  $perPage  페이지당 건수(항목도 이 개수만큼 채운다)
     * @param  int  $total  보고할 총 건수
     * @param  TotalRelation  $relation  총 건수가 정확한지(Exact) 하한인지(AtLeast)
     * @return BoundedPage 테스트용 상한형 페이지
     */
    private function boundedPage(int $page, int $perPage, int $total, TotalRelation $relation): BoundedPage
    {
        return new BoundedPage(
            items: new Collection(range(1, $perPage)),
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            totalRelation: $relation,
            resultCap: 10000,
            hasMorePages: true,
        );
    }

    /**
     * 총 건수가 정확하면 내림차순 순번은 총 건수부터 하나씩 줄어든다.
     *
     * @scenario case=exact_total
     *
     * @effects row_number_exact_when_total_exact
     *
     * @return void 반환값 없음
     */
    public function test_총건수가_정확하면_순번은_총건수부터_내림차순이다(): void
    {
        $paginator = new LengthAwarePaginator(new Collection(range(1, 5)), 120, 5, 1);

        $this->assertSame(
            [120, 119, 118, 117, 116],
            $this->collectionFor($paginator)->numbers('desc')
        );
    }

    /**
     * 총 건수가 상한에서 잘리면(AtLeast) 내림차순 순번을 모두 null 로 둔다.
     *
     * @scenario case=truncated_total
     *
     * @effects row_number_null_when_total_truncated
     *
     * @return void 반환값 없음
     */
    public function test_총건수가_잘리면_순번은_null_이다(): void
    {
        $page = $this->boundedPage(1, 5, 10000, TotalRelation::AtLeast);

        $this->assertSame(
            [null, null, null, null, null],
            $this->collectionFor($page)->numbers('desc')
        );
    }

    /**
     * 상한을 넘어선 페이지에서도 잘린 총 건수로 역산한 0·음수 정수 순번이 나오지 않는다.
     *
     * @scenario case=truncated_total
     *
     * @effects row_number_null_when_total_truncated
     *
     * @return void 반환값 없음
     */
    public function test_상한을_넘은_페이지에서_0이나_음수_순번이_없다(): void
    {
        foreach ([501, 502, 601] as $pageNumber) {
            $page = $this->boundedPage($pageNumber, 20, 10000, TotalRelation::AtLeast);

            foreach ($this->collectionFor($page)->numbers('desc') as $number) {
                $this->assertFalse(
                    is_int($number),
                    "{$pageNumber} 페이지에서 상한 총 건수로 역산한 순번이 그대로 나왔습니다."
                );
            }
        }
    }

    /**
     * 오름차순 순번은 총 건수와 무관하므로 잘린 총 건수에서도 페이지 오프셋부터 그대로 매긴다.
     *
     * @scenario case=truncated_total
     *
     * @effects row_number_ascending_unaffected_by_truncation
     *
     * @return void 반환값 없음
     */
    public function test_오름차순_순번은_총건수가_잘려도_유지된다(): void
    {
        $page = $this->boundedPage(3, 5, 10000, TotalRelation::AtLeast);

        $this->assertSame(
            [11, 12, 13, 14, 15],
            $this->collectionFor($page)->numbers('asc')
        );
    }
}
