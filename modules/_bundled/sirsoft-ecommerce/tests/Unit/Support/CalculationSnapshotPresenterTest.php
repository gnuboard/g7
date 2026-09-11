<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\Services\OrderAdjustmentService;
use Modules\Sirsoft\Ecommerce\Support\CalculationSnapshotPresenter;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문금액 비교 스냅샷 표시 재료 단일 출처 테스트 (#599 후속)
 *
 * 접수 뒤 상세는 다통화 줄을 저장하지 않고 읽을 때 다시 만든다 — 그 값은 미리보기가 낸 값과
 * **같아야** 한다(같은 신청서의 미리보기와 상세가 다른 외화 줄을 보이면 안 된다). 정가 합계는
 * 옵션 치환(교환 후 열)을 반영하고, 치환이 없으면 종전 식과 같다.
 */
class CalculationSnapshotPresenterTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected CalculationSnapshotPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->presenter = app(CalculationSnapshotPresenter::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 결제 통화가 USD 인 2수량 주문을 만듭니다. (부분취소가 재계산 경로를 타게)
     */
    protected function foreignTwoItemOrder()
    {
        return $this->deliveredClaimOrder(
            quantity: 2,
            orderOverrides: ['currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'USD',
                'base_unit' => 1,
                'exchange_rates' => [
                    'KRW' => ['rate' => 1, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'floor'],
                    'USD' => ['rate' => 0.001, 'decimal_places' => 2, 'rounding_unit' => '0.01', 'rounding_method' => 'floor'],
                ],
            ]],
            paymentOverrides: ['currency' => 'USD', 'paid_amount_local' => 58, 'paid_amount_base' => 58000],
        );
    }

    /**
     * 상세가 다시 만든 다통화 스냅샷은 미리보기가 낸 것과 같다. (원 열은 동결 정가 합계를 넘긴다)
     *
     * @scenario user-claim-list-detail-edit
     *
     * @effects claim_detail_comparison_renders_from_frozen_snapshot
     */
    public function test_mc_snapshots_match_preview(): void
    {
        $order = $this->foreignTwoItemOrder();
        $option = $order->options->first();

        $result = app(OrderAdjustmentService::class)->preview($order, CancellationAdjustment::fromArray([
            ['order_option_id' => $option->id, 'cancel_quantity' => 1],
        ]));

        $this->assertNotNull($result->mcOriginalSnapshot);
        $this->assertNotNull($result->mcRecalculatedSnapshot);

        $this->assertSame(
            $result->mcOriginalSnapshot,
            $this->presenter->mcOriginal($order, (float) $result->originalSnapshot['total_list_price_amount'])
        );
        $this->assertSame(
            $result->mcRecalculatedSnapshot,
            $this->presenter->mcRecalculated($result->recalculatedSnapshot, $order->currency_snapshot)
        );
        $this->assertArrayHasKey('USD', $result->mcRecalculatedSnapshot['mc_total_paid_amount_formatted']);
    }

    /**
     * 통화 스냅샷이 없으면 다통화 줄은 null 이다. (화면은 그 줄을 세우지 않는다)
     *
     * @scenario claim-exchange-lifecycle
     *
     * @effects claim_detail_comparison_renders_from_frozen_snapshot
     */
    public function test_mc_recalculated_is_null_without_currency_snapshot(): void
    {
        $this->assertNull($this->presenter->mcRecalculated(['subtotal_amount' => 1000, 'total_paid_amount' => 1000], null));
    }

    /**
     * 정가 합계는 옵션 치환을 반영한다 — 치환 수량만큼 원 정가를 빼고 신규 옵션 정가를 더한다.
     *
     * 29,000 × 2 = 58,000 → 1개를 정가 35,000 옵션으로 치환하면 64,000. 1개 제외 + 1개 치환이면 35,000.
     *
     * @scenario claim-exchange-lifecycle
     *
     * @effects exchange_preview_two_pass_keeps_refund_math
     */
    public function test_list_price_total_applies_substitutions(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $option = $order->options->first();
        $substitutions = [$option->id => ['quantity' => 1, 'option_snapshot' => ['list_price' => 35000]]];

        $this->assertSame(58000.0, $this->presenter->listPriceTotal($order));
        $this->assertSame(64000.0, $this->presenter->listPriceTotal($order, null, $substitutions));
        $this->assertSame(35000.0, $this->presenter->listPriceTotal($order, [$option->id => 1], $substitutions));
    }
}
