<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'ecommerce_order_payments';

    private const INDEX = 'ecommerce_order_payments_transaction_id_unique';

    /**
     * ecommerce_order_payments.transaction_id 컬럼에 unique 제약 추가.
     *
     * PG 결제 콜백 replay 공격에 대한 DB 레벨 멱등성 보장.
     * 동일 transaction_id 로 콜백이 두 번 도착해도 DB 가 두 번째 insert/update 를
     * 거부 → application-layer 가드(plugin 측 replay 트레이트) 와 함께 다중 방어 구축.
     *
     * MySQL 의 unique 인덱스는 NULL 다중 허용 (대기 상태 transaction_id IS NULL row 복수 허용).
     *
     * 적용 방지 패턴:
     *  - 테이블 미존재 시 skip
     *  - 인덱스 이미 존재 시 skip
     *  - 기존 중복 row 존재 시 fail (운영자가 정리 후 재시도)
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        // 사전 안전 검증: 기존 데이터에 중복 transaction_id 존재 시 마이그레이션 거부.
        // 운영자가 수동 정리 후 재시도하도록 한다 (중복 데이터 자동 삭제는 위험).
        $duplicates = DB::table(self::TABLE)
            ->whereNotNull('transaction_id')
            ->select('transaction_id', DB::raw('COUNT(*) AS cnt'))
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            throw new \RuntimeException(
                "ecommerce_order_payments 테이블에 transaction_id 가 중복된 row {$duplicates} 건 존재. "
                . '운영자가 SELECT transaction_id, COUNT(*) FROM ecommerce_order_payments WHERE transaction_id IS NOT NULL GROUP BY transaction_id HAVING COUNT(*) > 1; '
                . '로 식별 후 중복 정리 후 재시도하세요.'
            );
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unique('transaction_id', self::INDEX);
        });
    }

    /**
     * unique 제약 제거.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! $this->indexExists(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }

    /**
     * 지정된 인덱스가 테이블에 존재하는지 확인.
     *
     * @param  string  $table
     * @param  string  $indexName
     * @return bool
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $prefix = DB::getTablePrefix();
        $fullTable = $prefix . $table;
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $result = DB::select(
                'SELECT COUNT(*) AS cnt FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$fullTable, $indexName]
            );

            return ((int) ($result[0]->cnt ?? 0)) > 0;
        }

        // 그 외 드라이버: Doctrine 우회 — Schema::hasIndex 가 없으므로 보수적으로 false 반환
        // (sqlite 테스트 환경은 매번 fresh migrate 라 인덱스 중복 위험 없음)
        return false;
    }
};
