<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Listeners;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Page\Listeners\SearchPagesListener;
use Modules\Sirsoft\Page\Services\PageService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

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

        // 커서 경로는 코어 정책이 판정한다 — 커서를 보내지 않는 이 케이스들에서는
        // 서비스가 null 을 돌려주고 호출자는 offset 경로로 떨어진다.
        $this->pageServiceMock
            ->shouldReceive('searchByKeywordWithCursor')
            ->andReturnNull()
            ->byDefault();

        $this->listener = new SearchPagesListener($this->pageServiceMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 검색 실패가 "결과 0건" 으로 위장되지 않고 failed 페이로드로 표면화되는지 확인 (#103)
     *
     * @effects failed_flag_in_response, exception_stack_logged
     */
    public function test_search_failure_surfaces_failed_payload_and_logs_exception(): void
    {
        Log::spy();

        $exception = new \RuntimeException('DB 오류 재현');
        $this->pageServiceMock
            ->shouldReceive('searchByKeywordWithCursor')
            ->andThrow($exception);

        $context = [
            'type' => 'pages',
            'q' => '문의',
            'sort' => 'relevance',
            'page' => 1,
            'per_page' => 10,
            'user' => null,
            'request' => null,
        ];

        $result = $this->listener->searchPages([], $context);

        $this->assertArrayHasKey('pages', $result);
        $this->assertTrue($result['pages']['failed'] ?? false, '실패 카테고리에는 failed 플래그가 실려야 합니다.');
        $this->assertFalse($result['pages']['total_is_exact'], '실패한 0건을 "정확한 0건" 으로 말하면 안 됩니다.');
        $this->assertSame([], $result['pages']['items']);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $ctx = []) => ($ctx['exception'] ?? null) === $exception)
            ->once();
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
        $this->assertArrayHasKey('core.search.index_validation_rules', $hooks);
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
     * 검색 결과 페이지(BoundedPage)를 만듭니다.
     *
     * 저장소가 반환하는 계약과 같은 형태를 테스트에서도 그대로 씁니다.
     *
     * @param  Collection  $items  페이지 항목
     * @param  int  $total  총 건수
     * @param  int  $perPage  페이지당 건수
     * @return BoundedPage 페이지 결과
     */
    private function boundedPage(Collection $items, int $total, int $perPage = 5): BoundedPage
    {
        return new BoundedPage(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: 1,
            totalRelation: TotalRelation::Exact,
            resultCap: 10000,
            hasMorePages: false,
        );
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
            ->andReturn(new BoundedCount(5, TotalRelation::Exact, 10000));

        $result = $this->listener->searchPages([], [
            'q' => 'Laravel',
            'type' => 'posts',
        ]);

        $this->assertEquals(5, $result['pages']['total']);
        $this->assertEmpty($result['pages']['items']);
    }

    /**
     * 비활성 탭 배지도 잘린 건수를 정확한 것처럼 보고하지 않는지 확인 (#519)
     */
    public function test_count_only_badge_carries_accuracy_when_truncated(): void
    {
        $this->pageServiceMock
            ->shouldReceive('countByKeyword')
            ->with('Laravel')
            ->once()
            ->andReturn(new BoundedCount(10000, TotalRelation::AtLeast, 10000));

        $result = $this->listener->searchPages([], [
            'q' => 'Laravel',
            'type' => 'posts',
        ]);

        $this->assertSame(10000, $result['pages']['total']);
        $this->assertSame('at_least', $result['pages']['total_relation']);
        $this->assertFalse($result['pages']['total_is_exact']);
        $this->assertSame(10000, $result['pages']['result_cap']);
    }

    /**
     * all 탭에서 검색 결과를 반환하는지 확인
     */
    public function test_search_pages_returns_results_for_all_tab(): void
    {
        $fakePage = $this->makeFakePage('이용약관', 'terms', '본 약관은 서비스 이용조건을 규정합니다.');

        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->with('약관', 'created_at', 'desc', 5, 1)
            ->once()
            ->andReturn($this->boundedPage(new Collection([$fakePage]), 1));

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
            ->with('개인정보', 'created_at', 'desc', 10, 1)
            ->once()
            ->andReturn($this->boundedPage(new Collection([$fakePage]), 1));

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
            ->with('G7', 'created_at', 'asc', 5, 1)
            ->once()
            ->andReturn($this->boundedPage(new Collection, 0));

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
            ->with('G7', 'created_at', 'desc', 5, 1)
            ->once()
            ->andReturn($this->boundedPage(new Collection, 0));

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
            ->andReturn($this->boundedPage(new Collection([$fakePage]), 1));

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
            ->andReturn($this->boundedPage(new Collection([$fakePage]), 1));

        $result = $this->listener->searchPages([], [
            'q' => '이용',
            'type' => 'all',
            'all_tab_limit' => 5,
        ]);

        $item = $result['pages']['items'][0];
        $this->assertStringContainsString('<mark>', $item['title_highlighted']);
    }

    /**
     * 제목에 삽입된 태그가 하이라이트 필드에서 이스케이프되는지 확인 (⑧)
     *
     * @scenario case=search_highlight_escape
     *
     * @effects highlighted_fields_escaped
     */
    public function test_search_pages_escapes_markup_in_highlighted_title(): void
    {
        $fakePage = $this->makeFakePage('<img src=x onerror=alert(1)> 이용약관', 'terms', '');

        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->once()
            ->andReturn($this->boundedPage(new Collection([$fakePage]), 1));

        $result = $this->listener->searchPages([], [
            'q' => '이용',
            'type' => 'all',
            'all_tab_limit' => 5,
        ]);

        $item = $result['pages']['items'][0];
        $this->assertStringNotContainsString('<img', $item['title_highlighted']);
        $this->assertStringContainsString('&lt;img', $item['title_highlighted']);
        $this->assertStringContainsString('<mark>이용</mark>', $item['title_highlighted']);
    }

    /**
     * 엔티티로 인코딩된 태그가 본문 프리뷰에서 부활하지 않는지 확인 (N-8)
     *
     * @scenario case=preview_entity_no_resurrect
     *
     * @effects preview_does_not_resurrect_entities
     */
    public function test_search_pages_does_not_resurrect_entity_encoded_tags_in_preview(): void
    {
        $fakePage = $this->makeFakePage('약관', 'terms', '&lt;script&gt;alert(1)&lt;/script&gt; 약관 본문입니다.');

        $this->pageServiceMock
            ->shouldReceive('searchByKeyword')
            ->once()
            ->andReturn($this->boundedPage(new Collection([$fakePage]), 1));

        $result = $this->listener->searchPages([], [
            'q' => '약관',
            'type' => 'all',
            'all_tab_limit' => 5,
        ]);

        $item = $result['pages']['items'][0];
        $this->assertStringNotContainsString('<script>', $item['content_preview']);
        $this->assertStringNotContainsString('<script>', $item['content_preview_highlighted']);
    }

    /**
     * PageService 예외 발생 시 다른 카테고리는 보존하고 pages 는 failed 로 표면화하는지 확인
     *
     * 종전에는 키 미설정으로 삼켜 화면이 "검색 결과 없음" 을 그렸다 (#103).
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

        // 예외가 전파되지 않고, 다른 카테고리 결과는 그대로 보존된다
        $this->assertSame($original['posts'], $result['posts']);
        // pages 는 실패 페이로드로 표면화된다
        $this->assertTrue($result['pages']['failed'] ?? false);
        $this->assertFalse($result['pages']['total_is_exact']);
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
     * all 탭에서 pages_count 와 조회된 항목이 그대로 반환되는지 확인
     *
     * 항목은 저장소가 이미 요청한 분량만 조회해 오므로 여기서 다시 자르지 않는다.
     * 종전에는 전량을 받아 PHP 에서 잘랐고, 그래서 매칭이 많을수록 메모리가 함께 커졌다.
     */
    public function test_build_pages_response_returns_summary_for_all_tab(): void
    {
        $items = array_map(fn ($i) => ['id' => $i], range(1, 3));
        $results = [
            'pages' => ['total' => 10, 'items' => $items],
        ];

        $response = $this->listener->buildPagesResponse([], $results, [
            'type' => 'all',
            'all_tab_limit' => 3,
        ]);

        $this->assertEquals(10, $response['pages_count']);
        $this->assertEquals(10, $response['pages']['total']);
        $this->assertCount(3, $response['pages']['items'], '조회된 항목을 그대로 싣는다');
    }

    /**
     * pages 탭에서 페이지네이션 메타가 포함되는지 확인
     */
    public function test_build_pages_response_returns_pagination_for_pages_tab(): void
    {
        // 2페이지 분량(10건)만 조회돼 있는 상태 — 저장소가 이미 잘라서 준다
        $items = array_map(fn ($i) => ['id' => $i], range(11, 20));
        $results = [
            'pages' => [
                'total' => 25,
                'items' => $items,
                'last_page' => 3,
                'has_more_pages' => true,
            ],
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
        $this->assertTrue($response['has_more_pages']);
    }

    /**
     * 총 건수가 상한에 걸리면 마지막 페이지가 null 로 전달되는지 확인
     *
     * 계산할 수 없는 값을 1 이나 임의 숫자로 채우면 화면이 그것을 사실로 읽는다.
     */
    public function test_build_pages_response_omits_last_page_when_total_is_bounded(): void
    {
        $results = [
            'pages' => [
                'total' => 10000,
                'total_is_exact' => false,
                'total_relation' => 'at_least',
                'result_cap' => 10000,
                'items' => [['id' => 1]],
                'last_page' => null,
                'has_more_pages' => true,
            ],
        ];

        $response = $this->listener->buildPagesResponse([], $results, [
            'type' => 'pages',
            'page' => 1,
            'per_page' => 10,
        ]);

        $this->assertNull($response['last_page'], '마지막 페이지는 계산 불가');
        $this->assertTrue($response['has_more_pages'], '"다음" 이동은 계속 열려 있다');
        $this->assertFalse($response['pages']['total_is_exact']);
        $this->assertEquals('at_least', $response['pages']['total_relation']);
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

            public ?Carbon $published_at;

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
