<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Performance;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Concerns\CountsQueries;

/**
 * 주문 옵션 일괄 처리의 쿼리 수 회귀 테스트 (#519 E3)
 *
 * 일괄 상태 변경은 항목마다 옵션을 다시 조회했고, 결제완료 전이 가드는 주문마다
 * 다시 조회한 뒤 결제수단을 지연 로드했다. 둘 다 대상이 늘수록 조회가 함께 늘어난다.
 *
 * 전체 쿼리 수로는 재지 않는다 — 상태 UPDATE 는 항목마다 나는 것이 정상이라
 * 총량은 어차피 항목 수에 비례한다. 여기서 고정하는 것은 **쓰기 직전에 같은 행을
 * 한 건씩 다시 읽던 조회**이므로, 그 단일 행 조회의 형태를 직접 센다.
 */
class OrderOptionBulkQueryCountRegressionTest extends ModuleTestCase
{
    use CountsQueries;

    private OrderOptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OrderOptionService::class);
    }

    /**
     * 결제완료 상태의 주문과 옵션 N건을 만든다.
     *
     * @param  int  $optionCount  생성할 옵션 수
     * @return Order 생성된 주문
     */
    private function makeOrderWithOptions(int $optionCount): Order
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        for ($i = 0; $i < $optionCount; $i++) {
            OrderOptionFactory::new()->forOrder($order)->create([
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'quantity' => 1,
            ]);
        }

        return $order->fresh();
    }

    /**
     * 주문의 전 옵션을 대상으로 하는 일괄 변경 요청 페이로드를 만든다.
     *
     * @param  Order  $order  대상 주문
     * @return array<int, array{option_id: int, quantity: int}> 변경 항목 배열
     */
    private function itemsFor(Order $order): array
    {
        return $order->options->map(fn ($option) => [
            'option_id' => $option->id,
            'quantity' => (int) $option->quantity,
        ])->values()->all();
    }

    /**
     * 기본키 단일 행 조회(`where ...id = ? limit 1`)의 건수를 센다.
     *
     * @param  array<int, string>  $queries  실행된 SQL 목록
     * @param  string  $table  대상 테이블 이름 조각
     * @return int 단일 행 조회 건수
     */
    private function countSingleRowLookups(array $queries, string $table): int
    {
        $pattern = '/^select .* from \S*'.preg_quote($table, '/').'\S* where \S*"?id"?\S* = \? limit 1$/i';

        return count(array_filter(
            $queries,
            fn (string $sql) => (bool) preg_match($pattern, str_replace('`', '"', $sql))
        ));
    }

    /**
     * 일괄 상태 변경이 대상 옵션을 1회 일괄 조회해 재사용한다.
     *
     * 총 단일 행 조회 수로는 재지 않는다 — 옵션 단위 훅(`order_option.after_status_change`)이
     * 항목마다 발화하고 그 구독자들이 자기 대상을 읽으므로, 총량은 어느 구현에서든 항목 수에
     * 비례한다. 그 비례는 훅 설계상 정상이고 E3 의 대상이 아니다.
     *
     * E3 가 없앤 것은 **변경 대상을 확정하는 단계**에서 항목마다 같은 행을 한 건씩 다시 읽던
     * 조회다. 그래서 "첫 쓰기 이전에 단일 행 조회가 없고, 대상이 일괄 조회로 확정된다"는
     * 구조를 직접 고정한다. 루프 안에 단건 조회가 되살아나면 첫 항목의 조회가 첫 UPDATE 를
     * 앞서므로 이 단언이 깨진다.
     *
     * @scenario operation=bulk_change_with_quantity, item_count=6, target_status=shipping
     *
     * @effects bulk_change_resolves_targets_in_one_batched_query
     */
    public function test_bulk_change_resolves_targets_in_one_batched_query(): void
    {
        $order = $this->makeOrderWithOptions(6);
        $items = $this->itemsFor($order);

        $queries = $this->captureQueries(function () use ($items, $order) {
            $this->service->bulkChangeStatusWithQuantity(
                $items,
                OrderStatusEnum::SHIPPING,
                $order->id
            );
        });

        $firstWriteAt = $this->indexOfFirstWrite($queries, 'order_options');
        $this->assertNotNull($firstWriteAt, '옵션 상태 UPDATE 가 실행되지 않았다 — 측정 전제가 무너졌다');

        $beforeWrite = array_slice($queries, 0, $firstWriteAt);

        $lookupsBeforeWrite = $this->countSingleRowLookups($beforeWrite, 'order_options');
        $this->assertSame(
            0,
            $lookupsBeforeWrite,
            "변경 대상을 확정하기 전에 옵션을 단건으로 {$lookupsBeforeWrite}회 조회했다. "
            .'항목마다 옵션을 다시 읽고 있다 — 대상 옵션을 1회 일괄 조회해 재사용한다'
        );

        $batched = array_filter(
            $beforeWrite,
            fn (string $sql) => (bool) preg_match('/^select \* from \S*order_options\S* where \S*id\S* in \(/i', str_replace('`', '"', $sql))
        );
        $this->assertNotEmpty(
            $batched,
            '대상 옵션을 일괄 조회(`where id in (...)`)한 흔적이 없다 — 1회 일괄 조회로 확정해야 한다'
        );
    }

    /**
     * 대상 테이블에 대한 첫 쓰기(UPDATE) 위치를 찾는다.
     *
     * @param  array<int, string>  $queries  실행된 SQL 목록
     * @param  string  $table  대상 테이블 이름 조각
     * @return int|null 첫 UPDATE 의 인덱스 (없으면 null)
     */
    private function indexOfFirstWrite(array $queries, string $table): ?int
    {
        foreach ($queries as $index => $sql) {
            if (preg_match('/^update \S*'.preg_quote($table, '/').'\S*/i', str_replace('`', '"', $sql))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * 결제완료 전이 가드가 주문마다 다시 읽지 않는다.
     *
     * 옵션 일괄변경은 단일 주문 범위지만, 가드는 옵션에서 주문을 역산하므로
     * 여러 주문에 걸친 내부/훅 호출에서 주문마다 조회 + 결제수단 지연 로드가 발생했다.
     *
     * @scenario operation=enforce_payment_complete_idv, order_count=1_vs_3, target_status=payment_complete
     *
     * @effects payment_complete_guard_query_count_does_not_grow_with_order_count
     */
    public function test_payment_complete_guard_query_count_does_not_grow_with_order_count(): void
    {
        $single = $this->makeGuardTargets(1);
        $multiple = $this->makeGuardTargets(3);

        $singleQueries = $this->countQueries(fn () => $this->invokeGuard($single));
        $multipleQueries = $this->countQueries(fn () => $this->invokeGuard($multiple));

        $this->assertLessThanOrEqual(
            $singleQueries,
            $multipleQueries,
            "결제완료 가드의 쿼리 수가 주문 수에 비례한다 (1주문={$singleQueries} / 3주문={$multipleQueries}). "
            .'주문마다 다시 조회하고 결제수단을 지연 로드하고 있다 — 결제 관계까지 1회 일괄 조회한다'
        );
    }

    /**
     * 가드 대상 옵션 ID 집합을 만든다 (주문마다 옵션 1건 + 결제 1건).
     *
     * @param  int  $orderCount  생성할 주문 수
     * @return array<int, int> 옵션 ID 배열
     */
    private function makeGuardTargets(int $orderCount): array
    {
        $optionIds = [];

        for ($i = 0; $i < $orderCount; $i++) {
            $order = OrderFactory::new()->create([
                'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            ]);
            OrderPaymentFactory::new()->create(['order_id' => $order->id]);
            $optionIds[] = OrderOptionFactory::new()->forOrder($order)->create([
                'option_status' => OrderStatusEnum::PENDING_PAYMENT,
                'quantity' => 1,
            ])->id;
        }

        return $optionIds;
    }

    /**
     * private 가드 메서드를 직접 호출한다.
     *
     * @param  array<int, int>  $optionIds  대상 옵션 ID 배열
     */
    private function invokeGuard(array $optionIds): void
    {
        $method = new \ReflectionMethod($this->service, 'enforcePaymentCompleteIdv');
        $method->invoke($this->service, $optionIds);
    }
}
