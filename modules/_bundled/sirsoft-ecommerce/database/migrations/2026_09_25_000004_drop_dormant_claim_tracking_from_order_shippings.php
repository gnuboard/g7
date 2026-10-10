<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 주문 배송 행의 쓰지 않는 클레임 송장 컬럼 4개를 지웁니다.
 *
 * `return_carrier_id`·`return_tracking_number`·`exchange_carrier_id`·`exchange_tracking_number` 는 주문
 * 배송 표를 만들 때 자리만 잡아 두고 어느 경로도 쓰지 않았다. 클레임 송장은 교환·반품 상품 행이
 * 가지므로(`add_tracking_columns_to_claim_option_tables`) 이 네 칸이 남아 있으면 확장이 읽어도 언제나
 * NULL 인 두 번째 진실 공급원이 된다.
 *
 * `return_shipping_amount`·`mc_return_shipping_amount` 는 주문 생성이 0 을 쓰므로 남긴다.
 * 네 컬럼에는 인덱스·외래키가 없다(2026_04_01_000021 생성 마이그레이션). `down()` 은 같은 형으로 되살린다.
 *
 * 번들 코드는 이 네 칸을 쓴 적이 없지만 1.2.1 모델은 대량 할당을 허용했다 — 외부 확장이 값을 적어 두었을 수 있어
 * 지우기 전에 값이 있는 행 수를 경고 로그로 남긴다(값 자체는 옮기지 않는다, 7.1.0 출시 점검 EU-8).
 */
return new class extends Migration
{
    /**
     * 지울 컬럼
     *
     * @var array<int, string>
     */
    private array $columns = [
        'return_carrier_id',
        'return_tracking_number',
        'exchange_carrier_id',
        'exchange_tracking_number',
    ];

    /**
     * Run the migrations.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        $table = 'ecommerce_order_shippings';

        if (! Schema::hasTable($table)) {
            return;
        }

        $existing = array_values(array_filter($this->columns, fn (string $column) => Schema::hasColumn($table, $column)));

        if ($existing === []) {
            return;
        }

        $filled = DB::table($table)->where(function ($query) use ($existing) {
            foreach ($existing as $column) {
                $query->orWhereNotNull($column);
            }
        })->count();

        if ($filled > 0) {
            Log::warning('[ecommerce] 주문 배송 행의 미사용 클레임 송장 컬럼을 지웁니다 — 값이 있던 행 '.$filled.'건', [
                'table' => $table,
                'columns' => $existing,
            ]);
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing) {
            $blueprint->dropColumn($existing);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        $table = 'ecommerce_order_shippings';

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'return_carrier_id')) {
                $blueprint->unsignedBigInteger('return_carrier_id')->nullable()->after('return_shipping_amount')
                    ->comment('반품 택배사 ID');
            }
            if (! Schema::hasColumn($table, 'return_tracking_number')) {
                $blueprint->string('return_tracking_number', 50)->nullable()->after('return_carrier_id')
                    ->comment('반품 운송장 번호');
            }
            if (! Schema::hasColumn($table, 'exchange_carrier_id')) {
                $blueprint->unsignedBigInteger('exchange_carrier_id')->nullable()->after('return_tracking_number')
                    ->comment('교환 택배사 ID');
            }
            if (! Schema::hasColumn($table, 'exchange_tracking_number')) {
                $blueprint->string('exchange_tracking_number', 50)->nullable()->after('exchange_carrier_id')
                    ->comment('교환 운송장 번호');
            }
        });
    }
};
