<?php

namespace Tests\Support\TestingSchema;

use Illuminate\Database\Connection;

/**
 * 재사용하는 테스트 스키마의 잔여 행 정리
 *
 * 테스트는 트랜잭션 안에서 돌고 끝나면 롤백하지만, 그 밖에 남는 행이 있다 — 트랜잭션 안 DDL 이 암묵 커밋한 행,
 * 시드 프로필(`db:seed`)이 넣은 행, 트랜잭션을 쓰지 않는 테스트가 남긴 행. 전체 재생은 이걸 모두 지웠으므로,
 * 재사용할 때도 같은 상태로 맞춘다: 비어 있지 않은 테이블을 비우고, 마이그레이션이 넣었던 기준 행을 되돌린다.
 * `migrations` 기록도 기준 행에 들어 있어 전체 재생 직후의 batch 구성으로 돌아간다({@see Baseline}).
 *
 * 빈 테이블의 자동 증가 번호는 초기화하지 않는다(2026-11-02 결정 — DELETE 는 번호를 되돌리지 않는다).
 * 아주 큰 테이블만 TRUNCATE 한다.
 */
final class DataReset
{
    /** 이 행 수를 넘는 테이블은 TRUNCATE 한다 */
    public const TRUNCATE_THRESHOLD = 5000;

    /** 기준 행을 한 번에 넣는 행 수 */
    private const INSERT_CHUNK = 500;

    /**
     * 잔여 행을 정리하고 기준 행을 되돌립니다.
     *
     * @param  Connection  $connection  테스트 DB 연결(write)
     * @param  string  $database  스키마 이름
     * @param  array<string, array{columns: array<int, string>, b64: array<int, string>, rows: array<int, array<int, mixed>>}>  $baseline  기준 행
     * @return array<int, string> 정리한 테이블 목록
     */
    public static function run(Connection $connection, string $database, array $baseline): array
    {
        $targets = array_values(array_unique(array_merge(
            self::nonEmptyTables($connection, $database),
            array_keys($baseline)
        )));
        sort($targets, SORT_STRING);

        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($targets as $table) {
                if (self::hasMoreRowsThan($connection, $table, self::TRUNCATE_THRESHOLD)) {
                    $connection->statement('TRUNCATE TABLE '.self::quote($table));
                } else {
                    $connection->statement('DELETE FROM '.self::quote($table));
                }
            }

            foreach ($baseline as $table => $snapshot) {
                Baseline::restore($connection, $table, $snapshot, self::INSERT_CHUNK);
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $targets;
    }

    /**
     * `migrations` 를 뺀 일반 테이블 목록(접두어 포함 실제 이름)을 돌려줍니다.
     *
     * @param  Connection  $connection  테스트 DB 연결
     * @param  string  $database  스키마 이름
     * @return array<int, string> 테이블 이름 목록
     */
    public static function baseTables(Connection $connection, string $database): array
    {
        $rows = $connection->select(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            [$database],
            false
        );

        $migrations = self::migrationsTable($connection);

        return array_values(array_filter(
            array_map(static fn ($row): string => (string) ((array) $row)['t'], $rows),
            static fn (string $table): bool => $table !== $migrations
        ));
    }

    /**
     * 행이 하나라도 있는 테이블 목록을 한 번의 조회로 돌려줍니다(`migrations` 제외).
     *
     * @param  Connection  $connection  테스트 DB 연결
     * @param  string  $database  스키마 이름
     * @return array<int, string> 테이블 이름 목록
     */
    public static function nonEmptyTables(Connection $connection, string $database): array
    {
        $tables = self::baseTables($connection, $database);
        if ($tables === []) {
            return [];
        }

        $parts = array_map(
            static fn (string $table): string => 'SELECT '.$connection->getPdo()->quote($table).' AS t FROM DUAL WHERE EXISTS (SELECT 1 FROM '.self::quote($table).')',
            $tables
        );

        return array_map(
            static fn ($row): string => (string) ((array) $row)['t'],
            $connection->select(implode(' UNION ALL ', $parts), [], false)
        );
    }

    /**
     * 실제 `migrations` 테이블 이름(접두어 포함)을 돌려줍니다.
     *
     * @param  Connection  $connection  테스트 DB 연결
     * @return string 테이블 이름
     */
    public static function migrationsTable(Connection $connection): string
    {
        $configured = function_exists('config') ? config('database.migrations') : null;
        $table = is_array($configured) ? ($configured['table'] ?? 'migrations') : ($configured ?: 'migrations');

        return $connection->getTablePrefix().$table;
    }

    /**
     * 식별자를 백틱으로 감쌉니다.
     *
     * @param  string  $identifier  테이블·컬럼 이름
     * @return string 감싼 식별자
     */
    public static function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * 테이블 행 수가 기준을 넘는지 확인합니다(전체 COUNT 없이).
     *
     * @param  Connection  $connection  테스트 DB 연결
     * @param  string  $table  테이블 이름
     * @param  int  $threshold  기준 행 수
     * @return bool 넘으면 true
     */
    private static function hasMoreRowsThan(Connection $connection, string $table, int $threshold): bool
    {
        return $connection->selectOne('SELECT 1 AS x FROM '.self::quote($table).' LIMIT 1 OFFSET '.$threshold, [], false) !== null;
    }
}
