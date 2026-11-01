<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Order;

use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\AdjustmentResult;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 영세 품목의 클레임 재계산과 결제 행 취소 부가세 (#612)
 *
 * 부분취소 재계산은 주문 시점 스냅샷 모드로 돈다. 주문상품 행에 확정해 둔 과세 종류(`tax_status`)를
 * 따라야 남은 상품의 과세·영세·면세 갈래가 주문 생성 때와 같은 규칙으로 다시 나뉜다.
 * 결제 행의 취소 부가세(`cancelled_vat_amount`)는 종전에 쓰는 곳이 없어 부분환불 뒤에도 0 이었다(④).
 */
class ZeroRatedClaimLedgerTest extends ModuleTestCase
{
    /**
     * 과세(따름)·면세·영세 옵션 3개를 한 상품에 만들어 회원이 각 1개씩 주문합니다.
     *
     * @return array{0: Order, 1: array<string, ProductOption>} 주문과 옵션 (inherit/free/zero)
     */
    private function placeMixedOrder(): array
    {
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => 20000,
            'selling_price' => 0,
            'tax_status' => ProductTaxStatus::TAXABLE,
            'tax_rate' => 10.0,
        ]);

        $options = [];
        foreach (['inherit' => [11000, null], 'free' => [10000, ProductTaxStatus::TAX_FREE], 'zero' => [10000, ProductTaxStatus::ZERO_RATED]] as $key => [$price, $status]) {
            $options[$key] = ProductOptionFactory::new()->forProduct($product)->create([
                'selling_price' => $price,
                'price_adjustment' => $price,
                'stock_quantity' => 100,
                'tax_status' => $status,
            ]);
        }

        $user = $this->createUser();
        $cartIds = array_map(
            fn (ProductOption $option) => CartFactory::new()->forUser($user)->forOption($option)->create(['quantity' => 1])->id,
            array_values($options),
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => $cartIds])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/user/orders', [
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'zero@test.com'],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 31000,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ])->assertStatus(201);

        return [Order::where('user_id', $user->id)->latest('id')->first(), $options];
    }

    /**
     * 혼합 주문의 품목별 과세 종류와 장부 4값이 주문 생성 시점에 기록되는지 확인합니다.
     *
     * @effects mixed_zero_rated_order_splits_ledger
     *
     * @return void 반환값 없음
     */
    public function test_mixed_order_records_row_tax_status_and_ledger(): void
    {
        [$order, $options] = $this->placeMixedOrder();

        $byOption = $order->options->keyBy('product_option_id');
        $this->assertSame(ProductTaxStatus::TAXABLE, $byOption[$options['inherit']->id]->tax_status);
        $this->assertSame(ProductTaxStatus::TAX_FREE, $byOption[$options['free']->id]->tax_status);
        $this->assertSame(ProductTaxStatus::ZERO_RATED, $byOption[$options['zero']->id]->tax_status);

        $this->assertSame(21000, (int) $order->total_tax_amount);
        $this->assertSame(10000, (int) $order->total_zero_rated_amount);
        $this->assertSame(1000, (int) $order->total_vat_amount);
        $this->assertSame(10000, (int) $order->total_tax_free_amount);
        $this->assertSame(1000, (int) $order->payment->vat_amount);
    }

    /**
     * 영세 품목을 부분취소하면 재계산이 영세 금액을 0 으로 내리고 나머지 갈래는 그대로 두는지 확인합니다.
     *
     * @effects zero_rated_partial_cancel_recalculates_ledger
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_of_zero_rated_item_recalculates_ledger(): void
    {
        [$order, $options] = $this->placeMixedOrder();
        $zeroRow = $order->options->firstWhere('product_option_id', $options['zero']->id);

        $preview = app(OrderCancellationService::class)->previewRefund($order->fresh(), [
            ['order_option_id' => $zeroRow->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(10000.0, (float) $preview->originalSnapshot['total_zero_rated_amount']);
        $this->assertSame(0.0, (float) $preview->orderUpdates['total_zero_rated_amount']);
        $this->assertSame(11000.0, (float) $preview->orderUpdates['total_tax_amount']);
        $this->assertSame(1000.0, (float) $preview->orderUpdates['total_vat_amount']);
        $this->assertSame(10000.0, (float) $preview->orderUpdates['total_tax_free_amount']);
    }

    /**
     * 영세 품목이 든 주문을 전액취소하면 영세 금액(base·mc)도 과세·면세·부가세와 함께 0 으로 내리는지 확인합니다.
     *
     * 전액취소는 재계산을 거치지 않고 장부 컬럼을 하나씩 0 으로 적는다 — 영세 컬럼을 빼먹으면
     * 취소된 주문에 영세 매출 10,000 이 남아 영세율 신고·결제사 면세 몫에 그대로 잡힌다.
     *
     * @effects zero_rated_full_cancel_zeroes_ledger
     *
     * @return void 반환값 없음
     */
    public function test_full_cancel_zeroes_zero_rated_ledger(): void
    {
        [$order] = $this->placeMixedOrder();
        if (empty($order->currency_snapshot)) {
            $order->forceFill(['currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'base_unit' => 1,
                'exchange_rates' => ['KRW' => ['rate' => 1, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor']],
            ]])->save();
        }
        $order = $order->fresh(['options']);

        $preview = app(OrderCancellationService::class)->previewRefund($order, $order->options->map(fn (OrderOption $row) => [
            'order_option_id' => $row->id,
            'cancel_quantity' => $row->quantity,
        ])->all());

        // 전제: 취소 전 영세 10,000 이 있었고 전액취소 경로를 탔다
        $this->assertSame(10000.0, (float) $preview->originalSnapshot['total_zero_rated_amount']);
        $this->assertSame(0.0, (float) $preview->orderUpdates['total_amount']);

        foreach (['total_zero_rated_amount', 'total_tax_amount', 'total_vat_amount', 'total_tax_free_amount'] as $column) {
            $this->assertArrayHasKey($column, $preview->orderUpdates, $column);
            $this->assertSame(0.0, (float) $preview->orderUpdates[$column], $column);
        }
        $this->assertSame(0.0, (float) $preview->recalculatedSnapshot['total_zero_rated_amount']);

        // base 와 짝인 보조통화 컬럼도 같은 행 안에서 0
        $this->assertArrayHasKey('mc_total_zero_rated_amount', $preview->orderUpdates);
        $this->assertSame(0, (int) ($preview->orderUpdates['mc_total_zero_rated_amount']['KRW'] ?? -1));
    }

    /**
     * 옵션 스냅샷에 과세 종류가 없어도 재계산은 주문상품 행에 확정한 과세 종류(영세)를 따르는지 확인합니다.
     *
     * 스냅샷만 보면 상품 기본값(과세)으로 떨어져, 남은 영세 품목이 과세로 다시 나뉘고 부가세가 생긴다.
     *
     * @effects zero_rated_partial_cancel_recalculates_ledger
     *
     * @return void 반환값 없음
     */
    public function test_recalculation_uses_row_tax_status_when_option_snapshot_lacks_it(): void
    {
        [$order, $options] = $this->placeMixedOrder();
        $zeroRow = $order->options->firstWhere('product_option_id', $options['zero']->id);
        $freeRow = $order->options->firstWhere('product_option_id', $options['free']->id);

        $snapshot = $zeroRow->option_snapshot;
        unset($snapshot['tax_status']);
        $zeroRow->forceFill(['option_snapshot' => $snapshot])->save();

        $preview = app(OrderCancellationService::class)->previewRefund($order->fresh(), [
            ['order_option_id' => $freeRow->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(10000.0, (float) $preview->orderUpdates['total_zero_rated_amount']);
        $this->assertSame(1000.0, (float) $preview->orderUpdates['total_vat_amount']);
    }

    /**
     * 교환 「교환 후」 재계산 입력은 신규 옵션이 아니라 원 행의 과세 종류를 쓰는지 확인합니다.
     *
     * 실제 후속 행은 원 행의 과세 종류를 잇는다 — 미리보기만 신규 옵션 값을 쓰면 영세 → 과세 교환이
     * 화면에는 부가세가 붙은 것으로 보이고 실제 장부는 영세로 남는다.
     *
     * @effects zero_rated_partial_cancel_recalculates_ledger
     *
     * @return void 반환값 없음
     */
    public function test_exchange_preview_substitution_keeps_original_row_tax_status(): void
    {
        $original = new OrderOption(['option_snapshot' => ['tax_status' => 'zero_rated']]);
        $original->id = 9001;
        $original->tax_status = ProductTaxStatus::ZERO_RATED;
        $target = new ProductOption(['tax_status' => ProductTaxStatus::TAXABLE, 'option_values' => []]);
        $target->id = 9002;

        $service = app(OrderExchangeService::class);
        $build = new \ReflectionMethod($service, 'buildSubstitutionsFromPlans');
        $substitutions = $build->invoke($service, [[
            'option' => $original,
            'target' => $target,
            'is_option_change' => true,
            'quantity' => 1,
            'new_unit_price' => 10000,
            'custom_options' => null,
        ]]);

        $this->assertSame('zero_rated', $substitutions[9001]['option_snapshot']['tax_status']);
    }

    /**
     * 과세 품목을 부분취소하면 줄어든 부가세만큼 결제 행 취소 부가세가 누적되는지 확인합니다(④).
     *
     * @effects payment_cancelled_vat_accumulates_on_partial_refund
     *
     * @return void 반환값 없음
     */
    public function test_cancelled_vat_accumulates_by_the_vat_decrease(): void
    {
        [$order, $options] = $this->placeMixedOrder();
        $taxableRow = $order->options->firstWhere('product_option_id', $options['inherit']->id);

        $preview = app(OrderCancellationService::class)->previewRefund($order->fresh(), [
            ['order_option_id' => $taxableRow->id, 'cancel_quantity' => 1],
        ]);

        $this->assertSame(0.0, (float) $preview->orderUpdates['total_vat_amount']);

        $updates = app(RefundExecutionService::class)->buildCancelledAmountUpdates($order->payment, $preview);

        $this->assertSame(1000.0, (float) $updates['cancelled_vat_amount']);
    }

    /**
     * 환불신청서 [환불하기] 경로에서도 줄어든 부가세만큼 결제 행 취소 부가세가 쌓이는지 확인합니다(④).
     *
     * 이 경로의 계산 결과에는 재계산 전 스냅샷이 실리지 않는다 — 재계산 전 부가세는 아직 갱신 전인
     * 주문 헤더 값이다(2026-10-26 실측: 과세 품목 환불 뒤 부가세 1,143 → 0 인데 취소 부가세 0).
     *
     * @effects payment_cancelled_vat_accumulates_on_partial_refund
     *
     * @return void 반환값 없음
     */
    public function test_cancelled_vat_accumulates_when_a_refund_request_is_executed(): void
    {
        [$order, $options] = $this->placeMixedOrder();
        $order->payment->forceFill(['payment_status' => PaymentStatusEnum::PAID, 'paid_amount_local' => 31000, 'paid_amount_base' => 31000, 'paid_at' => now()])->save();
        $order->forceFill(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE, 'total_paid_amount' => 31000])->save();
        foreach ($order->options as $option) {
            $option->forceFill(['option_status' => OrderStatusEnum::PAYMENT_COMPLETE])->save();
        }
        $taxableRow = $order->options->firstWhere('product_option_id', $options['inherit']->id);

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-TEST-'.uniqid(),
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::BANK,
            'refund_amount' => 11000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'refund_bank_code' => 'KB',
            'refund_bank_account' => '1234567890',
            'refund_bank_holder' => '홍길동',
        ]);
        OrderRefundOption::create([
            'order_refund_id' => $refund->id,
            'order_id' => $order->id,
            'order_option_id' => $taxableRow->id,
            'option_status' => RefundStatusEnum::APPROVED,
            'quantity' => 1,
            'unit_price' => 11000,
            'subtotal_amount' => 11000,
            'discount_amount' => 0,
            'refund_amount' => 11000,
        ]);

        app(OrderRefundRequestService::class)->executeRefund($refund->fresh(['refundOptions']));

        $fresh = $order->fresh(['payment']);
        $this->assertSame(0.0, (float) $fresh->total_vat_amount);
        $this->assertSame(1000.0, (float) $fresh->payment->vat_amount);
        $this->assertSame(1000.0, (float) $fresh->payment->cancelled_vat_amount);
    }

    /**
     * 취소 부가세 누적은 승인 부가세를 넘지 않고, 재계산 근거가 없으면 건드리지 않는지 확인합니다.
     *
     * @effects payment_cancelled_vat_accumulates_on_partial_refund
     *
     * @return void 반환값 없음
     */
    public function test_cancelled_vat_is_capped_and_skipped_without_snapshots(): void
    {
        $payment = new OrderPayment(['vat_amount' => 1000, 'cancelled_vat_amount' => 800, 'cancelled_amount' => 0]);
        $service = app(RefundExecutionService::class);

        $capped = $service->buildCancelledAmountUpdates($payment, new AdjustmentResult(
            refundAmount: 5000,
            orderUpdates: ['total_vat_amount' => 0],
            originalSnapshot: ['total_vat_amount' => 1000],
        ));
        $this->assertSame(1000.0, (float) $capped['cancelled_vat_amount']);

        $skipped = $service->buildCancelledAmountUpdates($payment, new AdjustmentResult(refundAmount: 5000));
        $this->assertArrayNotHasKey('cancelled_vat_amount', $skipped);
    }
}
