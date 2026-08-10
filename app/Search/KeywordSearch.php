<?php

namespace App\Search;

use App\Extension\HookManager;
use App\Search\Contracts\KeywordPredicateProvider;
use App\Search\DTO\KeywordSearchContext;
use App\Support\Query\PaginationLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Throwable;

/**
 * 진행 중인 DB 쿼리에 키워드 술어를 붙이는 단일 진입점
 *
 * 저장소는 "이 컬럼들로 이 키워드를 걸어라" 만 말하고, **어떤 엔진이 그 조건을 만드는지는
 * 알지 않는다.** 활성 Scout 엔진이 {@see KeywordPredicateProvider} 를 구현하면 그 엔진에게
 * 위임하고, 구현하지 않았으면 `LIKE` 로 내려간다.
 *
 * 이 지점이 없으면 저장소가 구체 엔진 클래스(예: `DatabaseFulltextEngine::whereFulltext()`)를
 * 직접 부르게 되고, 그 순간 플러그인이 등록한 검색 엔진은 **호출될 기회 자체가 없어진다** —
 * 오류도 경고도 없이 그 사이트의 검색만 조용히 다른 방식으로 동작한다.
 *
 * 확장이 자기 엔진을 등록하는 방법은 [search-system.md](../../docs/backend/search-system.md) 참조.
 */
final class KeywordSearch
{
    /**
     * DBMS 별 부분일치 연산자 표를 조정하는 필터 훅
     *
     * 확장이 새 DBMS 의 연산자를 선언할 때 씁니다.
     *
     * ```php
     * HookManager::addFilter('core.search.like_operators', function (array $operators) {
     *     $operators['somedb'] = 'imatch';
     *
     *     return $operators;
     * });
     * ```
     */
    public const LIKE_OPERATORS_FILTER = 'core.search.like_operators';

    /**
     * 폴백 경고를 요청당 한 번만 남기기 위한 기록 (드라이버명 집합)
     *
     * @var array<string, true>
     */
    private static array $warnedDrivers = [];

    /**
     * 쿼리에 키워드 술어를 붙입니다.
     *
     * 컬럼을 배열로 넘기면 **하나의 조건으로 함께 평가**해 달라는 뜻입니다. 엔진이 그 조합을
     * 다룰 수 없는 경우(MySQL FULLTEXT 는 그 컬럼 조합의 복합 인덱스를 요구한다)에는
     * {@see self::applyAny()} 로 컬럼별 OR 을 요청하세요.
     *
     * @param  Builder  $query  조건을 붙일 Eloquent 쿼리 빌더
     * @param  string|array<int, string>  $columns  검색 대상 컬럼명
     * @param  string  $keyword  검색어 원문
     * @param  string  $boolean  기존 조건과의 결합 방식 ('and' 또는 'or')
     * @param  string|null  $limitContext  상한 해석에 쓸 목록 컨텍스트 이름 (예: 'search')
     */
    public static function apply(
        Builder $query,
        string|array $columns,
        string $keyword,
        string $boolean = 'and',
        ?string $limitContext = null
    ): void {
        $columns = array_values((array) $columns);

        if ($columns === []) {
            return;
        }

        $provider = self::provider();

        if ($provider !== null) {
            $provider->applyKeywordPredicate(
                $query,
                $columns,
                $keyword,
                $boolean,
                self::context($limitContext)
            );

            return;
        }

        self::applyLikeMatch($query, $columns, $keyword, $boolean);
    }

    /**
     * 엔진에게 넘길 술어 생성 조건을 만듭니다.
     *
     * 키 집합 상한은 목록 총 건수 상한과 같은 값을 쓴다 — 두 상한이 갈라지면 "엔진이
     * 돌려준 건수" 와 "화면이 보고하는 총 건수" 가 서로 다른 근거를 갖게 된다.
     *
     * @param  string|null  $limitContext  상한 해석에 쓸 목록 컨텍스트 이름
     * @return KeywordSearchContext 술어 생성 조건
     */
    private static function context(?string $limitContext): KeywordSearchContext
    {
        return new KeywordSearchContext(
            keyCap: PaginationLimits::resultCap($limitContext),
        );
    }

    /**
     * 컬럼별로 따로 평가해 OR 로 묶은 키워드 술어를 붙입니다.
     *
     * 컬럼마다 개별 인덱스만 있는 테이블에서 씁니다. 바깥은 하나의 그룹으로 감싸므로
     * 호출자의 기존 조건과 섞이지 않습니다.
     *
     * @param  Builder  $query  조건을 붙일 Eloquent 쿼리 빌더
     * @param  array<int, string>  $columns  검색 대상 컬럼명
     * @param  string  $keyword  검색어 원문
     * @param  string  $boolean  기존 조건과의 결합 방식 ('and' 또는 'or')
     * @param  string|null  $limitContext  상한 해석에 쓸 목록 컨텍스트 이름 (예: 'search')
     */
    public static function applyAny(
        Builder $query,
        array $columns,
        string $keyword,
        string $boolean = 'and',
        ?string $limitContext = null
    ): void {
        $columns = array_values($columns);

        if ($columns === []) {
            return;
        }

        $method = $boolean === 'or' ? 'orWhere' : 'where';

        $query->$method(function ($group) use ($columns, $keyword, $limitContext) {
            foreach ($columns as $index => $column) {
                self::apply($group, $column, $keyword, $index === 0 ? 'and' : 'or', $limitContext);
            }
        });
    }

    /**
     * 활성 엔진이 키워드 술어를 제공하는지 확인하고 그 엔진을 반환합니다.
     *
     * 제공하지 않으면 null 을 돌려주고, 그 사실을 드라이버당 한 번 기록합니다.
     * 기록하지 않으면 "검색이 느리고 관련도가 이상하다" 는 증상만 남고 원인을 찾을 단서가
     * 없어진다 — 폴백은 정상 동작처럼 보이기 때문이다.
     *
     * @return KeywordPredicateProvider|null 술어를 제공하는 엔진 (없으면 null)
     */
    private static function provider(): ?KeywordPredicateProvider
    {
        try {
            $engine = app(EngineManager::class)->engine();
        } catch (Throwable) {
            // 부팅 초기 등 엔진 해석이 불가한 상황 — 경고 없이 폴백한다.
            return null;
        }

        if ($engine instanceof KeywordPredicateProvider) {
            return $engine;
        }

        self::warnFallbackOnce();

        return null;
    }

    /**
     * 폴백 사용 사실을 드라이버당 한 번 기록합니다.
     */
    private static function warnFallbackOnce(): void
    {
        $driver = (string) config('scout.driver', 'mysql-fulltext');

        if (isset(self::$warnedDrivers[$driver])) {
            return;
        }

        self::$warnedDrivers[$driver] = true;

        Log::warning('활성 검색 엔진이 키워드 술어를 제공하지 않아 LIKE 로 검색합니다. 관련도 정렬이 적용되지 않고 전체 스캔이 발생합니다.', [
            'driver' => $driver,
            'contract' => KeywordPredicateProvider::class,
        ]);
    }

    /**
     * 부분일치(LIKE) 조건을 붙입니다.
     *
     * 이 경로는 "엔진이 없을 때의 임시방편" 이 아니다. 전문검색을 제공하지 않는 DBMS 로
     * 설치된 사이트에서는 **이것이 정상 검색 경로**이므로 이식성을 갖춰야 한다.
     *
     * - 대소문자: PostgreSQL 의 `LIKE` 는 대소문자를 구분한다. MySQL 은 기본 collation 이
     *   구분하지 않으므로, 드라이버별로 연산자를 갈라 **같은 검색어가 DBMS 에 따라 다른
     *   결과를 내지 않도록** 한다.
     * - 와일드카드: 검색어의 `%` `_` `\` 를 escape 해 이용자가 입력한 글자 그대로 찾는다.
     *   escape 하지 않으면 `50%` 검색이 `50` 으로 시작하는 모든 행을 반환한다.
     *
     * @param  Builder  $query  조건을 붙일 Eloquent 쿼리 빌더
     * @param  array<int, string>  $columns  검색 대상 컬럼명
     * @param  string  $keyword  검색어 원문
     * @param  string  $boolean  기존 조건과의 결합 방식
     */
    public static function applyLikeMatch(
        Builder $query,
        array $columns,
        string $keyword,
        string $boolean = 'and'
    ): void {
        $columns = array_values($columns);

        if ($columns === []) {
            return;
        }

        $pattern = '%'.self::escapeLikeWildcards($keyword).'%';
        $operator = self::caseInsensitiveLikeOperator();
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        $query->$method(function ($nested) use ($columns, $pattern, $operator) {
            foreach ($columns as $index => $column) {
                $nested->{$index === 0 ? 'where' : 'orWhere'}($column, $operator, $pattern);
            }
        });
    }

    /**
     * 대소문자를 구분하지 않는 부분일치 연산자를 드라이버별로 반환합니다.
     *
     * 드라이버명을 코드에 적지 않는다 — 앞으로 어떤 DBMS 가 공식 지원될지 정해져 있지
     * 않으므로, 코드에 박으면 DBMS 가 늘 때마다 코어를 고쳐야 한다. 표는 선언형 config
     * (`core.search.like_operators`)에 있고, 확장은 필터 훅으로 조정한다.
     *
     * @return string 사용할 비교 연산자
     */
    private static function caseInsensitiveLikeOperator(): string
    {
        $default = (string) config('core.search.like_operator_default', 'like');

        $operators = config('core.search.like_operators', []);
        $operators = HookManager::applyFilters(self::LIKE_OPERATORS_FILTER, $operators);

        if (! is_array($operators)) {
            return $default;
        }

        try {
            $driver = DB::getDriverName();
        } catch (Throwable) {
            return $default;
        }

        $operator = $operators[$driver] ?? $default;

        return is_string($operator) && $operator !== '' ? $operator : $default;
    }

    /**
     * `LIKE` 패턴에서 특수 의미를 갖는 문자를 escape 합니다.
     *
     * @param  string  $keyword  검색어 원문
     * @return string escape 된 검색어
     */
    public static function escapeLikeWildcards(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
    }

    /**
     * 폴백 경고 기록을 초기화합니다 (테스트 전용).
     */
    public static function forgetFallbackWarnings(): void
    {
        self::$warnedDrivers = [];
    }
}
