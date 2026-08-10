<?php

namespace Tests\Unit\Search;

use App\Extension\HookManager;
use App\Search\Contracts\KeywordPredicateProvider;
use App\Search\DTO\KeywordSearchContext;
use App\Search\Engines\DatabaseFulltextEngine;
use App\Search\KeywordSearch;
use App\Support\Query\PaginationLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 키워드 술어 해석기 계약 테스트 (#519)
 *
 * G7 은 플러그인이 검색 엔진을 등록할 수 있게 설계돼 있다. 그런데 저장소가 구체 엔진의
 * 정적 메서드를 직접 부르면 등록된 엔진은 **호출될 기회 자체가 없어진다** — 오류도 경고도
 * 없이 그 사이트의 검색만 조용히 다른 방식으로 동작한다.
 *
 * 여기서는 "활성 엔진이 실제로 호출되는가" 를 고정한다. 조건이 어떤 SQL 로 렌더되는지는
 * 엔진의 자유이므로 단언하지 않는다.
 *
 * @scenario case=keyword_predicate_contract
 *
 * @effects keyword_predicate_delegates_to_active_engine,
 *          keyword_predicate_falls_back_when_engine_lacks_contract,
 *          like_operator_is_declarative_not_hardcoded,
 *          like_fallback_escapes_wildcards
 */
class KeywordSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        KeywordSearch::forgetFallbackWarnings();
    }

    protected function tearDown(): void
    {
        HookManager::clearFilter(KeywordSearch::LIKE_OPERATORS_FILTER);

        parent::tearDown();
    }

    /**
     * 검색 대상 모델을 만듭니다.
     */
    private function makeQuery(): Builder
    {
        $model = new class extends Model
        {
            protected $table = 'keyword_search_probe';
        };

        return $model->newQuery();
    }

    /**
     * 활성 엔진을 지정한 인스턴스로 교체합니다.
     *
     * @param  Engine  $engine  활성으로 만들 엔진
     */
    private function useEngine(Engine $engine): void
    {
        $manager = $this->mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($engine);
        $this->app->instance(EngineManager::class, $manager);
    }

    /**
     * 계약을 구현한 엔진이 실제로 호출되는지 확인
     *
     * @effects keyword_predicate_delegates_to_active_engine
     */
    #[Test]
    public function delegates_to_active_engine_that_implements_the_contract(): void
    {
        $engine = $this->makeRecordingEngine();

        $this->useEngine($engine);

        $query = $this->makeQuery();
        KeywordSearch::apply($query, ['title', 'content'], '검색어');

        $this->assertSame(
            [[['title', 'content'], '검색어', 'and']],
            $engine->calls,
            '활성 엔진이 호출되지 않았다 — 플러그인 엔진이 우회된다.'
        );
        $this->assertStringNotContainsString('like', strtolower($query->toSql()), '엔진이 만든 조건 대신 폴백이 적용됐다.');
    }

    /**
     * 호출 내역을 기록하는 계약 구현 엔진을 만듭니다.
     */
    private function makeRecordingEngine(): Engine
    {
        return new class extends Engine implements KeywordPredicateProvider
        {
            public array $calls = [];

            public ?KeywordSearchContext $lastContext = null;

            public function applyKeywordPredicate(Builder $query, array $columns, string $keyword, string $boolean, KeywordSearchContext $context): void
            {
                $this->calls[] = [$columns, $keyword, $boolean];
                $this->lastContext = $context;
                $query->whereIn($query->getModel()->getQualifiedKeyName(), [7]);
            }

            public function update($models) {}

            public function delete($models) {}

            public function search(\Laravel\Scout\Builder $builder) {}

            public function paginate(\Laravel\Scout\Builder $builder, $perPage, $page) {}

            public function mapIds($results) {}

            public function map(\Laravel\Scout\Builder $builder, $results, $model) {}

            public function lazyMap(\Laravel\Scout\Builder $builder, $results, $model) {}

            public function getTotalCount($results) {}

            public function flush($model) {}

            public function createIndex($name, array $options = []) {}

            public function deleteIndex($name) {}
        };
    }

    /**
     * 엔진에게 키 집합 상한이 전달되는지 확인
     *
     * 외부 검색 서버를 쓰는 엔진은 자기 서버에서 키 집합을 받아 조건으로 붙인다. 상한을
     * 손에 쥐어 주지 않으면 매칭이 큰 검색어에서 그 집합 자체가 메모리 폭발이 된다 —
     * 이 프로젝트가 실제로 겪은 결함이다. 규정만으로는 강제할 수 없으므로 **값이 실제로
     * 도달하는지**를 고정한다.
     *
     * @effects keyword_predicate_passes_key_cap_to_engine
     */
    #[Test]
    public function passes_the_key_cap_to_the_engine(): void
    {
        $engine = $this->makeRecordingEngine();
        $this->useEngine($engine);

        $query = $this->makeQuery();
        KeywordSearch::apply($query, ['title'], '검색어', 'and', 'search');

        $this->assertNotNull($engine->lastContext, '엔진이 술어 생성 조건을 받지 못했다.');
        $this->assertSame(
            PaginationLimits::resultCap('search'),
            $engine->lastContext->keyCap,
            '엔진이 받은 키 집합 상한이 목록 총 건수 상한과 다르다 — 두 기준이 갈라지면 "엔진이 돌려준 건수" 와 "화면이 보고하는 총 건수" 가 어긋난다.'
        );
    }

    /**
     * 계약 미구현 엔진에서는 부분일치로 내려가는지 확인
     *
     * @effects keyword_predicate_falls_back_when_engine_lacks_contract
     */
    #[Test]
    public function falls_back_to_partial_match_when_engine_lacks_the_contract(): void
    {
        $engine = new class extends Engine
        {
            public function update($models) {}

            public function delete($models) {}

            public function search(\Laravel\Scout\Builder $builder) {}

            public function paginate(\Laravel\Scout\Builder $builder, $perPage, $page) {}

            public function mapIds($results) {}

            public function map(\Laravel\Scout\Builder $builder, $results, $model) {}

            public function lazyMap(\Laravel\Scout\Builder $builder, $results, $model) {}

            public function getTotalCount($results) {}

            public function flush($model) {}

            public function createIndex($name, array $options = []) {}

            public function deleteIndex($name) {}
        };

        $this->useEngine($engine);

        $query = $this->makeQuery();
        KeywordSearch::apply($query, ['title'], '검색어');

        $this->assertStringContainsString('like', strtolower($query->toSql()));
        $this->assertContains('%검색어%', $query->getBindings());
    }

    /**
     * 번들 엔진이 계약을 구현하는지 확인
     *
     * 구현하지 않으면 MySQL 설치본조차 폴백으로 내려간다.
     *
     * @effects keyword_predicate_delegates_to_active_engine
     */
    #[Test]
    public function bundled_engine_implements_the_contract(): void
    {
        $this->assertInstanceOf(
            KeywordPredicateProvider::class,
            new DatabaseFulltextEngine,
            '번들 엔진이 키워드 술어 계약을 구현하지 않는다.'
        );
    }

    /**
     * 부분일치 연산자가 선언형 표에서 오는지 확인
     *
     * 코드에 드라이버명을 박으면 공식 지원 DBMS 가 늘 때마다 코어를 고쳐야 한다.
     * 여기서는 표에 없던 드라이버를 훅으로 선언했을 때 그 연산자가 실제로 쓰이는지 본다.
     *
     * @effects like_operator_is_declarative_not_hardcoded
     */
    #[Test]
    public function like_operator_comes_from_the_declarative_table(): void
    {
        $driver = DB::getDriverName();

        HookManager::addFilter(KeywordSearch::LIKE_OPERATORS_FILTER, function (array $operators) use ($driver) {
            $operators[$driver] = 'ilike';

            return $operators;
        }, 10, ['type' => 'filter']);

        $query = $this->makeQuery();
        KeywordSearch::applyLikeMatch($query, ['title'], '검색어');

        // `ilike` 는 문자열로 `like` 를 포함하므로 부분일치 단언은 두 연산자를 구분하지
        // 못한다 — 낱말 경계로 정확히 가른다.
        $this->assertMatchesRegularExpression('/\bilike\b/i', $query->toSql());
    }

    /**
     * 표에 없는 드라이버는 기본 연산자를 쓰는지 확인
     *
     * @effects like_operator_is_declarative_not_hardcoded
     */
    #[Test]
    public function unknown_driver_uses_the_configured_default(): void
    {
        Config::set('core.search.like_operators', []);

        $query = $this->makeQuery();
        KeywordSearch::applyLikeMatch($query, ['title'], '검색어');
        $sql = $query->toSql();

        $this->assertMatchesRegularExpression('/(?<!i)\blike\b/i', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bilike\b/i', $sql);
    }

    /**
     * 부분일치가 검색어의 와일드카드를 escape 하는지 확인
     *
     * escape 하지 않으면 `50%` 검색이 `50` 으로 시작하는 모든 행을 반환한다.
     *
     * @effects like_fallback_escapes_wildcards
     */
    #[Test]
    public function partial_match_escapes_wildcards_in_the_keyword(): void
    {
        $query = $this->makeQuery();
        KeywordSearch::applyLikeMatch($query, ['title'], '50%_할인');

        $this->assertContains('%50\\%\\_할인%', $query->getBindings());
    }
}
