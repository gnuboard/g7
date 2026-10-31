<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * 부분취소 주문의 헤더 적립 예정액 보정(DataMigration) 테스트
 *
 * 1.2.1 의 부분취소 재계산은 행별 적립 예정액은 다시 썼지만 주문 헤더(`total_earned_points_amount`)는 취소 전 값으로
 * 두었다. 1.3.0 은 재계산 때 헤더도 맞추고, 업그레이드는 이미 틀린 채 남은 부분취소 주문의 헤더를 회계 주체 행의 합으로
 * 바로잡는다. 적립 원장은 행 단위라 헤더는 표시값이다.
 *
 * 시나리오 매니페스트: `tests/scenarios/partial-cancel-row-allocation.yaml`
 */
class BackfillPartialCancelEarnedPointsTest extends ModuleTestCase
{
    private object $migration;

    /** @var array<int, array{0: string, 1: string, 2: array}> 기록된 로그 */
    private array $records = [];

    private UpgradeContext $context;

    /**
     * 업그레이드 데이터 마이그레이션을 불러오고 로그를 모으는 컨텍스트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.0/migrations/10_BackfillPartialCancelEarnedPoints.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\BackfillPartialCancelEarnedPoints';
        $this->migration = new $class;

        $records = &$this->records;
        $logger = new class($records) extends AbstractLogger
        {
            /**
             * 로그 버퍼를 참조로 받습니다.
             *
             * @param  array  $records  기록 버퍼 (참조)
             */
            public function __construct(private array &$records) {}

            /**
             * 로그 한 줄을 버퍼에 쌓습니다.
             *
             * @param  mixed  $level  수준
             * @param  string|\Stringable  $message  메시지
             * @param  array  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };

        Log::shouldReceive('channel')->once()->andReturn($logger);
        $this->context = new UpgradeContext(fromVersion: '1.2.1', toVersion: '1.3.0', currentStep: '1.3.0');
        Log::clearResolvedInstance('log');
    }

    /**
     * 헤더 적립 예정액과 행 목록으로 주문을 만듭니다.
     *
     * @param  float  $header  헤더 적립 예정액
     * @param  array<int, array<string, mixed>>  $rows  행 덮어쓰기 목록
     * @return Order 주문
     */
    private function orderWith(float $header, array $rows): Order
    {
        $order = Order::factory()->create(['total_earned_points_amount' => $header]);
        $created = [];
        foreach ($rows as $row) {
            $attributes = array_merge([
                'order_id' => $order->id,
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal_price' => 10000,
                'subtotal_paid_amount' => 10000,
            ], $row);
            if (isset($attributes['source_option_index'])) {
                $attributes['source_option_id'] = $created[$attributes['source_option_index']]->id;
                unset($attributes['source_option_index']);
            }
            $created[] = OrderOption::factory()->create($attributes);
        }

        return $order;
    }

    /**
     * 부분취소 주문만 회계 주체 행의 합으로 보정한다 — 반품 행은 포함, 취소·교환완료 원 행·진행 중 교환 후속 행은 제외.
     *
     * @effects upgrade_backfills_partial_cancel_earned_points
     *
     * @return void 반환값 없음
     */
    public function test_backfills_partial_cancel_orders_from_accountable_rows(): void
    {
        // 232 형태 — 남은 행 640, 취소 행 430, 헤더는 취소 전 합 1,070
        $partial = $this->orderWith(1070, [
            ['option_status' => OrderStatusEnum::DELIVERED, 'subtotal_earned_points_amount' => 640],
            ['option_status' => OrderStatusEnum::CANCELLED, 'subtotal_earned_points_amount' => 430],
        ]);
        // 반품 행·교환완료 원 행·완료된 교환 후속 행이 섞인 부분취소
        $mixed = $this->orderWith(999, [
            ['option_status' => OrderStatusEnum::RETURNED, 'subtotal_earned_points_amount' => 100],
            ['option_status' => OrderStatusEnum::EXCHANGED, 'subtotal_earned_points_amount' => 200],
            ['option_status' => OrderStatusEnum::DELIVERED, 'subtotal_earned_points_amount' => 200, 'source_type' => OrderOptionSourceTypeEnum::EXCHANGE, 'source_option_index' => 1],
            ['option_status' => OrderStatusEnum::CANCELLED, 'subtotal_earned_points_amount' => 50],
        ]);

        $this->migration->run($this->context);

        $this->assertEquals(640, (float) $partial->fresh()->total_earned_points_amount, '부분취소 주문 헤더가 취소 전 값으로 남았습니다.');
        $this->assertEquals(300, (float) $mixed->fresh()->total_earned_points_amount, '반품 행 포함·교환완료 원 행 제외·후속 행 포함이어야 합니다.');

        $changed = array_values(array_filter($this->records, fn ($r) => ($r[2]['order_number'] ?? null) === $partial->order_number));
        $this->assertNotEmpty($changed, '바꾼 주문을 전·후 값과 함께 남겨야 합니다.');
        $this->assertEquals(1070, $changed[0][2]['before']);
        $this->assertEquals(640, $changed[0][2]['after']);
    }

    /**
     * 전체취소·취소 없는 주문·같은 상품옵션 금액 겹침 주문은 바꾸지 않는다(겹침은 로그만).
     *
     * @effects upgrade_backfills_partial_cancel_earned_points
     *
     * @return void 반환값 없음
     */
    public function test_leaves_full_cancel_untouched_and_overlap_orders_reported(): void
    {
        $fullCancel = $this->orderWith(70, [
            ['option_status' => OrderStatusEnum::CANCELLED, 'subtotal_earned_points_amount' => 70],
        ]);
        $noCancel = $this->orderWith(500, [
            ['option_status' => OrderStatusEnum::DELIVERED, 'subtotal_earned_points_amount' => 400],
        ]);
        $overlapFirst = ['option_status' => OrderStatusEnum::PREPARING, 'subtotal_earned_points_amount' => 600, 'subtotal_paid_amount' => 20000];
        $overlap = $this->orderWith(1500, [
            $overlapFirst,
            ['option_status' => OrderStatusEnum::CANCELLED, 'subtotal_earned_points_amount' => 300],
        ]);
        // 같은 상품옵션의 두 번째 활성 행 — 결제 합이 판매가 합보다 커 겹침
        $firstRow = $overlap->options()->orderBy('id')->first();
        OrderOption::factory()->create([
            'order_id' => $overlap->id,
            'product_id' => $firstRow->product_id,
            'product_option_id' => $firstRow->product_option_id,
            'option_status' => OrderStatusEnum::SHIPPING,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal_price' => 10000,
            'subtotal_paid_amount' => 20000,
            'subtotal_earned_points_amount' => 600,
        ]);

        $this->migration->run($this->context);

        $this->assertEquals(70, (float) $fullCancel->fresh()->total_earned_points_amount, '전체취소 주문은 현행 설계대로 헤더를 두어야 합니다.');
        $this->assertEquals(500, (float) $noCancel->fresh()->total_earned_points_amount, '취소 없는 주문은 대상이 아닙니다.');
        $this->assertEquals(1500, (float) $overlap->fresh()->total_earned_points_amount, '겹침 주문의 부푼 행 값을 헤더로 옮기면 안 됩니다.');

        $warned = array_filter($this->records, fn ($r) => $r[0] === 'warning' && ($r[2]['order_number'] ?? null) === $overlap->order_number);
        $this->assertNotEmpty($warned, '겹침 주문은 보정하지 않은 이유를 로그로 남겨야 합니다.');
    }

    /**
     * 두 번 돌려도 같은 결과다(멱등).
     *
     * @effects upgrade_backfills_partial_cancel_earned_points
     *
     * @return void 반환값 없음
     */
    public function test_is_idempotent(): void
    {
        $partial = $this->orderWith(1070, [
            ['option_status' => OrderStatusEnum::DELIVERED, 'subtotal_earned_points_amount' => 640],
            ['option_status' => OrderStatusEnum::CANCELLED, 'subtotal_earned_points_amount' => 430],
        ]);

        $this->migration->run($this->context);
        $this->records = [];
        $this->migration->run($this->context);

        $this->assertEquals(640, (float) $partial->fresh()->total_earned_points_amount);
        $this->assertEmpty(array_filter($this->records, fn ($r) => ($r[2]['order_number'] ?? null) === $partial->order_number), '이미 맞는 주문을 다시 바꿨다고 기록했습니다.');
    }
}
