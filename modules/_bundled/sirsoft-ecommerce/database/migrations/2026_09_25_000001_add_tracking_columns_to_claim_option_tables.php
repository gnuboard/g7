<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 교환·반품 신청 상품 행에 송장 컬럼을 추가합니다. (상품 행마다 송장 — 주문과 같은 행 쪼개기 방식)
 *
 * 송장은 신청서 헤더 한 쌍(회수 1·재발송 1)뿐이라, 상품을 두 상자로 나눠 따로 회수·발송하면
 * 두 번째 송장이 첫 송장을 덮어썼다. 주문이 주문 상품 행(`order_shippings`)마다 송장을 두는 것처럼
 * 클레임도 **상품 행마다** 송장을 둔다. 한 상자에 여러 상품이면 같은 운송장을 여러 행에 적고,
 * 같은 상품을 나눠 보내면 수량을 지정해 행을 쪼갠 뒤 쪼갠 행에 적는다. 새 상자 테이블은 두지 않는다.
 *
 * 도착 시각(`collected_at`·`redelivered_at`)도 행마다 둔다 — 헤더의 같은 이름 컬럼은
 * 「전체 회수/발송 완료 시각」 으로 뜻이 고정된다.
 *
 * 반품은 회수 방향만 있다(교환품을 다시 보내지 않는다).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        $this->addCollectionColumns('ecommerce_order_exchange_options', 'ecommerce_order_ex_opts', 'fk_ex_opt_collection_carrier_id');
        $this->addCollectionColumns('ecommerce_order_return_options', 'ecommerce_order_rt_opts', 'fk_rt_opt_collection_carrier_id');

        $table = 'ecommerce_order_exchange_options';

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'redelivery_carrier_id')) {
                $blueprint->foreignId('redelivery_carrier_id')->nullable()->after('collected_at')
                    ->comment('재발송 택배사 ID (이 행의 교환품을 실은 택배사)');
            }
            if (! Schema::hasColumn($table, 'redelivery_tracking_number')) {
                $blueprint->string('redelivery_tracking_number', 50)->nullable()->after('redelivery_carrier_id')
                    ->comment('재발송 운송장 번호 (같은 상자에 담긴 행은 같은 번호)');
            }
            if (! Schema::hasColumn($table, 'redelivery_shipped_at')) {
                $blueprint->timestamp('redelivery_shipped_at')->nullable()->after('redelivery_tracking_number')
                    ->comment('재발송 송장 등록 시각');
            }
            if (! Schema::hasColumn($table, 'redelivered_at')) {
                $blueprint->timestamp('redelivered_at')->nullable()->after('redelivery_shipped_at')
                    ->comment('이 행의 재발송 도착 시각');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasIndex($table, 'ecommerce_order_ex_opts_redelivery_tracking_index')) {
                $blueprint->index('redelivery_tracking_number', 'ecommerce_order_ex_opts_redelivery_tracking_index');
            }
            if (! $this->hasForeignKey($table, 'fk_ex_opt_redelivery_carrier_id')) {
                $blueprint->foreign('redelivery_carrier_id', 'fk_ex_opt_redelivery_carrier_id')
                    ->references('id')->on('ecommerce_shipping_carriers')->nullOnDelete();
            }
        });
    }

    /**
     * 회수 방향 송장 컬럼 4개와 인덱스·외래키를 추가합니다. (교환·반품 공용)
     *
     * @param  string  $table  테이블명
     * @param  string  $indexPrefix  인덱스명 접두 (MySQL 식별자 64자 한도 때문에 짧게 둔다)
     * @param  string  $foreignKey  택배사 외래키 이름
     * @return void 반환값 없음
     */
    private function addCollectionColumns(string $table, string $indexPrefix, string $foreignKey): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'collection_carrier_id')) {
                $blueprint->foreignId('collection_carrier_id')->nullable()->after('completed_at')
                    ->comment('회수 택배사 ID (이 행의 헌 물건을 실은 택배사)');
            }
            if (! Schema::hasColumn($table, 'collection_tracking_number')) {
                $blueprint->string('collection_tracking_number', 50)->nullable()->after('collection_carrier_id')
                    ->comment('회수 운송장 번호 (같은 상자에 담긴 행은 같은 번호)');
            }
            if (! Schema::hasColumn($table, 'collection_shipped_at')) {
                $blueprint->timestamp('collection_shipped_at')->nullable()->after('collection_tracking_number')
                    ->comment('회수 송장 등록 시각');
            }
            if (! Schema::hasColumn($table, 'collected_at')) {
                $blueprint->timestamp('collected_at')->nullable()->after('collection_shipped_at')
                    ->comment('이 행의 회수 도착 시각');
            }
        });

        Schema::table($table, function (Blueprint $blueprint) use ($table, $indexPrefix, $foreignKey) {
            if (! Schema::hasIndex($table, $indexPrefix.'_collection_tracking_index')) {
                $blueprint->index('collection_tracking_number', $indexPrefix.'_collection_tracking_index');
            }
            if (! $this->hasForeignKey($table, $foreignKey)) {
                $blueprint->foreign('collection_carrier_id', $foreignKey)
                    ->references('id')->on('ecommerce_shipping_carriers')->nullOnDelete();
            }
        });
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
     * Reverse the migrations.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        $this->dropColumns('ecommerce_order_exchange_options', [
            'fk_ex_opt_redelivery_carrier_id' => 'foreign',
            'fk_ex_opt_collection_carrier_id' => 'foreign',
            'ecommerce_order_ex_opts_redelivery_tracking_index' => 'index',
            'ecommerce_order_ex_opts_collection_tracking_index' => 'index',
        ], [
            'redelivery_carrier_id', 'redelivery_tracking_number', 'redelivery_shipped_at', 'redelivered_at',
            'collection_carrier_id', 'collection_tracking_number', 'collection_shipped_at', 'collected_at',
        ]);

        $this->dropColumns('ecommerce_order_return_options', [
            'fk_rt_opt_collection_carrier_id' => 'foreign',
            'ecommerce_order_rt_opts_collection_tracking_index' => 'index',
        ], [
            'collection_carrier_id', 'collection_tracking_number', 'collection_shipped_at', 'collected_at',
        ]);
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
};
