<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 완료 후 환불 미접수 발견성 테스트 (#599 후속 §3-12 · D9)
 *
 * 반품·저가 교환을 완료하면서 환불신청서를 접수하지 않으면 운영자가 그 사실을 찾을 수 있어야 한다.
 * 판정의 단일 출처는 `AdminClaimService::pendingRefundFor()` 이고, 목록은 금액 계산 없이 같은 술어의
 * 쿼리 스코프(`pending_refund`)를 쓴다.
 *
 * 검증 축:
 * - 완료 반품·환불 미접수 → 상세 `pending_refund` 금액 = 정산 블록의 환불액, 목록 `has_pending_refund`·필터 `pending_refund=1`
 * - 환불신청서를 접수하면 세 곳 모두 사라진다
 * - 저가 교환(차액 환불)도 같은 규칙 · 차액 없는 교환·미완료 신청서는 표시하지 않는다
 */
class ClaimPendingRefundTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 클레임 조회·상태 변경 권한 운영자를 세우고 PG 환불 훅을 성공으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
        ]);
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
     * 반품을 회수도착까지 옮깁니다. (환불금 차감)
     *
     * @return OrderReturn 회수도착 반품
     */
    protected function collectedReturn(): OrderReturn
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderReturnService::class);
        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);

        return $service->transition($return, ReturnStatusEnum::COLLECTED)->fresh(['returnOptions']);
    }

    /**
     * 신청서를 환불신청서 없이 완료합니다. (관리자 완료 API)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return void 반환값 없음
     */
    protected function completeWithoutRefund(OrderExchange|OrderReturn $claim): void
    {
        $segment = $claim instanceof OrderExchange ? 'exchanges' : 'returns';
        $items = $claim instanceof OrderExchange
            ? $claim->fresh('exchangeOptions')->exchangeOptions
            : $claim->fresh('returnOptions')->returnOptions;

        $this->actingAs($this->admin)->patchJson($this->base()."/{$segment}/{$claim->id}/status", [
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => $items->mapWithKeys(fn ($item) => [$item->id => false])->all(),
                'create_refund_request' => false,
            ],
        ])->assertOk();
    }

    /**
     * 목록에서 그 신청서의 행을 찾습니다.
     *
     * @param  string  $type  유형 (exchange|return)
     * @param  string  $claimNumber  신청 번호
     * @param  string  $query  추가 쿼리 문자열
     * @return array<string, mixed>|null 목록 행 (없으면 null)
     */
    protected function listRow(string $type, string $claimNumber, string $query = ''): ?array
    {
        $rows = $this->actingAs($this->admin)->getJson($this->base()."/claims?type={$type}{$query}")->assertOk()->json('data.data');

        return collect($rows)->firstWhere('claim_number', $claimNumber);
    }

    /**
     * 환불 없이 완료한 반품은 상세 `pending_refund` 에 정산 블록과 같은 금액을 싣고, 목록은 뱃지·필터로 드러낸다.
     * 환불신청서를 접수하면 세 곳 모두 사라진다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects pending_refund_surfaces_a_completed_return_until_filed
     */
    public function test_completed_return_without_refund_is_flagged_until_filed(): void
    {
        $return = $this->collectedReturn();
        $this->assertNull(app(AdminClaimService::class)->pendingRefundFor($return), '완료 전에 환불 미접수로 판정됐습니다.');

        $this->completeWithoutRefund($return);

        $detail = $this->actingAs($this->admin)->getJson($this->base()."/returns/{$return->id}")->assertOk();
        $pending = $detail->json('data.pending_refund');
        $settlement = $detail->json('data.settlement');

        $this->assertNotNull($pending);
        $this->assertGreaterThan(0, $pending['amount']);
        $this->assertEqualsWithDelta((float) $settlement['refund_amount'] + (float) $settlement['refund_points_amount'], (float) $pending['amount'], 0.001);
        $this->assertSame(1, $pending['item_count']);
        $this->assertNotSame('', $pending['amount_formatted']);

        $this->assertTrue($this->listRow('return', $return->return_number)['has_pending_refund']);
        $this->assertNotNull($this->listRow('return', $return->return_number, '&pending_refund=1'));

        $this->actingAs($this->admin)->postJson($this->base()."/returns/{$return->id}/refund-request")->assertCreated();

        $this->actingAs($this->admin)->getJson($this->base()."/returns/{$return->id}")
            ->assertOk()
            ->assertJsonPath('data.pending_refund', null);
        $this->assertFalse($this->listRow('return', $return->return_number)['has_pending_refund']);
        $this->assertNull($this->listRow('return', $return->return_number, '&pending_refund=1'));
    }

    /**
     * 저가 교환(차액 환불)도 같은 규칙이다 — 환불 없이 완료하면 드러나고, 접수하면 사라진다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects pending_refund_surfaces_a_lower_priced_exchange_until_filed
     */
    public function test_lower_priced_exchange_without_refund_is_flagged_until_filed(): void
    {
        [, $exchange] = $this->redeliveredLowerPricedExchange();

        $this->completeWithoutRefund($exchange);

        $pending = $this->actingAs($this->admin)->getJson($this->base()."/exchanges/{$exchange->id}")->assertOk()->json('data.pending_refund');
        $this->assertNotNull($pending);
        $this->assertGreaterThan(0, $pending['amount']);

        $this->assertTrue($this->listRow('exchange', $exchange->exchange_number)['has_pending_refund']);
        $this->assertNotNull($this->listRow('exchange', $exchange->exchange_number, '&pending_refund=1'));

        $this->actingAs($this->admin)->postJson($this->base()."/exchanges/{$exchange->id}/refund-request")->assertCreated();

        $this->actingAs($this->admin)->getJson($this->base()."/exchanges/{$exchange->id}")
            ->assertOk()
            ->assertJsonPath('data.pending_refund', null);
        $this->assertFalse($this->listRow('exchange', $exchange->exchange_number)['has_pending_refund']);
        $this->assertNull($this->listRow('exchange', $exchange->exchange_number, '&pending_refund=1'));
    }

    /**
     * 돌려줄 돈이 없는 완료 건(차액 없는 교환)은 상세·목록·필터 어디에도 환불 미접수로 드러나지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, gate_side=shipped
     *
     * @effects pending_refund_ignores_zero_refund_claims
     */
    public function test_zero_refund_completed_exchange_is_not_flagged(): void
    {
        $order = $this->deliveredClaimOrder(exchangeFee: 0);
        $service = app(OrderExchangeService::class);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]);

        foreach ([ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTED, ExchangeStatusEnum::REDELIVERING] as $step) {
            $exchange = $service->transition($exchange->fresh(), $step, $this->claimTrackingFor($step));
        }

        $this->completeWithoutRefund($exchange);
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $exchange->fresh()->exchange_status);

        $this->actingAs($this->admin)->getJson($this->base()."/exchanges/{$exchange->id}")
            ->assertOk()
            ->assertJsonPath('data.pending_refund', null);
        $this->assertFalse($this->listRow('exchange', $exchange->exchange_number)['has_pending_refund']);
        $this->assertNull($this->listRow('exchange', $exchange->exchange_number, '&pending_refund=1'));
    }

    /**
     * 목록 필터 `pending_refund` 는 교환·반품 유형에만 있다 — 환불 탭에 보내면 422 다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, gate_side=shipped
     *
     * @effects pending_refund_filter_is_limited_to_exchange_and_return
     */
    public function test_pending_refund_filter_is_rejected_for_refunds(): void
    {
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=refund&pending_refund=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pending_refund']);

        $this->assertNull(app(AdminClaimService::class)->pendingRefundFor($this->collectedReturn()->fresh()));
    }

    /**
     * 「환불 미접수」 판정은 모델 한 곳이다 — 상세 배너·목록/주문 상세 뱃지가 읽는 `pendingRefundItems()` 와
     * 목록 필터가 쓰는 쿼리판 `pendingRefund()` 가 모든 상태에서 같은 답을 낸다. 네 곳에 흩어져 있던 판정을
     * 모은 뒤(3차 검증 인터뷰 2026-09-26) 한쪽만 바뀌면 이 표에서 갈린다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, claim_type=exchange
     *
     * @effects pending_refund_instance_and_query_agree
     */
    public function test_pending_refund_instance_and_query_agree(): void
    {
        $refund = fn (int $orderId, RefundStatusEnum $status, ?int $exchangeId = null) => OrderRefund::create([
            'order_id' => $orderId,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => $status,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 1000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'refund_source_type' => $exchangeId !== null ? RefundSourceTypeEnum::EXCHANGE : null,
            'refund_source_id' => $exchangeId,
        ]);

        $returns = [];
        foreach ([
            'completed_unlinked' => [ReturnStatusEnum::COMPLETED, null, true],
            'completed_rejected_refund' => [ReturnStatusEnum::COMPLETED, RefundStatusEnum::REJECTED, true],
            'completed_withdrawn_refund' => [ReturnStatusEnum::COMPLETED, RefundStatusEnum::WITHDRAWN, true],
            'completed_live_refund' => [ReturnStatusEnum::COMPLETED, RefundStatusEnum::REQUESTED, false],
            'approved_unlinked' => [ReturnStatusEnum::APPROVED, null, false],
        ] as $case => [$status, $refundStatus, $expected]) {
            $row = OrderReturnOption::factory()->create(['option_status' => $status]);
            if ($refundStatus !== null) {
                $row->update(['refund_id' => $refund($row->order_id, $refundStatus)->id]);
            }
            $returns[$case] = [$row->order_return_id, $expected];
        }

        $exchanges = [];
        foreach ([
            'lower_priced_unlinked' => [-1000, null, true],
            'lower_priced_rejected_refund' => [-1000, RefundStatusEnum::REJECTED, true],
            'lower_priced_live_refund' => [-1000, RefundStatusEnum::APPROVED, false],
            'no_difference' => [0, null, false],
        ] as $case => [$difference, $refundStatus, $expected]) {
            $row = OrderExchangeOption::factory()->create(['option_status' => ExchangeStatusEnum::COMPLETED, 'price_difference' => $difference]);
            if ($refundStatus !== null) {
                $refund($row->order_id, $refundStatus, $row->order_exchange_id);
            }
            $exchanges[$case] = [$row->order_exchange_id, $expected];
        }

        $returnQuery = OrderReturn::query()->pendingRefund()->pluck('id')->all();
        foreach ($returns as $case => [$id, $expected]) {
            $this->assertSame($expected, OrderReturn::findOrFail($id)->pendingRefundItems()->isNotEmpty(), "반품 {$case} — 인스턴스");
            $this->assertSame($expected, in_array($id, $returnQuery, true), "반품 {$case} — 쿼리");
        }

        $exchangeQuery = OrderExchange::query()->pendingRefund()->pluck('id')->all();
        $counted = OrderExchange::query()->withLiveRefundCount()->get()->keyBy('id');
        foreach ($exchanges as $case => [$id, $expected]) {
            $this->assertSame($expected, OrderExchange::findOrFail($id)->pendingRefundItems()->isNotEmpty(), "교환 {$case} — 인스턴스");
            $this->assertSame($expected, $counted[$id]->pendingRefundItems()->isNotEmpty(), "교환 {$case} — 목록(live_refund_count)");
            $this->assertSame($expected, in_array($id, $exchangeQuery, true), "교환 {$case} — 쿼리");
        }
    }
}
