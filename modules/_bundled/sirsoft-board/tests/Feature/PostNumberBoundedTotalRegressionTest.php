<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedCount;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Http\Resources\PostCollection;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 목록 순번이 상한 총 건수로 역산되어 0·음수로 내려가는 회귀 테스트 (#519)
 *
 * 내림차순 순번은 "전체 몇 건 중 몇 번째" 라 총 건수를 알아야 계산된다. 총 건수가 상한에
 * 걸려 잘리면 그 값으로 역산한 순번은 첫 페이지부터 이미 틀리고, 상한을 넘어가는 페이지에서는
 * 0 과 음수까지 내려간다. 실측(12,027 행): 500p=20 → 501p=0/−19 → 601p=−2000.
 *
 * 틀린 숫자를 내보내는 것보다 내보내지 않는 편이 낫다. `last_page` 를 모를 때 null 로
 * 내보내는 것과 같은 원칙으로, 잘린 총 건수에서는 순번을 null 로 둔다. 오름차순은 offset
 * 기반이라 총 건수와 무관하게 정확하므로 그대로 둔다.
 *
 * @scenario case=post_number_bounded_total
 *
 * @effects post_number_null_when_total_truncated,
 *          post_number_never_non_positive,
 *          post_number_exact_when_total_exact,
 *          post_number_ascending_unaffected_by_truncation
 */
class PostNumberBoundedTotalRegressionTest extends BoardTestCase
{
    /**
     * 순번 계산에 필요한 최소 형태의 게시글 목록을 만듭니다.
     *
     * @param  int  $count  만들 개수
     * @return Collection<int, Post> 게시글 컬렉션
     */
    private function makePosts(int $count): Collection
    {
        return collect(range(1, $count))->map(function (int $i) {
            $post = new Post;
            $post->id = 1000 + $i;
            $post->is_notice = false;
            $post->parent_id = null;

            return $post;
        });
    }

    /**
     * 컬렉션을 렌더링해 순번만 뽑아냅니다.
     *
     * @param  int  $page  현재 페이지
     * @param  int  $perPage  페이지당 건수
     * @param  BoundedCount  $total  총 건수 + 정확도
     * @param  string  $direction  정렬 방향
     * @return array<int, mixed> 순번 목록
     */
    private function numbersFor(int $page, int $perPage, BoundedCount $total, string $direction = 'desc'): array
    {
        $paginator = new LengthAwarePaginator(
            $this->makePosts($perPage),
            max($total->total(), $page * $perPage),
            $perPage,
            $page,
        );

        $collection = new PostCollection($paginator);
        $collection->setTotalNormalPosts($total);
        $collection->setOrderDirection($direction);

        $rendered = $collection->toArray(request());

        return collect($rendered['data'])->pluck('number')->all();
    }

    /**
     * 총 건수가 잘리지 않았으면 순번은 지금까지처럼 정확해야 합니다.
     */
    public function test_총건수가_정확하면_순번은_총건수부터_내림차순이다(): void
    {
        $numbers = $this->numbersFor(
            page: 1,
            perPage: 5,
            total: new BoundedCount(120, TotalRelation::Exact, 10000),
        );

        $this->assertSame([120, 119, 118, 117, 116], $numbers);
    }

    /**
     * 총 건수가 잘렸으면 순번을 지어내지 않아야 합니다.
     */
    public function test_총건수가_잘리면_순번은_null_이다(): void
    {
        $numbers = $this->numbersFor(
            page: 1,
            perPage: 5,
            total: new BoundedCount(10000, TotalRelation::AtLeast, 10000),
        );

        $this->assertSame([null, null, null, null, null], $numbers);
    }

    /**
     * 상한을 넘어선 깊은 페이지에서도 0·음수가 나오지 않아야 합니다.
     */
    public function test_상한을_넘은_페이지에서_0이나_음수_순번이_없다(): void
    {
        foreach ([501, 502, 601] as $page) {
            $numbers = $this->numbersFor(
                page: $page,
                perPage: 20,
                total: new BoundedCount(10000, TotalRelation::AtLeast, 10000),
            );

            foreach ($numbers as $number) {
                $this->assertNotIsInt(
                    $number,
                    "{$page} 페이지에서 상한 총 건수로 역산한 순번이 그대로 나왔습니다."
                );
            }
        }
    }

    /**
     * 오름차순은 offset 기반이라 총 건수가 잘려도 그대로 정확해야 합니다.
     */
    public function test_오름차순_순번은_총건수가_잘려도_유지된다(): void
    {
        $numbers = $this->numbersFor(
            page: 3,
            perPage: 5,
            total: new BoundedCount(10000, TotalRelation::AtLeast, 10000),
            direction: 'asc',
        );

        $this->assertSame([11, 12, 13, 14, 15], $numbers);
    }

    /**
     * PHPUnit 11 에는 assertNotIsInt 가 없으므로 의미를 그대로 옮겨 둡니다.
     *
     * @param  mixed  $value  검사 대상
     * @param  string  $message  실패 메시지
     */
    private function assertNotIsInt(mixed $value, string $message = ''): void
    {
        $this->assertFalse(is_int($value), $message);
    }
}
