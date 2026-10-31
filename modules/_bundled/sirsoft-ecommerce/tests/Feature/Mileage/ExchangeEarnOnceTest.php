<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 교환 후속 상품의 구매 적립은 같은 구매에 한 번만 (S1)
 *
 * 교환 후속 행은 원 행을 복제해 적립 예정액(subtotal_earned_points_amount)을 그대로 갖는다. 적립 멱등은 주문상품
 * 행 단위라, 원 상품이 이미 적립된 뒤 교환품이 배송완료되면 같은 구매에 한 번 더 적립됐다. 후속 행의 기적립액에는
 * 원 행(교환 전 상품)의 적립을 포함한다. 원 행이 분할 조각이면 적립 거래는 부모 행에 붙어 있으므로 부모가 자기
 * 목표보다 더 받은 몫을 원 행 몫으로 본다.
 *
 * 시나리오 매니페스트: `tests/scenarios/exchange-earn-once.yaml`
 */
class ExchangeEarnOnceTest extends ModuleTestCase
{
    private UserMileageService $service;

    private User $member;

    private Order $order;

    /**
     * 마일리지 기능을 켜고 회원 주문을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        $this->service = app(UserMileageService::class);
        $this->member = User::factory()->create();
        $this->order = Order::factory()->create(['user_id' => $this->member->id, 'currency' => 'KRW']);
    }

    /**
     * 주문상품 행을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return OrderOption 주문상품
     */
    private function row(array $attributes): OrderOption
    {
        return OrderOption::factory()->forOrder($this->order)->create(array_merge([
            'quantity' => 1,
            'subtotal_earned_points_amount' => 300,
        ], $attributes));
    }

    /**
     * 주문상품에 구매 적립 거래를 직접 남깁니다(이미 적립된 상태 재현).
     *
     * @param  OrderOption  $row  주문상품
     * @param  int  $amount  적립액
     * @return void 반환값 없음
     */
    private function earned(OrderOption $row, int $amount): void
    {
        MileageTransaction::create([
            'user_id' => $this->member->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $amount,
            'order_id' => $this->order->id,
            'order_option_id' => $row->id,
        ]);
    }

    /**
     * 교환 후속 행을 만듭니다.
     *
     * @param  OrderOption  $source  교환 전 원 행
     * @return OrderOption 후속 행 (배송완료)
     */
    private function successorOf(OrderOption $source): OrderOption
    {
        return $this->row([
            'source_type' => OrderOptionSourceTypeEnum::EXCHANGE,
            'source_option_id' => $source->id,
            'option_status' => OrderStatusEnum::DELIVERED,
        ]);
    }

    /**
     * 구매 적립 합계를 구합니다.
     *
     * @return float 이 주문의 구매 적립 합
     */
    private function totalEarned(): float
    {
        return (float) MileageTransaction::query()
            ->where('order_id', $this->order->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->sum('amount');
    }

    /**
     * 원 상품이 이미 적립된 뒤 교환품이 배송완료돼도 같은 구매에 다시 적립하지 않는다.
     *
     * @scenario original_earned=before_exchange, original_shape=whole_row
     *
     * @effects exchanged_option_does_not_earn_again
     *
     * @return void 반환값 없음
     */
    public function test_exchanged_option_does_not_earn_again_after_original_earned(): void
    {
        $original = $this->row(['option_status' => OrderStatusEnum::EXCHANGED]);
        $this->earned($original, 300);

        $this->service->earnForOrderOption($this->order, $this->successorOf($original), MileageTransactionTypeEnum::PURCHASE_EARN);

        $this->assertEquals(300, $this->totalEarned());
    }

    /**
     * 원 상품이 적립 전에 교환됐으면 교환품이 그 몫을 적립한다.
     *
     * @scenario original_earned=never, original_shape=whole_row
     *
     * @effects exchanged_option_earns_when_original_never_earned
     *
     * @return void 반환값 없음
     */
    public function test_exchanged_option_earns_when_original_never_earned(): void
    {
        $original = $this->row(['option_status' => OrderStatusEnum::EXCHANGED]);

        $this->service->earnForOrderOption($this->order, $this->successorOf($original), MileageTransactionTypeEnum::PURCHASE_EARN);

        $this->assertEquals(300, $this->totalEarned());
    }

    /**
     * 2개 중 1개만 교환하면 적립 거래는 분할 전 부모 행에 붙어 있다 — 부모가 자기 목표보다 더 받은 몫을 교환된
     * 조각의 기적립으로 보아 교환품이 다시 적립하지 않는다.
     *
     * @scenario original_earned=before_exchange, original_shape=split_child
     *
     * @effects exchanged_option_does_not_earn_again
     *
     * @return void 반환값 없음
     */
    public function test_split_exchange_counts_parent_excess_as_already_earned(): void
    {
        $parent = $this->row(['option_status' => OrderStatusEnum::DELIVERED, 'subtotal_earned_points_amount' => 300]);
        $this->earned($parent, 600);
        $child = $this->row(['option_status' => OrderStatusEnum::EXCHANGED, 'parent_option_id' => $parent->id]);

        $this->service->earnForOrderOption($this->order, $this->successorOf($child), MileageTransactionTypeEnum::PURCHASE_EARN);

        $this->assertEquals(600, $this->totalEarned());
    }
}
