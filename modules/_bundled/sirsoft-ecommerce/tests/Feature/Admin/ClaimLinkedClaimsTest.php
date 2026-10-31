<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 반품·교환 ↔ 후속 환불신청서 ↔ 처리 결과 취소의 양방향 연결 (보완 5 · Q7)
 *
 * 관리자 반품 상세에 그 반품으로 접수된 환불신청서로 가는 길이 없었고, 상품 행 `refund_id` 를 거치는 관계는 반려·철회 뒤
 * 재접수할 때 덮어써져 앞선 신청서를 잃었다. 표시는 출처 축(`refund_source_type` + `refund_source_id`)으로 모은다.
 *
 * 시나리오 매니페스트: `tests/scenarios/admin-claim-detail-payment.yaml`
 */
class ClaimLinkedClaimsTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 통화·사유와 PG 환불 성공 훅, 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'handled' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
        ]);
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 관리자 API 경로 접두사를 반환합니다.
     *
     * @return string 경로
     */
    private function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 환불신청서 없이 완료한 반품을 만듭니다.
     *
     * @return OrderReturn 완료된 반품
     */
    private function completedReturn(): OrderReturn
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderReturnService::class);
        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTED)->fresh(['returnOptions']);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => $return->returnOptions->mapWithKeys(fn ($item) => [$item->id => false])->all(),
                'create_refund_request' => false,
            ],
        ])->assertOk();

        return $return->fresh(['returnOptions']);
    }

    /**
     * 관리자 상세 응답의 연결 신청서 봉투를 반환합니다.
     *
     * @param  string  $claimNumber  신청서 번호
     * @return array 봉투
     */
    private function linked(string $claimNumber): array
    {
        return $this->actingAs($this->admin)->getJson($this->base()."/claims/{$claimNumber}")->assertOk()->json('data.linked_claims');
    }

    /**
     * 반려 뒤 재접수한 환불까지 반품 상세의 후속 환불 목록에 모두 나온다 — 상품 행 관계는 앞 신청서를 잃는다.
     *
     * @scenario claim_type=return, cancellable=absent
     *
     * @effects claim_linked_refunds_listed_from_source_axis
     *
     * @return void 반환값 없음
     */
    public function test_return_detail_lists_every_refund_including_refiled_after_rejection(): void
    {
        $return = $this->completedReturn();
        $returns = app(OrderReturnService::class);
        $first = $returns->createRefundRequestForCompleted($return->fresh(['returnOptions']));
        app(OrderRefundRequestService::class)->transition($first->fresh(), RefundStatusEnum::REJECTED, ['reject_reason' => '재확인 필요']);
        $second = $returns->createRefundRequestForCompleted($return->fresh(['returnOptions']));

        $linked = $this->linked($return->return_number);

        $this->assertSame([$first->refund_number, $second->refund_number], array_column($linked['refunds'], 'claim_number'));
        $this->assertSame([false, true], array_column($linked['refunds'], 'is_live'));
        $this->assertSame('/admin/ecommerce/claims/'.$second->refund_number, $linked['refunds'][1]['detail_url']);
        $this->assertNotEmpty($linked['refunds'][1]['refund_total_formatted']);
        $this->assertSame($second->refund_number, $linked['latest_refund']['claim_number']);
        $this->assertSame(1, $return->fresh()->refunds()->count(), '상품 행 관계는 재접수 때 앞 신청서를 잃는다(표시에 쓰지 않는 이유)');
    }

    /**
     * 교환 상세는 그 교환에서 생긴 차액 환불을 보이고, 반품의 출처 관계는 교환 출처를 섞지 않는다.
     *
     * @scenario claim_type=exchange, cancellable=absent
     *
     * @effects claim_linked_refunds_listed_from_source_axis
     *
     * @return void 반환값 없음
     */
    public function test_exchange_detail_lists_difference_refund(): void
    {
        [$order, $exchange] = $this->redeliveredLowerPricedExchange(-10000);
        $refund = OrderRefund::create([
            'order_id' => $order->id, 'refund_source_type' => RefundSourceTypeEnum::EXCHANGE, 'refund_source_id' => $exchange->id,
            'refund_number' => 'RF-LINK-EXCH', 'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG, 'refund_amount' => 10000,
        ]);

        $this->assertSame([$refund->refund_number], array_column($this->linked($exchange->exchange_number)['refunds'], 'claim_number'));

        // 같은 id 의 반품이 있어도 반품의 출처 관계(sourcedRefunds)는 교환 출처 환불을 담지 않는다
        $return = new OrderReturn;
        $return->id = $exchange->id;
        $this->assertSame(0, $return->sourcedRefunds()->count());
    }

    /**
     * 환불 없이 완료한 반품은 후속 환불이 비어 있다.
     *
     * @scenario claim_type=return, cancellable=absent
     *
     * @effects claim_linked_refunds_empty_notice
     *
     * @return void 반환값 없음
     */
    public function test_return_without_refund_has_empty_list(): void
    {
        $return = $this->completedReturn();

        $linked = $this->linked($return->return_number);

        $this->assertSame([], $linked['refunds']);
        $this->assertNull($linked['latest_refund']);
        $this->assertNull($linked['source']);
    }

    /**
     * 실행된 반품 발 환불의 상세는 원 반품과 처리 결과 취소 내역으로 이어진다.
     *
     * @scenario claim_type=refund, cancellable=absent
     *
     * @effects claim_header_links_source_and_result_cancel
     *
     * @return void 반환값 없음
     */
    public function test_refund_detail_carries_source_and_result_cancel(): void
    {
        $return = $this->completedReturn();
        $refund = app(OrderReturnService::class)->createRefundRequestForCompleted($return->fresh(['returnOptions']));
        $refund->forceFill(['refund_status' => RefundStatusEnum::APPROVED])->save();
        app(OrderRefundRequestService::class)->executeRefund($refund->fresh());

        $linked = $this->linked($refund->refund_number);

        $this->assertSame($return->return_number, $linked['source']['claim_number']);
        $this->assertSame('return', $linked['source']['claim_type']);
        $this->assertNotNull($linked['result_cancel']);
        $this->assertStringStartsWith('CN', $linked['result_cancel']['claim_number']);

        // 취소 상세는 그 환불신청서를 원 신청서로 가리킨다
        $cancelLinked = $this->linked($linked['result_cancel']['claim_number']);
        $this->assertSame('refund', $cancelLinked['source']['claim_type']);
        $this->assertSame($refund->refund_number, $cancelLinked['source']['claim_number']);
    }

    /**
     * 고객 직접·운영자 대리 출처의 환불은 원 신청서가 없다.
     *
     * @scenario claim_type=refund, cancellable=absent
     *
     * @effects claim_header_links_source_and_result_cancel
     *
     * @return void 반환값 없음
     */
    public function test_direct_refund_has_no_source(): void
    {
        $order = $this->deliveredClaimOrder();
        $refund = OrderRefund::create([
            'order_id' => $order->id, 'refund_source_type' => RefundSourceTypeEnum::DIRECT, 'refund_source_id' => null,
            'refund_number' => 'RF-LINK-DIRECT', 'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG, 'refund_amount' => 1000,
        ]);

        $this->assertNull($this->linked($refund->refund_number)['source']);
    }

    /**
     * 다른 주문의 환불신청서는 같은 출처 id 를 가져도 목록에 섞이지 않는다.
     *
     * @scenario claim_type=return, cancellable=absent
     *
     * @effects claim_linked_refunds_listed_from_source_axis
     *
     * @return void 반환값 없음
     */
    public function test_linked_refunds_never_cross_orders(): void
    {
        $return = $this->completedReturn();
        $otherOrder = $this->deliveredClaimOrder();
        OrderRefund::create([
            'order_id' => $otherOrder->id, 'refund_source_type' => RefundSourceTypeEnum::RETURN, 'refund_source_id' => $return->id,
            'refund_number' => 'RF-LINK-OTHER', 'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG, 'refund_amount' => 1000,
        ]);

        $this->assertSame([], $this->linked($return->return_number)['refunds']);
    }

    /**
     * 목록의 반품 행은 최신 환불의 진행 상태를 싣고, 환불·취소 탭 행에는 싣지 않는다.
     *
     * @scenario claim_type=return, cancellable=absent
     *
     * @effects claim_list_refund_progress_badge_navigates_to_refund
     *
     * @return void 반환값 없음
     */
    public function test_list_rows_carry_latest_refund_progress(): void
    {
        $return = $this->completedReturn();
        $returns = app(OrderReturnService::class);
        $first = $returns->createRefundRequestForCompleted($return->fresh(['returnOptions']));
        app(OrderRefundRequestService::class)->transition($first->fresh(), RefundStatusEnum::REJECTED, ['reject_reason' => '재확인 필요']);
        $second = $returns->createRefundRequestForCompleted($return->fresh(['returnOptions']));

        $rows = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return')->assertOk()->json('data.data');
        $row = collect($rows)->firstWhere('claim_number', $return->return_number);

        $this->assertSame($second->refund_number, $row['refund_progress']['claim_number']);
        $this->assertSame(RefundStatusEnum::REQUESTED->value, $row['refund_progress']['status']);
        $this->assertSame(2, $row['refund_progress']['count']);

        $refundRows = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=refund')->assertOk()->json('data.data');
        $this->assertArrayNotHasKey('refund_progress', collect($refundRows)->firstWhere('claim_number', $second->refund_number) ?? []);
    }

    /**
     * 목록의 환불 진행은 페이지당 한 번에 싣는다 — 반품이 늘어도 환불신청서 조회 수는 같다.
     *
     * @scenario claim_type=return, cancellable=absent
     *
     * @effects claim_list_refund_progress_badge_navigates_to_refund
     *
     * @return void 반환값 없음
     */
    public function test_list_refund_progress_is_batched(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return')->assertOk();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'order_refunds'))->count();
            DB::disableQueryLog();

            return $n;
        };

        $this->completedReturn();
        $one = $count();
        $this->completedReturn();
        $this->completedReturn();
        $three = $count();

        $this->assertSame($one, $three, '반품 행마다 환불신청서를 다시 읽는다(N+1)');
    }

    /**
     * 주문 상세 클레임 내역의 반품 발 환불 행에는 원 신청서 번호가 붙는다.
     *
     * @scenario claim_type=refund, cancellable=absent
     *
     * @effects claim_header_links_source_and_result_cancel
     *
     * @return void 반환값 없음
     */
    public function test_order_claim_history_refund_row_has_source_number(): void
    {
        $return = $this->completedReturn();
        $refund = app(OrderReturnService::class)->createRefundRequestForCompleted($return->fresh(['returnOptions']));

        $claims = $this->actingAs($this->admin)->getJson($this->base()."/orders/{$return->order_id}/claims")->assertOk()->json('data.claims');
        $row = collect($claims)->firstWhere('claim_number', $refund->refund_number);

        $this->assertSame($return->return_number, $row['source_claim_number']);
        $this->assertNull(collect($claims)->firstWhere('claim_number', $return->return_number)['source_claim_number']);
    }
}
