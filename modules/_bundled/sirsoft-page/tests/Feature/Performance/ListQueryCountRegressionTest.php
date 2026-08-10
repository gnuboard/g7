<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Performance;

use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 페이지 목록 조회의 쿼리 수 회귀 테스트 (#519)
 *
 * 행 수를 늘려도 쿼리 수가 늘지 않는지 단언한다 — 그것이 N+1 의 정의다.
 *
 * @scenario case=list_query_count
 *
 * @effects page_list_query_count_stable,
 *          page_search_query_count_stable
 */
class ListQueryCountRegressionTest extends ModuleTestCase
{
    use CountsQueries;

    /**
     * 페이지를 원하는 수만큼 만듭니다.
     *
     * @param  int  $count  생성할 수
     * @param  string  $prefix  슬러그 접두
     */
    private function seedPages(int $count, string $prefix): void
    {
        for ($i = 0; $i < $count; $i++) {
            Page::create([
                'slug' => $prefix.'-'.$i,
                'title' => ['ko' => '페이지 '.$prefix.$i],
                'content' => '본문 내용',
                'published' => true,
            ]);
        }
    }

    /**
     * 페이지 목록: 페이지 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * @effects page_list_query_count_stable
     */
    public function test_page_list_query_count_is_stable(): void
    {
        $repository = app(PageRepositoryInterface::class);
        $this->seedPages(5, 'list-a');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->paginate([], 50),
            grow: fn () => $this->seedPages(10, 'list-b'),
            context: '페이지 목록',
        );
    }

    /**
     * 페이지 검색: 매칭 수가 늘어도 쿼리 수가 늘지 않는지 확인
     *
     * 종전에는 PHP_INT_MAX 로 매칭 전량을 읽고 PHP 에서 잘라냈다.
     *
     * @effects page_search_query_count_stable
     */
    public function test_page_search_query_count_is_stable(): void
    {
        $repository = app(PageRepositoryInterface::class);
        $this->seedPages(5, 'search-a');

        $this->assertQueryCountStableAsDataGrows(
            measure: fn () => $repository->searchByKeyword('페이지', 'created_at', 'desc', 10, 1),
            grow: fn () => $this->seedPages(10, 'search-b'),
            context: '페이지 검색',
        );
    }
}
