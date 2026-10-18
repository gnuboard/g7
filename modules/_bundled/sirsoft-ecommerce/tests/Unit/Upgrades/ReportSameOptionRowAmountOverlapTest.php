<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * 같은 상품옵션 행 금액 겹침 보고(DataMigration) 테스트 (S-Q26-c)
 *
 * 1.2.1 의 부분취소 재계산이 같은 상품옵션의 활성 두 행에 각각 키 합계를 써 둔 주문(1521 형태)만 경고한다.
 * 교환 후속 행은 원 행 금액을 의도적으로 승계하므로 오탐 대상이 아니다. 금액은 바꾸지 않는다.
 *
 * 시나리오 매니페스트: `tests/scenarios/partial-cancel-row-allocation.yaml`
 *
 * @effects upgrade_reports_same_option_amount_overlap_without_writing
 */
class ReportSameOptionRowAmountOverlapTest extends ModuleTestCase
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
            .'/upgrades/data/1.3.0/migrations/09_ReportSameOptionRowAmountOverlap.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\ReportSameOptionRowAmountOverlap';
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
     * 주문 하나에 같은 상품옵션 두 행을 만듭니다.
     *
     * @param  array<string, mixed>  $second  두 번째 행 덮어쓰기
     * @param  float  $paid  각 행 결제 금액
     * @return Order 주문
     */
    private function orderWithTwoRows(array $second, float $paid): Order
    {
        $order = Order::factory()->create();
        $first = OrderOption::factory()->create([
            'order_id' => $order->id,
            'option_status' => OrderStatusEnum::PREPARING,
            'quantity' => 1,
            'unit_price' => 30000,
            'subtotal_price' => 30000,
            'subtotal_paid_amount' => $paid,
        ]);
        OrderOption::factory()->create(array_merge([
            'order_id' => $order->id,
            'product_id' => $first->product_id,
            'product_option_id' => $first->product_option_id,
            'option_status' => OrderStatusEnum::SHIPPING,
            'quantity' => 1,
            'unit_price' => 30000,
            'subtotal_price' => 30000,
            'subtotal_paid_amount' => $paid,
        ], $second));

        return $order;
    }

    /**
     * 금액이 겹친 같은 상품옵션 행만 경고하고(교환 후속 쌍·정상 쌍 제외) DB 는 바꾸지 않는다.
     *
     * @effects upgrade_reports_same_option_amount_overlap_without_writing
     *
     * @return void 반환값 없음
     */
    public function test_reports_only_overlapping_rows_and_writes_nothing(): void
    {
        // 1521 형태 — 30,000원 행 둘이 각 60,000원
        $overlap = $this->orderWithTwoRows([], 60000);
        // 교환 후속 쌍 — 후속 행은 원 행 금액 승계(오탐 대상)
        $this->orderWithTwoRows(['source_type' => OrderOptionSourceTypeEnum::EXCHANGE], 60000);
        // 정상 쌍
        $this->orderWithTwoRows([], 30000);

        $before = DB::table('ecommerce_order_options')->orderBy('id')->get()->toArray();

        $this->migration->run($this->context);

        $warnings = array_values(array_filter($this->records, fn ($r) => $r[0] === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertSame($overlap->order_number, $warnings[0][2]['order_number']);
        $this->assertCount(2, $warnings[0][2]['rows']);

        $this->assertEquals($before, DB::table('ecommerce_order_options')->orderBy('id')->get()->toArray());
    }
}
