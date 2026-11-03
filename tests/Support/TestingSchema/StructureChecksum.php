<?php

namespace Tests\Support\TestingSchema;

use Illuminate\Database\Connection;

/**
 * DB 실물 구조 지문
 *
 * 기록 파일이 "이 지문의 마이그레이션으로 만든 스키마" 라고 말해도, 그 뒤 누가 DB 를 손댔을 수 있다 — 스키마를
 * 바꾼 채 끝난 테스트, 중간에 끊긴 마이그레이션(반쪽 스키마, 2026-04-26 252 errors), 손으로 고친 컬럼. 그래서
 * 재사용 판정마다 `information_schema` 에서 구조를 직접 읽어 기록과 대조한다.
 *
 * 동적으로 변하는 값(AUTO_INCREMENT, 행 수, 데이터 크기, 생성·갱신 시각, 인덱스 카디널리티)은 넣지 않는다 — 행을
 * 넣고 롤백만 해도 바뀌어 매번 재생으로 가게 된다. GROUP_CONCAT 은 1024자에서 잘리므로 쓰지 않고 행 배열을
 * PHP 에서 해시한다.
 */
final class StructureChecksum
{
    /**
     * 출처별 조회문. 모두 `?` 하나(스키마 이름)를 받는다.
     *
     * @var array<string, string>
     */
    private const QUERIES = [
        'tables' => 'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
        'columns' => 'SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                CHARACTER_SET_NAME, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION, COLUMN_COMMENT, SRS_ID
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
        'statistics' => 'SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, SUB_PART, INDEX_TYPE,
                COLLATION, IS_VISIBLE, EXPRESSION
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        'table_constraints' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
            FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE',
        'key_column_usage' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
        'referential_constraints' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE, REFERENCED_TABLE_NAME
            FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME',
        'check_constraints' => 'SELECT CONSTRAINT_NAME, CHECK_CLAUSE
            FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? ORDER BY CONSTRAINT_NAME',
        'partitions' => 'SELECT TABLE_NAME, PARTITION_NAME, SUBPARTITION_NAME, PARTITION_METHOD, PARTITION_EXPRESSION,
                PARTITION_DESCRIPTION, PARTITION_ORDINAL_POSITION
            FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, PARTITION_ORDINAL_POSITION, PARTITION_NAME, SUBPARTITION_NAME',
        'views' => 'SELECT TABLE_NAME, VIEW_DEFINITION
            FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
        'triggers' => 'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
            FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
        'routines' => 'SELECT ROUTINE_NAME, ROUTINE_TYPE, ROUTINE_DEFINITION
            FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME',
        'events' => 'SELECT EVENT_NAME, EVENT_DEFINITION
            FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME',
    ];

    /**
     * 연결로 구조 지문을 계산합니다(write 연결로 읽음).
     *
     * @param  Connection  $connection  테스트 DB 연결
     * @param  string  $database  스키마 이름
     * @return string sha1 구조 지문
     */
    public static function compute(Connection $connection, string $database): string
    {
        return self::computeWith(
            static fn (string $sql, array $bindings): array => $connection->select($sql, $bindings, false),
            $database
        );
    }

    /**
     * 조회 함수로 구조 지문을 계산합니다(테스트 밖 도구도 같은 규칙을 쓰도록 분리).
     *
     * @param  callable(string, array<int, mixed>): array<int, object|array<string, mixed>>  $select  조회 함수
     * @param  string  $database  스키마 이름
     * @return string sha1 구조 지문
     */
    public static function computeWith(callable $select, string $database): string
    {
        $parts = [];

        foreach (self::QUERIES as $source => $sql) {
            $rows = array_map(
                static fn ($row): array => array_values((array) $row),
                $select($sql, [$database])
            );
            $parts[] = $source.':'.sha1((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }

        return sha1(implode("\n", $parts));
    }
}
