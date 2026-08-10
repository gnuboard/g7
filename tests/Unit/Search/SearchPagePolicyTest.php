<?php

namespace Tests\Unit\Search;

use App\Search\SearchPagePolicy;
use Tests\TestCase;

/**
 * 검색 커서 적용 판정 계약 테스트 (#519)
 *
 * 이 판정을 도메인마다 각자 구현하면 규칙이 검색 모듈 수만큼 갈라진다.
 * 코어가 규칙을 소유한다는 것을 여기서 고정한다.
 *
 * @scenario case=search_cursor_policy
 *
 * @effects cursor_starts_on_first_page,
 *          deep_page_without_cursor_stays_on_offset,
 *          cursor_requires_real_columns,
 *          unknown_sort_name_falls_back_to_offset
 */
class SearchPagePolicyTest extends TestCase
{
    /** 검사에 쓰는 정렬 선언 (도메인이 제공하는 형태) */
    private const SORT_MAP = [
        'latest' => ['created_at', 'desc'],
        'views' => ['view_count', 'desc'],
    ];

    /** 커서 경계로 허용한 실제 컬럼 */
    private const CURSOR_COLUMNS = ['created_at', 'view_count'];

    /**
     * 커서가 없어도 첫 페이지면 커서로 시작하는지 확인
     *
     * 커서를 받은 요청만 커서로 처리하면 첫 커서가 만들어질 자리가 없다. 서버는 커서
     * 모드에서만 다음 커서를 내보내므로, 화면은 건넬 커서가 없어 영원히 offset 에 머문다.
     * 첫 페이지는 커서가 없는 것이 정상이므로 이를 "커서 없음" 이 아니라 "시작점" 으로 읽는다.
     *
     * @effects cursor_starts_on_first_page
     */
    public function test_first_page_starts_cursor_mode(): void
    {
        $sortKeys = SearchPagePolicy::sortKeys('latest', self::SORT_MAP);

        $this->assertTrue(SearchPagePolicy::usesCursor(null, $sortKeys, self::CURSOR_COLUMNS));
        $this->assertTrue(SearchPagePolicy::usesCursor('', $sortKeys, self::CURSOR_COLUMNS));
        $this->assertTrue(SearchPagePolicy::usesCursor(null, $sortKeys, self::CURSOR_COLUMNS, page: 1));
    }

    /**
     * 커서 없이 깊은 페이지를 직접 지목한 요청은 offset 을 유지하는지 확인
     *
     * 주소로 특정 페이지를 열어 둔 링크(딥링크·북마크)는 그 페이지를 그대로 보여줘야 한다.
     * 커서로 바꿔 버리면 첫 페이지로 되돌아가 링크가 가리키던 자리를 잃는다.
     *
     * @effects deep_page_without_cursor_stays_on_offset
     */
    public function test_deep_page_without_cursor_stays_on_offset(): void
    {
        $sortKeys = SearchPagePolicy::sortKeys('latest', self::SORT_MAP);

        $this->assertFalse(SearchPagePolicy::usesCursor(null, $sortKeys, self::CURSOR_COLUMNS, page: 2));
        $this->assertFalse(SearchPagePolicy::usesCursor('', $sortKeys, self::CURSOR_COLUMNS, page: 7));
    }

    /**
     * 깊은 페이지라도 커서를 들고 왔으면 커서로 이어가는지 확인
     *
     * @effects cursor_starts_on_first_page
     */
    public function test_cursor_wins_over_page_number(): void
    {
        $sortKeys = SearchPagePolicy::sortKeys('latest', self::SORT_MAP);

        $this->assertTrue(SearchPagePolicy::usesCursor('encoded-cursor', $sortKeys, self::CURSOR_COLUMNS, page: 9));
    }

    /**
     * 실제 컬럼 정렬 + 커서가 있으면 커서로 응답하는지 확인
     *
     * @effects cursor_requires_real_columns
     */
    public function test_real_column_sort_with_cursor_uses_cursor(): void
    {
        foreach (['latest', 'views'] as $sort) {
            $sortKeys = SearchPagePolicy::sortKeys($sort, self::SORT_MAP);

            $this->assertTrue(
                SearchPagePolicy::usesCursor('encoded-cursor', $sortKeys, self::CURSOR_COLUMNS),
                $sort.' 정렬은 실제 컬럼이므로 커서를 쓸 수 있어야 한다'
            );
        }
    }

    /**
     * 선언에 없는 정렬 이름(관련도순 등)은 offset 을 유지하는지 확인
     *
     * 관련도순은 FULLTEXT 점수라는 계산값으로 정렬하므로 WHERE 절 경계로 쓸 수 없다.
     *
     * @effects unknown_sort_name_falls_back_to_offset
     */
    public function test_unknown_sort_name_falls_back_to_offset(): void
    {
        $sortKeys = SearchPagePolicy::sortKeys('relevance', self::SORT_MAP);

        $this->assertSame([], $sortKeys);
        $this->assertFalse(SearchPagePolicy::usesCursor('encoded-cursor', $sortKeys, self::CURSOR_COLUMNS));
    }

    /**
     * 허용 목록에 없는 컬럼으로 정렬하면 커서를 쓰지 않는지 확인
     *
     * @effects cursor_requires_real_columns
     */
    public function test_column_outside_whitelist_falls_back_to_offset(): void
    {
        $sortKeys = SearchPagePolicy::sortKeys('score', ['score' => ['_ft_score', 'desc']]);

        $this->assertFalse(SearchPagePolicy::usesCursor('encoded-cursor', $sortKeys, self::CURSOR_COLUMNS));
    }
}
