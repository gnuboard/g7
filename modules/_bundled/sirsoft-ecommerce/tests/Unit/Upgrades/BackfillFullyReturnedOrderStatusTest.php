<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * 전량 반품 주문 상태 보정(DataMigration) 테스트
 *
 * 1.2.1 까지 전량 반품을 마친 주문은 「배송완료」 등으로 남았다. 업그레이드는 모든 행이 반품완료·취소이고 반품완료가
 * 하나 이상인 주문만 반품완료로 바꾼다 — 부분 반품·진행 중 클레임이 남은 주문은 그대로다.
 *
 * 시나리오 매니페스트: `tests/scenarios/order-fully-returned-status.yaml`
 */
class BackfillFullyReturnedOrderStatusTest extends ModuleTestCase
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
            .'/upgrades/data/1.3.0/migrations/11_BackfillFullyReturnedOrderStatus.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_0\\Migrations\\BackfillFullyReturnedOrderStatus';
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
     * 주문 상태와 행 상태 목록으로 주문을 만듭니다.
     *
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  array<int, OrderStatusEnum>  $rowStatuses  행 상태 목록
     * @return Order 주문
     */
    private function orderWith(OrderStatusEnum $status, array $rowStatuses): Order
    {
        $order = Order::factory()->create(['order_status' => $status]);
        foreach ($rowStatuses as $rowStatus) {
            OrderOption::factory()->create(['order_id' => $order->id, 'option_status' => $rowStatus]);
        }

        return $order;
    }

    /**
     * 전량 반품(반품만·반품 + 취소) 주문만 반품완료로 바꾸고, 부분 반품·진행 중 클레임 주문은 그대로 둔다. 두 번 돌려도 같다.
     *
     * @scenario remaining=none, mix=returned_and_cancelled
     *
     * @effects upgrade_backfills_fully_returned_orders
     *
     * @return void 반환값 없음
     */
    public function test_backfills_only_fully_returned_orders_idempotently(): void
    {
        $returnedOnly = $this->orderWith(OrderStatusEnum::DELIVERED, [OrderStatusEnum::RETURNED, OrderStatusEnum::RETURNED]);
        $withCancelled = $this->orderWith(OrderStatusEnum::DELIVERED, [OrderStatusEnum::RETURNED, OrderStatusEnum::CANCELLED]);
        $partial = $this->orderWith(OrderStatusEnum::DELIVERED, [OrderStatusEnum::RETURNED, OrderStatusEnum::DELIVERED]);
        $inProgress = $this->orderWith(OrderStatusEnum::DELIVERED, [OrderStatusEnum::RETURNED, OrderStatusEnum::EXCHANGE_IN_PROGRESS]);
        $allCancelled = $this->orderWith(OrderStatusEnum::CANCELLED, [OrderStatusEnum::CANCELLED]);

        $this->migration->run($this->context);

        $this->assertSame(OrderStatusEnum::RETURNED, $returnedOnly->fresh()->order_status);
        $this->assertSame(OrderStatusEnum::RETURNED, $withCancelled->fresh()->order_status);
        $this->assertSame(OrderStatusEnum::DELIVERED, $partial->fresh()->order_status, '부분 반품 주문을 바꿨습니다.');
        $this->assertSame(OrderStatusEnum::DELIVERED, $inProgress->fresh()->order_status, '진행 중 클레임이 남은 주문을 바꿨습니다.');
        $this->assertSame(OrderStatusEnum::CANCELLED, $allCancelled->fresh()->order_status);

        $changed = array_filter($this->records, fn ($r) => isset($r[2]['order_number']));
        $this->assertCount(2, $changed, '바꾼 주문마다 주문번호·전후 상태를 남겨야 합니다.');

        $this->records = [];
        $this->migration->run($this->context);
        $this->assertEmpty(array_filter($this->records, fn ($r) => isset($r[2]['order_number'])), '두 번째 실행이 같은 주문을 다시 바꿨습니다.');
    }

    /**
     * 교환을 마친 뒤 받은 상품을 전부 반품한 주문도 보정한다 — 교환완료로 닫힌 원래 상품은 살아 있는 상품이 아니다.
     *
     * @scenario remaining=none, mix=exchanged_then_returned
     *
     * @effects upgrade_backfills_fully_returned_orders
     *
     * @return void 반환값 없음
     */
    public function test_backfills_order_whose_exchanged_items_were_all_returned(): void
    {
        $order = $this->orderWith(OrderStatusEnum::DELIVERED, [OrderStatusEnum::EXCHANGED, OrderStatusEnum::RETURNED]);
        $exchangedOnly = $this->orderWith(OrderStatusEnum::DELIVERED, [OrderStatusEnum::EXCHANGED, OrderStatusEnum::DELIVERED]);

        $this->migration->run($this->context);

        $this->assertSame(OrderStatusEnum::RETURNED, $order->fresh()->order_status, '교환 후 전량 반품 주문을 보정하지 않았습니다.');
        $this->assertSame(OrderStatusEnum::DELIVERED, $exchangedOnly->fresh()->order_status, '교환만 마친 주문을 바꿨습니다.');
    }
}
