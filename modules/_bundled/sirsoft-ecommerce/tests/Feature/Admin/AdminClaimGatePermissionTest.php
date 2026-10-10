<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 직권 접수의 부가 동작 권한 게이트 Feature 테스트 (7.1.0 abilities 게이트 R1·S3·G03)
 *
 * 검증 축:
 * - 「환불까지 한 번에 처리」(`auto_advance_refund`)는 환불 실행 권한(`claims.execute-refund`)이 있어야 한다.
 *   직권 접수 경로(`cancel-refund`)와 호환용 구 경로(`orders/{order}/cancel`) 모두 같다.
 * - 관리자 주문 응답은 그 체크박스를 잠글 키 `abilities.can_execute_refund` 를 싣는다.
 * - 「접수와 함께 승인」(`approve`, 기본 켜짐)은 클레임 상태 변경 권한(`claims.update-status`)이 있어야 한다.
 * - 취소 창의 환불 예상금액 조회는 접수와 같은 `claims.create` 축이다.
 */
class AdminClaimGatePermissionTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정과 사유 카탈로그를 세웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 관리자 API 의 공통 접두 경로를 돌려줍니다.
     *
     * @return string 관리자 라우트 접두 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 직권 접수만 할 수 있고 환불 실행·상태 변경은 못 하는 운영자를 만듭니다.
     *
     * @param  array<int, string>  $extra  추가로 줄 권한
     * @return User 운영자
     */
    protected function creatorOnly(array $extra = []): User
    {
        return $this->createAdminUser(array_merge([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.create',
        ], $extra));
    }

    /**
     * 배송완료 10일이 지난 주문을 만듭니다. (교환·반품 직권 접수 대상)
     *
     * @return Order 배송완료 주문
     */
    protected function deliveredOrder(): Order
    {
        app(EcommerceSettingsService::class)->setSetting('claim.request_period_days', 7);

        $order = $this->deliveredClaimOrder(quantity: 2);

        foreach ($order->options as $option) {
            $option->forceFill(['delivered_at' => now()->subDays(10)])->save();
        }

        return $order->fresh(['options', 'shippings', 'payment', 'shippingAddress']);
    }

    /**
     * 환불 실행 권한이 없으면 「환불까지 한 번에 처리」 를 켠 취소·환불 접수가 403 이고 신청서도 생기지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects admin_auto_refund_requires_execute_refund
     */
    public function test_cancel_refund_auto_advance_requires_execute_refund(): void
    {
        $order = $this->paidClaimOrder(quantity: 2);

        $this->actingAs($this->creatorOnly())->postJson($this->base()."/orders/{$order->id}/cancel-refund", [
            'type' => 'full',
            'reason' => 'changed_mind',
            'auto_advance_refund' => true,
        ])->assertForbidden();

        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
    }

    /**
     * 같은 운영자라도 체크를 끄면 신청서 접수까지는 됩니다. (접수 권한만으로 가능한 범위)
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects admin_cancel_refund_without_auto_advance_is_accepted
     */
    public function test_cancel_refund_without_auto_advance_is_accepted_for_creator(): void
    {
        $order = $this->paidClaimOrder(quantity: 2);

        $this->actingAs($this->creatorOnly())->postJson($this->base()."/orders/{$order->id}/cancel-refund", [
            'type' => 'full',
            'reason' => 'changed_mind',
            'auto_advance_refund' => false,
        ])->assertCreated();

        $this->assertSame(1, OrderRefund::query()->where('order_id', $order->id)->count());
    }

    /**
     * 호환용 구 경로(`orders/{order}/cancel`)도 자동 환불은 환불 실행 권한을 요구합니다. (R1-b)
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects admin_legacy_cancel_auto_refund_requires_execute_refund
     */
    public function test_legacy_cancel_auto_advance_requires_execute_refund(): void
    {
        $order = $this->paidClaimOrder(quantity: 2);
        $operator = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update']);

        $this->actingAs($operator)->postJson($this->base()."/orders/{$order->id}/cancel", [
            'type' => 'full',
            'reason' => 'changed_mind',
            'auto_advance_refund' => true,
        ])->assertForbidden();

        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
    }

    /**
     * 관리자 주문 상세 응답은 환불 실행 권한 키를 싣습니다. 기존 `can_update` 도 그대로입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects admin_order_exposes_can_execute_refund
     */
    public function test_admin_order_detail_exposes_can_execute_refund(): void
    {
        $order = $this->paidClaimOrder();

        $this->actingAs($this->creatorOnly())->getJson($this->base()."/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.abilities.can_execute_refund', false)
            ->assertJsonPath('data.abilities.can_update', false);

        $refunder = $this->creatorOnly(['sirsoft-ecommerce.claims.execute-refund', 'sirsoft-ecommerce.orders.update']);

        $this->actingAs($refunder)->getJson($this->base()."/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.abilities.can_execute_refund', true)
            ->assertJsonPath('data.abilities.can_update', true);
    }

    /**
     * 상태 변경 권한이 없으면 「접수와 함께 승인」(기본 켜짐) 반품 접수가 403 이고 신청서도 생기지 않습니다. (S3)
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects admin_approve_on_receipt_requires_update_status
     */
    public function test_return_with_approval_requires_update_status(): void
    {
        $order = $this->deliveredOrder();

        $this->actingAs($this->creatorOnly())->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'free',
        ])->assertForbidden();

        $this->assertSame(0, OrderReturn::query()->where('order_id', $order->id)->count());
    }

    /**
     * 승인을 끄면 상태 변경 권한 없이도 신청 상태로 접수됩니다. (S3)
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects admin_request_without_approval_is_filed
     */
    public function test_return_without_approval_is_filed_as_requested(): void
    {
        $order = $this->deliveredOrder();

        $this->actingAs($this->creatorOnly())->postJson($this->base()."/orders/{$order->id}/returns", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'free',
            'approve' => false,
        ])->assertCreated();

        $return = OrderReturn::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->return_status);
    }

    /**
     * 교환 접수도 「접수와 함께 승인」 이면 상태 변경 권한을 요구합니다. (S3)
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects admin_approve_on_receipt_requires_update_status
     */
    public function test_exchange_with_approval_requires_update_status(): void
    {
        $order = $this->deliveredOrder();

        $this->actingAs($this->creatorOnly())->postJson($this->base()."/orders/{$order->id}/exchanges", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => 'free',
            'approve' => true,
        ])->assertForbidden();

        $this->assertSame(0, OrderExchange::query()->where('order_id', $order->id)->count());

        $this->actingAs($this->creatorOnly(['sirsoft-ecommerce.claims.update-status']))
            ->postJson($this->base()."/orders/{$order->id}/exchanges", [
                'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
                'reason_code' => 'size_mismatch',
                'charge_method' => 'free',
                'approve' => true,
            ])->assertCreated();

        $exchange = OrderExchange::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertSame(ExchangeStatusEnum::APPROVED, $exchange->exchange_status);
    }

    /**
     * 취소 창의 환불 예상금액 조회는 접수 권한(`claims.create`)으로 열리고, 주문 수정 권한만으로는 열리지 않습니다. (G03)
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects admin_estimate_refund_follows_claims_create
     */
    public function test_estimate_refund_follows_the_claims_create_axis(): void
    {
        $order = $this->paidClaimOrder(quantity: 2);
        $body = ['items' => [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]]];

        $this->actingAs($this->creatorOnly())
            ->postJson($this->base()."/orders/{$order->order_number}/estimate-refund", $body)
            ->assertOk();

        $updater = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update']);

        $this->actingAs($updater)
            ->postJson($this->base()."/orders/{$order->order_number}/estimate-refund", $body)
            ->assertForbidden();
    }
}
