<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Listeners;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Page\Listeners\SearchPagesListener;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Mockery;

/**
 * SearchPagesListener 단위 테스트
 *
 * 통합 검색에 페이지 결과를 제공하는 리스너의 각 메서드를 검증합니다.
 * - 훅 구독 등록 확인 (type: filter 포함)
 * - searchPages(): 검색어 없음, 관련 없는 탭, all 탭, pages 탭
 * - buildPagesResponse(): all 탭 요약, pages 탭 페이지네이션
 * - addValidationRules(): sort 규칙 추가
 */
class SearchPagesListenerTest extends ModuleTestCase
{
    private SearchPagesListener $listener;

    private PageService $pageServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pageServiceMock = Mockery::mock(PageService::class);
        $this->listener = new SearchPagesListener($this->pageServiceMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── 훅 구독 등록 ───────────────────────────────────

    /**
     * getSubscribedHooks()가 3개 훅을 반환하는지 확인
     */
    public function test_get_subscribed_hooks_returns_three_hooks(): void
    {
        $hooks = SearchPagesListener::getSubscribedHooks();

        $this->assertCount(3, $hooks);
        $this->assertArrayHasKey('core.search.results', $hooks);
        $this->assertArrayHasKey('core.search.build_response', $hooks);
        $this->assertArrayHasKey('core.search.validation_rules', $hooks);
    }

    /**
     * 모든 훅이 type: filter를 포함하는지 확인
     */
    public function test_all_hooks_have_filter_type(): void
    {
        $hooks = SearchPagesListener::getSubscribedHooks();

        foreach ($hooks as $hookName => $config) {
            $this->assertEquals('filter', $config['type'], "훅 '{$hookName}'에 type: filter가 없습니다.");
        }
    }

    /**
     * 각 훅에 method와 priority가 있는지 확인
     */
    public function test_hooks_have_required_fields(): void
    {
        $hooks = SearchPagesListener::getSubscribedHooks();

        foreach ($hooks as $hookName => $config) {
            $this->assertArrayHasKey('method', $config, "훅 '{$hookName}'에 method가 없습니다.");
            $this->assertArrayHasKey('priority', $config, "훅 '{$hookName}'에 priority가 없습니다.");
        }
    }

    // ─── searchPages() ───────────────────────────────────

    /**
     * 검색어가 비어 있으면 결과를 그대로 반환하는지 확인
     */
    public function test_search_pages_returns_unchanged_results_when_keyword_empty(): void
    {
        $original = ['posts' => ['total' => 3, 'items' => []]];
        $context = ['q' => '', 'type' => 'all'];

        $result = $this->listener->searchPages($original, $context);

        $this->assertSame($original, $result);
    }

    /**
     * 관련 없는 탭(posts)일 때 total만 반환하고 items는 빈 배열인지 확인
     */
    public function test_search_pages_returns_count_only_for_unrelated_tab(): void
    {
        $this->pageServiceMock
            ->shouldReceive('countByKeyword')
            ->with('Laravel')
            ->once()
            ->andReturn(5);

        $result = $this->listener->searchPages([], [
            'q' => 'Laravel',
            'type' => 'posts',
        ]);

        $this->assertEquals(5, $result['pages']['total']);
        $this->assertEmpty($result['pages']['items']);
    }

    /**
     * all 탭에서 검색 결과를 반환하는지 확인
     */
    public function test_search_pages_returns_results_for_all_tab(): void
    {
        $fakePage = $this->makeFakePage('이용약관', 'terms', '본 약관은 서비스 이용조건을 규정합니다.');

        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->with('약관', 'created_at', 'desc', 5)
            ->once()
            ->andReturn([
                'total' => 1,
                'items' => new Collection([$fakePage]),
            ]);

        $result = $this->listener->searchPages([], [
            'q' => '약관',
            'type' => 'all',
            'all_tab_limit' => 5,
        ]);

        $this->assertEquals(1, $result['pages']['total']);
        $this->assertCount(1, $result['pages']['items']);
    }

    /**
     * pages 탭에서 전체 결과를 반환하는지 확인
     */
    public function test_search_pages_returns_all_results_for_pages_tab(): void
    {
        $fakePage = $this->makeFakePage('개인정보처리방침', 'privacy', '개인정보를 수집합니다.');

        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->with('개인정보', 'created_at', 'desc', PHP_INT_MAX)
            ->once()
            ->andReturn([
                'total' => 1,
                'items' => new Collection([$fakePage]),
            ]);

        $result = $this->listener->searchPages([], [
            'q' => '개인정보',
            'type' => 'pages',
        ]);

        $this->assertEquals(1, $result['pages']['total']);
        $this->assertCount(1, $result['pages']['items']);
    }

    /**
     * sort=oldest 정렬이 asc로 변환되는지 확인
     */
    public function test_search_pages_resolves_oldest_sort_to_asc(): void
    {
        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->with('G7', 'created_at', 'asc', 5)
            ->once()
            ->andReturn(['total' => 0, 'items' => new Collection()]);

        $result = $this->listener->searchPages([], [
            'q' => 'G7',
            'type' => 'all',
            'sort' => 'oldest',
            'all_tab_limit' => 5,
        ]);

        // Mockery with('created_at', 'asc') 조건이 충족되지 않으면 tearDown에서 실패
        $this->assertEquals(0, $result['pages']['total']);
    }

    /**
     * sort=latest 정렬이 desc로 변환되는지 확인
     */
    public function test_search_pages_resolves_latest_sort_to_desc(): void
    {
        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->with('G7', 'created_at', 'desc', 5)
            ->once()
            ->andReturn(['total' => 0, 'items' => new Collection()]);

        $result = $this->listener->searchPages([], [
            'q' => 'G7',
            'type' => 'all',
            'sort' => 'latest',
            'all_tab_limit' => 5,
        ]);

        // Mockery with('created_at', 'desc') 조건이 충족되지 않으면 tearDown에서 실패
        $this->assertEquals(0, $result['pages']['total']);
    }

    /**
     * 검색 결과 아이템에 하이라이트 필드가 포함되는지 확인
     */
    public function test_search_pages_formats_result_with_highlight(): void
    {
        $fakePage = $this->makeFakePage('이용약관', 'terms', '본 약관은 서비스 이용조건을 규정합니다.');

        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->once()
            ->andReturn([
                'total' => 1,
                'items' => new Collection([$fakePage]),
            ]);

        $result = $this->listener->searchPages([], [
            'q' => '약관',
            'type' => 'all',
            'all_tab_limit' => 5,
        ]);

        $item = $result['pages']['items'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('slug', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('title_highlighted', $item);
        $this->assertArrayHasKey('content_preview', $item);
        $this->assertArrayHasKey('content_preview_highlighted', $item);
        $this->assertArrayHasKey('url', $item);
        $this->assertEquals('/page/terms', $item['url']);
    }

    /**
     * 키워드가 하이라이트 태그로 감싸지는지 확인
     */
    public function test_search_pages_highlights_keyword_in_title(): void
    {
        $fakePage = $this->makeFakePage('이용약관', 'terms', '');

        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->once()
            ->andReturn([
                'total' => 1,
                'items' => new Collection([$fakePage]),
            ]);

        $result = $this->listener->searchPages([], [
            'q' => '이용',
            'type' => 'all',
            'all_tab_limit' => 5,
        ]);

        $item = $result['pages']['items'][0];
        $this->assertStringContainsString('<mark>', $item['title_highlighted']);
    }

    /**
     * PageService 예외 발생 시 결과가 변경되지 않는지 확인
     */
    public function test_search_pages_handles_service_exception_gracefully(): void
    {
        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->once()
            ->andThrow(new \Exception('DB 오류'));

        $original = ['posts' => ['total' => 2, 'items' => []]];
        $result = $this->listener->searchPages($original, [
            'q' => '약관',
            'type' => 'all',
        ]);

        // 예외 발생 시 기존 results 그대로 반환
        $this->assertEquals($original, $result);
    }

    // ─── buildPagesResponse() ───────────────────────────

    /**
     * pages 데이터가 없으면 response를 그대로 반환하는지 확인
     */
    public function test_build_pages_response_returns_unchanged_when_no_pages_data(): void
    {
        $original = ['posts_count' => 3];
        $result = $this->listener->buildPagesResponse($original, [], ['type' => 'all']);

        $this->assertSame($original, $result);
    }

    /**
     * all 탭에서 pages_count와 pages 슬라이스가 반환되는지 확인
     */
    public function test_build_pages_response_returns_summary_for_all_tab(): void
    {
        $items = array_map(fn ($i) => ['id' => $i], range(1, 5));
        $results = [
            'pages' => ['total' => 10, 'items' => $items],
        ];

        $response = $this->listener->buildPagesResponse([], $results, [
            'type' => 'all',
            'all_tab_limit' => 3,
        ]);

        $this->assertEquals(10, $response['pages_count']);
        $this->assertEquals(10, $response['pages']['total']);
        $this->assertCount(3, $response['pages']['items']);
    }

    /**
     * pages 탭에서 페이지네이션 메타가 포함되는지 확인
     */
    public function test_build_pages_response_returns_pagination_for_pages_tab(): void
    {
        $items = array_map(fn ($i) => ['id' => $i], range(1, 25));
        $results = [
            'pages' => ['total' => 25, 'items' => $items],
        ];

        $response = $this->listener->buildPagesResponse([], $results, [
            'type' => 'pages',
            'page' => 2,
            'per_page' => 10,
        ]);

        $this->assertEquals(25, $response['pages']['total']);
        $this->assertCount(10, $response['pages']['items']);
        $this->assertEquals(2, $response['current_page']);
        $this->assertEquals(10, $response['per_page']);
        $this->assertEquals(3, $response['last_page']);
    }

    // ─── addValidationRules() ────────────────────────────

    /**
     * sort validation rule이 추가되는지 확인
     */
    public function test_add_validation_rules_adds_sort_rule(): void
    {
        $rules = $this->listener->addValidationRules([]);

        $this->assertArrayHasKey('sort', $rules);
        $this->assertContains('nullable', $rules['sort']);
        $this->assertContains('string', $rules['sort']);
    }

    /**
     * 기존 규칙이 유지되면서 sort가 추가되는지 확인
     */
    public function test_add_validation_rules_preserves_existing_rules(): void
    {
        $existing = ['q' => ['required', 'string'], 'type' => ['nullable', 'string']];
        $rules = $this->listener->addValidationRules($existing);

        $this->assertArrayHasKey('q', $rules);
        $this->assertArrayHasKey('type', $rules);
        $this->assertArrayHasKey('sort', $rules);
    }

    // ─── 헬퍼 ────────────────────────────────────────────

    /**
     * 테스트용 가짜 페이지 객체를 생성합니다.
     *
     * @param  string  $title  제목 (한국어)
     * @param  string  $slug  슬러그
     * @param  string  $contentKo  본문 (한국어)
     * @return object
     */
    private function makeFakePage(string $title, string $slug, string $contentKo): object
    {
        return new class($title, $slug, $contentKo)
        {
            public int $id = 1;

            public string $slug;

            public array $content;

            public ?\Carbon\Carbon $published_at;

            public function __construct(string $title, string $slug, string $contentKo)
            {
                $this->slug = $slug;
                $this->content = ['ko' => $contentKo, 'en' => ''];
                $this->published_at = now();
                $this->_title = $title;
            }

            private string $_title;

            public function getLocalizedTitle(): string
            {
                return $this->_title;
            }
        };
    }
}
