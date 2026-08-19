<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_1_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;

/**
 * 주문/주문옵션 외래키 컬럼의 누락된 한국어 comment 를 채웁니다.
 *
 * 1.0.5 의 같은 보정에서 빠져 있던 컬럼들입니다. 마이그레이션 소스는 교정했지만,
 * 이미 설치를 마친 사이트는 create 마이그레이션을 다시 실행하지 않으므로
 * 설명이 비어 있는 채로 남습니다.
 *
 * 멱등: 설명이 이미 있는 컬럼은 건드리지 않습니다. 재실행해도 결과가 같습니다.
 *
 * 안전:
 *   - 설명이 **비어 있을 때만** 채웁니다 — 운영자가 직접 넣어 둔 설명을 덮어쓰지 않습니다.
 *   - 자료형·NULL 허용·기본값·자동증가를 현재 스키마에서 읽어 그대로 재적용하므로 설명 외에는
 *     아무 것도 바뀌지 않습니다.
 *   - MySQL 계열에서만 동작합니다(다른 DB 는 건너뜁니다).
 *
 * 실패 정책: 컬럼 단위로 실패를 흡수합니다 — 한 컬럼이 실패해도 나머지를 계속 처리합니다.
 *
 * V-1 안전: `Illuminate\Support\Facades\DB` 와 로컬 private 헬퍼만 사용하고, 대상 목록을
 * 본 클래스에 동결 상수로 둡니다(마이그레이션 파일·모델 미참조).
 */
class BackfillOrderForeignKeyColumnComments implements DataMigration
{
    /**
     * 보정 대상 (1.1.1 시점 동결) — 테이블 => [컬럼 => 설명].
     */
    private const TARGETS = [
        'ecommerce_orders' => [
            'user_id' => '주문자 회원 ID (비회원 주문은 null)',
        ],
        'ecommerce_order_options' => [
            'order_id' => '소속 주문 ID',
            'product_id' => '주문 시점 상품 ID',
            'product_option_id' => '주문 시점 상품 옵션 ID',
        ],
    ];

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 식별자
     */
    public function name(): string
    {
        return 'BackfillOrderForeignKeyColumnComments';
    }

    /**
     * 비어 있는 외래키 컬럼 설명을 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $context->logger->info('[ecommerce:1.1.1] 컬럼 설명 보정 — MySQL 계열이 아니어서 건너뜁니다');

            return;
        }

        $filled = 0;
        $skipped = 0;
        $failed = 0;

        foreach (self::TARGETS as $table => $columns) {
            $prefixed = $context->table($table);

            foreach ($columns as $column => $comment) {
                try {
                    $meta = $this->columnMeta($prefixed, $column);

                    if ($meta === null || trim((string) $meta->COLUMN_COMMENT) !== '') {
                        $skipped++;

                        continue;
                    }

                    DB::statement($this->buildModifyStatement($prefixed, $column, $meta, $comment));
                    $filled++;
                } catch (\Throwable $e) {
                    $failed++;
                    $context->logger->warning(sprintf(
                        '[ecommerce:1.1.1] 컬럼 설명 보정 실패 (계속 진행): %s.%s — %s',
                        $prefixed,
                        $column,
                        $e->getMessage(),
                    ));
                }
            }
        }

        $context->logger->info(sprintf(
            '[ecommerce:1.1.1] 주문 외래키 컬럼 설명 보정 — 채움 %d건 / 대상 아님 %d건 / 실패 %d건',
            $filled,
            $skipped,
            $failed,
        ));
    }

    /**
     * 컬럼의 현재 스키마 메타데이터를 조회합니다.
     *
     * @param  string  $table  프리픽스가 적용된 테이블명
     * @param  string  $column  컬럼명
     * @return object|null 컬럼 메타 (테이블/컬럼 부재 시 null)
     */
    private function columnMeta(string $table, string $column): ?object
    {
        $rows = DB::select(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        return $rows[0] ?? null;
    }

    /**
     * 설명만 덧붙이는 MODIFY COLUMN 문을 조립합니다.
     *
     * @param  string  $table  프리픽스가 적용된 테이블명
     * @param  string  $column  컬럼명
     * @param  object  $meta  columnMeta() 결과
     * @param  string  $comment  넣을 설명
     * @return string 실행할 SQL
     */
    private function buildModifyStatement(string $table, string $column, object $meta, string $comment): string
    {
        $sql = sprintf(
            'ALTER TABLE %s MODIFY COLUMN %s %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column),
            $meta->COLUMN_TYPE,
        );

        $sql .= $meta->IS_NULLABLE === 'YES' ? ' NULL' : ' NOT NULL';

        if ($meta->COLUMN_DEFAULT !== null) {
            $sql .= ' DEFAULT '.$this->quoteValue((string) $meta->COLUMN_DEFAULT);
        }

        if (stripos(trim((string) $meta->EXTRA), 'auto_increment') !== false) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql.' COMMENT '.$this->quoteValue($comment);
    }

    /**
     * 식별자를 백틱으로 감쌉니다.
     *
     * @param  string  $identifier  테이블/컬럼명
     * @return string 이스케이프된 식별자
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * 문자열 값을 SQL 리터럴로 인용합니다.
     *
     * @param  string  $value  값
     * @return string 인용된 리터럴
     */
    private function quoteValue(string $value): string
    {
        return DB::connection()->getPdo()->quote($value);
    }
}
