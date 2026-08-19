<?php

namespace App\Search;

use App\Enums\SearchIndexStatus;
use App\Search\DTO\FulltextIndexDefinition;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FULLTEXT 인덱스 건강도 점검·복구기
 *
 * 인덱스는 존재하는데 **행이 색인되어 있지 않아** `MATCH ... AGAINST` 가 아무것도
 * 돌려주지 않는 상태를 검출하고, 같은 정의로 재생성해 복구합니다. 이 상태는
 * 오류를 남기지 않고 "검색 결과 0건" 으로만 나타나므로 로그·예외로는 드러나지 않습니다.
 *
 * 대상은 하드코딩하지 않습니다 — `INFORMATION_SCHEMA` 에서 현재 스키마의 FULLTEXT
 * 인덱스를 전수 수집하므로 코어·모듈·플러그인이 나중에 추가하는 인덱스도 자동 포함됩니다.
 *
 * ## 판정 방법 — 자기 매칭(self-match)
 *
 * 표본 행을 뽑아 **그 행 자신의 내용에서 만든 검색어로 그 행을 찾을 수 있는지** 봅니다.
 * 특정 키워드를 사람이 골라 넣지 않으므로 어떤 테이블·언어에도 그대로 적용됩니다.
 *
 * 토큰은 행마다 여러 개 만듭니다. 하나만 쓰면 토크나이저 특성으로 인한 실패를
 * 색인 누락으로 오판합니다 — 예를 들어 ngram(`ngram_token_size=2`) 은 `Basic` 을
 * `Ba/as/si/ic` 로 쪼개는데 그중 `as` 가 기본 불용어라 구문 검색이 깨집니다.
 * 그래서 **한 행이 어떤 토큰으로도 자신을 찾지 못할 때만** 그 행을 실패로 셉니다.
 *
 * ## 판정 등급
 *
 * - `healthy`  — 표본 전 행이 자기 자신을 찾는다
 * - `degraded` — 일부만 찾는다. 토크나이저 특성일 수도, 일부 행만 누락된 것일 수도 있어
 *                자동 복구 대상에서 제외하고 수치만 보고한다
 * - `stale`    — 표본 중 **한 행도** 자기 자신을 찾지 못한다. 색인 누락의 신호이며 복구 대상
 * - `skipped`  — 표본을 만들 수 없다(행이 없거나 내용이 모두 비어 토큰 추출 불가). 사유·수치 기재
 */
class FulltextIndexInspector
{
    /** 인덱스당 기본 표본 행 수 */
    public const DEFAULT_SAMPLE_SIZE = 5;

    /** 한 행에서 만들 최대 토큰 수 */
    private const MAX_TOKENS_PER_ROW = 4;

    /** 토큰 최대 길이 (긴 토큰은 구문 검색 비용만 늘린다) */
    private const MAX_TOKEN_LENGTH = 12;

    /**
     * 현재 스키마의 FULLTEXT 인덱스를 전수 수집합니다.
     *
     * @param  string|null  $table  테이블명 필터 (프리픽스 포함/미포함 모두 허용)
     * @param  string|null  $index  인덱스명 필터
     * @return array<int, FulltextIndexDefinition>
     */
    public function discover(?string $table = null, ?string $index = null): array
    {
        if (! DatabaseFulltextEngine::supportsFulltext()) {
            return [];
        }

        $rows = DB::select(
            'SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND INDEX_TYPE = ?
             GROUP BY TABLE_NAME, INDEX_NAME
             ORDER BY TABLE_NAME, INDEX_NAME',
            ['FULLTEXT']
        );

        $prefix = DB::getTablePrefix();
        $wanted = $table === null ? null : [$table, $prefix.$table];

        $definitions = [];
        foreach ($rows as $row) {
            if ($wanted !== null && ! in_array($row->table_name, $wanted, true)) {
                continue;
            }
            if ($index !== null && $row->index_name !== $index) {
                continue;
            }

            $definitions[] = new FulltextIndexDefinition(
                table: $row->table_name,
                name: $row->index_name,
                columns: explode(',', (string) $row->columns),
                parser: $this->resolveParser($row->table_name, $row->index_name),
            );
        }

        return $definitions;
    }

    /**
     * 현재 스키마의 FULLTEXT 인덱스 컬럼 집합을 테이블별로 수집합니다.
     *
     * `discover()` 와 같은 INFORMATION_SCHEMA 조회를 쓰되, 인덱스마다 `SHOW CREATE TABLE`
     * 을 도는 파서 해석은 하지 않는 경량판입니다 — 커버 판정에는 컬럼 집합만 필요합니다.
     *
     * 반환 키는 프리픽스를 제거한 테이블명입니다. `Model::getTable()` 이 프리픽스 미포함
     * 이름을 돌려주므로, 판정하는 쪽이 그 이름으로 바로 조회할 수 있게 맞춥니다.
     *
     * @return array<string, array<int, array<int, string>>> 테이블명 => 인덱스별 컬럼 집합 목록
     */
    public function indexedColumnSets(): array
    {
        if (! DatabaseFulltextEngine::supportsFulltext()) {
            return [];
        }

        $rows = DB::select(
            'SELECT TABLE_NAME AS table_name,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND INDEX_TYPE = ?
             GROUP BY TABLE_NAME, INDEX_NAME',
            ['FULLTEXT']
        );

        $prefix = DB::getTablePrefix();
        $sets = [];

        foreach ($rows as $row) {
            $table = (string) $row->table_name;

            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
            }

            $sets[strtolower($table)][] = explode(',', (string) $row->columns);
        }

        return $sets;
    }

    /**
     * 인덱스 하나의 건강도를 판정합니다.
     *
     * 엔진 중립 DTO 로의 변환은 호출자(`FulltextIndexMaintainer`)가 합니다 — 이 클래스는
     * FULLTEXT 고유의 판정만 담당하고 표현 형식을 알지 않습니다.
     *
     * @param  FulltextIndexDefinition  $definition  인덱스 정의
     * @param  int  $sampleSize  표본 행 수
     * @return array{definition: FulltextIndexDefinition, status: SearchIndexStatus, probed: int, found: int, reason: string|null}
     */
    public function inspect(FulltextIndexDefinition $definition, int $sampleSize = self::DEFAULT_SAMPLE_SIZE): array
    {
        $key = $this->resolvePrimaryKey($definition->table);

        if ($key === null) {
            return $this->skipped($definition, __('search.index.fulltext.skip.no_single_pk'));
        }

        $samples = $this->sampleRows($definition, $key, $sampleSize);

        if ($samples === []) {
            return $this->skipped($definition, __('search.index.fulltext.skip.no_rows'));
        }

        $probed = 0;
        $found = 0;

        foreach ($samples as $sample) {
            $tokens = $this->buildTokens((string) $sample->body);

            if ($tokens === []) {
                continue;
            }

            $probed++;

            if ($this->rowFindsItself($definition, $key, $sample->pk, $tokens)) {
                $found++;
            }
        }

        if ($probed === 0) {
            return $this->skipped($definition, __('search.index.fulltext.skip.no_tokens'));
        }

        return [
            'definition' => $definition,
            'status' => match (true) {
                $found === 0 => SearchIndexStatus::Stale,
                $found < $probed => SearchIndexStatus::Degraded,
                default => SearchIndexStatus::Healthy,
            },
            'probed' => $probed,
            'found' => $found,
            'reason' => null,
        ];
    }

    /**
     * 전체(또는 필터된) 인덱스의 건강도를 판정합니다.
     *
     * @param  string|null  $table  테이블명 필터
     * @param  string|null  $index  인덱스명 필터
     * @param  int  $sampleSize  표본 행 수
     * @return array<int, array{definition: FulltextIndexDefinition, status: SearchIndexStatus, probed: int, found: int, reason: string|null}>
     */
    public function inspectAll(?string $table = null, ?string $index = null, int $sampleSize = self::DEFAULT_SAMPLE_SIZE): array
    {
        return array_map(
            fn (FulltextIndexDefinition $definition) => $this->inspect($definition, $sampleSize),
            $this->discover($table, $index)
        );
    }

    /**
     * 판정 불가 결과를 만듭니다.
     *
     * @param  FulltextIndexDefinition  $definition  인덱스 정의
     * @param  string  $reason  사유
     * @return array{definition: FulltextIndexDefinition, status: SearchIndexStatus, probed: int, found: int, reason: string}
     */
    private function skipped(FulltextIndexDefinition $definition, string $reason): array
    {
        return [
            'definition' => $definition,
            'status' => SearchIndexStatus::Skipped,
            'probed' => 0,
            'found' => 0,
            'reason' => $reason,
        ];
    }

    /**
     * 인덱스를 같은 정의로 재생성합니다.
     *
     * 컬럼 구성과 파서를 그대로 유지하므로 검색 동작이 달라지지 않습니다.
     * 재생성 중에는 대상 테이블이 잠기므로 대용량 테이블에서는 시간이 걸립니다.
     *
     * @param  FulltextIndexDefinition  $definition  인덱스 정의
     * @return void
     *
     * @throws Throwable 재생성 실패 시 (드롭까지만 되고 끝나지 않도록 예외를 그대로 전파)
     */
    public function rebuild(FulltextIndexDefinition $definition): void
    {
        $table = $definition->quotedTable();
        $name = $definition->name;

        DB::statement("ALTER TABLE {$table} DROP INDEX `{$name}`");

        try {
            DB::statement(
                "ALTER TABLE {$table} ADD FULLTEXT INDEX `{$name}` ({$definition->quotedColumns()})"
                .$definition->parserClause()
            );
        } catch (Throwable $e) {
            // 드롭만 되고 끝나면 검색이 아예 죽는다 — 원 정의로 되돌린 뒤 예외를 전파한다
            $this->restoreQuietly($definition);

            throw $e;
        }
    }

    /**
     * 인덱스의 파서를 조회합니다.
     *
     * `INFORMATION_SCHEMA` 는 파서를 노출하지 않으므로 `SHOW CREATE TABLE` 정의에서 읽습니다.
     *
     * @param  string  $table  테이블명 (프리픽스 포함)
     * @param  string  $index  인덱스명
     * @return string|null 파서명 (없으면 null)
     */
    private function resolveParser(string $table, string $index): ?string
    {
        try {
            $create = DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
        } catch (Throwable) {
            return null;
        }

        $sql = (array) $create;
        $ddl = (string) (($sql['Create Table'] ?? $sql['Create View'] ?? ''));

        $pattern = '/FULLTEXT\s+KEY\s+`'.preg_quote($index, '/').'`[^\n]*?WITH\s+PARSER\s+`?(\w+)`?/i';

        return preg_match($pattern, $ddl, $m) === 1 ? $m[1] : null;
    }

    /**
     * 테이블의 단일 컬럼 기본키를 조회합니다.
     *
     * @param  string  $table  테이블명 (프리픽스 포함)
     * @return string|null 기본키 컬럼명 (복합키·부재 시 null)
     */
    private function resolvePrimaryKey(string $table): ?string
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS column_name FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             ORDER BY SEQ_IN_INDEX',
            [$table, 'PRIMARY']
        );

        return count($rows) === 1 ? (string) $rows[0]->column_name : null;
    }

    /**
     * 색인 컬럼에 내용이 있는 표본 행을 뽑습니다.
     *
     * @param  FulltextIndexDefinition  $definition  인덱스 정의
     * @param  string  $key  기본키 컬럼명
     * @param  int  $sampleSize  표본 행 수
     * @return array<int, object> `pk`, `body` 를 가진 행 배열
     */
    private function sampleRows(FulltextIndexDefinition $definition, string $key, int $sampleSize): array
    {
        $body = "CONCAT_WS(' ', {$definition->quotedColumns()})";

        return DB::select(
            "SELECT `{$key}` AS pk, {$body} AS body
             FROM {$definition->quotedTable()}
             WHERE {$body} <> '' AND {$body} IS NOT NULL
             ORDER BY `{$key}` DESC
             LIMIT ".max(1, $sampleSize)
        );
    }

    /**
     * 행 내용에서 검색 토큰 후보를 만듭니다.
     *
     * 비-라틴(한글·한자 등) 조각을 먼저 담습니다 — 기본 불용어 목록이 영어라
     * 라틴 토큰보다 토크나이저 영향을 덜 받습니다.
     *
     * @param  string  $text  행 내용
     * @return array<int, string> 토큰 후보 목록
     */
    private function buildTokens(string $text): array
    {
        // 부울 모드 연산자(+ - > < ( ) ~ * " @)와 구분자를 제거한다
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
        $pieces = array_values(array_filter(
            explode(' ', $normalized),
            fn (string $piece) => mb_strlen($piece) >= 2
        ));

        if ($pieces === []) {
            return [];
        }

        // 길이 내림차순 — 짧은 조각일수록 불용어·잡음일 확률이 높다
        usort($pieces, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $nonLatin = [];
        $latin = [];

        foreach ($pieces as $piece) {
            $token = mb_substr($piece, 0, self::MAX_TOKEN_LENGTH);

            if (preg_match('/^[\x00-\x7F]+$/', $token) === 1) {
                $latin[] = $token;
            } else {
                $nonLatin[] = $token;
            }
        }

        return array_slice(array_values(array_unique([...$nonLatin, ...$latin])), 0, self::MAX_TOKENS_PER_ROW);
    }

    /**
     * 한 행이 자기 토큰 중 하나로 자신을 찾을 수 있는지 확인합니다.
     *
     * @param  FulltextIndexDefinition  $definition  인덱스 정의
     * @param  string  $key  기본키 컬럼명
     * @param  mixed  $pk  행의 기본키 값
     * @param  array<int, string>  $tokens  토큰 후보
     * @return bool 하나라도 찾으면 true
     */
    private function rowFindsItself(FulltextIndexDefinition $definition, string $key, mixed $pk, array $tokens): bool
    {
        foreach ($tokens as $token) {
            $hit = DB::selectOne(
                "SELECT COUNT(*) AS hits FROM {$definition->quotedTable()}
                 WHERE `{$key}` = ? AND MATCH({$definition->quotedColumns()}) AGAINST(? IN BOOLEAN MODE)",
                [$pk, '"'.$token.'"']
            );

            if ((int) ($hit->hits ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 재생성 실패 시 원 정의로 되돌립니다 (실패해도 원 예외를 가리지 않도록 조용히 처리).
     *
     * @param  FulltextIndexDefinition  $definition  인덱스 정의
     * @return void
     */
    private function restoreQuietly(FulltextIndexDefinition $definition): void
    {
        try {
            DB::statement(
                "ALTER TABLE {$definition->quotedTable()} ADD FULLTEXT INDEX `{$definition->name}` "
                ."({$definition->quotedColumns()})".$definition->parserClause()
            );
        } catch (Throwable) {
            // 되돌리기까지 실패하면 원 예외가 더 중요한 정보다
        }
    }
}
