<?php

declare(strict_types=1);

namespace Tests\Unit\Search;

use App\Search\Contracts\FulltextSearchable;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;
use Tests\TestCase;

/**
 * `mapIds()` 가 넘겨받은 쿼리의 SELECT 를 재작성하지 않는지 검증.
 *
 * 회귀 배경: `mapIds()` 가 `clone()->select($key)` 로 SELECT 를 통째로 대체해
 * `selectRaw` 로 붙은 `(...) as _ft_score` 를 지웠다. clone 이 보존한
 * `ORDER BY _ft_score DESC` 는 남아 `SQLSTATE[42S22] Unknown column '_ft_score'
 * in 'order clause'` 가 됐다. `queryCallback` 이 있을 때만 도달하므로
 * `->query(fn)` 을 쓰는 Scout `paginate()` 경로(관리자 상품 공통정보 목록 검색)가 500 이었다.
 *
 * 이 클래스가 기존 `DatabaseFulltextEngineTest` 와 분리된 이유: 그쪽은 SQL 을 실행하지 않는
 * 빠른 단위 테스트이고, 그 클래스의 `mapIds` 케이스는 `['query' => null]` 만 넣어 early return
 * 만 타므로 수정 전후 모두 green 이라 증빙이 되지 못한다. 여기서는 실제 FULLTEXT 인덱스를
 * 만들고 SQL 을 실행한다.
 */
class DatabaseFulltextEngineMapIdsTest extends TestCase
{
    /**
     * 프리픽스 미포함 테이블명 (`DB::getTablePrefix()` 가 붙어 실제 테이블이 된다).
     */
    private const TABLE = 'ft_engine_fixtures';

    private DatabaseFulltextEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        if (! DatabaseFulltextEngine::supportsFulltext()) {
            $this->markTestSkipped('FULLTEXT 를 제공하지 않는 DBMS 입니다.');
        }

        $this->engine = new DatabaseFulltextEngine;

        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function ($table): void {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->unsignedInteger('sort_order')->default(0);
        });

        // 엔진은 컬럼마다 별도 MATCH() 를 만들고, MySQL 은 MATCH 의 컬럼 목록과 정확히
        // 일치하는 FULLTEXT 인덱스를 요구한다 → 복합 인덱스가 아니라 컬럼별 인덱스여야 한다.
        // 실제 마이그레이션과 동일하게 코어 헬퍼를 써서 ngram 파서 부착까지 일치시킨다.
        DatabaseFulltextEngine::addFulltextIndex(self::TABLE, 'ft_'.self::TABLE.'_name', 'name');
        DatabaseFulltextEngine::addFulltextIndex(self::TABLE, 'ft_'.self::TABLE.'_description', 'description');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    /**
     * 픽스처 행을 저장합니다.
     *
     * @param  string  $name  이름
     * @param  string  $description  설명
     * @param  int  $sortOrder  정렬 순서
     * @return int 생성된 행 ID
     */
    private function seedRow(string $name, string $description, int $sortOrder = 0): int
    {
        return (int) DB::table(self::TABLE)->insertGetId([
            'name' => $name,
            'description' => $description,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * 검색 빌더를 만듭니다.
     *
     * @param  string  $keyword  검색어
     * @param  callable|null  $queryCallback  소비자 쿼리 콜백
     * @return Builder Scout 빌더
     */
    private function builder(string $keyword, ?callable $queryCallback = null): Builder
    {
        $builder = new Builder(new FulltextEngineFixture, $keyword);

        if ($queryCallback !== null) {
            $builder->query($queryCallback);
        }

        return $builder;
    }

    /**
     * 소비자 정렬이 걸린 페이지네이션 쿼리에서 mapIds 가 살아남는지.
     *
     * 수정 전에는 `QueryException 42S22 Unknown column '_ft_score' in 'order clause'`.
     */
    public function test_map_ids_survives_score_order_by_on_paginated_query(): void
    {
        $first = $this->seedRow('검색대상 문서', '본문 내용', 1);
        $second = $this->seedRow('검색대상 안내', '본문 내용', 2);

        $builder = $this->builder('검색대상', fn ($query) => $query->orderBy('sort_order'));

        $results = $this->engine->paginate($builder, 20, 1);
        $ids = $this->engine->mapIds($results);

        $this->assertEqualsCanonicalizing([$first, $second], $ids->all());
    }

    /**
     * 소비자 콜백이 얹은 **다른** 별칭에 정렬이 걸려도 살아남는지.
     *
     * "스코어 SELECT 만 보존해 재조립" 안을 기각한 이유를 고정한다 — 알려진 별칭 하나만
     * 막으면 `withCount()` 의 `*_count` 같은 평범한 별칭 정렬에서 똑같이 재발한다.
     */
    public function test_map_ids_survives_consumer_added_order_by_alias(): void
    {
        $this->seedRow('검색대상 짧은', '본문 내용');
        $this->seedRow('검색대상 아주아주 긴 이름', '본문 내용');

        $builder = $this->builder(
            '검색대상',
            fn ($query) => $query->selectRaw('CHAR_LENGTH(name) as _len')->orderByDesc('_len'),
        );

        $results = $this->engine->paginate($builder, 20, 1);
        $ids = $this->engine->mapIds($results);

        $this->assertCount(2, $ids);
    }

    /**
     * keysOnly 프루닝이 조립 시점에 이미 적용되어 있는지 (SELECT 에 키 + 스코어만).
     */
    public function test_keys_narrows_select_to_key_and_score(): void
    {
        $this->seedRow('검색대상 문서', '본문 내용');

        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            $captured[] = $query->sql;
        });

        $this->engine->keys($this->builder('검색대상'));

        $selects = array_values(array_filter(
            $captured,
            static fn (string $sql): bool => str_contains($sql, self::TABLE) && str_starts_with(strtolower(ltrim($sql)), 'select'),
        ));

        $this->assertNotEmpty($selects, 'keys() 가 SELECT 를 실행하지 않았습니다.');

        $sql = $selects[0];
        $this->assertStringContainsString('_ft_score', $sql, 'ORDER BY 가 참조하므로 스코어 별칭은 항상 SELECT 에 있어야 합니다.');
        $this->assertStringNotContainsString(
            '`'.DB::getTablePrefix().self::TABLE.'`.*',
            $sql,
            'keysOnly 경로는 전 컬럼을 읽지 않아야 합니다.',
        );
    }

    /**
     * `keys()` 가 관련도 순서를 유지하는지.
     *
     * `reorder()` 로 정렬을 제거하는 안을 기각한 이유를 고정한다 — `keys()` 는 Scout 공개
     * 계약상 관련도 순 키 목록을 약속하므로, 정렬을 지우면 오류 없이 무순서 키를 돌려주는
     * 무음 회귀가 된다.
     */
    public function test_keys_preserves_relevance_order(): void
    {
        // name 가중치가 description 보다 높다 → 이름에 매칭된 행이 앞선다.
        $descriptionHit = $this->seedRow('무관한 제목', '본문에 검색대상 이 들어있다');
        $nameHit = $this->seedRow('검색대상 문서', '무관한 본문');

        $keys = $this->engine->keys($this->builder('검색대상'))->all();

        $this->assertSame(
            [$nameHit, $descriptionHit],
            array_map('intval', $keys),
            'name 가중치가 더 높으므로 이름 매칭이 먼저 와야 합니다.',
        );
    }
}

/**
 * FULLTEXT 엔진 테스트 전용 픽스처 모델.
 */
class FulltextEngineFixture extends Model implements FulltextSearchable
{
    use Searchable;

    protected $table = 'ft_engine_fixtures';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * {@inheritDoc}
     */
    public function searchableColumns(): array
    {
        return ['name', 'description'];
    }

    /**
     * {@inheritDoc}
     */
    public function searchableWeights(): array
    {
        return ['name' => 3.0, 'description' => 1.0];
    }
}
