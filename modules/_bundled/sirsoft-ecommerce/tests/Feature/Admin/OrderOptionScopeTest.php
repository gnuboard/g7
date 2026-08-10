<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\ResourceScopeMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 옵션 일괄 상태 변경의 상위 주문 스코프 검증 테스트
 *
 * PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order}/options/bulk-status
 *
 * 검증 목적:
 * - 주문 A 의 경로로 주문 B 의 옵션을 일괄 변경할 수 없다 (422)
 * - FormRequest 를 우회한 Service 직접 호출도 2차 방어로 차단된다
 * - 대상 주문의 옵션은 기존과 동일하게 변경된다
 */
class OrderOptionScopeTest extends ModuleTestCase
{
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
    }

    /**
     * 다른 주문의 옵션 ID 를 넣으면 422 이고 그 옵션은 변경되지 않는다
     *
     * @scenario resource=order_option, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_array_item_returns_422, target_resource_unchanged_on_rejection
     */
    public function test_cannot_change_option_of_other_order(): void
    {
        $orderA = $this->makeOrder();
        $orderB = $this->makeOrder();

        $optionOfB = OrderOptionFactory::new()->forOrder($orderB)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => 2,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->url($orderA), [
                'items' => [
                    ['option_id' => $optionOfB->id, 'quantity' => 1],
                ],
                'status' => OrderStatusEnum::PREPARING->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.option_id']);

        $this->assertSame(
            OrderStatusEnum::PAYMENT_COMPLETE->value,
            $optionOfB->fresh()->option_status->value,
            '다른 주문의 옵션 상태가 변경되면 안 됩니다.'
        );
    }

    /**
     * FormRequest 를 우회한 Service 직접 호출도 2차 방어로 차단된다
     *
     * 컨트롤러 경로는 FormRequest 가 422 로 선차단하므로, 훅·내부 호출처럼 검증을 거치지
     * 않는 경로에서도 스코프가 지켜지는지 Service 를 직접 호출해 확인한다.
     *
     * @scenario resource=order_option, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_write_rejected, target_resource_unchanged_on_rejection
     */
    public function test_service_rejects_option_of_other_order_when_called_directly(): void
    {
        $orderA = $this->makeOrder();
        $orderB = $this->makeOrder();

        $optionOfB = OrderOptionFactory::new()->forOrder($orderB)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => 2,
        ]);

        $this->expectException(ResourceScopeMismatchException::class);

        try {
            app(OrderOptionService::class)->bulkChangeStatusWithQuantity(
                [['option_id' => $optionOfB->id, 'quantity' => 1]],
                OrderStatusEnum::PREPARING,
                $orderA->id,
            );
        } finally {
            $this->assertSame(
                OrderStatusEnum::PAYMENT_COMPLETE->value,
                $optionOfB->fresh()->option_status->value,
                'Service 직접 호출에서도 다른 주문의 옵션이 변경되면 안 됩니다.'
            );
        }
    }

    /**
     * 자기 주문의 옵션은 정상 변경된다 (회귀 방지)
     *
     * @scenario resource=order_option, parent_scope=matching, actor=admin
     *
     * @effects matching_scope_still_succeeds
     */
    public function test_can_change_option_of_own_order(): void
    {
        $order = $this->makeOrder();

        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'quantity' => 2,
        ]);

        $this->actingAs($this->adminUser)
            ->patchJson($this->url($order), [
                'items' => [
                    ['option_id' => $option->id, 'quantity' => 2],
                ],
                'status' => OrderStatusEnum::PREPARING->value,
            ])
            ->assertStatus(200);

        $this->assertSame(
            OrderStatusEnum::PREPARING->value,
            $option->fresh()->option_status->value,
            '자기 주문의 옵션은 변경되어야 합니다.'
        );
    }

    /**
     * 테스트용 주문을 생성합니다.
     *
     * @return Order 생성된 주문
     */
    private function makeOrder(): Order
    {
        return OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ])->fresh();
    }

    /**
     * 일괄 상태 변경 엔드포인트 URL 을 생성합니다.
     *
     * @param  Order  $order  대상 주문
     * @return string 엔드포인트 URL
     */
    private function url(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/options/bulk-status";
    }
}
