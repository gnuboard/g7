<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 주문상세가 클레임 선점 수량을 알려주는지 검증한다 (S1-D16)
 *
 * 관리자 [주문취소] 모달은 수량 입력칸의 상한을 `cancelItem.quantity` 로 묶는다
 * (`_modal_cancel_order.json:357`). 그 값은 **주문행의 원 수량**이라, 고객이 먼저
 * 접수해 선점한 수량이 빠져 있지 않다. 서버는 선점을 알고 422 로 거절하므로,
 * 운영자는 **고를 수 없는 수를 고르고 나서야** 진짜 한도를 알게 된다.
 *
 * 실측(#599 세션1 · 주문 20260915-1352103470): 고객이 양말 4개 중 2개를 접수한 뒤
 * 관리자 모달이 `max=4` · 기본값 `4`. 3 을 넣어 제출하자 **422**
 * `이미 진행 중인 신청 건이 있어 3개를 신청할 수 없습니다. (신청 가능 수량: 2개)`.
 *
 * 계약: 관리자 주문상세 응답의 주문옵션은 사용자 측(`claimable-options`)과 같은
 * 어휘로 `available_quantity`·`locked_quantity` 를 실어 화면이 상한을 그릴 근거를 준다.
 */
class AdminOrderClaimAvailabilityTest extends ModuleTestCase
{
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
    }

    /**
     * 진행 중인 환불신청이 선점한 수량이 관리자 응답에 드러난다.
     *
     * @scenario claim_state=pending_refund
     *
     * @effects admin_order_options_expose_claim_availability
     */
    public function test_admin_order_detail_exposes_available_quantity(): void
    {
        $order = OrderFactory::new()->paid()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 4,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        // 고객이 2개를 먼저 접수해 선점한 상태
        $refund = OrderRefund::query()->create([
            'order_id' => $order->id,
            'refund_number' => 'RF-S1D16-'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::BANK,
            // 수량을 소유하는 출처라야 선점 집계에 들어간다 (반품·교환 발 신청서는 제 서비스가 이미 옮겼다)
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'reason_code' => 'changed_mind',
            'refund_amount' => 8000,
            'refund_points_amount' => 0,
        ]);

        OrderRefundOption::query()->create([
            'order_refund_id' => $refund->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'option_status' => 'requested',
            'quantity' => 2,
            'unit_price' => 4000,
            'subtotal_amount' => 8000,
            'discount_amount' => 0,
            'refund_amount' => 8000,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders/'.$order->id)
            ->assertOk();

        $row = collect($response->json('data.options'))->firstWhere('id', $option->id);

        $this->assertNotNull($row, '주문옵션이 응답에 있어야 한다');
        $this->assertSame(4, (int) $row['quantity'], '원 수량은 그대로 남는다');
        $this->assertSame(
            2,
            (int) ($row['available_quantity'] ?? -1),
            '선점 2개를 뺀 가용 수량이 실려야 한다 (화면 수량 상한의 근거)'
        );
        $this->assertSame(
            2,
            (int) ($row['locked_quantity'] ?? -1),
            '선점 수량도 함께 알려줘야 운영자에게 사유를 설명할 수 있다'
        );
    }

    /**
     * 선점이 없으면 가용 수량은 원 수량과 같다 — 과잉 차단 방지.
     *
     * @scenario claim_state=none
     *
     * @effects admin_order_options_expose_claim_availability
     */
    public function test_available_quantity_equals_quantity_without_claims(): void
    {
        $order = OrderFactory::new()->paid()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'quantity' => 3,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $row = collect(
            $this->actingAs($this->adminUser)
                ->getJson('/api/modules/sirsoft-ecommerce/admin/orders/'.$order->id)
                ->assertOk()
                ->json('data.options')
        )->firstWhere('id', $option->id);

        $this->assertSame(3, (int) ($row['available_quantity'] ?? -1));
        $this->assertSame(0, (int) ($row['locked_quantity'] ?? -1));
    }
}
