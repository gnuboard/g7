<?php

namespace Tests\Support\TestingSchema;

use Illuminate\Database\Connection;

/**
 * 테이블 컬럼 순서 기록·복원 — 마이그레이션 down()·up() 을 다시 돌리는 테스트가 끝날 때 원래 순서로 되돌린다.
 *
 * 컬럼을 `after()` 로 끼워 넣는 마이그레이션은, 그 뒤 마이그레이션이 같은 자리에 다른 컬럼을 더 넣은 스키마에서 up() 을 다시
 * 돌리면 원래와 다른 위치에 컬럼을 만든다. 컬럼 순서는 `SELECT *` 결과의 키 순서라 실행마다 테스트 결과가 갈릴 수 있고,
 * 구조 지문이 달라져 다음 실행이 테스트 스키마를 처음부터 다시 만든다. 그래서 기록한 순서와 어긋난 첫 컬럼부터
 * `MODIFY ... AFTER` 로 정의를 그대로 둔 채 자리만 옮긴다(생성 컬럼은 옮기지 않는다).
 */
final class ColumnOrder
{
    /**
     * 테이블들의 컬럼 정의를 순서대로 기록합니다.
     *
     * @param  Connection  $connection  테스트 DB 연결
     * @param  array<int, string>  $tables  테이블 이름(접두어 없이)
     * @return array<string, array<int, array<string, mixed>>> 접두어 포함 테이블 이름 → 컬럼 정의 목록
     */
    public static function capture(Connection $connection, array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $full = $connection->getTablePrefix().$table;
            $snapshot[$full] = array_map(
                static fn ($row): array => (array) $row,
                $connection->select(
                    'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT, CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION
                     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                    [$full],
                    false
                )
            );
        }

        return $snapshot;
    }

    /**
     * 기록한 순서와 다른 테이블의 컬럼 자리를 되돌립니다(정의는 기록한 그대로).
     *
     * @param  Connection  $connection  테스트 DB 연결
     * @param  array<string, array<int, array<string, mixed>>>  $snapshot  capture() 결과
     * @return array<int, string> 순서를 고친 테이블 목록
     */
    public static function restore(Connection $connection, array $snapshot): array
    {
        $fixed = [];

        foreach ($snapshot as $table => $columns) {
            $current = array_map(
                static fn ($row): string => (string) ((array) $row)['COLUMN_NAME'],
                $connection->select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table], false)
            );
            $wanted = array_column($columns, 'COLUMN_NAME');

            if ($current === $wanted || array_diff($wanted, $current) !== [] || array_diff($current, $wanted) !== []) {
                continue; // 순서가 같거나, 컬럼 집합 자체가 다르면(복원 대상 아님) 건드리지 않는다
            }

            $clauses = [];
            $mismatch = false;
            foreach ($columns as $i => $column) {
                $mismatch = $mismatch || ($current[$i] ?? null) !== $column['COLUMN_NAME'];
                if (! $mismatch || (string) ($column['GENERATION_EXPRESSION'] ?? '') !== '') {
                    continue;
                }
                $position = $i === 0 ? 'FIRST' : 'AFTER '.DataReset::quote($columns[$i - 1]['COLUMN_NAME']);
                $clauses[] = 'MODIFY COLUMN '.DataReset::quote($column['COLUMN_NAME']).' '.self::definition($connection, $column).' '.$position;
            }

            if ($clauses !== []) {
                $connection->statement('ALTER TABLE '.DataReset::quote($table).' '.implode(', ', $clauses));
                $fixed[] = $table;
            }
        }

        return $fixed;
    }

    /**
     * information_schema 행으로 컬럼 정의 SQL 을 만듭니다.
     *
     * @param  Connection  $connection  테스트 DB 연결(문자열 인용용)
     * @param  array<string, mixed>  $column  컬럼 정의 행
     * @return string 정의 SQL(이름·위치 제외)
     */
    private static function definition(Connection $connection, array $column): string
    {
        $pdo = $connection->getPdo();
        $sql = (string) $column['COLUMN_TYPE'];

        if ($column['CHARACTER_SET_NAME'] !== null) {
            $sql .= ' CHARACTER SET '.$column['CHARACTER_SET_NAME'].' COLLATE '.$column['COLLATION_NAME'];
        }

        $sql .= $column['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';

        $extra = (string) $column['EXTRA'];
        $default = $column['COLUMN_DEFAULT'];
        if ($default !== null) {
            // 시각 기본값은 괄호 없이 — 괄호를 두르면 MySQL 이 now() 식 기본값으로 바꿔 저장해 정의가 달라진다
            $sql .= match (true) {
                ! str_contains($extra, 'DEFAULT_GENERATED') => ' DEFAULT '.$pdo->quote((string) $default),
                (bool) preg_match('/^CURRENT_TIMESTAMP(\(\d*\))?$/i', (string) $default) => ' DEFAULT '.$default,
                default => ' DEFAULT ('.$default.')',
            };
        } elseif ($column['IS_NULLABLE'] === 'YES') {
            $sql .= ' DEFAULT NULL';
        }

        $extra = trim(str_replace('DEFAULT_GENERATED', '', $extra));
        if ($extra !== '') {
            $sql .= ' '.$extra;
        }

        if ((string) $column['COLUMN_COMMENT'] !== '') {
            $sql .= ' COMMENT '.$pdo->quote((string) $column['COLUMN_COMMENT']);
        }

        return $sql;
    }
}
