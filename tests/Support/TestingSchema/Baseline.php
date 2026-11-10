<?php

namespace Tests\Support\TestingSchema;

use Illuminate\Database\Connection;

/**
 * 전체 재생 직후 마이그레이션이 넣은 기준 행 스냅숏
 *
 * 마이그레이션 중에는 행을 넣는 것이 있다(예: 개인정보 처리방침 첫 판). 전체 재생은 이 행이 늘 있는 DB 를
 * 만들었으므로, 재사용할 때 잔여 행을 지운 뒤 이 스냅숏으로 되돌린다. 시드 프로필은 여기 넣지 않는다 —
 * 시드는 판정 때마다 다시 돌려 `now()` 같은 값까지 지금과 같게 만든다.
 *
 * `migrations` 기록도 담는다 — 테스트가 `migrate:rollback --path`·`migrate --path` 로 batch 구성을 바꿔 두면(2026-11-03 실측),
 * 구조 지문·이름 집합은 같아도 다음 실행의 rollback 이 다른 batch 를 보고 엉뚱하게 동작한다. 재사용 때 전체 재생 직후의
 * id·이름·batch 로 되돌린다.
 *
 * 바이너리 계열 값은 JSON 에 그대로 담을 수 없어 base64 로 바꾼다. 생성 컬럼은 넣을 수 없으므로 뺀다.
 */
final class Baseline
{
    /** base64 로 담는 컬럼 형식 */
    private const BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection', 'geomcollection',
    ];

    /**
     * 비어 있지 않은 테이블 전부와 `migrations` 기록의 행을 스냅숏합니다.
     *
     * @param  Connection  $connection  테스트 DB 연결(write)
     * @param  string  $database  스키마 이름
     * @return array<string, array{columns: array<int, string>, b64: array<int, string>, rows: array<int, array<int, mixed>>}> 테이블 → 스냅숏
     */
    public static function snapshot(Connection $connection, string $database): array
    {
        $baseline = [];

        foreach (DataReset::nonEmptyTables($connection, $database) as $table) {
            $baseline[$table] = self::snapshotTable($connection, $database, $table);
        }

        $migrations = DataReset::migrationsTable($connection);
        $baseline[$migrations] = self::snapshotTable($connection, $database, $migrations);

        ksort($baseline, SORT_STRING);

        return $baseline;
    }

    /**
     * 테이블 하나의 행을 스냅숏합니다.
     *
     * @param  Connection  $connection  테스트 DB 연결(write)
     * @param  string  $database  스키마 이름
     * @param  string  $table  테이블 이름(접두어 포함)
     * @return array{columns: array<int, string>, b64: array<int, string>, rows: array<int, array<int, mixed>>} 스냅숏
     */
    public static function snapshotTable(Connection $connection, string $database, string $table): array
    {
        $columns = [];
        $b64 = [];

        $meta = $connection->select(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, EXTRA AS extra, GENERATION_EXPRESSION AS gen
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$database, $table],
            false
        );

        foreach ($meta as $column) {
            $column = (array) $column;
            if ((string) ($column['gen'] ?? '') !== '' || str_contains(strtoupper((string) $column['extra']), 'GENERATED')) {
                continue;
            }
            $columns[] = (string) $column['name'];
            if (in_array(strtolower((string) $column['type']), self::BINARY_TYPES, true)) {
                $b64[] = (string) $column['name'];
            }
        }

        $select = implode(', ', array_map([DataReset::class, 'quote'], $columns));
        $rows = [];
        foreach ($connection->select('SELECT '.$select.' FROM '.DataReset::quote($table), [], false) as $row) {
            $row = (array) $row;
            $values = [];
            foreach ($columns as $name) {
                $value = $row[$name] ?? null;
                if ($value !== null && in_array($name, $b64, true)) {
                    $value = base64_encode((string) $value);
                }
                $values[] = $value;
            }
            $rows[] = $values;
        }

        return ['columns' => $columns, 'b64' => $b64, 'rows' => $rows];
    }

    /**
     * 스냅숏을 테이블에 다시 넣습니다(테이블은 이미 비어 있어야 함).
     *
     * @param  Connection  $connection  테스트 DB 연결(write)
     * @param  string  $table  테이블 이름(접두어 포함)
     * @param  array{columns: array<int, string>, b64: array<int, string>, rows: array<int, array<int, mixed>>}  $snapshot  스냅숏
     * @param  int  $chunk  한 번에 넣는 행 수
     * @return void 반환값 없음
     */
    public static function restore(Connection $connection, string $table, array $snapshot, int $chunk = 500): void
    {
        $columns = $snapshot['columns'];
        if ($columns === [] || $snapshot['rows'] === []) {
            return;
        }

        $b64Positions = array_keys(array_intersect($columns, $snapshot['b64']));
        $placeholder = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $head = 'INSERT INTO '.DataReset::quote($table).' ('.implode(', ', array_map([DataReset::class, 'quote'], $columns)).') VALUES ';

        foreach (array_chunk($snapshot['rows'], max(1, $chunk)) as $rows) {
            $bindings = [];
            foreach ($rows as $row) {
                foreach ($b64Positions as $position) {
                    if ($row[$position] !== null) {
                        $row[$position] = base64_decode((string) $row[$position], true);
                    }
                }
                array_push($bindings, ...array_values($row));
            }
            $connection->insert($head.implode(', ', array_fill(0, count($rows), $placeholder)), $bindings);
        }
    }
}
