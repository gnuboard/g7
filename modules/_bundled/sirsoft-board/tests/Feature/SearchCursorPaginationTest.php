<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Enums\TotalRelation;
use App\Search\SearchCategoryPayload;
use App\Support\Query\BoundedCount;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 검색의 커서(키셋) 전환 회귀 테스트 (#519)
 *
 * 깊은 페이지를 OFFSET 으로 훑으면 건너뛸 행을 실제로 읽어야 한다. 최신순처럼 실제 컬럼
 * 기준 정렬은 커서로 넘겨 그 비용을 없애고, 관련도순은 계산값 정렬이라 offset 을 유지한다.
 *
 * 여기서는 **어떤 경로를 타는지와 응답 형태**를 고정한다. 커서가 모든 행을 정확히 한 번씩
 * 훑는다는 성질 자체는 코어 계약 테스트(PaginationContractTest)가 검색과 무관한 모델로
 * 이미 고정하고 있고, FULLTEXT 매칭 건수는 InnoDB 전문검색 캐시 상태에 좌우되어
 * 이 계층에서 단언할 대상이 아니다.
 *
 * @scenario case=search_cursor_pagination
 *
 * @effects search_latest_sort_uses_cursor,
 *          search_relevance_sort_stays_on_offset,
 *          search_cursor_payload_keeps_offset_key_set
 */
class SearchCursorPaginationTest extends BoardTestCase
{
    private PostService $postService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postService = app(PostService::class);
    }

    /**
     * 검색 대상 게시글을 만듭니다.
     *
     * @param  int  $count  생성할 수
     */
    private function seedPosts(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Post::create([
                'board_id' => $this->board->id,
                'author_name' => '작성자',
                'ip_address' => '127.0.0.1',
                'title' => '커서키워드 게시글 '.$i,
                'content' => '커서키워드 본문 '.$i,
                'status' => 'published',
                'is_secret' => false,
            ]);
        }
    }

    /**
     * 실제 컬럼 기준 정렬은 커서 경로를 타는지 확인
     *
     * @effects search_latest_sort_uses_cursor
     */
    public function test_real_column_sorts_use_cursor(): void
    {
        // Given: 검색 대상 게시글
        $this->seedPosts(3);

        // When/Then: 선언된 정렬 전부가 커서 페이지네이터를 돌려준다
        foreach (array_keys(PostService::SEARCH_SORT_MAP) as $sort) {
            $page = $this->postService->searchAcrossBoardsByCursor(
                [$this->board->id],
                '커서키워드',
                $sort,
                4,
                'encoded-cursor'
            );

            $this->assertNotNull($page, $sort.' 정렬은 실제 컬럼이므로 커서를 써야 한다');
        }
    }

    /**
     * 관련도순은 커서를 쓰지 않고 offset 을 유지하는지 확인
     *
     * FULLTEXT 점수는 계산값이라 WHERE 절 경계로 쓸 수 없다.
     *
     * @effects search_relevance_sort_stays_on_offset
     */
    public function test_relevance_sort_stays_on_offset(): void
    {
        // Given: 검색 대상 게시글
        $this->seedPosts(3);

        // When: 관련도순으로 커서를 요청
        $page = $this->postService->searchAcrossBoardsByCursor(
            [$this->board->id],
            '커서키워드',
            'relevance',
            4,
            'encoded-cursor'
        );

        // Then: 커서를 쓰지 않는다 (호출자는 offset 경로로 떨어진다)
        $this->assertNull($page);
    }

    /**
     * 커서가 없어도 첫 페이지면 커서 경로로 시작하는지 확인
     *
     * 첫 페이지에 커서가 없는 것은 정상이다. 이를 "커서 없음" 으로 읽어 offset 으로 돌리면
     * 다음 커서가 발급될 자리가 사라져 화면이 영원히 offset 에 머문다.
     *
     * @effects search_latest_sort_uses_cursor
     */
    public function test_first_page_without_cursor_starts_cursor_path(): void
    {
        // Given: 검색 대상 게시글
        $this->seedPosts(3);

        // When: 커서 없이 최신순 첫 페이지 요청
        $page = $this->postService->searchAcrossBoardsByCursor(
            [$this->board->id],
            '커서키워드',
            'latest',
            4,
            null
        );

        // Then: 커서 경로로 처리한다
        $this->assertNotNull($page);
    }

    /**
     * 커서 없이 깊은 페이지를 직접 지목한 요청은 offset 을 유지하는지 확인
     *
     * 주소로 특정 페이지를 열어 둔 링크는 그 페이지를 그대로 보여줘야 한다.
     *
     * @effects search_relevance_sort_stays_on_offset
     */
    public function test_deep_page_without_cursor_stays_on_offset(): void
    {
        // Given: 검색 대상 게시글
        $this->seedPosts(3);

        // When: 커서 없이 2 페이지를 직접 지목
        $page = $this->postService->searchAcrossBoardsByCursor(
            [$this->board->id],
            '커서키워드',
            'latest',
            4,
            null,
            page: 2
        );

        // Then: offset 으로 처리한다
        $this->assertNull($page);
    }

    /**
     * 커서·offset·건수전용 세 응답의 키 집합이 같은지 확인
     *
     * 화면이 세 형태를 분기 없이 그릴 수 있어야 한다. 응답 조립을 도메인마다 손으로 하면
     * 이 형태가 조용히 갈라진다.
     *
     * @effects search_cursor_payload_keeps_offset_key_set
     */
    public function test_cursor_and_offset_payloads_share_key_set(): void
    {
        // Given: 검색 대상 게시글과 세 형태의 페이지 결과
        $this->seedPosts(3);
        $boardIds = [$this->board->id];

        $offsetPage = $this->postService->searchAcrossBoards($boardIds, '커서키워드', 'latest', 4, 1);
        $cursorPage = $this->postService->searchAcrossBoardsByCursor($boardIds, '커서키워드', 'latest', 4, 'first');
        $count = new BoundedCount(3, TotalRelation::Exact, 10000);

        $this->assertNotNull($cursorPage);

        // When: 세 형태의 페이로드를 만든다
        $payloads = [
            'offset' => SearchCategoryPayload::fromBounded($offsetPage, []),
            'cursor' => SearchCategoryPayload::fromCursor($cursorPage, $count, []),
            'count_only' => SearchCategoryPayload::fromCountOnly($count),
        ];

        // Then: 키 집합이 완전히 같다
        $reference = array_keys($payloads['offset']);
        sort($reference);

        foreach ($payloads as $label => $payload) {
            $keys = array_keys($payload);
            sort($keys);
            $this->assertSame($reference, $keys, $label.' 응답의 키 집합이 offset 과 달라졌다');
        }

        // 커서 응답은 마지막 페이지를 계산하지 않는다 (그 값만 계산 불가하다)
        $this->assertNull($payloads['cursor']['last_page']);

        // offset 응답에도 커서 키가 존재한다 — 값만 null 이라 화면이 분기하지 않는다
        $this->assertNull($payloads['offset']['next_cursor']);
        $this->assertNull($payloads['offset']['prev_cursor']);
    }
}
