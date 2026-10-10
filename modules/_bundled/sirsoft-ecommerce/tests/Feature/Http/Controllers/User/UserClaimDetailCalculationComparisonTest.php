<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 상세의 주문금액 비교 블록(`calculation_comparison`) Feature 테스트 (#599 후속)
 *
 * 검증 축:
 * - 네 유형(반품·교환·환불신청·취소)의 회원 상세가 접수 시점 전/후 스냅샷 + 다통화 줄을 싣는다
 * - 교환은 치환 근거 행(`substituted_items`)이 실리고 내부 키(`_substituted_items`)는 노출되지 않는다
 * - 스냅샷이 없는 구형 신청서는 `null`
 * - 목록에는 실리지 않는다
 * - 비회원 상세(토큰)와 관리자 봉투(`claims.read`)도 같은 키를 싣는다
 */
class UserClaimDetailCalculationComparisonTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const GUEST_PASSWORD = 'guest12';

    private const GUEST_PHONE = '010-1234-5678';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->user = $this->createUser();

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/user';
    }

    protected function myDeliveredOrder(int $quantity = 2): Order
    {
        $order = $this->deliveredClaimOrder(quantity: $quantity);
        $order->forceFill(['user_id' => $this->user->id])->save();

        return $order->fresh(['options', 'shippingAddress']);
    }

    protected function myPaidOrder(): Order
    {
        $order = $this->paidClaimOrder(quantity: 2);
        $order->forceFill(['user_id' => $this->user->id])->save();

        return $order->fresh(['options']);
    }

    /**
     * 반품 1건(2개 중 1개 · 환불금 차감)을 서비스 경로로 접수합니다.
     */
    protected function myReturn(?Order $order = null): OrderReturn
    {
        $order ??= $this->myDeliveredOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $this->user->id,
        ]);
    }

    /**
     * @scenario claim_type=return, screen=detail, entry=none
     *
     * @effects claim_detail_comparison_renders_from_frozen_snapshot
     */
    public function test_return_detail_carries_the_frozen_comparison_with_multi_currency_rows(): void
    {
        $return = $this->myReturn();

        $response = $this->actingAs($this->user)->getJson($this->base()."/claims/{$return->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.calculation_comparison.original.subtotal_amount', 58000)
            ->assertJsonPath('data.claim.calculation_comparison.original.item_count', 2)
            ->assertJsonPath('data.claim.calculation_comparison.recalculated.subtotal_amount', 29000)
            ->assertJsonPath('data.claim.calculation_comparison.recalculated.item_count', 1)
            ->assertJsonPath('data.claim.calculation_comparison.recalculated.base_currency', 'KRW')
            ->assertJsonPath('data.claim.calculation_comparison.substituted_items', []);

        $comparison = $response->json('data.claim.calculation_comparison');
        $this->assertArrayHasKey('subtotal_amount', $comparison['recalculated']['formatted']);
        // 다통화 줄은 읽을 때 주문의 동결 환율로 다시 만든다 — 저장하지 않지만 상세에는 실린다.
        // 원 열의 정가 합계는 동결값(58,000)을 환산한 것이고, 후 열은 저장된 재계산 스냅샷을 환산한 것이다.
        $this->assertSame(['KRW' => 58000.0], array_map('floatval', $comparison['mc_original']['mc_total_list_price_amount']));
        $this->assertSame(['KRW' => 29000.0], array_map('floatval', $comparison['mc_recalculated']['mc_subtotal_amount']));
        $this->assertStringContainsString('29,000', $comparison['mc_recalculated']['mc_total_paid_amount_formatted']['KRW']);
    }

    /**
     * @scenario exchange_mode=change, preview_result=ok
     *
     * @effects claim_apply_exchange_substituted_rows_render
     */
    public function test_exchange_detail_carries_substituted_items_and_hides_the_internal_key(): void
    {
        $order = $this->myDeliveredOrder(1);
        $product = $order->options->first()->product;
        $newOption = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 3000, 'stock_quantity' => 100, 'is_default' => false, 'is_active' => true,
        ]);

        $exchange = app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1, 'new_product_option_id' => $newOption->id]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base()."/claims/{$exchange->exchange_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.calculation_comparison.original.subtotal_amount', 29000)
            ->assertJsonPath('data.claim.calculation_comparison.recalculated.subtotal_amount', 32000)
            ->assertJsonPath('data.claim.calculation_comparison.substituted_items.0.product_option_id', $newOption->id)
            ->assertJsonPath('data.claim.calculation_comparison.substituted_items.0.new_unit_price', 32000);

        $this->assertArrayNotHasKey('_substituted_items', $response->json('data.claim.calculation_comparison.recalculated'));
    }

    /**
     * @scenario claim_type=refund, screen=detail, entry=none
     *
     * @effects claim_detail_comparison_renders_from_frozen_snapshot
     */
    public function test_refund_request_and_cancel_details_carry_the_comparison(): void
    {
        // 환불신청서 — 배송준비 상품
        $preparing = $this->myPaidOrder();
        $preparing->options->each(fn ($option) => $option->forceFill(['option_status' => OrderStatusEnum::PREPARING])->save());
        $preparing->forceFill(['order_status' => OrderStatusEnum::PREPARING])->save();

        $refundNumber = $this->actingAs($this->user)->postJson($this->base()."/orders/{$preparing->id}/refund-requests", [
            'items' => [['order_option_id' => $preparing->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ])->assertCreated()->assertJsonPath('data.branch', 'request')->json('data.claim.claim_number');

        $this->actingAs($this->user)->getJson($this->base()."/claims/{$refundNumber}")
            ->assertOk()
            ->assertJsonPath('data.claim.claim_type', 'refund')
            ->assertJsonPath('data.claim.calculation_comparison.original.item_count', 2)
            ->assertJsonPath('data.claim.calculation_comparison.recalculated.item_count', 1);

        // 자동 전이 분기 — 결제완료 상품도 **환불신청서**가 접수되고(D2) 그 신청서가 비교표를
        // 싣는다. 취소신청서는 환불완료 순간 함께 생기며, 그 상세도 같은 표를 보여 준다
        // (취소는 자기 컬럼이 없어 연결된 환불 행에서 읽는다).
        $paid = $this->myPaidOrder();

        $refundNumberOfAuto = $this->actingAs($this->user)->postJson($this->base()."/orders/{$paid->id}/refund-requests", [
            'items' => [['order_option_id' => $paid->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ])->assertCreated()
            ->assertJsonPath('data.branch', 'auto')
            ->assertJsonPath('data.claim.claim_type', 'refund')
            ->json('data.claim.claim_number');

        $this->actingAs($this->user)->getJson($this->base()."/claims/{$refundNumberOfAuto}")
            ->assertOk()
            ->assertJsonPath('data.claim.claim_type', 'refund')
            ->assertJsonPath('data.claim.calculation_comparison.original.item_count', 2)
            ->assertJsonPath('data.claim.calculation_comparison.recalculated.item_count', 1);

        $cancelNumber = OrderCancel::where('order_id', $paid->id)
            ->value('cancel_number');
        $this->assertNotNull($cancelNumber, '환불완료 시 취소신청서가 함께 생겨야 한다 (D3)');

        $this->actingAs($this->user)->getJson($this->base()."/claims/{$cancelNumber}")
            ->assertOk()
            ->assertJsonPath('data.claim.claim_type', 'cancel');
    }

    /**
     * @scenario claim_type=return, screen=detail, entry=none
     *
     * @effects claim_detail_comparison_absent_notice_for_legacy_claims
     */
    public function test_legacy_claim_without_snapshots_returns_null_and_the_list_omits_the_block(): void
    {
        $return = $this->myReturn();
        $return->forceFill(['original_calculation_snapshot' => null, 'recalculated_snapshot' => null])->save();

        $this->actingAs($this->user)->getJson($this->base()."/claims/{$return->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.calculation_comparison', null);

        $list = $this->actingAs($this->user)->getJson($this->base().'/claims?type=return')->assertOk();
        $first = $list->json('data.data.0');

        $this->assertIsArray($first);
        $this->assertSame($return->return_number, $first['claim_number']);
        $this->assertArrayNotHasKey('calculation_comparison', $first);
    }

    /**
     * @scenario claim_type=return, screen=detail, entry=none
     *
     * @effects claim_detail_comparison_renders_from_frozen_snapshot
     */
    public function test_guest_detail_carries_the_same_block(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $order->forceFill(['user_id' => null, 'guest_lookup_password_hash' => Hash::make(self::GUEST_PASSWORD)])->save();
        OrderAddress::where('order_id', $order->id)->update(['orderer_phone' => self::GUEST_PHONE]);
        $order = $order->fresh(['options', 'shippingAddress']);

        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => null,
        ]);

        $token = app(GuestOrderAuthService::class)->authenticate($order->order_number, self::GUEST_PHONE, self::GUEST_PASSWORD, '10.0.0.9')['token'];

        $this->getJson("/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/claims/{$return->return_number}", ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.claim.calculation_comparison.original.subtotal_amount', 58000)
            ->assertJsonPath('data.claim.calculation_comparison.recalculated.subtotal_amount', 29000);
    }

    /**
     * @scenario claim_type=return, cancellable=absent
     *
     * @effects admin_claim_detail_comparison_in_settlement_card
     */
    public function test_admin_envelope_carries_the_same_block(): void
    {
        $return = $this->myReturn();
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($admin)->getJson("/api/modules/sirsoft-ecommerce/admin/claims/{$return->return_number}")
            ->assertOk()
            ->assertJsonPath('data.claim.calculation_comparison.original.subtotal_amount', 58000)
            ->assertJsonPath('data.claim.calculation_comparison.recalculated.subtotal_amount', 29000)
            ->assertJsonPath('data.claim.calculation_comparison.substituted_items', []);
    }
}
