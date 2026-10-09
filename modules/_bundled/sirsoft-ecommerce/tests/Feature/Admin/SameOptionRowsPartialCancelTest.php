<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsManualPaidOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 같은 상품옵션이 상태가 다른 두 행으로 나뉜 주문의 부분취소 (S-Q26-c)
 *
 * 1521 실례: 30,000원 행 둘(교환 진행·반품 진행)이 다른 상품 부분취소 뒤 각 60,000원·적립 600P 로 덮어쓰였다.
 * 엔진이 결과를 상품옵션 키로 합치고, 키로 맞춰 쓰면 두 행이 모두 합계를 받기 때문이다. 상태 분할과 부분취소는
 * 실제 관리자 API 로 한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/partial-cancel-row-allocation.yaml`
 */
class SameOptionRowsPartialCancelTest extends ModuleTestCase
{
    use BuildsManualPaidOrders;

    protected User $admin;

    /**
     * 통화·사유·마일리지(적립률 10%)를 심고 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->enableManualPaidMileage();
        app(EcommerceSettingsService::class)->setSetting('mileage.default_earn_rate', 10);
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update']);
    }

    /**
     * 테스트가 심은 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 같은 상품옵션이 상태가 다른 두 행이어도 다른 상품 부분취소 뒤 각 행은 자기 몫을 갖고, 구매 적립도 그 몫만큼이다.
     *
     * @effects option_amounts_are_allocated_per_order_row_not_per_option_key, purchase_earn_follows_the_row_share
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_keeps_each_same_option_row_at_its_share_and_earn_follows_it(): void
    {
        $order = $this->paidOrderUsingMileage(points: 0, price: 30000, quantity: 2, extraLines: [['price' => 10000, 'quantity' => 2]]);
        $k = $order->options->firstWhere('quantity', 2);
        $z = $order->options->first(fn ($o) => $o->id !== $k->id);

        // K 1개만 상품준비중으로 — 같은 상품옵션 두 행(상태 다름)
        $this->actingAs($this->admin)->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/options/bulk-status", [
            'items' => [['option_id' => $k->id, 'quantity' => 1]], 'status' => 'preparing',
        ])->assertOk();

        $order = $order->fresh(['options', 'payment', 'shippings']);
        $kRows = $order->options->where('product_option_id', $k->product_option_id)->values();
        $this->assertCount(2, $kRows);

        $order = $this->cancelThroughAdmin($order, 'partial', [['order_option_id' => $z->id, 'cancel_quantity' => 1]]);

        $kRows = $order->options->where('product_option_id', $k->product_option_id)
            ->whereNotIn('option_status', [OrderStatusEnum::CANCELLED])->values();
        $this->assertCount(2, $kRows);
        foreach ($kRows as $row) {
            $this->assertEquals(30000, (float) $row->subtotal_paid_amount, "행 {$row->id} 가 키 합계를 받았다");
            $this->assertEquals(3000, (float) $row->subtotal_earned_points_amount);
        }

        // 한 행이 배송완료되어 적립되면 그 행 몫만 적립된다
        $r2 = $kRows->firstWhere('option_status', OrderStatusEnum::PREPARING);
        $r2->forceFill(['option_status' => OrderStatusEnum::DELIVERED])->save();
        app(UserMileageService::class)->earnForOrderOption($order->fresh(), $r2->fresh(), MileageTransactionTypeEnum::PURCHASE_EARN);

        $earned = (float) MileageTransaction::query()
            ->where('order_option_id', $r2->id)
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->sum('amount');
        $this->assertEquals(3000, $earned);
    }
}
