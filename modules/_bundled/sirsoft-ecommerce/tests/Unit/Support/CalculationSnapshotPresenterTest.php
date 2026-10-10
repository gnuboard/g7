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
     * @scenario screen=detail
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
     * @scenario screen=detail
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

    /**
     * 동결값을 넘기면 보조 통화도 **그 시점**을 가리킨다. (S3-D15)
     *
     * 기준 통화 줄은 접수 시점에 동결된 `original_calculation_snapshot` 을 그린다. 그런데 보조
     * 통화가 주문의 현재 맵을 읽으면, 접수 뒤 그 주문에 취소·변동이 생겼을 때 **한 칸 안에서 두
     * 시점이 섞인다** — 기준줄 252,000원 / 보조줄 224,000원 처럼 갈리는데, 둘 다 정상 서식이라
     * 화면에도 응답에도 어긋난 흔적이 남지 않는다(2026-09-16 3-H 실측: 그 사이 28,000원짜리
     * 행이 취소돼 있었다).
     *
     * 되돌림 축: 인자를 무시하고 `$order->mc_subtotal_amount` 를 읽으면 주문의 현재 값이 나와 red.
     *
     * @scenario screen=detail
     *
     * @effects claim_detail_comparison_renders_from_frozen_snapshot
     */
    public function test_mc_original_follows_the_frozen_amounts_not_the_live_order(): void
    {
        $order = $this->foreignTwoItemOrder();

        // 접수 뒤 주문이 줄어든 상황을 만든다 — 동결값과 현재 값이 갈리게.
        $liveSubtotal = (float) $order->subtotal_amount;
        $frozenSubtotal = $liveSubtotal + 28000;
        $frozenPaid = (float) $order->total_paid_amount + 28000;

        $mc = $this->presenter->mcOriginal($order, null, $frozenSubtotal, $frozenPaid);

        $this->assertNotNull($mc);
        $this->assertEqualsWithDelta(
            $frozenSubtotal,
            (float) $mc['mc_subtotal_amount']['KRW'],
            0.001,
            '보조 통화가 주문의 현재 값을 읽으면 기준줄과 시점이 갈린다.'
        );
        $this->assertEqualsWithDelta($frozenPaid, (float) $mc['mc_total_paid_amount']['KRW'], 0.001);

        // 현재 주문값과 **다르다**는 것이 이 테스트의 핵심이다 — 같으면 아무것도 증명하지 못한다.
        $this->assertNotEqualsWithDelta($liveSubtotal, (float) $mc['mc_subtotal_amount']['KRW'], 0.001);

        // 서식 줄도 같은 근거 위에 선다.
        $this->assertArrayHasKey('USD', $mc['mc_subtotal_amount_formatted']);
    }

    /**
     * 동결값을 넘기지 않으면 종전대로 주문의 현재 값을 읽는다. (접수 시점 경로는 불변)
     *
     * 미리보기는 주문과 스냅샷이 같은 시점이라 넘길 동결값이 없다 — 그 경로를 깨지 않는다.
     *
     * @effects claim_detail_comparison_renders_from_frozen_snapshot
     */
    public function test_mc_original_falls_back_to_the_order_when_no_frozen_amounts_are_given(): void
    {
        $order = $this->foreignTwoItemOrder();

        $mc = $this->presenter->mcOriginal($order);

        $this->assertSame($order->mc_subtotal_amount, $mc['mc_subtotal_amount']);
        $this->assertSame($order->mc_total_paid_amount, $mc['mc_total_paid_amount']);
    }
}
