<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 발행 페이지 목록 정렬 색인 회귀 테스트 (#519 F5)
 *
 * 색인은 "만들었다" 가 아니라 "쿼리가 그것을 고른다" 가 목표다. 존재 여부만 확인하면
 * 컬럼 순서가 어긋나 옵티마이저가 무시하는 색인을 정상으로 판정한다.
 *
 * @scenario page-published-sort-index
 *
 * @effects published_sort_index_exists,
 *          published_sort_index_column_order,
 *          page_list_query_uses_published_sort_index
 */
class PublishedSortIndexCoverageTest extends ModuleTestCase
{
    /**
     * MySQL 계열이 아니면 건너뜁니다 (EXPLAIN 출력 형식이 다름).
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('EXPLAIN 판정은 MySQL 계열에서만 수행합니다.');
        }
    }

    /**
     * 정렬을 덮는 색인이 존재하는지 확인
     *
     * @effects published_sort_index_exists
     */
    public function test_published_sort_index_exists(): void
    {
        $names = array_column(Schema::getIndexes('pages'), 'name');

        $this->assertContains(
            'idx_pages_published_created_id',
            $names,
            '발행 조건 + 등록일 정렬을 덮는 색인이 없다'
        );
    }

    /**
     * 색인 컬럼 순서가 조건·정렬 순서와 일치하는지 확인
     *
     * @effects published_sort_index_column_order
     */
    public function test_index_column_order_matches_query_shape(): void
    {
        $index = collect(Schema::getIndexes('pages'))->firstWhere('name', 'idx_pages_published_created_id');

        $this->assertNotNull($index);
        $this->assertSame(
            ['published', 'created_at', 'id'],
            array_values($index['columns']),
            '색인 컬럼 순서가 조건·정렬 순서와 다르다 — 존재해도 정렬에 쓰이지 않는다'
        );
    }

    /**
     * 옵티마이저가 실제로 이 색인을 **선택**하는지 EXPLAIN 으로 확인
     *
     * `possible_keys` 는 "쓸 수 있었다" 일 뿐이라, 후보에만 올라 있고 실제로는 전체
     * 스캔을 골라도 통과해 버린다. 판정은 실행계획이 확정한 `key` 로만 한다.
     *
     * @effects page_list_query_uses_published_sort_index
     */
    public function test_query_plan_uses_the_index(): void
    {
        $rows = [];

        // 행이 몇 건뿐이면 전체 스캔이 정상 선택이라 key 단언이 성립하지 않는다.
        for ($i = 0; $i < 300; $i++) {
            $rows[] = [
                'slug' => 'idx-test-'.$i,
                'title' => json_encode(['ko' => '색인 테스트 '.$i], JSON_UNESCAPED_UNICODE),
                'content' => json_encode(['ko' => '본문'], JSON_UNESCAPED_UNICODE),
                'published' => $i % 10 !== 0,
                'created_at' => now()->subMinutes($i),
                'updated_at' => now(),
            ];
        }

        DB::table('pages')->insert($rows);
        DB::statement('ANALYZE TABLE '.DB::getTablePrefix().'pages');

        $query = Page::query()
            ->where('published', true)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->toBase();

        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());

        $this->assertNotEmpty($plan, 'EXPLAIN 결과가 비어 있다');

        $row = (array) $plan[0];
        $chosen = (string) ($row['key'] ?? '');

        $this->assertStringContainsString(
            'idx_pages_published_created_id',
            $chosen,
            '옵티마이저가 이 색인을 고르지 않았다 (선택: '.($chosen !== '' ? $chosen : '없음(전체 스캔)').
            ', 후보: '.($row['possible_keys'] ?? '없음').') — 컬럼 순서나 조건 형태가 어긋났다'
        );
    }
}
