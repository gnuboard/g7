<?php

namespace App\Benchmark;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 계측용 합성 행 시딩기
 *
 * 깊은 OFFSET 비용은 행 수와 행 폭이 있어야 재현되므로, 계측 전에 대상 테이블을 원하는
 * 규모까지 채웁니다. 스키마를 introspect 해 NOT NULL + 기본값 없는 컬럼만 타입에 맞춰
 * 채우므로 계측 대상이 늘 때마다 시더를 따라 만들지 않아도 됩니다.
 *
 * 합성 값은 실제 부모 행을 가리키지 않으므로 외래키 제약을 일시 해제합니다. 본 시딩이
 * 재현하려는 것은 대상 테이블의 OFFSET 스캔 비용(행 수·행 폭)이고 조인은 계측 대상이
 * 아니므로, 부모 무결성 없이 목적에 충분합니다 — 폐기 가능한 계측용 DB 에서만 쓰는 것을
 * 전제로 하며, 운영 환경 거부는 호출자(`ListAxisRunner`)가 담당합니다.
 */
class SyntheticSeeder
{
    /**
     * 한 번에 삽입할 행 수
     */
    private const CHUNK_SIZE = 500;

    /**
     * 계측용 합성 행을 시딩합니다.
     *
     * @param  string  $table  대상 테이블
     * @param  int  $count  시딩 건수
     * @param  array<string, mixed>  $overrides  컬럼별 고정값 (실제 조회 조건과 맞추기 위한 지정)
     * @param  \Closure|null  $onProgress  진행 콜백 (int $inserted, int $total)
     * @param  array<int, string>  $requiredColumns  nullable 이어도 반드시 채울 컬럼 (계측이 정렬 기준으로 삼는 컬럼)
     */
    public function seed(string $table, int $count, array $overrides = [], ?\Closure $onProgress = null, array $requiredColumns = []): void
    {
        $fillable = $this->fillableColumns($table);
        $required = $this->requiredColumnDefinitions($table, $requiredColumns, $fillable);

        $chunk = [];
        $inserted = 0;

        for ($i = 1; $i <= $count; $i++) {
            $chunk[] = $this->synthesizeRow($table, array_merge($fillable, $required), $overrides, $i);

            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->insert($table, $chunk);
                $inserted += count($chunk);
                $chunk = [];

                if ($onProgress !== null) {
                    $onProgress($inserted, $count);
                }
            }
        }

        if ($chunk !== []) {
            $this->insert($table, $chunk);
            $inserted += count($chunk);

            if ($onProgress !== null) {
                $onProgress($inserted, $count);
            }
        }
    }

    /**
     * 대상 테이블을 비웁니다.
     *
     * 다른 테이블이 FK 로 참조하면 TRUNCATE 가 거부되므로 제약을 잠시 내립니다.
     *
     * @param  string  $table  대상 테이블
     */
    public function truncate(string $table): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($table) {
            DB::table($table)->truncate();
        });
    }

    /**
     * 값을 반드시 채워야 하는 컬럼 목록을 반환합니다.
     *
     * @param  string  $table  대상 테이블
     * @return array<int, array<string, mixed>> Schema::getColumns() 항목 목록
     */
    private function fillableColumns(string $table): array
    {
        return array_values(array_filter(
            Schema::getColumns($table),
            fn (array $column) => ! ($column['auto_increment'] ?? false)
                && ! $column['nullable']
                && ($column['default'] === null)
        ));
    }

    /**
     * 계측이 정렬 기준으로 삼는 컬럼의 정의를 반환합니다.
     *
     * `fillableColumns()` 는 "NOT NULL + 기본값 없음" 만 남기므로, Laravel 기본 `timestamps()`
     * 처럼 nullable 로 선언된 `created_at` 계열은 여기서 빠진다. 그런데 목록 프로파일 대부분이
     * `created_at` 으로 정렬하므로, 그대로 두면 합성 행이 전부 NULL 동률이 되어 계측이
     * 정렬 인덱스가 아니라 tie-break 경로를 재게 된다.
     *
     * @param  string  $table  대상 테이블
     * @param  array<int, string>  $names  반드시 채울 컬럼명 목록
     * @param  array<int, array<string, mixed>>  $already  이미 채우기로 한 컬럼 목록
     * @return array<int, array<string, mixed>> 추가로 채울 컬럼 정의 목록
     */
    private function requiredColumnDefinitions(string $table, array $names, array $already): array
    {
        if ($names === []) {
            return [];
        }

        $covered = array_column($already, 'name');
        $columns = collect(Schema::getColumns($table))->keyBy('name');

        $extra = [];

        foreach (array_unique($names) as $name) {
            if (in_array($name, $covered, true) || ! $columns->has($name)) {
                continue;
            }

            $column = $columns->get($name);

            // auto increment 키는 DB 가 채운다
            if ($column['auto_increment'] ?? false) {
                continue;
            }

            $extra[] = $column;
        }

        return $extra;
    }

    /**
     * 합성 행 1건을 만듭니다.
     *
     * @param  string  $table  대상 테이블
     * @param  array<int, array<string, mixed>>  $fillable  채워야 하는 컬럼 목록
     * @param  array<string, mixed>  $overrides  컬럼별 고정값
     * @param  int  $index  행 인덱스 (고유값 생성용)
     * @return array<string, mixed> 합성 행
     */
    private function synthesizeRow(string $table, array $fillable, array $overrides, int $index): array
    {
        $row = [];

        foreach ($fillable as $column) {
            $row[$column['name']] = array_key_exists($column['name'], $overrides)
                ? $overrides[$column['name']]
                : $this->synthesizeValue($column, $index);
        }

        // NULL 허용 컬럼이라 위 루프에서 빠졌더라도, 실제 조회 조건에 쓰이는 컬럼은
        // 채워야 계측이 화면과 같은 인덱스를 타게 된다.
        foreach ($overrides as $name => $value) {
            if (! array_key_exists($name, $row) && Schema::hasColumn($table, $name)) {
                $row[$name] = $value;
            }
        }

        return $row;
    }

    /**
     * 합성 행을 삽입합니다. (외래키 제약 일시 해제)
     *
     * @param  string  $table  대상 테이블
     * @param  array<int, array<string, mixed>>  $chunk  삽입할 행 묶음
     */
    private function insert(string $table, array $chunk): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($table, $chunk) {
            DB::table($table)->insert($chunk);
        });
    }

    /**
     * 컬럼 타입에 맞는 합성 값을 만듭니다.
     *
     * @param  array<string, mixed>  $column  Schema::getColumns() 항목
     * @param  int  $index  행 인덱스 (고유값 생성용)
     * @return mixed 합성 값
     */
    private function synthesizeValue(array $column, int $index): mixed
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'varchar'));

        return match (true) {
            str_contains($type, 'int') => $index,
            str_contains($type, 'decimal'), str_contains($type, 'float'), str_contains($type, 'double') => 1000,
            str_contains($type, 'bool'), str_contains($type, 'tinyint') => 0,
            str_contains($type, 'json') => '{}',
            // 시간 컬럼을 한 값으로 채우면 정렬이 전부 동률이 되어 filesort 가 지배해 버린다.
            // 정렬 인덱스 효과를 재는 것이 목적이므로 행마다 값을 분산시킨다.
            str_contains($type, 'date'), str_contains($type, 'time') => now()->subMinutes($index),
            str_contains($type, 'text') => str_repeat('벤치마크 본문 ', 100),
            default => $this->synthesizeString($column, $index),
        };
    }

    /**
     * 문자열 컬럼의 선언 길이에 맞는 합성 값을 만듭니다.
     *
     * 컬럼 길이를 무시하고 고정 길이 문자열을 넣으면 `varchar(10)` 류(신고 대상 타입,
     * 우편번호, 로그 타입 등)에서 "Data too long" 으로 시딩 자체가 실패한다.
     *
     * @param  array<string, mixed>  $column  Schema::getColumns() 항목
     * @param  int  $index  행 인덱스 (고유값 생성용)
     * @return string 합성 문자열
     */
    private function synthesizeString(array $column, int $index): string
    {
        $length = 40;

        // type 은 'varchar(10)' 형태로 선언 길이를 담고 있다
        if (preg_match('/\((\d+)\)/', (string) ($column['type'] ?? ''), $m) === 1) {
            $length = max(1, (int) $m[1]);
        }

        // 짧은 컬럼은 고유성 확보가 우선이라 인덱스 자체를 문자열로 쓴다
        if ($length <= 12) {
            return substr((string) $index, -$length);
        }

        return substr("bench-{$index}-".Str::random(8), 0, $length);
    }
}
