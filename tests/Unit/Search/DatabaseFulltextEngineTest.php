<?php

namespace Tests\Unit\Search;

use App\Search\Contracts\FulltextSearchable;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;
use Mockery;
use Tests\TestCase;

/**
 * DatabaseFulltextEngine 단위 테스트
 *
 * MySQL FULLTEXT + ngram 커스텀 Scout 엔진의 동작을 검증합니다.
 */
class DatabaseFulltextEngineTest extends TestCase
{
    private DatabaseFulltextEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DatabaseFulltextEngine();
    }

    /**
     * update()가 no-op으로 동작하는지 테스트합니다.
     */
    public function test_update_is_noop(): void
    {
        $models = new Collection();

        // 예외 없이 정상 실행되어야 함
        $this->engine->update($models);
        $this->assertTrue(true);
    }

    /**
     * delete()가 no-op으로 동작하는지 테스트합니다.
     */
    public function test_delete_is_noop(): void
    {
        $models = new Collection();

        $this->engine->delete($models);
        $this->assertTrue(true);
    }

    /**
     * flush()가 no-op으로 동작하는지 테스트합니다.
     */
    public function test_flush_is_noop(): void
    {
        $model = Mockery::mock(Model::class);

        $this->engine->flush($model);
        $this->assertTrue(true);
    }

    /**
     * createIndex()가 no-op으로 동작하는지 테스트합니다.
     */
    public function test_create_index_is_noop(): void
    {
        $this->engine->createIndex('test_index');
        $this->assertTrue(true);
    }

    /**
     * deleteIndex()가 no-op으로 동작하는지 테스트합니다.
     */
    public function test_delete_index_is_noop(): void
    {
        $this->engine->deleteIndex('test_index');
        $this->assertTrue(true);
    }

    /**
     * 빈 검색어로 검색 시 빈 결과를 반환하는지 테스트합니다.
     */
    public function test_search_returns_empty_for_blank_query(): void
    {
        $model = $this->createSearchableModel();
        $builder = new Builder($model, '   ');

        $results = $this->engine->search($builder);

        $this->assertNull($results['query']);
        $this->assertEquals(0, $results['total']);
    }

    /**
     * 빈 검색어로 검색 시 getTotalCount가 0을 반환하는지 테스트합니다.
     */
    public function test_get_total_count_returns_zero_for_empty_results(): void
    {
        $results = ['total' => 0];

        $this->assertEquals(0, $this->engine->getTotalCount($results));
    }

    /**
     * getTotalCount가 올바른 값을 반환하는지 테스트합니다.
     */
    public function test_get_total_count_returns_correct_value(): void
    {
        $results = ['total' => 42];

        $this->assertEquals(42, $this->engine->getTotalCount($results));
    }

    /**
     * mapIds가 빈 결과에 대해 빈 컬렉션을 반환하는지 테스트합니다.
     */
    public function test_map_ids_returns_empty_collection_for_null_query(): void
    {
        $results = ['query' => null];

        $ids = $this->engine->mapIds($results);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $ids);
        $this->assertTrue($ids->isEmpty());
    }

    /**
     * map()이 빈 결과에 대해 빈 컬렉션을 반환하는지 테스트합니다.
     */
    public function test_map_returns_empty_collection_for_null_query(): void
    {
        $model = $this->createSearchableModel();
        $builder = new Builder($model, 'test');
        $results = ['query' => null];

        $mapped = $this->engine->map($builder, $results, $model);

        $this->assertInstanceOf(Collection::class, $mapped);
        $this->assertTrue($mapped->isEmpty());
    }

    /**
     * lazyMap()이 빈 결과에 대해 빈 LazyCollection을 반환하는지 테스트합니다.
     */
    public function test_lazy_map_returns_empty_for_null_query(): void
    {
        $model = $this->createSearchableModel();
        $builder = new Builder($model, 'test');
        $results = ['query' => null];

        $mapped = $this->engine->lazyMap($builder, $results, $model);

        $this->assertInstanceOf(LazyCollection::class, $mapped);
        $this->assertTrue($mapped->isEmpty());
    }

    /**
     * FulltextSearchable 미구현 모델로 검색 시 빈 결과를 반환하는지 테스트합니다.
     */
    public function test_search_returns_empty_for_non_fulltext_searchable_model(): void
    {
        $model = new NonFulltextModel();
        $builder = new Builder($model, '검색어');

        $results = $this->engine->search($builder);

        $this->assertNull($results['query']);
        $this->assertEquals(0, $results['total']);
    }

    /**
     * searchableColumns()가 빈 배열인 모델로 검색 시 빈 결과를 반환하는지 테스트합니다.
     */
    public function test_search_returns_empty_for_empty_searchable_columns(): void
    {
        $model = $this->createSearchableModelWithEmptyColumns();
        $builder = new Builder($model, '검색어');

        $results = $this->engine->search($builder);

        $this->assertNull($results['query']);
        $this->assertEquals(0, $results['total']);
    }

    /**
     * supportsFulltext()가 MySQL/MariaDB에서 true를 반환하는지 테스트합니다.
     */
    public function test_supports_fulltext_returns_true_for_mysql(): void
    {
        // 현재 테스트 환경의 드라이버를 확인
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            $this->assertTrue(DatabaseFulltextEngine::supportsFulltext());
        } else {
            $this->assertFalse(DatabaseFulltextEngine::supportsFulltext());
        }
    }

    /**
     * supportsNgramParser()가 MySQL에서만 true를 반환하는지 테스트합니다.
     * MariaDB를 mysql 드라이버로 연결한 경우에도 false를 반환해야 합니다.
     */
    public function test_supports_ngram_parser_returns_true_only_for_mysql(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mariadb') {
            $this->assertFalse(DatabaseFulltextEngine::supportsNgramParser());
        } elseif ($driver === 'mysql') {
            // mysql 드라이버라도 실제 MariaDB이면 false
            if (DatabaseFulltextEngine::isMariaDb()) {
                $this->assertFalse(DatabaseFulltextEngine::supportsNgramParser());
            } else {
                $this->assertTrue(DatabaseFulltextEngine::supportsNgramParser());
            }
        } else {
            $this->assertFalse(DatabaseFulltextEngine::supportsNgramParser());
        }
    }

    /**
     * isMariaDb()가 mariadb 드라이버에서 true를 반환하는지 테스트합니다.
     */
    public function test_is_mariadb_detects_mariadb_driver(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mariadb') {
            $this->assertTrue(DatabaseFulltextEngine::isMariaDb());
        } elseif ($driver === 'mysql') {
            // mysql 드라이버로 MariaDB에 연결한 경우 서버 버전으로 감지
            $version = DB::selectOne('SELECT VERSION() as version');
            $isActuallyMariaDb = $version && str_contains(strtolower($version->version), 'mariadb');
            $this->assertEquals($isActuallyMariaDb, DatabaseFulltextEngine::isMariaDb());
        } else {
            $this->assertFalse(DatabaseFulltextEngine::isMariaDb());
        }
    }

    /**
     * whereFulltext()가 FULLTEXT 지원 DBMS에서 MATCH...AGAINST를 생성하는지 테스트합니다.
     */
    public function test_where_fulltext_generates_match_against_on_mysql(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'])) {
            $this->markTestSkipped('FULLTEXT 미지원 DBMS에서는 LIKE fallback 테스트로 대체');
        }

        $model = $this->createSearchableModel();
        $query = $model->newQuery();

        DatabaseFulltextEngine::whereFulltext($query, 'name', '검색어');

        $sql = $query->toSql();
        $this->assertStringContainsString('MATCH(`name`) AGAINST(? IN BOOLEAN MODE)', $sql);
    }

    /**
     * whereFulltext()가 OR 조건을 올바르게 생성하는지 테스트합니다.
     */
    public function test_where_fulltext_generates_or_condition(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'])) {
            $this->markTestSkipped('FULLTEXT 미지원 DBMS');
        }

        $model = $this->createSearchableModel();
        $query = $model->newQuery()->where('id', 1);

        DatabaseFulltextEngine::whereFulltext($query, 'name', '검색어', 'or');

        $sql = $query->toSql();
        $this->assertStringContainsString('MATCH(`name`) AGAINST(? IN BOOLEAN MODE)', $sql);
    }

    /**
     * whereFulltext()가 FULLTEXT 미지원 DBMS에서 LIKE fallback을 생성하는지 테스트합니다.
     */
    public function test_where_fulltext_like_fallback_on_non_fulltext_dbms(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            $this->markTestSkipped('FULLTEXT 지원 DBMS에서는 MATCH 테스트로 대체');
        }

        $model = $this->createSearchableModel();
        $query = $model->newQuery();

        DatabaseFulltextEngine::whereFulltext($query, 'name', '검색어');

        $sql = $query->toSql();
        $this->assertStringContainsString('LIKE', $sql);
    }

    /**
     * addFulltextIndex()가 FULLTEXT 미지원 DBMS에서 예외 없이 스킵하는지 테스트합니다.
     */
    public function test_add_fulltext_index_skips_on_non_fulltext_dbms(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'])) {
            $this->markTestSkipped('FULLTEXT 지원 DBMS에서는 스킵 테스트 불가');
        }

        // 예외 없이 정상 스킵되어야 함
        DatabaseFulltextEngine::addFulltextIndex('non_existent_table', 'ft_test', 'name');
        $this->assertTrue(true);
    }

    /**
     * Searchable + FulltextSearchable 모델을 생성합니다.
     *
     * @return Model&FulltextSearchable
     */
    private function createSearchableModel(): Model
    {
        return new class extends Model implements FulltextSearchable
        {
            use Searchable;

            protected $table = 'test_table';

            public function searchableColumns(): array
            {
                return ['name', 'description'];
            }

            public function searchableWeights(): array
            {
                return ['name' => 2.0, 'description' => 1.0];
            }
        };
    }

    /**
     * 빈 컬럼을 가진 FulltextSearchable 모델을 생성합니다.
     *
     * @return Model&FulltextSearchable
     */
    private function createSearchableModelWithEmptyColumns(): Model
    {
        return new class extends Model implements FulltextSearchable
        {
            use Searchable;

            protected $table = 'test_table';

            public function searchableColumns(): array
            {
                return [];
            }

            public function searchableWeights(): array
            {
                return [];
            }
        };
    }
}

/**
 * FulltextSearchable을 구현하지 않은 테스트용 모델
 */
class NonFulltextModel extends Model
{
    use Searchable;

    protected $table = 'test_non_fulltext';

    public function toSearchableArray(): array
    {
        return ['title' => 'test'];
    }
}
