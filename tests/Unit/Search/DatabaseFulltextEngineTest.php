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

    /**
     * 테스트마다 엔진을 새로 만들고 픽스처 테이블의 FULLTEXT 인덱스 카탈로그를 시드합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DatabaseFulltextEngine;

        // 픽스처 테이블(test_table)은 실존하지 않으므로 인덱스 카탈로그를 시드해
        // MATCH 조립 경로를 검증한다 — 시드하지 않으면 인덱스 부재 게이트(#103)가
        // LIKE 로 내려 보내 조립 단언이 무의미해진다.
        DatabaseFulltextEngine::primeFulltextIndexCatalog([
            'test_table' => [['name']],
        ]);
    }

    /**
     * 시드한 인덱스 카탈로그와 경고 기록을 비워 다음 테스트로 정적 캐시가 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        DatabaseFulltextEngine::forgetFulltextIndexCatalog();

        parent::tearDown();
    }

    /**
     * update()가 no-op으로 동작하는지 테스트합니다.
     *
     * @scenario entry_point=index_sync
     *
     * @effects scout_update_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_update_is_noop(): void
    {
        $models = new Collection;

        // 예외 없이 정상 실행되어야 함
        $this->engine->update($models);
        $this->assertTrue(true);
    }

    /**
     * delete()가 no-op으로 동작하는지 테스트합니다.
     *
     * @scenario entry_point=index_sync
     *
     * @effects scout_delete_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_delete_is_noop(): void
    {
        $models = new Collection;

        $this->engine->delete($models);
        $this->assertTrue(true);
    }

    /**
     * flush()가 no-op으로 동작하는지 테스트합니다.
     *
     * @scenario entry_point=index_sync
     *
     * @effects scout_flush_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_flush_is_noop(): void
    {
        $model = Mockery::mock(Model::class);

        $this->engine->flush($model);
        $this->assertTrue(true);
    }

    /**
     * createIndex()가 no-op으로 동작하는지 테스트합니다.
     *
     * @scenario entry_point=index_sync
     *
     * @effects scout_create_index_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_create_index_is_noop(): void
    {
        $this->engine->createIndex('test_index');
        $this->assertTrue(true);
    }

    /**
     * deleteIndex()가 no-op으로 동작하는지 테스트합니다.
     *
     * @scenario entry_point=index_sync
     *
     * @effects scout_delete_index_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_delete_index_is_noop(): void
    {
        $this->engine->deleteIndex('test_index');
        $this->assertTrue(true);
    }

    /**
     * 빈 검색어로 검색 시 빈 결과를 반환하는지 테스트합니다.
     *
     * @scenario entry_point=search_guard
     *
     * @effects blank_keyword_returns_empty_result
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=result_mapping
     *
     * @effects total_count_reads_result_total
     *
     * @return void 반환값 없음
     */
    public function test_get_total_count_returns_zero_for_empty_results(): void
    {
        $results = ['total' => 0];

        $this->assertEquals(0, $this->engine->getTotalCount($results));
    }

    /**
     * getTotalCount가 올바른 값을 반환하는지 테스트합니다.
     *
     * @scenario entry_point=result_mapping
     *
     * @effects total_count_reads_result_total
     *
     * @return void 반환값 없음
     */
    public function test_get_total_count_returns_correct_value(): void
    {
        $results = ['total' => 42];

        $this->assertEquals(42, $this->engine->getTotalCount($results));
    }

    /**
     * mapIds가 빈 결과에 대해 빈 컬렉션을 반환하는지 테스트합니다.
     *
     * @scenario entry_point=result_mapping
     *
     * @effects null_query_maps_to_empty_key_collection
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=result_mapping
     *
     * @effects null_query_maps_to_empty_model_collection
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=result_mapping
     *
     * @effects null_query_maps_to_empty_lazy_collection
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=search_guard
     *
     * @effects non_fulltext_searchable_model_returns_empty_result
     *
     * @return void 반환값 없음
     */
    public function test_search_returns_empty_for_non_fulltext_searchable_model(): void
    {
        $model = new NonFulltextModel;
        $builder = new Builder($model, '검색어');

        $results = $this->engine->search($builder);

        $this->assertNull($results['query']);
        $this->assertEquals(0, $results['total']);
    }

    /**
     * searchableColumns()가 빈 배열인 모델로 검색 시 빈 결과를 반환하는지 테스트합니다.
     *
     * @scenario entry_point=search_guard
     *
     * @effects empty_searchable_columns_returns_empty_result
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=dbms_detection
     *
     * @effects fulltext_support_follows_driver
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=dbms_detection
     *
     * @effects ngram_parser_only_on_real_mysql
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=dbms_detection
     *
     * @effects mariadb_detected_by_driver_or_server_version
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=where_fulltext
     *
     * @effects where_fulltext_assembles_match_against
     *
     * @return void 반환값 없음
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
     *
     * @scenario entry_point=where_fulltext
     *
     * @effects where_fulltext_or_boolean_assembles_match_against
     *
     * @return void 반환값 없음
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
     *
     * FULLTEXT 지원 DBMS(MySQL·MariaDB)로 도는 시험 환경에서는 건너뛴다.
     *
     * @scenario entry_point=where_fulltext
     *
     * @effects where_fulltext_falls_back_to_like_without_fulltext
     *
     * @return void 반환값 없음
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
     *
     * FULLTEXT 지원 DBMS(MySQL·MariaDB)로 도는 시험 환경에서는 건너뛴다.
     *
     * @scenario entry_point=index_creation
     *
     * @effects add_fulltext_index_skips_without_fulltext
     *
     * @return void 반환값 없음
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
     * sanitizeBooleanModeKeyword()가 BOOLEAN MODE 연산자를 제거하고 phrase로 변환하는지 테스트합니다.
     *
     * @effects plain_keyword_matches
     *
     * @return void 반환값 없음
     */
    public function test_sanitize_boolean_mode_keyword_wraps_tokens_in_phrases(): void
    {
        $this->assertSame('"셔츠"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('셔츠'));
        $this->assertSame('"shirt"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('shirt'));
        // 다중 토큰 → 각각 phrase
        $this->assertSame('"셔츠" "면"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('셔츠 면'));
    }

    /**
     * sanitizeBooleanModeKeyword()가 각 BOOLEAN MODE 연산자를 제거하는지 테스트합니다.
     *
     * @effects no_500_on_special_chars
     *
     * @return void 반환값 없음
     */
    public function test_sanitize_boolean_mode_keyword_strips_operators(): void
    {
        // 연산자가 제거되고 남은 토큰만 phrase 로 묶임
        $this->assertSame('"상품"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('+상품'));
        $this->assertSame('"바지"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('-바지'));
        $this->assertSame('"상품"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('상품)'));
        $this->assertSame('"셔츠"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('(셔츠'));
        $this->assertSame('"키워드"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('~키워드'));
        $this->assertSame('"상품"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('상품*'));
        $this->assertSame('"셔츠"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('"셔츠'));
        $this->assertSame('"user"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('@user'));
        // <script> → 연산자/태그 제거 후 'script'
        $this->assertSame('"script"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('<script>'));
    }

    /**
     * sanitizeBooleanModeKeyword()가 토큰 하나를 MySQL 구문 낱말 한도 안으로 자르는지 테스트합니다.
     *
     * ngram 파서는 공백 없는 토큰을 글자 단위 n-gram 낱말로 쪼개고, InnoDB 는 따옴표 구문 하나에
     * 낱말이 128개를 넘으면 오류(191 Too many words in a FTS phrase)를 냅니다. 한글 130자 검색이
     * 목록 화면을 500 으로 만들던 결함의 회귀 잠금입니다.
     *
     * @effects long_token_capped_to_phrase_word_limit
     *
     * @return void 반환값 없음
     */
    public function test_sanitize_boolean_mode_keyword_caps_each_phrase_to_phrase_word_limit(): void
    {
        $long = str_repeat('가', 130);
        $this->assertSame('"'.str_repeat('가', 128).'"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword($long));

        // 한도 이하 토큰은 그대로, 여러 토큰은 각각 자른다
        $this->assertSame('"'.str_repeat('나', 128).'"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword(str_repeat('나', 128)));
        $this->assertSame(
            '"'.str_repeat('a', 128).'" "짧음"',
            DatabaseFulltextEngine::sanitizeBooleanModeKeyword(str_repeat('a', 200).' 짧음')
        );
    }

    /**
     * sanitizeBooleanModeKeyword()가 연산자만 입력 시 빈 문자열을 반환하는지 테스트합니다.
     *
     * @effects operators_only_returns_empty
     *
     * @return void 반환값 없음
     */
    public function test_sanitize_boolean_mode_keyword_returns_empty_for_operators_only(): void
    {
        $this->assertSame('', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('< >'));
        $this->assertSame('', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('+-~*'));
        $this->assertSame('', DatabaseFulltextEngine::sanitizeBooleanModeKeyword('   '));
        $this->assertSame('', DatabaseFulltextEngine::sanitizeBooleanModeKeyword(''));
    }

    /**
     * sanitizeBooleanModeKeyword()가 제어문자를 제거하는지 테스트합니다.
     *
     * @effects control_chars_stripped
     *
     * @return void 반환값 없음
     */
    public function test_sanitize_boolean_mode_keyword_removes_control_chars(): void
    {
        $this->assertSame('"셔츠"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword("셔츠\x00"));
        $this->assertSame('"a" "b"', DatabaseFulltextEngine::sanitizeBooleanModeKeyword("a\tb"));
    }

    /**
     * whereFulltext()가 특수문자 입력 시 정제된 안전한 phrase를 바인딩하는지 테스트합니다.
     *
     * @scenario entry_point=where_fulltext
     *
     * @effects sanitized_phrase_bound
     *
     * @return void 반환값 없음
     */
    public function test_where_fulltext_binds_sanitized_keyword(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'])) {
            $this->markTestSkipped('FULLTEXT 미지원 DBMS');
        }

        $model = $this->createSearchableModel();
        $query = $model->newQuery();

        DatabaseFulltextEngine::whereFulltext($query, 'name', '<script>');

        $sql = $query->toSql();
        $this->assertStringContainsString('MATCH(`name`) AGAINST(? IN BOOLEAN MODE)', $sql);
        // 원본 특수문자가 아닌 정제된 phrase 가 바인딩
        $this->assertContains('"script"', $query->getBindings());
    }

    /**
     * whereFulltext()가 연산자만 입력 시 항상 거짓인 조건으로 빈 결과를 만드는지 테스트합니다.
     *
     * 조건이 어떤 문자열로 렌더되는지(`1 = 0` / `0 = 1`)는 빌더 구현 사항이므로 단언하지
     * 않는다. 여기서 고정할 것은 **매칭을 시도하지 않고(AGAINST 없음) 결과가 비어야 한다**
     * 는 동작이다. 리터럴을 박아 두면 빈 whereIn 처럼 동등한 구현으로 바꿀 때 깨진다.
     *
     * @scenario entry_point=where_fulltext
     *
     * @effects operators_only_returns_empty, where_fulltext_operators_only_adds_constant_false
     *
     * @return void 반환값 없음
     */
    public function test_where_fulltext_uses_false_condition_for_operators_only(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'])) {
            $this->markTestSkipped('FULLTEXT 미지원 DBMS');
        }

        $model = $this->createSearchableModel();
        $query = $model->newQuery();

        DatabaseFulltextEngine::whereFulltext($query, 'name', '< >');

        $sql = $query->toSql();

        // 매칭을 시도하지 않는다
        $this->assertStringNotContainsString('AGAINST', $sql);
        // 조건이 아예 없으면 전체 행이 나온다 — 조건은 반드시 붙어야 한다
        $this->assertStringContainsString('where', strtolower($sql));
        // 그 조건은 어떤 행도 통과시키지 않는 상수 거짓이어야 한다.
        // `1 = 0`(raw)과 `0 = 1`(빈 whereIn) 은 같은 뜻이므로 둘 다 허용한다.
        $this->assertMatchesRegularExpression('/\b(?:1\s*=\s*0|0\s*=\s*1)\b/', $sql);
        // 상수 거짓 조건에는 바인딩이 없다 (사용자 입력이 새어 들어가지 않았다는 뜻)
        $this->assertSame([], $query->getBindings());
    }

    /**
     * Searchable + FulltextSearchable 모델을 생성합니다.
     *
     * @return Model&FulltextSearchable test_table 의 name·description 을 검색 대상으로 선언한 익명 모델
     */
    private function createSearchableModel(): Model
    {
        return new class extends Model implements FulltextSearchable
        {
            use Searchable;

            protected $table = 'test_table';

            /**
             * FULLTEXT 검색 대상 컬럼을 반환합니다.
             *
             * @return array<int, string> 검색 대상 컬럼명 (name, description)
             */
            public function searchableColumns(): array
            {
                return ['name', 'description'];
            }

            /**
             * 컬럼별 스코어 가중치를 반환합니다.
             *
             * @return array<string, float> 컬럼명 => 가중치 (name 2.0, description 1.0)
             */
            public function searchableWeights(): array
            {
                return ['name' => 2.0, 'description' => 1.0];
            }
        };
    }

    /**
     * 빈 컬럼을 가진 FulltextSearchable 모델을 생성합니다.
     *
     * @return Model&FulltextSearchable 검색 대상 컬럼·가중치가 모두 빈 익명 모델
     */
    private function createSearchableModelWithEmptyColumns(): Model
    {
        return new class extends Model implements FulltextSearchable
        {
            use Searchable;

            protected $table = 'test_table';

            /**
             * 검색 대상 컬럼이 없음을 반환합니다 (엔진의 빈 컬럼 가드 검증용).
             *
             * @return array<int, string> 빈 배열
             */
            public function searchableColumns(): array
            {
                return [];
            }

            /**
             * 가중치가 없음을 반환합니다.
             *
             * @return array<string, float> 빈 배열
             */
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

    /**
     * Scout 색인용 배열을 반환합니다 (FULLTEXT 엔진은 이 값을 쓰지 않는다).
     *
     * @return array<string, string> 고정 색인 데이터
     */
    public function toSearchableArray(): array
    {
        return ['title' => 'test'];
    }
}
