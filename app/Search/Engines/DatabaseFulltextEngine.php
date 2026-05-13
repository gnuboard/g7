<?php

namespace App\Search\Engines;

use App\Search\Contracts\FulltextSearchable;
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
class DatabaseFulltextEngine extends Engine
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
     * @param \Illuminate\Database\Eloquent\Collection $models 모델 컬렉션
     * @return void
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
     * @param \Illuminate\Database\Eloquent\Collection $models 모델 컬렉션
     * @return void
     */
    public function delete($models): void
    {
        // MySQL FULLTEXT는 테이블 데이터가 곧 인덱스 소스 — no-op
    }

    /**
     * FULLTEXT 검색을 수행합니다.
     *
     * @param Builder $builder Scout 빌더
     * @return array{query: \Illuminate\Database\Eloquent\Builder, total: int}
     */
    public function search(Builder $builder): array
    {
        return $this->performSearch($builder);
    }

    /**
     * 페이지네이션 적용 FULLTEXT 검색을 수행합니다.
     *
     * @param Builder $builder Scout 빌더
     * @param int $perPage 페이지당 결과 수
     * @param int $page 페이지 번호
     * @return array{query: \Illuminate\Database\Eloquent\Builder, total: int}
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        return $this->performSearch($builder, $perPage, $page);
    }

    /**
     * 검색 결과에서 모델 ID를 추출합니다.
     *
     * @param array $results 검색 결과
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results): \Illuminate\Support\Collection
    {
        $query = $results['query'] ?? null;
        if (! $query) {
            return collect();
        }

        return $query->pluck($query->getModel()->getKeyName());
    }

    /**
     * 검색 결과를 모델 인스턴스로 매핑합니다.
     *
     * @param Builder $builder Scout 빌더
     * @param array $results 검색 결과
     * @param Model $model 기준 모델
     * @return Collection
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
     * @param Builder $builder Scout 빌더
     * @param array $results 검색 결과
     * @param Model $model 기준 모델
     * @return LazyCollection
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
     * @param array $results 검색 결과
     * @return int
     */
    public function getTotalCount($results): int
    {
        return $results['total'] ?? 0;
    }

    /**
     * 모델의 모든 레코드를 검색 인덱스에서 제거합니다.
     *
     * MySQL FULLTEXT는 테이블 자체가 인덱스 소스이므로 no-op.
     *
     * @param Model $model 모델
     * @return void
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
     * @param string $name 인덱스명
     * @param array $options 옵션
     * @return void
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
     * @param string $name 인덱스명
     * @return void
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
     * 관계 검색 등 Scout 직접 사용이 불가한 곳에서 FULLTEXT 검색 조건을 추가합니다.
     *
     * DBMS별로 MATCH...AGAINST 또는 LIKE fallback을 자동 적용합니다.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Eloquent 쿼리 빌더
     * @param string $column 검색 대상 컬럼명
     * @param string $keyword 검색어
     * @param string $boolean 조건 결합 방식 ('and' 또는 'or')
     * @return void
     */
    public static function whereFulltext(
        \Illuminate\Database\Eloquent\Builder $query,
        string $column,
        string $keyword,
        string $boolean = 'and'
    ): void {
        if (static::supportsFulltext()) {
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            $query->$method("MATCH(`{$column}`) AGAINST(? IN BOOLEAN MODE)", [$keyword]);
        } else {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $query->$method($column, 'LIKE', "%{$keyword}%");
        }
    }

    /**
     * 마이그레이션에서 FULLTEXT 인덱스를 DBMS별 조건부로 생성합니다.
     *
     * FULLTEXT 미지원 DBMS에서는 스킵합니다.
     * MySQL에서는 ngram 파서를, MariaDB에서는 기본 파서를 사용합니다.
     *
     * @param string $table 테이블명 (prefix 제외)
     * @param string $indexName 인덱스명
     * @param string|array $columns 대상 컬럼 (단일 또는 복수)
     * @return void
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
            . "ADD FULLTEXT INDEX `{$indexName}` (`{$columnList}`){$parserClause}"
        );
    }

    /**
     * FULLTEXT 검색 쿼리를 생성하고 실행합니다.
     *
     * DBMS별로 MATCH...AGAINST 또는 LIKE fallback을 자동 적용합니다.
     *
     * @param Builder $builder Scout 빌더
     * @param int|null $perPage 페이지당 결과 수
     * @param int|null $page 페이지 번호
     * @return array{query: \Illuminate\Database\Eloquent\Builder, total: int}
     */
    protected function performSearch(Builder $builder, ?int $perPage = null, ?int $page = null): array
    {
        $model = $builder->model;
        $keyword = $builder->query;

        // 빈 검색어인 경우 빈 결과 반환
        if (empty(trim($keyword))) {
            return [
                'query' => null,
                'total' => 0,
            ];
        }

        $query = $model->newQuery();

        // FulltextSearchable 인터페이스 구현 확인
        if (! ($model instanceof FulltextSearchable)) {
            return [
                'query' => null,
                'total' => 0,
            ];
        }

        $columns = $model->searchableColumns();
        $weights = $model->searchableWeights();

        if (empty($columns)) {
            return [
                'query' => null,
                'total' => 0,
            ];
        }

        $useFulltext = static::supportsFulltext();

        // prefix 포함 테이블명 (selectRaw에서 사용)
        $prefix = DB::getTablePrefix();
        $qualifiedTable = $prefix . $model->getTable();

        if ($useFulltext) {
            // MATCH...AGAINST WHERE 조건 생성 (MySQL, MariaDB)
            $matchClauses = [];
            $bindings = [];
            foreach ($columns as $column) {
                $matchClauses[] = "MATCH(`{$column}`) AGAINST(? IN BOOLEAN MODE)";
                $bindings[] = $keyword;
            }
            $query->whereRaw('(' . implode(' OR ', $matchClauses) . ')', $bindings);

            // 가중치 기반 스코어 계산 SELECT
            $scoreExpressions = [];
            $scoreBindings = [];
            foreach ($columns as $column) {
                $weight = $weights[$column] ?? 1.0;
                $scoreExpressions[] = "MATCH(`{$column}`) AGAINST(? IN BOOLEAN MODE) * {$weight}";
                $scoreBindings[] = $keyword;
            }
            $scoreRaw = '(' . implode(' + ', $scoreExpressions) . ') as _ft_score';
            $query->selectRaw($qualifiedTable . '.*, ' . $scoreRaw, $scoreBindings);
        } else {
            // LIKE fallback (PostgreSQL, SQLite 등)
            $query->where(function ($q) use ($columns, $keyword) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'LIKE', "%{$keyword}%");
                }
            });

            // 스코어 고정 0 (관련성 순위 불가)
            $query->selectRaw($qualifiedTable . '.*, 0 as _ft_score');
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

        // 전체 건수 계산 (페이지네이션 전)
        $total = $query->toBase()->getCountForPagination();

        // 페이지네이션 적용
        if ($perPage !== null) {
            $offset = ($page - 1) * $perPage;
            $query->limit($perPage)->offset($offset);
        } elseif ($builder->limit !== null) {
            $query->limit($builder->limit);
        }

        return [
            'query' => $query,
            'total' => $total,
        ];
    }
}
