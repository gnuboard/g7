<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Search;

use App\Search\SearchPagePolicy;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 검색의 커서 딥링크 회귀 테스트 (#519)
 *
 * 커서 판정은 코어({@see SearchPagePolicy})가 소유하지만, 그 판정에 페이지
 * 번호가 도달하지 않으면 규칙이 무력해진다. 기본값 1 이 적용돼 **모든 요청이 첫 페이지로
 * 판정**되고, `?page=3` 딥링크가 조용히 1 페이지를 돌려준다.
 *
 * 응답은 200 이고 형태도 정상이라 기능 테스트로는 드러나지 않는다. 어느 경로를 타는지
 * 자체를 단언해야 회귀가 잡힌다.
 *
 * @scenario case=search_cursor_page_propagation
 *
 * @effects search_deep_page_link_keeps_offset,
 *          search_first_page_starts_cursor
 */
class ProductSearchCursorDeepLinkTest extends ModuleTestCase
{
    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productService = app(ProductService::class);
    }

    /**
     * 커서 없이 깊은 페이지를 지목한 요청이 offset 을 유지하는지 확인
     *
     * 커서로 바꿔 버리면 링크가 가리키던 자리를 잃고 첫 페이지가 열린다.
     *
     * @effects search_deep_page_link_keeps_offset
     */
    public function test_deep_page_without_cursor_stays_on_offset(): void
    {
        // Given/When: 커서 없이 3 페이지를 직접 지목한 최신순 검색
        $result = $this->productService->searchByKeywordWithCursor(
            keyword: '커서키워드',
            sort: 'latest',
            categoryId: null,
            perPage: 10,
            cursor: null,
            page: 3
        );

        // Then: 커서 경로를 타지 않는다 (호출자가 offset 경로로 내려간다)
        $this->assertNull(
            $result,
            '커서 없이 깊은 페이지를 지목한 요청은 offset 을 유지해야 한다'
        );
    }

    /**
     * 첫 페이지는 커서가 없어도 커서 경로로 시작하는지 확인
     *
     * 서버는 커서 모드에서만 다음 커서를 내보낸다. 첫 페이지를 offset 으로 처리하면
     * 첫 커서가 만들어질 자리가 없어 화면이 영원히 offset 에 머문다.
     *
     * @effects search_first_page_starts_cursor
     */
    public function test_first_page_starts_cursor_mode(): void
    {
        // Given/When: 커서 없는 1 페이지 최신순 검색
        $result = $this->productService->searchByKeywordWithCursor(
            keyword: '커서키워드',
            sort: 'latest',
            categoryId: null,
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
     * 계산값(`_ft_score`) 정렬은 WHERE 경계로 쓸 수 없다.
     *
     * @effects search_deep_page_link_keeps_offset
     */
    public function test_relevance_sort_never_uses_cursor(): void
    {
        foreach ([1, 3] as $page) {
            $this->assertNull(
                $this->productService->searchByKeywordWithCursor(
                    keyword: '커서키워드',
                    sort: 'relevance',
                    categoryId: null,
                    perPage: 10,
                    cursor: null,
                    page: $page
                ),
                $page.' 페이지 관련도순은 커서를 쓸 수 없어야 한다'
            );
        }
    }
}
