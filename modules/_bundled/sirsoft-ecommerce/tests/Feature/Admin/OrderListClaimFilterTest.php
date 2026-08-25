<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 주문 목록 클레임 기준 필터 Feature 테스트 (S6c — D22)
 *
 * 검증 축:
 * - whereExists 총 건수 불변 — 한 주문에 매칭 클레임이 N건이어도 행·총 건수는 1 (조인 부풀림 회귀 차단)
 * - claim_presence 3종 (none / any / active)
 * - 유형별 상태(취소는 유형) 집합 필터 + 유형 간 OR
 * - 귀책·신청일 축 (취소는 귀책 축 없음 — 매칭 제외)
 * - claim_summary opt-in 직렬화 (미요청 시 키 부재, 취소 발 환불 제외, 유형별 최신 상태·건수)
 * - 어휘 밖 상태값 422
 */
class OrderListClaimFilterTest extends ModuleTestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
    }

    protected function url(string $query = ''): string
    {
        return '/api/modules/sirsoft-ecommerce/admin/orders'.($query !== '' ? '?'.$query : '');
    }

    protected function makeOrder(): Order
    {
        return OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
    }

    protected function makeReturn(Order $order, ReturnStatusEnum $status = ReturnStatusEnum::REQUESTED, array $overrides = []): OrderReturn
    {
        return OrderReturnFactory::new()->create(array_merge([
            'order_id' => $order->id,
            'return_status' => $status,
        ], $overrides));
    }

    protected function makeExchange(Order $order, ExchangeStatusEnum $status = ExchangeStatusEnum::REQUESTED, array $overrides = []): OrderExchange
    {
        return OrderExchangeFactory::new()->create(array_merge([
            'order_id' => $order->id,
            'exchange_status' => $status,
        ], $overrides));
    }

    protected function orderNumbers($response): array
    {
        return collect($response->json('data.data'))->pluck('order_number')->all();
    }

    public function test_claim_filter_total_is_not_inflated_by_multiple_matching_claims(): void
    {
        $order = $this->makeOrder();
        $this->makeReturn($order);
        $this->makeReturn($order);
        $this->makeOrder(); // 클레임 없는 주문

        $response = $this->actingAs($this->admin)
            ->getJson($this->url('claim_return_statuses[]=requested'))
            ->assertOk();

        // whereExists — 매칭 클레임 2건이어도 주문 행 1개, 총 건수 1 (조인 부풀림 회귀 차단)
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame([$order->order_number], $this->orderNumbers($response));
    }

    public function test_claim_presence_filters_none_any_active(): void
    {
        $withActive = $this->makeOrder();
        $this->makeReturn($withActive, ReturnStatusEnum::COLLECTING);

        $withFinalOnly = $this->makeOrder();
        $this->makeReturn($withFinalOnly, ReturnStatusEnum::COMPLETED);

        $without = $this->makeOrder();

        $any = $this->actingAs($this->admin)->getJson($this->url('claim_presence=any'))->assertOk();
        $this->assertEqualsCanonicalizing(
            [$withActive->order_number, $withFinalOnly->order_number],
            $this->orderNumbers($any),
        );

        $none = $this->actingAs($this->admin)->getJson($this->url('claim_presence=none'))->assertOk();
        $this->assertSame([$without->order_number], $this->orderNumbers($none));

        $active = $this->actingAs($this->admin)->getJson($this->url('claim_presence=active'))->assertOk();
        $this->assertSame([$withActive->order_number], $this->orderNumbers($active));
    }

    public function test_type_status_sets_are_combined_with_or_across_types(): void
    {
        $returnOrder = $this->makeOrder();
        $this->makeReturn($returnOrder, ReturnStatusEnum::REQUESTED);

        $exchangeOrder = $this->makeOrder();
        $this->makeExchange($exchangeOrder, ExchangeStatusEnum::COLLECTING);

        $otherStatusOrder = $this->makeOrder();
        $this->makeExchange($otherStatusOrder, ExchangeStatusEnum::APPROVED);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url('claim_return_statuses[]=requested&claim_exchange_statuses[]=collecting'))
            ->assertOk();

        // 체크한 상태 중 하나라도 해당하는 클레임이 있는 주문 (유형 간 OR)
        $this->assertEqualsCanonicalizing(
            [$returnOrder->order_number, $exchangeOrder->order_number],
            $this->orderNumbers($response),
        );
    }

    public function test_cancel_axis_uses_cancel_type_vocabulary(): void
    {
        $partialOrder = $this->makeOrder();
        OrderCancel::create([
            'order_id' => $partialOrder->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);

        $fullOrder = $this->makeOrder();
        OrderCancel::create([
            'order_id' => $fullOrder->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url('claim_cancel_types[]=partial'))
            ->assertOk();

        $this->assertSame([$partialOrder->order_number], $this->orderNumbers($response));

        // 전이 상태 어휘(requested 등)는 취소 축에서 422 — 어휘는 CancelTypeEnum 단일 출처
        $this->actingAs($this->admin)
            ->getJson($this->url('claim_cancel_types[]=requested'))
            ->assertUnprocessable();
    }

    public function test_fault_and_requested_date_constrain_the_same_claim_row(): void
    {
        $sellerFault = $this->makeOrder();
        $this->makeReturn($sellerFault, ReturnStatusEnum::REQUESTED, ['fault_type' => ClaimReasonFaultTypeEnum::SELLER]);

        $customerFault = $this->makeOrder();
        $this->makeReturn($customerFault, ReturnStatusEnum::REQUESTED, ['fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER]);

        // 귀책 축이 없는 취소만 있는 주문 — 귀책 필터에서 매칭 불가
        $cancelOnly = $this->makeOrder();
        OrderCancel::create([
            'order_id' => $cancelOnly->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);

        $fault = $this->actingAs($this->admin)
            ->getJson($this->url('claim_fault_types[]=seller'))
            ->assertOk();
        $this->assertSame([$sellerFault->order_number], $this->orderNumbers($fault));

        // 신청일 범위 — 어제 접수된 건만
        $old = $this->makeOrder();
        $this->makeReturn($old)->forceFill(['created_at' => now()->subDays(10)])->save();

        $range = $this->actingAs($this->admin)
            ->getJson($this->url('claim_requested_from='.now()->subDay()->toDateString()))
            ->assertOk();
        $this->assertNotContains($old->order_number, $this->orderNumbers($range));
        $this->assertContains($sellerFault->order_number, $this->orderNumbers($range));
    }

    public function test_claim_summary_is_serialized_only_when_requested(): void
    {
        $order = $this->makeOrder();
        $this->makeReturn($order, ReturnStatusEnum::REQUESTED);
        $latest = $this->makeReturn($order, ReturnStatusEnum::COLLECTING);

        // 취소 발 환불 — 요약의 환불 축에서 제외
        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);
        OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL,
            'refund_number' => 'RF-TEST-CANCEL-'.uniqid(),
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 5000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        // opt-in 없이 — 키 자체가 없다 (하위 컬렉션 무조건 직렬화 금지)
        $withoutFlag = $this->actingAs($this->admin)->getJson($this->url())->assertOk();
        $this->assertArrayNotHasKey('claim_summary', $withoutFlag->json('data.data.0'));

        $withFlag = $this->actingAs($this->admin)
            ->getJson($this->url('with_claim_summary=1'))
            ->assertOk();

        $row = collect($withFlag->json('data.data'))->firstWhere('order_number', $order->order_number);
        $summary = collect($row['claim_summary'])->keyBy('type');

        // 반품 — 건수 2 + 최신(마지막 접수) 상태
        $this->assertSame(2, $summary['return']['count']);
        $this->assertSame(ReturnStatusEnum::COLLECTING->value, $summary['return']['status']);
        $this->assertSame($latest->return_number, $summary['return']['latest_claim_number']);

        // 취소 축은 실리고 환불 축은 취소 발 제외로 비어 있다
        $this->assertSame(1, $summary['cancel']['count']);
        $this->assertArrayNotHasKey('refund', $summary->all());
    }

    public function test_summary_refund_count_excludes_cancel_origin_requests(): void
    {
        $order = $this->makeOrder();

        // 직접 환불 1건 + 취소 발 환불 1건 — 요약의 환불 건수는 1 이어야 한다.
        // 건수는 DB 집계로 얻으므로, 집계 제약이 관계 제약과 어긋나면 여기서만 2 가 된다
        // (배지 상태는 직접 환불로 정상 표시되어 건수만 조용히 틀린다).
        $direct = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-TEST-DIRECT-'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 3000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);
        OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL,
            'refund_number' => 'RF-TEST-CANCEL-'.uniqid(),
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 5000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url('with_claim_summary=1'))
            ->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('order_number', $order->order_number);
        $summary = collect($row['claim_summary'])->keyBy('type');

        $this->assertSame(1, $summary['refund']['count']);
        $this->assertSame($direct->refund_number, $summary['refund']['latest_claim_number']);
    }

    public function test_fault_and_date_must_be_satisfied_by_the_same_claim(): void
    {
        // 귀책은 클레임 A, 신청일은 클레임 B — 두 축을 각각 만족하는 클레임이 따로 있을 뿐
        // 둘을 함께 만족하는 클레임은 없다. 같은 서브쿼리 결합(판정 ⑥)이라 제외되어야 한다.
        // 축을 별도 서브쿼리로 분리하면 이 주문이 걸려 나온다 (교차 오탐).
        $split = $this->makeOrder();
        $this->makeReturn($split, ReturnStatusEnum::REQUESTED, [
            'fault_type' => ClaimReasonFaultTypeEnum::SELLER,
            'created_at' => now()->subDays(10),
        ]);
        $this->makeReturn($split, ReturnStatusEnum::REQUESTED, [
            'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
            'created_at' => now(),
        ]);

        // 한 클레임이 두 축을 함께 만족하는 주문 — 걸려야 한다.
        $matched = $this->makeOrder();
        $this->makeReturn($matched, ReturnStatusEnum::REQUESTED, [
            'fault_type' => ClaimReasonFaultTypeEnum::SELLER,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson($this->url(
            'claim_fault_types[]='.ClaimReasonFaultTypeEnum::SELLER->value
            .'&claim_requested_from='.now()->subDay()->toDateString(),
        ))->assertOk();

        $this->assertSame([$matched->order_number], $this->orderNumbers($response));
    }

    public function test_claim_status_vocabulary_is_gated_per_type(): void
    {
        $this->actingAs($this->admin)
            ->getJson($this->url('claim_return_statuses[]=not_a_status'))
            ->assertUnprocessable();

        $this->actingAs($this->admin)
            ->getJson($this->url('claim_presence=sometimes'))
            ->assertUnprocessable();
    }

    public function test_legacy_refunds_without_source_type_are_treated_as_direct(): void
    {
        // 1.2.0 백필 스텝은 `order_cancel_id` 가 없는 구형 환불신청서를 "출처 미상" 으로
        // 남긴다(refund_source_type = NULL). SQL 3치 논리에서 `!= 'cancel'` 은 NULL 행을
        // 배제하므로, 그 신청서는 필터·요약·건수 어디에도 잡히지 않고 그 주문은
        // `claim_presence=none` 이 "클레임 없음" 으로 오분류한다.
        $legacy = $this->makeOrder();
        OrderRefund::create([
            'order_id' => $legacy->id,
            'refund_source_type' => null,
            'refund_number' => 'RF-TEST-LEGACY-'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 4000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        $clean = $this->makeOrder();

        // ① 상태 필터로 도달한다
        $filtered = $this->actingAs($this->admin)
            ->getJson($this->url('claim_refund_statuses[]='.RefundStatusEnum::REQUESTED->value))
            ->assertOk();
        $this->assertSame([$legacy->order_number], $this->orderNumbers($filtered));

        // ② presence=none 이 "클레임 없음" 으로 오분류하지 않는다
        $none = $this->actingAs($this->admin)->getJson($this->url('claim_presence=none'))->assertOk();
        $this->assertSame([$clean->order_number], $this->orderNumbers($none));

        // ③ 요약 배지·건수에 실린다
        $summary = $this->actingAs($this->admin)
            ->getJson($this->url('with_claim_summary=1'))
            ->assertOk();
        $row = collect($summary->json('data.data'))->firstWhere('order_number', $legacy->order_number);
        $refund = collect($row['claim_summary'])->firstWhere('type', 'refund');
        $this->assertNotNull($refund, '출처 미상 환불이 요약에서 사라졌습니다');
        $this->assertSame(1, $refund['count']);
    }

    public function test_refund_status_filter_excludes_cancel_origin_requests(): void
    {
        // 환불 축의 취소 발 제외가 요약 경로에만 걸려 있고 필터 경로에는 단언이 없었다.
        // 취소 발 환불은 취소 축이 대표하므로 환불 상태 칩으로 걸리면 안 된다.
        $cancelOrigin = $this->makeOrder();
        $cancel = OrderCancel::create([
            'order_id' => $cancelOrigin->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'cancel_amount' => 5000,
            'items_snapshot' => [],
        ]);
        OrderRefund::create([
            'order_id' => $cancelOrigin->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL,
            'refund_number' => 'RF-TEST-CO-'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 5000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        $direct = $this->makeOrder();
        OrderRefund::create([
            'order_id' => $direct->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-TEST-D-'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 3000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url('claim_refund_statuses[]='.RefundStatusEnum::REQUESTED->value))
            ->assertOk();

        $this->assertSame([$direct->order_number], $this->orderNumbers($response));
    }

    public function test_with_claim_summary_accepts_string_booleans_and_rejects_unparsable_values(): void
    {
        $order = $this->makeOrder();
        $this->makeReturn($order);

        // 쿼리 파라미터는 문자열로 도착한다 — 화면이 "true"/"false" 를 보내는 형태를 받아야 한다.
        $true = $this->actingAs($this->admin)
            ->getJson($this->url('with_claim_summary=true'))
            ->assertOk();
        $this->assertArrayHasKey('claim_summary', $true->json('data.data.0'));

        $false = $this->actingAs($this->admin)
            ->getJson($this->url('with_claim_summary=false'))
            ->assertOk();
        $this->assertArrayNotHasKey('claim_summary', $false->json('data.data.0'));

        // 해석 불가한 값은 건드리지 않는다 — null 로 정규화하면 오타 파라미터가
        // "필터 없음" 으로 조용히 통과한다.
        $this->actingAs($this->admin)
            ->getJson($this->url('with_claim_summary=maybe'))
            ->assertStatus(422);
    }

    public function test_claim_requested_to_bounds_the_range(): void
    {
        $inRange = $this->makeOrder();
        $this->makeReturn($inRange, ReturnStatusEnum::REQUESTED, ['created_at' => '2026-03-10 09:00:00']);

        $afterRange = $this->makeOrder();
        $this->makeReturn($afterRange, ReturnStatusEnum::REQUESTED, ['created_at' => '2026-03-20 09:00:00']);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url('claim_requested_to=2026-03-15'))
            ->assertOk();

        $this->assertSame([$inRange->order_number], $this->orderNumbers($response));
    }

    public function test_fault_filter_with_cancel_only_type_returns_empty(): void
    {
        // 귀책 필터 + 취소 유형만 지정 — 취소 테이블에는 귀책 컬럼이 없어 매칭 가능한
        // 유형이 남지 않는다. 이 분기가 조용히 전건을 돌려주면 필터가 무력화된다.
        $order = $this->makeOrder();
        OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'cancel_amount' => 5000,
            'items_snapshot' => [],
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url(
                'claim_cancel_types[]='.CancelTypeEnum::FULL->value
                .'&claim_fault_types[]='.ClaimReasonFaultTypeEnum::CUSTOMER->value
            ))
            ->assertOk();

        $this->assertSame([], $this->orderNumbers($response));
        $this->assertSame(0, $response->json('data.pagination.total'));
    }
}
