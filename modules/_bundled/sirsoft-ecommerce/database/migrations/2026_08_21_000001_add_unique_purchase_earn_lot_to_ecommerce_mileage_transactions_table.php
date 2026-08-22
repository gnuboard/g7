<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 주문옵션당 구매 적립(purchase_earn) lot 을 1건으로 강제합니다.
     *
     * 적립 코드는 "옵션당 적립 내역 한 줄"을 전제로 동작한다 — 목표 적립액과 기적립 합계의
     * 차액만 증액하고, 취소 회수는 그 한 줄을 찾아 되돌린다. 그런데 lot 이 아직 없는 최초
     * 적립은 조회와 생성 사이에 잠글 행이 없어, 같은 옵션의 확정이 동시에 겹치면 두 줄이
     * 만들어질 수 있다. 그 순간 적립액은 두 배가 되고 취소 회수는 한 줄만 되돌린다.
     *
     * 반복이 정상인 유형(부분취소마다 생기는 earn_cancel 등)까지 막으면 안 되므로,
     * purchase_earn 이면서 주문옵션이 있는 행만 값을 갖는 생성 컬럼에 유니크를 건다
     * (MySQL 유니크 인덱스는 NULL 중복을 허용한다).
     *
     * 기설치본에는 이미 중복이 쌓여 있을 수 있어 인덱스 생성 전에 통합한다. 통합과 제약은
     * 한 단계라도 어긋나면 다음 실행이 영구 실패하므로 각 단계를 독립적으로 가드한다.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_mileage_transactions')) {
            return;
        }

        $this->consolidateDuplicateEarnLots();

        if (! Schema::hasColumn('ecommerce_mileage_transactions', 'purchase_earn_option_key')) {
            $table = $this->qualifiedTable();

            DB::statement(
                "ALTER TABLE {$table} ADD COLUMN `purchase_earn_option_key` BIGINT UNSIGNED"
                ." GENERATED ALWAYS AS (CASE WHEN `type` = 'purchase_earn' THEN `order_option_id` ELSE NULL END) VIRTUAL"
                ." COMMENT '주문옵션당 구매적립 1건 강제용 파생 키 (purchase_earn 이 아니면 NULL)'"
            );
        }

        if (! $this->indexExists('ecommerce_mileage_transactions_purchase_earn_option_unique')) {
            $table = $this->qualifiedTable();

            DB::statement(
                "ALTER TABLE {$table} ADD UNIQUE INDEX"
                .' `ecommerce_mileage_transactions_purchase_earn_option_unique` (`purchase_earn_option_key`)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_mileage_transactions')) {
            return;
        }

        $table = $this->qualifiedTable();

        if ($this->indexExists('ecommerce_mileage_transactions_purchase_earn_option_unique')) {
            DB::statement("ALTER TABLE {$table} DROP INDEX `ecommerce_mileage_transactions_purchase_earn_option_unique`");
        }

        if (Schema::hasColumn('ecommerce_mileage_transactions', 'purchase_earn_option_key')) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN `purchase_earn_option_key`");
        }
    }

    /**
     * 같은 주문옵션에 중복 생성된 구매 적립 lot 을 가장 먼저 만들어진 한 줄로 통합합니다.
     *
     * 금액·잔여금액을 합산해 살아남는 lot 에 얹고 나머지는 삭제한다. 유효기간은 최초 적립
     * 시점을 유지하는 기존 정책(방식 A)과 같게 살아남는 lot 의 값을 그대로 둔다.
     *
     * 반복 종료는 데이터에 맡기지 않는다 — 한 바퀴에서 실제로 지운 행이 없으면 다음 바퀴도
     * 같은 목록을 다시 읽을 뿐이므로 그 자리에서 멈춘다 (진행 없는 반복 차단).
     *
     * @return void
     */
    private function consolidateDuplicateEarnLots(): void
    {
        // 중복 주문옵션 목록은 통합 대상이 남아 있는 동안만 반복 조회한다.
        // 한 번에 모두 읽지 않으므로 중복이 많은 설치본에서도 메모리가 늘지 않는다.
        while (true) {
            $duplicated = DB::table('ecommerce_mileage_transactions')
                ->select('order_option_id')
                ->where('type', 'purchase_earn')
                ->whereNotNull('order_option_id')
                ->groupBy('order_option_id')
                ->havingRaw('COUNT(*) > 1')
                ->limit(200)
                ->pluck('order_option_id');

            if ($duplicated->isEmpty()) {
                return;
            }

            $deleted = 0;

            foreach ($duplicated as $orderOptionId) {
                $lots = DB::table('ecommerce_mileage_transactions')
                    ->where('type', 'purchase_earn')
                    ->where('order_option_id', $orderOptionId)
                    ->orderBy('id')
                    ->get(['id', 'amount', 'remaining_amount']);

                if ($lots->count() < 2) {
                    continue;
                }

                $survivor = $lots->shift();

                DB::table('ecommerce_mileage_transactions')
                    ->where('id', $survivor->id)
                    ->update([
                        'amount' => (float) $survivor->amount + (float) $lots->sum(fn ($l) => (float) $l->amount),
                        'remaining_amount' => (float) $survivor->remaining_amount + (float) $lots->sum(fn ($l) => (float) $l->remaining_amount),
                    ]);

                $deleted += DB::table('ecommerce_mileage_transactions')
                    ->whereIn('id', $lots->pluck('id')->all())
                    ->delete();
            }

            // 목록은 남아 있는데 한 행도 지우지 못했다면 더 진행할 수 없다.
            if ($deleted === 0) {
                return;
            }
        }
    }

    /**
     * 프리픽스가 붙은 실제 테이블명을 반환합니다.
     *
     * @return string 백틱으로 감싼 테이블명
     */
    private function qualifiedTable(): string
    {
        return '`'.DB::getTablePrefix().'ecommerce_mileage_transactions`';
    }

    /**
     * 인덱스 존재 여부를 확인합니다.
     *
     * @param  string  $indexName  인덱스명
     * @return bool 존재 여부
     */
    private function indexExists(string $indexName): bool
    {
        return ! empty(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [DB::getTablePrefix().'ecommerce_mileage_transactions', $indexName]
        ));
    }
};
