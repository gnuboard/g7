<?php

declare(strict_types=1);

namespace Tests\Unit\Search;

use App\Search\Contracts\FulltextSearchable;
use App\Search\Engines\DatabaseFulltextEngine;
use App\Search\KeywordSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;
use Tests\TestCase;

/**
 * FULLTEXT 인덱스 부재 게이트 검증 (공개 이슈 #103)
 *
 * 회귀 배경: `applyFulltextPredicate()` / `performSearch()` 의 게이트가 드라이버명만
 * 검사해, MySQL 인데 대상 테이블에 FULLTEXT 인덱스가 없는 설치(부분 실패 설치 등)에서
 * `1191 Can't find FULLTEXT index` 가 발생했다. 그 오류는 리스너의 catch 에 삼켜져
 * 화면에는 "검색 결과 0건" 으로만 나타났다.
 *
 * 수정 후 계약: 드라이버가 지원해도 대상 테이블·컬럼 조합을 커버하는 FULLTEXT 인덱스가
 * 없으면 LIKE 로 내려가고, 그 사실을 조합당 프로세스 1회 경고로 남긴다.
 */
class DatabaseFulltextEngineIndexGateTest extends TestCase
{
    /**
     * 프리픽스 미포함 테이블명 — FULLTEXT 인덱스를 만들지 않는 probe 테이블.
     */
    private const TABLE = 'ft_gate_fixtures';

    protected function setUp(): void
    {
        parent::setUp();

        if (! DatabaseFulltextEngine::supportsFulltext()) {
            $this->markTestSkipped('FULLTEXT 를 제공하지 않는 DBMS 입니다.');
        }

        DatabaseFulltextEngine::forgetFulltextIndexCatalog();

        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function ($table): void {
            $table->id();
            $table->string('title');
            $table->text('content');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);
        DatabaseFulltextEngine::forgetFulltextIndexCatalog();

        parent::tearDown();
    }

    /**
     * T1-a: 인덱스가 없는 테이블은 MATCH 대신 LIKE 폴백으로 조립되어야 한다.
     *
     * 수정 전에는 드라이버만 검사해 MATCH 가 조립됐다 (실행 시 1191).
     *
     * @effects like_fallback_on_missing_index
     */
    public function test_like_fallback_when_table_has_no_fulltext_index(): void
    {
        $query = FulltextGateFixture::query();

        KeywordSearch::apply($query, ['title', 'content'], '문의');

        $sql = strtolower($query->toSql());

        $this->assertStringNotContainsString('match(', $sql, '인덱스 없는 테이블에 MATCH 가 조립되면 실행 시 1191 이 됩니다.');
        $this->assertStringContainsString('like', $sql, 'LIKE 폴백이 조립되어야 합니다.');
    }

    /**
     * T1-b: 복합 인덱스는 단일 컬럼 MATCH 를 커버하지 못한다 (집합 동등 비교, 순서 무관).
     */
    public function test_composite_index_does_not_cover_single_column(): void
    {
        DatabaseFulltextEngine::primeFulltextIndexCatalog([
            'board_posts' => [['title', 'content']],
        ]);

        $this->assertFalse(
            DatabaseFulltextEngine::fulltextIndexCoversColumns('board_posts', ['title']),
            '복합 (title,content) 인덱스는 MATCH(title) 단독을 커버하지 못합니다.'
        );
        $this->assertTrue(
            DatabaseFulltextEngine::fulltextIndexCoversColumns('board_posts', ['title', 'content']),
            '컬럼 집합이 정확히 일치하면 커버합니다.'
        );
        $this->assertTrue(
            DatabaseFulltextEngine::fulltextIndexCoversColumns('board_posts', ['content', 'title']),
            '집합 비교는 순서 무관이어야 합니다 — 깨지면 정상 경로가 LIKE 로 강등됩니다.'
        );
    }

    /**
     * T1-c: Scout 경로(performSearch)는 컬럼마다 단일 MATCH 를 만들므로,
     * 각 컬럼이 개별 인덱스로 커버되지 않으면 LIKE 로 내려가야 한다.
     */
    public function test_scout_search_falls_back_when_columns_lack_individual_indexes(): void
    {
        // 복합 인덱스만 존재하는 상황을 시드 — per-column MATCH 는 커버되지 않는다.
        DatabaseFulltextEngine::primeFulltextIndexCatalog([
            self::TABLE => [['title', 'content']],
        ]);

        $engine = new DatabaseFulltextEngine;
        $results = $engine->paginate(new Builder(new FulltextGateFixture, '문의'), 10, 1);

        $this->assertNotNull($results['query']);

        $sql = strtolower($results['query']->toSql());

        $this->assertStringNotContainsString('match(', $sql, 'per-column MATCH 는 복합 인덱스로 커버되지 않으므로 조립되면 안 됩니다.');
        $this->assertStringContainsString('like', $sql);
    }

    /**
     * T1-f: 두 게이트의 LIKE 폴백이 같은 술어를 만든다 — 검색어의 `%`/`_` 는
     * 와일드카드가 아니라 글자로 취급되어야 한다.
     *
     * 게이트 ①(applyFulltextPredicate)은 `KeywordSearch::applyLikeMatch()` 로 내려가
     * escape 를 거치는데, 게이트 ②(performSearch)가 LIKE 를 손으로 조립하면 같은
     * "부분일치" 인데도 `100%` 같은 검색어에서 결과가 갈린다. 게이트 ② 신설로
     * MySQL 트래픽이 처음 이 경로에 유입되므로 두 경로의 술어를 여기서 고정한다.
     *
     * @effects like_fallback_on_missing_index
     */
    public function test_scout_like_fallback_escapes_wildcards_like_the_shared_path(): void
    {
        $keyword = '100%_할인';
        $expected = '%'.KeywordSearch::escapeLikeWildcards($keyword).'%';

        // 게이트 ① — 공용 경로. 이 경로가 escape 된 패턴을 만든다는 것을 먼저 확정한다.
        $shared = FulltextGateFixture::query();
        KeywordSearch::apply($shared, ['title', 'content'], $keyword);

        $this->assertContains(
            $expected,
            $shared->getBindings(),
            '공용 폴백 경로가 escape 된 패턴을 만들지 않으면 이 테스트의 기준값이 무의미합니다.'
        );

        // 게이트 ② — Scout 경로. 복합 인덱스만 시드해 per-column MATCH 를 미커버로 만든다.
        DatabaseFulltextEngine::primeFulltextIndexCatalog([
            self::TABLE => [['title', 'content']],
        ]);

        $engine = new DatabaseFulltextEngine;
        $results = $engine->paginate(new Builder(new FulltextGateFixture, $keyword), 10, 1);

        $this->assertNotNull($results['query']);

        $this->assertContains(
            $expected,
            $results['query']->getBindings(),
            'Scout 폴백이 % 와 _ 를 escape 하지 않으면 검색어가 와일드카드로 해석되어 공용 경로와 결과가 갈립니다.'
        );
    }

    /**
     * T1-d: 인덱스 부재 폴백 경고는 테이블+컬럼 조합당 프로세스 1회만 남는다.
     *
     * @effects missing_index_fallback_logged_once
     */
    public function test_missing_index_fallback_is_logged_once_per_table_and_columns(): void
    {
        Log::spy();

        $apply = function (array $columns): void {
            $query = FulltextGateFixture::query();
            KeywordSearch::apply($query, $columns, '문의');
        };

        $apply(['title', 'content']);
        $apply(['title', 'content']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'FULLTEXT'))
            ->once();

        $apply(['title']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'FULLTEXT'))
            ->twice();
    }

    /**
     * T1-e: addFulltextIndex 성공 직후 카탈로그가 무효화되어, 새 인덱스가
     * forget 호출 없이도 다음 판정에 반영되어야 한다.
     */
    public function test_add_fulltext_index_invalidates_catalog(): void
    {
        $this->assertFalse(
            DatabaseFulltextEngine::fulltextIndexCoversColumns(self::TABLE, ['title']),
            'probe 테이블은 인덱스 없이 생성되므로 커버되지 않아야 합니다 (카탈로그 적재 확인).'
        );

        DatabaseFulltextEngine::addFulltextIndex(self::TABLE, 'ft_'.self::TABLE.'_title', 'title');

        $this->assertTrue(
            DatabaseFulltextEngine::fulltextIndexCoversColumns(self::TABLE, ['title']),
            'DDL 성공 직후 카탈로그가 무효화되어 새 인덱스가 반영되어야 합니다.'
        );
    }
}

/**
 * 인덱스 게이트 테스트 전용 픽스처 모델 (FULLTEXT 인덱스 없는 테이블).
 */
class FulltextGateFixture extends Model implements FulltextSearchable
{
    use Searchable;

    protected $table = 'ft_gate_fixtures';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * {@inheritDoc}
     */
    public function searchableColumns(): array
    {
        return ['title', 'content'];
    }

    /**
     * {@inheritDoc}
     */
    public function searchableWeights(): array
    {
        return ['title' => 3.0, 'content' => 1.0];
    }
}
