<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Search;

use App\Search\SearchPagePolicy;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 검색의 커서 딥링크 회귀 테스트 (#519)
 *
 * 커서 판정은 코어({@see SearchPagePolicy})가 소유하지만, 그 판정에 페이지
 * 번호가 도달하지 않으면 규칙이 무력해진다. 기본값 1 이 적용돼 **모든 요청이 첫 페이지로
 * 판정**되고, `?page=3` 딥링크가 조용히 1 페이지를 돌려준다.
 *
 * 응답은 200 이고 형태도 정상이라 기능 테스트로는 드러나지 않는다.
 *
 * @scenario case=search_cursor_page_propagation
 *
 * @effects search_deep_page_link_keeps_offset,
 *          search_first_page_starts_cursor
 */
class PageSearchCursorDeepLinkTest extends ModuleTestCase
{
    private PageService $pageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pageService = app(PageService::class);
    }

    /**
     * 커서 없이 깊은 페이지를 지목한 요청이 offset 을 유지하는지 확인
     *
     * @effects search_deep_page_link_keeps_offset
     */
    public function test_deep_page_without_cursor_stays_on_offset(): void
    {
        // Given/When: 커서 없이 3 페이지를 직접 지목한 최신순 검색
        $result = $this->pageService->searchByKeywordWithCursor(
            keyword: '커서키워드',
            sort: 'latest',
            perPage: 10,
            cursor: null,
            page: 3
        );

        // Then: 커서 경로를 타지 않는다
        $this->assertNull(
            $result,
            '커서 없이 깊은 페이지를 지목한 요청은 offset 을 유지해야 한다'
        );
    }

    /**
     * 첫 페이지는 커서가 없어도 커서 경로로 시작하는지 확인
     *
     * @effects search_first_page_starts_cursor
     */
    public function test_first_page_starts_cursor_mode(): void
    {
        // Given/When: 커서 없는 1 페이지 최신순 검색
        $result = $this->pageService->searchByKeywordWithCursor(
            keyword: '커서키워드',
            sort: 'latest',
            perPage: 10,
            cursor: null,
            page: 1
        );

        // Then: 커서 페이지네이터를 돌려준다
        $this->assertNotNull($result, '첫 페이지는 커서 시작점으로 읽어야 한다');
    }

    /**
     * 관련도순은 페이지 번호와 무관하게 offset 을 유지하는지 확인
     *
     * @effects search_deep_page_link_keeps_offset
     */
    public function test_relevance_sort_never_uses_cursor(): void
    {
        foreach ([1, 3] as $page) {
            $this->assertNull(
                $this->pageService->searchByKeywordWithCursor(
                    keyword: '커서키워드',
                    sort: 'relevance',
                    perPage: 10,
                    cursor: null,
                    page: $page
                ),
                $page.' 페이지 관련도순은 커서를 쓸 수 없어야 한다'
            );
        }
    }
}
