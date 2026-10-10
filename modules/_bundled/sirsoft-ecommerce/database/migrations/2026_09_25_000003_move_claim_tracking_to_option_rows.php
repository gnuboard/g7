<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 신청서 헤더의 송장·회수지·반품 환불 연결을 상품 행으로 옮기고 헤더 컬럼을 지웁니다.
 *
 * 앞 마이그레이션(`add_tracking_columns_to_claim_option_tables` · `add_item_level_columns_to_claim_tables`)이
 * 만든 상품 행 칸을 기존 신청서 값으로 채운 뒤, 두 번째 진실 공급원이 되지 않게 헤더 칸을 없앤다.
 *
 * 백필 규칙 (쿼리 빌더 · 멱등 — 행에 이미 값이 있으면 건너뛴다)
 *  1. 헤더 회수 송장 → 그 신청서의 반려·철회를 뺀 상품 행 전부(완료 행 포함 — 그 송장으로 회수·발송됐던 행). 등록 시각은 헤더 `updated_at`,
 *     행 상태가 회수도착 이후면 행 도착 시각 = 헤더 `collection_completed_at`.
 *  2. 헤더 재발송 송장(교환) → 같은 방식. 행 상태가 발송도착 이후면 행 도착 시각 = 헤더 `redelivered_at`.
 *  3. 헤더 회수지 → 상품 행 회수지.
 *  4. 반품 헤더 `refund_id` → 그 반품의 **완료** 상품 행.
 *
 * `down()` 은 헤더 컬럼을 되살리고 신청서의 첫 행 값을 헤더로 되돌린다.
 */
return new class extends Migration
{
    /**
     * 비종결 상품 상태 판정에서 뺄 종결 상태 값 (교환·반품 공통 — 완료·반려·철회)
     *
     * 반려·철회된 상품 행은 회수·발송 대상이 아니므로 송장을 받지 않는다. 완료 행은 제외하지
     * 않는다 — 완료 전에 이미 송장을 달고 회수·발송됐던 행이다.
     *
     * @var array<int, string>
     */
    private array $closedStatuses = ['rejected', 'withdrawn'];

    /**
     * 회수도착 이후 상태 (행 도착 시각을 채울 상태)
     *
     * @var array<string, array<int, string>>
     */
    private array $collectedStatuses = [
        'exchange' => ['collected', 'redelivery_ready', 'redelivering', 'redelivered', 'completed'],
        'return' => ['collected', 'completed'],
    ];

    /**
     * 발송도착 이후 상태 (교환 행 재발송 도착 시각을 채울 상태)
     *
     * @var array<int, string>
     */
    private array $redeliveredStatuses = ['redelivered', 'completed'];

    /**
     * Run the migrations.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if ($this->headerHasColumn('ecommerce_order_exchanges', 'collection_tracking_number')) {
            $this->backfillExchanges();
        }

        if ($this->headerHasColumn('ecommerce_order_returns', 'collection_tracking_number')) {
            $this->backfillReturns();
        }

        $this->dropHeaderColumns();
    }

    /**
     * 교환 신청서의 송장·회수지를 상품 행으로 옮깁니다.
     *
     * @return void 반환값 없음
     */
    private function backfillExchanges(): void
    {
        DB::table('ecommerce_order_exchanges')
            ->select([
                'id', 'updated_at', 'return_address_snapshot',
                'collection_carrier_id', 'collection_tracking_number', 'collection_completed_at',
                'redelivery_carrier_id', 'redelivery_tracking_number', 'redelivered_at',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($headers) {
                foreach ($headers as $header) {
                    $this->copyDirection('ecommerce_order_exchange_options', 'order_exchange_id', $header, 'collection', $this->collectedStatuses['exchange']);
                    $this->copyDirection('ecommerce_order_exchange_options', 'order_exchange_id', $header, 'redelivery', $this->redeliveredStatuses);
                    $this->copyReturnAddress('ecommerce_order_exchange_options', 'order_exchange_id', $header);
                }
            });
    }

    /**
     * 반품 신청서의 송장·회수지·환불 연결을 상품 행으로 옮깁니다.
     *
     * @return void 반환값 없음
     */
    private function backfillReturns(): void
    {
        $columns = ['id', 'updated_at', 'return_address_snapshot', 'collection_carrier_id', 'collection_tracking_number', 'collection_completed_at'];
        $hasRefundId = Schema::hasColumn('ecommerce_order_returns', 'refund_id');

        if ($hasRefundId) {
            $columns[] = 'refund_id';
        }

        DB::table('ecommerce_order_returns')
            ->select($columns)
            ->orderBy('id')
            ->chunkById(200, function ($headers) use ($hasRefundId) {
                foreach ($headers as $header) {
                    $this->copyDirection('ecommerce_order_return_options', 'order_return_id', $header, 'collection', $this->collectedStatuses['return']);
                    $this->copyReturnAddress('ecommerce_order_return_options', 'order_return_id', $header);

                    if ($hasRefundId && $header->refund_id !== null && Schema::hasColumn('ecommerce_order_return_options', 'refund_id')) {
                        DB::table('ecommerce_order_return_options')
                            ->where('order_return_id', $header->id)
                            ->where('option_status', 'completed')
                            ->whereNull('refund_id')
                            ->update(['refund_id' => $header->refund_id]);
                    }
                }
            });
    }

    /**
     * 헤더의 한 방향 송장을 비종결 상품 행으로 복사합니다. (행에 송장이 이미 있으면 건너뜀)
     *
     * @param  string  $table  상품 행 테이블
     * @param  string  $foreignKey  신청서 FK 컬럼
     * @param  object  $header  신청서 헤더 행
     * @param  string  $direction  collection|redelivery
     * @param  array<int, string>  $arrivedStatuses  도착 시각을 채울 행 상태
     * @return void 반환값 없음
     */
    private function copyDirection(string $table, string $foreignKey, object $header, string $direction, array $arrivedStatuses): void
    {
        $carrier = $header->{$direction.'_carrier_id'} ?? null;
        $tracking = $header->{$direction.'_tracking_number'} ?? null;

        $arrivedAtColumn = $direction === 'collection' ? 'collected_at' : 'redelivered_at';
        $headerArrivedAt = $direction === 'collection' ? $header->collection_completed_at : $header->redelivered_at;

        // 도착 시각은 송장과 무관하게 옮긴다 — 송장 없이 회수도착·발송도착한 행(고객이 직접 가져온 물건)도
        // 도착 사실은 있고, 상품 칸·「송장 없이 도착」 묶음이 이 칸을 읽는다.
        if ($headerArrivedAt !== null) {
            DB::table($table)
                ->where($foreignKey, $header->id)
                ->whereIn('option_status', $arrivedStatuses)
                ->whereNull($arrivedAtColumn)
                ->update([$arrivedAtColumn => $headerArrivedAt]);
        }

        if ($tracking === null || trim((string) $tracking) === '') {
            return;
        }

        $base = fn () => DB::table($table)
            ->where($foreignKey, $header->id)
            ->whereNotIn('option_status', $this->closedStatuses)
            ->whereNull($direction.'_tracking_number');

        $base()->update([
            $direction.'_carrier_id' => $carrier,
            $direction.'_tracking_number' => $tracking,
            $direction.'_shipped_at' => $header->updated_at,
        ]);
    }

    /**
     * 헤더 회수지를 상품 행으로 복사합니다. (행에 회수지가 이미 있으면 건너뜀)
     *
     * @param  string  $table  상품 행 테이블
     * @param  string  $foreignKey  신청서 FK 컬럼
     * @param  object  $header  신청서 헤더 행
     * @return void 반환값 없음
     */
    private function copyReturnAddress(string $table, string $foreignKey, object $header): void
    {
        if ($header->return_address_snapshot === null) {
            return;
        }

        DB::table($table)
            ->where($foreignKey, $header->id)
            ->whereNull('return_address_snapshot')
            ->update(['return_address_snapshot' => $header->return_address_snapshot]);
    }

    /**
     * 헤더 송장 컬럼과 반품 헤더 환불 연결을 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function dropHeaderColumns(): void
    {
        $this->dropColumns('ecommerce_order_exchanges', [
            'fk_ex_collection_carrier_id' => 'foreign',
            'fk_ex_redelivery_carrier_id' => 'foreign',
        ], ['collection_carrier_id', 'collection_tracking_number', 'redelivery_carrier_id', 'redelivery_tracking_number']);

        $this->dropColumns('ecommerce_order_returns', [
            'fk_rt_collection_carrier_id' => 'foreign',
            'fk_rt_refund_id' => 'foreign',
            'ecommerce_order_returns_refund_id_index' => 'index',
        ], ['collection_carrier_id', 'collection_tracking_number', 'refund_id']);
    }

    /**
     * 테이블이 있고 컬럼이 있는지 확인합니다.
     *
     * @param  string  $table  테이블명
     * @param  string  $column  컬럼명
     * @return bool 있으면 true
     */
    private function headerHasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    /**
     * 지정한 이름의 외래키가 이미 존재하는지 확인합니다.
     *
     * @param  string  $table  테이블명 (프리픽스 없는 논리명)
     * @param  string  $name  외래키 제약 이름
     * @return bool 존재하면 true
     */
    private function hasForeignKey(string $table, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * 외래키·인덱스를 먼저 지우고 컬럼을 지웁니다.
     *
     * @param  string  $table  테이블명
     * @param  array<string, string>  $constraints  제약 이름 => foreign|index
     * @param  array<int, string>  $columns  지울 컬럼
     * @return void 반환값 없음
     */
    private function dropColumns(string $table, array $constraints, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $constraints) {
            foreach ($constraints as $name => $kind) {
                if ($kind === 'foreign' && $this->hasForeignKey($table, $name)) {
                    $blueprint->dropForeign($name);
                }
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table, $constraints) {
            foreach ($constraints as $name => $kind) {
                if ($kind === 'index' && Schema::hasIndex($table, $name)) {
                    $blueprint->dropIndex($name);
                }
            }
        });

        $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing) {
            $blueprint->dropColumn($existing);
        });
    }

    /**
     * Reverse the migrations.
     *
     * 헤더 컬럼을 되살리고 신청서의 **첫 행**(ID 오름차순) 값을 헤더로 되돌린다. 상품 행 칸은
     * 앞 마이그레이션의 `down()` 이 지운다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        $this->restoreExchangeHeader();
        $this->restoreReturnHeader();
    }

    /**
     * 교환 헤더 송장 컬럼을 되살리고 첫 행 값을 옮깁니다.
     *
     * @return void 반환값 없음
     */
    private function restoreExchangeHeader(): void
    {
        $table = 'ecommerce_order_exchanges';

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'collection_carrier_id')) {
                $blueprint->foreignId('collection_carrier_id')->nullable()->after('redelivery_address_snapshot')
                    ->comment('회수 택배사 ID');
            }
            if (! Schema::hasColumn($table, 'collection_tracking_number')) {
                $blueprint->string('collection_tracking_number', 50)->nullable()->after('collection_carrier_id')
                    ->comment('회수 운송장 번호');
            }
            if (! Schema::hasColumn($table, 'redelivery_carrier_id')) {
                $blueprint->foreignId('redelivery_carrier_id')->nullable()->after('collection_completed_at')
                    ->comment('재발송 택배사 ID');
            }
            if (! Schema::hasColumn($table, 'redelivery_tracking_number')) {
                $blueprint->string('redelivery_tracking_number', 50)->nullable()->after('redelivery_carrier_id')
                    ->comment('재발송 운송장 번호');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! $this->hasForeignKey($table, 'fk_ex_collection_carrier_id')) {
                $blueprint->foreign('collection_carrier_id', 'fk_ex_collection_carrier_id')
                    ->references('id')->on('ecommerce_shipping_carriers')->nullOnDelete();
            }
            if (! $this->hasForeignKey($table, 'fk_ex_redelivery_carrier_id')) {
                $blueprint->foreign('redelivery_carrier_id', 'fk_ex_redelivery_carrier_id')
                    ->references('id')->on('ecommerce_shipping_carriers')->nullOnDelete();
            }
        });

        if (! Schema::hasColumn('ecommerce_order_exchange_options', 'collection_tracking_number')) {
            return;
        }

        DB::table($table)->select('id')->orderBy('id')->chunkById(200, function ($headers) use ($table) {
            foreach ($headers as $header) {
                $updates = [];

                $collection = DB::table('ecommerce_order_exchange_options')
                    ->where('order_exchange_id', $header->id)
                    ->whereNotNull('collection_tracking_number')
                    ->orderBy('id')
                    ->first(['collection_carrier_id', 'collection_tracking_number']);

                if ($collection !== null) {
                    $updates['collection_carrier_id'] = $collection->collection_carrier_id;
                    $updates['collection_tracking_number'] = $collection->collection_tracking_number;
                }

                $redelivery = DB::table('ecommerce_order_exchange_options')
                    ->where('order_exchange_id', $header->id)
                    ->whereNotNull('redelivery_tracking_number')
                    ->orderBy('id')
                    ->first(['redelivery_carrier_id', 'redelivery_tracking_number']);

                if ($redelivery !== null) {
                    $updates['redelivery_carrier_id'] = $redelivery->redelivery_carrier_id;
                    $updates['redelivery_tracking_number'] = $redelivery->redelivery_tracking_number;
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $header->id)->update($updates);
                }
            }
        });
    }

    /**
     * 반품 헤더 송장·환불 연결 컬럼을 되살리고 첫 행 값을 옮깁니다.
     *
     * @return void 반환값 없음
     */
    private function restoreReturnHeader(): void
    {
        $table = 'ecommerce_order_returns';

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'collection_carrier_id')) {
                $blueprint->foreignId('collection_carrier_id')->nullable()->after('return_address_snapshot')
                    ->comment('회수 택배사 ID');
            }
            if (! Schema::hasColumn($table, 'collection_tracking_number')) {
                $blueprint->string('collection_tracking_number', 50)->nullable()->after('collection_carrier_id')
                    ->comment('회수 운송장 번호');
            }
            if (! Schema::hasColumn($table, 'refund_id')) {
                $blueprint->foreignId('refund_id')->nullable()->after('collection_completed_at')
                    ->comment('반품완료 후 생성된 환불신청서 ID');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasIndex($table, 'ecommerce_order_returns_refund_id_index')) {
                $blueprint->index('refund_id', 'ecommerce_order_returns_refund_id_index');
            }
            if (! $this->hasForeignKey($table, 'fk_rt_collection_carrier_id')) {
                $blueprint->foreign('collection_carrier_id', 'fk_rt_collection_carrier_id')
                    ->references('id')->on('ecommerce_shipping_carriers')->nullOnDelete();
            }
            if (! $this->hasForeignKey($table, 'fk_rt_refund_id')) {
                $blueprint->foreign('refund_id', 'fk_rt_refund_id')
                    ->references('id')->on('ecommerce_order_refunds')->nullOnDelete();
            }
        });

        $optionTable = 'ecommerce_order_return_options';

        if (! Schema::hasColumn($optionTable, 'collection_tracking_number')) {
            return;
        }

        $hasOptionRefund = Schema::hasColumn($optionTable, 'refund_id');

        DB::table($table)->select('id')->orderBy('id')->chunkById(200, function ($headers) use ($table, $optionTable, $hasOptionRefund) {
            foreach ($headers as $header) {
                $updates = [];

                $collection = DB::table($optionTable)
                    ->where('order_return_id', $header->id)
                    ->whereNotNull('collection_tracking_number')
                    ->orderBy('id')
                    ->first(['collection_carrier_id', 'collection_tracking_number']);

                if ($collection !== null) {
                    $updates['collection_carrier_id'] = $collection->collection_carrier_id;
                    $updates['collection_tracking_number'] = $collection->collection_tracking_number;
                }

                if ($hasOptionRefund) {
                    $refundId = DB::table($optionTable)
                        ->where('order_return_id', $header->id)
                        ->whereNotNull('refund_id')
                        ->orderBy('id')
                        ->value('refund_id');

                    if ($refundId !== null) {
                        $updates['refund_id'] = $refundId;
                    }
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $header->id)->update($updates);
                }
            }
        });
    }
};
