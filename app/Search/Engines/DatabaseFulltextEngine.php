<?php

namespace App\Search\Engines;

use App\Enums\TotalRelation;
use App\Search\Contracts\FulltextSearchable;
use App\Search\Contracts\KeywordPredicateProvider;
use App\Search\DTO\KeywordSearchContext;
use App\Search\KeywordSearch;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

/**
 * MySQL FULLTEXT + ngram 커스텀 Scout 엔진
 *
 * MATCH...AGAINST IN BOOLEAN MODE를 사용하여 검색합니다.
 * FulltextSearchable 인터페이스를 구현한 모델에서만 동작합니다.
 *
 * MySQL 자체가 인덱스 소스이므로 update/delete/flush는 no-op입니다.
 */
class DatabaseFulltextEngine extends Engine implements KeywordPredicateProvider
{
    /**
     * MariaDB 감지 결과 캐시 (프로세스 수명 동안 유지)
     */
    private static ?bool $isMariaDbCache = null;

    /**
     * 모델을 검색 인덱스에 업데이트합니다.
     *
     * MySQL FULLTEXT는 테이블 자체가 인덱스 소스이므로 no-op.
     *
     * @param  Collection  $models  모델 컬렉션
     */
    public function update($models): void
    {
        // MySQL FULLTEXT는 테이블 데이터가 곧 인덱스 소스 — no-op
    }

    /**
     * 모델을 검색 인덱스에서 제거합니다.
     *
     * MySQL FULLTEXT는 테이블 자체가 인덱스 소스이므로 no-op.
     *
     * @param  Collection  $models  모델 컬렉션
     */
    public function delete($models): void
    {
        // MySQL FULLTEXT는 테이블 데이터가 곧 인덱스 소스 — no-op
    }

    /**
     * FULLTEXT 검색을 수행합니다.
     *
     * @param  Builder  $builder  Scout 빌더
     * @return array{query: \Illuminate\Database\Eloquent\Builder|null, total: int|null, total_relation: TotalRelation|null, result_cap: int|null}
     */
    public function search(Builder $builder): array
    {
        return $this->performSearch($builder);
    }

    /**
     * 검색 결과의 키 목록만 조회합니다.
     *
     * 총 건수를 쓰지 않는 경로이므로 COUNT 를 아예 실행하지 않고, SELECT 도 키 컬럼과
     * 정렬용 스코어로만 좁힙니다. 좁히지 않으면 매칭된 전 행의 모든 컬럼(본문 포함)을
     * 읽고 나서 ID 만 뽑아내게 됩니다.
     *
     * @param  Builder  $builder  Scout 빌더
     * @return \Illuminate\Support\Collection 키 컬렉션
     */
    public function keys(Builder $builder): \Illuminate\Support\Collection
    {
        return $this->mapIds($this->performSearch($builder, withTotal: false, keysOnly: true));
    }

    /**
     * 검색 결과를 모델 컬렉션으로 조회합니다.
     *
     * 총 건수를 쓰지 않는 경로이므로 COUNT 를 실행하지 않습니다.
     *
     * @param  Builder  $builder  Scout 빌더
     * @return Collection 모델 컬렉션
     */
    public function get(Builder $builder): Collection
    {
        return $this->map(
            $builder,
            $builder->applyAfterRawSearchCallback($this->performSearch($builder, withTotal: false)),
            $builder->model
        );
    }

    /**
     * 페이지네이션 적용 FULLTEXT 검색을 수행합니다.
     *
     * @param  Builder  $builder  Scout 빌더
     * @param  int  $perPage  페이지당 결과 수
     * @param  int  $page  페이지 번호
     * @return array{query: \Illuminate\Database\Eloquent\Builder|null, total: int|null, total_relation: TotalRelation|null, result_cap: int|null}
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        return $this->performSearch($builder, $perPage, $page);
    }

    /**
     * 검색 결과에서 모델 ID를 추출합니다.
     *
     * @param  array  $results  검색 결과
     */
    public function mapIds($results): \Illuminate\Support\Collection
    {
        $query = $results['query'] ?? null;
        if (! $query) {
            return collect();
        }

        // `pluck()` 은 이미 걸려 있는 SELECT 를 보존한다(onceWithColumns). 검색 쿼리는
        // 관련도 점수를 위해 `table.*` 를 선택해 두므로, 그대로 두면 ID 만 필요한 자리에서
        // 전 컬럼을 읽는다. 사본에서 키 컬럼만 남겨 좁힌다.
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();

        return $query->clone()->select($key)->pluck($model->getKeyName());
    }

    /**
     * 검색 결과를 모델 인스턴스로 매핑합니다.
     *
     * @param  Builder  $builder  Scout 빌더
     * @param  array  $results  검색 결과
     * @param  Model  $model  기준 모델
     */
    public function map(Builder $builder, $results, $model): Collection
    {
        $query = $results['query'] ?? null;
        if (! $query) {
            return $model->newCollection();
        }

        return $query->get();
    }

    /**
     * 검색 결과를 LazyCollection으로 매핑합니다.
     *
     * @param  Builder  $builder  Scout 빌더
     * @param  array  $results  검색 결과
     * @param  Model  $model  기준 모델
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        $query = $results['query'] ?? null;
        if (! $query) {
            return LazyCollection::empty();
        }

        return $query->cursor();
    }

    /**
     * 검색 결과의 전체 건수를 반환합니다.
     *
     * @param  array  $results  검색 결과
     */
    public function getTotalCount($results): int
    {
        return (int) ($results['total'] ?? 0);
    }

    /**
     * 검색 결과의 총 건수 정확도를 반환합니다.
     *
     * 상한을 넘겨 정확히 세지 않은 경우 `AtLeast` 입니다.
     *
     * @param  array  $results  검색 결과
     * @return TotalRelation 총 건수 정확도
     */
    public function getTotalRelation($results): TotalRelation
    {
        return $results['total_relation'] ?? TotalRelation::Exact;
    }

    /**
     * 총 건수 집계에 적용된 상한을 반환합니다.
     *
     * @param  array  $results  검색 결과
     * @return int|null 상한 (무제한이면 null)
     */
    public function getResultCap($results): ?int
    {
        return $results['result_cap'] ?? null;
    }

    /**
     * 모델의 모든 레코드를 검색 인덱스에서 제거합니다.
     *
     * MySQL FULLTEXT는 테이블 자체가 인덱스 소스이므로 no-op.
     *
     * @param  Model  $model  모델
     */
    public function flush($model): void
    {
        // MySQL FULLTEXT는 테이블 데이터가 곧 인덱스 소스 — no-op
    }

    /**
     * 검색 인덱스를 생성합니다.
     *
     * MySQL FULLTEXT는 마이그레이션에서 인덱스를 관리하므로 no-op.
     *
     * @param  string  $name  인덱스명
     * @param  array  $options  옵션
     */
    public function createIndex($name, array $options = []): void
    {
        // MySQL FULLTEXT 인덱스는 마이그레이션에서 관리 — no-op
    }

    /**
     * 검색 인덱스를 삭제합니다.
     *
     * MySQL FULLTEXT는 마이그레이션에서 인덱스를 관리하므로 no-op.
     *
     * @param  string  $name  인덱스명
     */
    public function deleteIndex($name): void
    {
        // MySQL FULLTEXT 인덱스는 마이그레이션에서 관리 — no-op
    }

    /**
     * 현재 DBMS가 FULLTEXT 검색을 지원하는지 판단합니다.
     *
     * @return bool FULLTEXT 지원 여부
     */
    public static function supportsFulltext(): bool
    {
        $driver = DB::getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true);
    }

    /**
     * 현재 DBMS가 ngram 파서를 지원하는지 판단합니다.
     *
     * MySQL 8.0+에서만 ngram 파서를 지원합니다.
     * MariaDB는 FULLTEXT를 지원하지만 ngram 파서는 지원하지 않습니다.
     * DB_CONNECTION=mysql로 MariaDB에 연결하는 경우도 감지합니다.
     *
     * @return bool ngram 파서 지원 여부
     */
    public static function supportsNgramParser(): bool
    {
        if (static::isMariaDb()) {
            return false;
        }

        return DB::getDriverName() === 'mysql';
    }

    /**
     * 현재 연결된 DBMS가 MariaDB인지 판단합니다.
     *
     * DB_CONNECTION=mysql 드라이버로 MariaDB에 연결한 경우에도
     * 서버 버전 문자열에서 'MariaDB'를 감지합니다.
     *
     * @return bool MariaDB 여부
     */
    public static function isMariaDb(): bool
    {
        if (static::$isMariaDbCache !== null) {
            return static::$isMariaDbCache;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mariadb') {
            return static::$isMariaDbCache = true;
        }

        if ($driver === 'mysql') {
            try {
                $version = DB::selectOne('SELECT VERSION() as version');

                return static::$isMariaDbCache = $version && str_contains(strtolower($version->version), 'mariadb');
            } catch (\Throwable) {
                return static::$isMariaDbCache = false;
            }
        }

        return static::$isMariaDbCache = false;
    }

    /**
     * FULLTEXT BOOLEAN MODE 검색어에서 연산자 문자를 제거하고 안전한 구문으로 변환합니다.
     *
     * BOOLEAN MODE 연산자(+, -, <, >, (, ), ~, *, ", @)는 잘못된 구문일 때
     * MySQL 파싱 오류(ER_PARSE_ERROR)를 유발하므로, 사용자 입력을 일반 검색어로만
     * 취급하도록 연산자를 제거하고 남은 토큰을 따옴표 구문으로 묶습니다.
     *
     * 토큰은 공백으로 잇습니다 — BOOLEAN MODE 에서 공백 결합은 OR 입니다. 각 토큰에 `+` 를
     * 붙이면 AND 가 되어 매칭 집합이 줄고 대용량에서 검색 시간이 짧아질 여지가 있지만,
     * **의도적으로 OR 을 유지합니다**. 입력한 단어 중 하나만 든 문서가 결과에서 통째로
     * 사라지는 손실이 성능 이득보다 크기 때문입니다. 한글은 ngram 파서가 2글자 단위로
     * 토큰을 쪼개므로 AND 전환의 결과 축소 폭이 특히 큽니다. 성능 축이 문제가 되면 결합
     * 방식이 아니라 전용 검색 엔진(core.search.engine_drivers)으로 해결합니다.
     * 배경·실측: docs/backend/search-system.md, docs/backend/pagination.md
     *
     * @param  string  $keyword  원본 검색어
     * @return string 안전하게 정제된 BOOLEAN MODE 검색식 (정제 결과가 없으면 빈 문자열)
     */
    public static function sanitizeBooleanModeKeyword(string $keyword): string
    {
        // 1. BOOLEAN MODE 연산자 + 제어성 문자를 공백으로 치환
        //    연산자 집합: + - < > ( ) ~ * " @  (백슬래시 미포함 — Windows 경로 가드 규율과 무관하나 일관)
        $cleaned = preg_replace('/[+\-<>()~*"@]/u', ' ', $keyword);
        // 제어문자 제거
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $cleaned);

        // 2. 토큰 분리 (연속 공백 정규화)
        $tokens = preg_split('/\s+/u', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY);

        // 3. 빈 문자열 방어
        if (empty($tokens)) {
            return '';
        }

        // 4. 각 토큰을 따옴표 phrase로 묶어 공백 결합 (따옴표는 1단계에서 제거됨 → 균형 깨질 위험 없음)
        return implode(' ', array_map(fn ($t) => '"'.$t.'"', $tokens));
    }

    /**
     * 관계 검색 등 Scout 직접 사용이 불가한 곳에서 FULLTEXT 검색 조건을 추가합니다.
     *
     * DBMS별로 MATCH...AGAINST 또는 LIKE fallback을 자동 적용합니다.
     *
     * 검색어는 반드시 이 헬퍼를 거쳐야 합니다. BOOLEAN MODE 는 `+ - * " ( )` 등을
     * 연산자로 해석하므로, 원문 키워드를 그대로 바인딩하면 사용자가 `+` 하나만 입력해도
     * 파싱 오류로 500 이 됩니다. 정제는 sanitizeBooleanModeKeyword 한 곳에서만 합니다.
     *
     * **컬럼을 배열로 넘기면 하나의 `MATCH(a, b)` 가 됩니다.** MySQL 은 이 형태에 정확히
     * 그 컬럼 조합의 **복합 FULLTEXT 인덱스**를 요구하며, 없으면
     * `Can't find FULLTEXT index matching the column list` 오류가 납니다.
     * 컬럼별 단일 인덱스만 있는 테이블은 {@see self::whereFulltextAny()} 를 쓰세요.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Eloquent 쿼리 빌더
     * @param  string|array<int, string>  $columns  검색 대상 컬럼명 (배열이면 복합 인덱스 필요)
     * @param  string  $keyword  검색어 (원문 — 정제는 이 메서드가 수행)
     * @param  string  $boolean  조건 결합 방식 ('and' 또는 'or')
     */
    public static function whereFulltext(
        \Illuminate\Database\Eloquent\Builder $query,
        string|array $columns,
        string $keyword,
        string $boolean = 'and'
    ): void {
        // 활성 엔진 해석을 거친다 — 다른 엔진이 켜져 있으면 그 엔진이 조건을 만든다.
        // 이 정적 헬퍼를 직접 부르던 코드도 이 경유로 엔진 교체 혜택을 받는다.
        KeywordSearch::apply($query, $columns, $keyword, $boolean);
    }

    /**
     * {@inheritDoc}
     *
     * 이 엔진의 술어는 `MATCH ... AGAINST IN BOOLEAN MODE` 입니다. DBMS 가 FULLTEXT 를
     * 지원하지 않으면 같은 자리에서 부분일치로 내려갑니다.
     *
     * `$context->keyCap` 은 쓰지 않습니다 — 이 엔진의 술어는 SQL 조건 그 자체라 중간
     * 키 집합을 만들지 않으므로 상한을 적용할 대상이 없습니다. 상한은 키 집합을 만들어
     * 조건으로 붙이는 엔진(외부 검색 서버 등)을 위한 것입니다.
     */
    public function applyKeywordPredicate(
        \Illuminate\Database\Eloquent\Builder $query,
        array $columns,
        string $keyword,
        string $boolean,
        KeywordSearchContext $context
    ): void {
        static::applyFulltextPredicate($query, $columns, $keyword, $boolean);
    }

    /**
     * FULLTEXT 조건을 실제로 조립합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Eloquent 쿼리 빌더
     * @param  array<int, string>  $columns  검색 대상 컬럼명
     * @param  string  $keyword  검색어 원문
     * @param  string  $boolean  조건 결합 방식 ('and' 또는 'or')
     */
    protected static function applyFulltextPredicate(
        \Illuminate\Database\Eloquent\Builder $query,
        array $columns,
        string $keyword,
        string $boolean = 'and'
    ): void {
        $columns = array_values($columns);

        if (! static::supportsFulltext()) {
            // 전문검색을 제공하지 않는 DBMS 로 설치된 사이트에서는 부분일치가 정상 경로다.
            // 연산자 선택과 와일드카드 escape 는 코어 해석기가 단독으로 수행한다 —
            // 여기서 다시 조립하면 DBMS 가 늘 때마다 고쳐야 할 곳이 둘이 된다.
            KeywordSearch::applyLikeMatch($query, $columns, $keyword, $boolean);

            return;
        }

        $ftKeyword = static::sanitizeBooleanModeKeyword($keyword);

        if ($ftKeyword === '') {
            // 정제 결과 없음(연산자만 입력) → 빈 결과. 빌더가 바인딩까지 처리하도록
            // 빈 whereIn 을 쓴다 (raw '1 = 0' 은 쓰지 않는다).
            $method = $boolean === 'or' ? 'orWhereIn' : 'whereIn';
            $query->$method($query->getModel()->getQualifiedKeyName(), []);

            return;
        }

        $match = 'MATCH(`'.implode('`, `', $columns).'`)';
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        $query->$method($match.' AGAINST(? IN BOOLEAN MODE)', [$ftKeyword]);
    }

    /**
     * 여러 컬럼 중 하나라도 매칭하면 되는 FULLTEXT 조건을 추가합니다.
     *
     * 컬럼마다 별도의 `MATCH(col)` 를 만들어 OR 로 묶습니다. 컬럼별 단일 FULLTEXT 인덱스만
     * 있는 테이블(대부분의 경우)에서 쓰는 형태이며, 복합 인덱스가 없어도 동작합니다.
     *
     * 복합 인덱스가 있어 `MATCH(a, b)` 한 번으로 끝내야 하는 테이블은
     * {@see self::whereFulltext()} 에 배열을 넘기세요.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Eloquent 쿼리 빌더
     * @param  array<int, string>  $columns  검색 대상 컬럼명 목록
     * @param  string  $keyword  검색어 (원문 — 정제는 내부에서 수행)
     * @param  string  $boolean  바깥 쿼리와의 결합 방식 ('and' 또는 'or')
     */
    public static function whereFulltextAny(
        \Illuminate\Database\Eloquent\Builder $query,
        array $columns,
        string $keyword,
        string $boolean = 'and'
    ): void {
        // 활성 엔진 해석을 거친다 ({@see self::whereFulltext()} 와 동일한 이유).
        KeywordSearch::applyAny($query, $columns, $keyword, $boolean);
    }

    /**
     * 마이그레이션에서 FULLTEXT 인덱스를 DBMS별 조건부로 생성합니다.
     *
     * FULLTEXT 미지원 DBMS에서는 스킵합니다.
     * MySQL에서는 ngram 파서를, MariaDB에서는 기본 파서를 사용합니다.
     *
     * @param  string  $table  테이블명 (prefix 제외)
     * @param  string  $indexName  인덱스명
     * @param  string|array  $columns  대상 컬럼 (단일 또는 복수)
     */
    public static function addFulltextIndex(string $table, string $indexName, string|array $columns): void
    {
        if (! static::supportsFulltext()) {
            return;
        }

        $prefix = DB::getTablePrefix();
        $columnList = is_array($columns) ? implode('`, `', $columns) : $columns;
        $parserClause = static::supportsNgramParser() ? ' WITH PARSER ngram' : '';

        DB::statement(
            "ALTER TABLE `{$prefix}{$table}` "
            ."ADD FULLTEXT INDEX `{$indexName}` (`{$columnList}`){$parserClause}"
        );
    }

    /**
     * FULLTEXT 검색 쿼리를 생성하고 실행합니다.
     *
     * DBMS별로 MATCH...AGAINST 또는 LIKE fallback을 자동 적용합니다.
     *
     * @param  Builder  $builder  Scout 빌더
     * @param  int|null  $perPage  페이지당 결과 수
     * @param  int|null  $page  페이지 번호
     * @param  bool  $withTotal  총 건수를 계산할지 여부 (쓰지 않는 경로에서는 false)
     * @param  bool  $keysOnly  키 컬럼과 정렬용 스코어만 조회할지 여부
     * @return array{query: \Illuminate\Database\Eloquent\Builder|null, total: int|null, total_relation: TotalRelation|null, result_cap: int|null}
     */
    protected function performSearch(
        Builder $builder,
        ?int $perPage = null,
        ?int $page = null,
        bool $withTotal = true,
        bool $keysOnly = false
    ): array {
        $model = $builder->model;
        $keyword = $builder->query;

        // 빈 검색어인 경우 빈 결과 반환
        if (empty(trim($keyword))) {
            return self::emptyResult();
        }

        $query = $model->newQuery();

        // FulltextSearchable 인터페이스 구현 확인
        if (! ($model instanceof FulltextSearchable)) {
            return self::emptyResult();
        }

        $columns = $model->searchableColumns();
        $weights = $model->searchableWeights();

        if (empty($columns)) {
            return self::emptyResult();
        }

        $useFulltext = static::supportsFulltext();

        // prefix 포함 테이블명 (selectRaw에서 사용)
        $prefix = DB::getTablePrefix();
        $qualifiedTable = $prefix.$model->getTable();

        if ($useFulltext) {
            // BOOLEAN MODE 연산자/제어문자 정제 (특수문자 입력 시 ER_PARSE_ERROR → 500 방지)
            $ftKeyword = static::sanitizeBooleanModeKeyword($keyword);
            if ($ftKeyword === '') {
                // 연산자만 입력 → 500 대신 빈 결과 (빈 검색어와 동일 반환 형태)
                return self::emptyResult();
            }

            // MATCH...AGAINST WHERE 조건 생성 (MySQL, MariaDB)
            $matchClauses = [];
            $bindings = [];
            foreach ($columns as $column) {
                $matchClauses[] = "MATCH(`{$column}`) AGAINST(? IN BOOLEAN MODE)";
                $bindings[] = $ftKeyword;
            }
            $query->whereRaw('('.implode(' OR ', $matchClauses).')', $bindings);

            // 가중치 기반 스코어 계산 SELECT
            $scoreExpressions = [];
            $scoreBindings = [];
            foreach ($columns as $column) {
                $weight = $weights[$column] ?? 1.0;
                $scoreExpressions[] = "MATCH(`{$column}`) AGAINST(? IN BOOLEAN MODE) * {$weight}";
                $scoreBindings[] = $ftKeyword;
            }
            $scoreRaw = '('.implode(' + ', $scoreExpressions).') as _ft_score';
            $this->applySelect($query, $model, $qualifiedTable, $scoreRaw, $scoreBindings, $keysOnly);
        } else {
            // LIKE fallback (PostgreSQL, SQLite 등)
            $query->where(function ($q) use ($columns, $keyword) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'LIKE', "%{$keyword}%");
                }
            });

            // 스코어 고정 0 (관련성 순위 불가)
            $this->applySelect($query, $model, $qualifiedTable, '0 as _ft_score', [], $keysOnly);
        }

        // Scout Builder 콜백 적용 (추가 where 조건 등)
        if ($builder->callback) {
            $query = call_user_func($builder->callback, $query, $keyword, $builder);
        }

        // Scout Builder query() 콜백 적용 (Eloquent 쿼리 수정)
        if ($builder->queryCallback) {
            call_user_func($builder->queryCallback, $query);
        }

        // 추가 where 조건 적용 (Scout v10+ 배열 구조)
        foreach ($builder->wheres as $where) {
            // __soft_deleted는 Scout 내부 필터 — Eloquent 스코프가 처리하므로 스킵
            if (is_array($where)) {
                if (($where['field'] ?? '') === '__soft_deleted') {
                    continue;
                }
                $query->where($where['field'], $where['operator'] ?? '=', $where['value']);
            } else {
                // 레거시 키-값 형태 fallback
                // $where는 이 경우 값, 키는 필드명
            }
        }

        // 추가 whereIn 조건 적용
        foreach ($builder->whereIns as $whereIn) {
            if (is_array($whereIn) && isset($whereIn['field'])) {
                $query->whereIn($whereIn['field'], $whereIn['values'] ?? []);
            }
        }

        // 추가 whereNotIn 조건 적용
        foreach ($builder->whereNotIns as $whereNotIn) {
            if (is_array($whereNotIn) && isset($whereNotIn['field'])) {
                $query->whereNotIn($whereNotIn['field'], $whereNotIn['values'] ?? []);
            }
        }

        // 정렬: Scout Builder에 정렬이 있으면 사용, 없으면 스코어 DESC
        if (! empty($builder->orders)) {
            foreach ($builder->orders as $order) {
                $query->orderBy($order['column'], $order['direction']);
            }
        } else {
            $query->orderByDesc('_ft_score');
        }

        // 전체 건수 계산 (페이지네이션 전).
        // 총 건수를 쓰지 않는 경로에서는 아예 세지 않는다 — 대용량 매칭에서 COUNT 한 번이
        // 조회 본체보다 비쌀 수 있다.
        $resultCap = PaginationLimits::resultCap('search');
        $total = null;
        $relation = null;

        if ($withTotal) {
            [$total, $relation] = BoundedPaginator::countWithCap($query, $resultCap);
        }

        // 페이지네이션 적용
        if ($perPage !== null) {
            $offset = ($page - 1) * $perPage;
            $query->limit($perPage)->offset($offset);
        } elseif ($builder->limit !== null) {
            $query->limit($builder->limit);
        } elseif ($resultCap !== null) {
            // perPage 도 limit 도 없으면 LIMIT 이 아예 붙지 않아 매칭 전량을 PHP 로 끌어온다.
            // 상한을 걸어 한 요청이 읽는 행 수에 천장을 둔다.
            $query->limit($resultCap);
        }

        return [
            'query' => $query,
            'total' => $total,
            'total_relation' => $relation,
            'result_cap' => $resultCap,
        ];
    }

    /**
     * 조회 컬럼을 적용합니다.
     *
     * 키만 필요한 경로에서는 키 컬럼과 정렬용 스코어로 좁힙니다. 스코어는 `ORDER BY` 가
     * 참조하므로 좁힐 때도 함께 남겨야 합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  대상 쿼리
     * @param  Model  $model  기준 모델
     * @param  string  $qualifiedTable  프리픽스 포함 테이블명
     * @param  string  $scoreRaw  스코어 SELECT 표현식 (`... as _ft_score`)
     * @param  array<int, mixed>  $scoreBindings  스코어 표현식 바인딩
     * @param  bool  $keysOnly  키 컬럼만 조회할지 여부
     */
    protected function applySelect(
        $query,
        Model $model,
        string $qualifiedTable,
        string $scoreRaw,
        array $scoreBindings,
        bool $keysOnly
    ): void {
        if ($keysOnly) {
            $query->select($model->getQualifiedKeyName())->selectRaw($scoreRaw, $scoreBindings);

            return;
        }

        $query->selectRaw($qualifiedTable.'.*, '.$scoreRaw, $scoreBindings);
    }

    /**
     * 검색이 성립하지 않을 때의 빈 결과 형태를 반환합니다.
     *
     * @return array{query: null, total: int, total_relation: TotalRelation, result_cap: null}
     */
    protected static function emptyResult(): array
    {
        return [
            'query' => null,
            'total' => 0,
            'total_relation' => TotalRelation::Exact,
            'result_cap' => null,
        ];
    }
}
