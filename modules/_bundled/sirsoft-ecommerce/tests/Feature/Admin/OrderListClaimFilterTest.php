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
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 주문 목록 클레임 기준 필터 Feature 테스트 (S6c — D22)
 *
 * 검증 축:
 * - whereExists 총 건수 불변 — 한 주문에 매칭 클레임이 N건이어도 행·총 건수는 1 (조인 부풀림 회귀 차단)
 * - claim_presence 3종 (none / any / active)
 * - 유형별 상태(취소는 유형) 집합 필터 + 유형 간 OR
 * - 귀책·신청일 축 (취소는 귀책 축 없음 — 매칭 제외)
 * - claim_summary 항상 직렬화 (요청 파라미터와 무관 — 6차 확정으로 opt-in 폐지. 취소 발 환불 제외, 유형별 최신 상태·건수)
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

    /**
     * 총 건수 대조 — 한 주문에 매칭 클레임이 여럿이어도 원 행이 부풀지 않는다 (§8-1 T4).
     *
     * 이 effect 의 실질 단언은 여기뿐이다. E2E 는 클레임 표본이 0이면 아무것도 단언하지 못한다.
     *
     * @scenario claim_axis=type_status
     *
     * @effects order_list_claim_filter_total_is_not_inflated
     */
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors('claim_cancel_types.0');
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

    public function test_claim_summary_is_serialized_without_any_opt_in_parameter(): void
    {
        $order = $this->makeOrder();
        $this->makeReturn($order, ReturnStatusEnum::REQUESTED);
        $latest = $this->makeReturn($order, ReturnStatusEnum::COLLECTING);

        // 취소 발 환불 — 요약의 환불 축에 **함께** 선다 (#599 후속 D6)
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

        // 파라미터 없이도 항상 실린다. 종전에는 `with_claim_summary` 요청에만 싣는 opt-in
        // 이었는데, 컬럼 표시(브라우저 저장값)와 값 수신(주소)이 서로 다른 근거를 갖게 되어
        // 어긋났다 — 컬럼을 한 번 켠 운영자의 다음 진입에서 전 행이 "-" 가 됐다.
        $withFlag = $this->actingAs($this->admin)->getJson($this->url())->assertOk();
        $this->assertArrayHasKey('claim_summary', $withFlag->json('data.data.0'));

        $row = collect($withFlag->json('data.data'))->firstWhere('order_number', $order->order_number);
        $summary = collect($row['claim_summary'])->keyBy('type');

        // 반품 — 건수 2 + 최신(마지막 접수) 상태
        $this->assertSame(2, $summary['return']['count']);
        $this->assertSame(ReturnStatusEnum::COLLECTING->value, $summary['return']['status']);
        $this->assertSame($latest->return_number, $summary['return']['latest_claim_number']);

        // 배지 색을 정하는 값. 관리자 StatusBadge 와 마이페이지 classMap 이 **둘 다** 이 키
        // 하나를 읽으므로, 트레이트에서 빠지거나 오타가 나면 7어휘가 전부 무색(회색 하나)이
        // 되는데 어휘 대조 테스트는 Enum ↔ 사전 파일만 보아 초록으로 남는다. 페이로드에서
        // 직접 단언한다 — 라벨도 같은 이유로 함께 본다.
        $this->assertSame(
            ReturnStatusEnum::COLLECTING->variant(),
            $summary['return']['status_variant'],
            '요약이 배지 variant 를 싣지 않았다 — 화면의 모든 상태 배지가 무색이 된다'
        );
        $this->assertSame(ReturnStatusEnum::COLLECTING->label(), $summary['return']['status_label']);
        $this->assertNotEmpty($summary['return']['type_label'], '유형 라벨이 비어 있다 — 마이페이지 배지의 폴백 표기가 사라진다');

        // 취소 축과 환불 축이 **둘 다** 선다 (#599 후속 D6).
        //
        // 취소신청서와 환불신청서는 같은 사건의 두 사본이 아니라 다른 사실을 기록하는 별개
        // 문서이므로 한쪽이 다른 쪽을 대표하지 않는다. 예전에는 취소 발 환불을 요약에서 빼,
        // 결제완료 상품을 환불한 주문이 목록에서 "환불 없음" 으로 보였다.
        $this->assertSame(1, $summary['cancel']['count']);
        $this->assertNotNull($summary['cancel']['status_variant'], '취소 축만 variant 가 비었다');
        $this->assertSame(1, $summary['refund']['count']);
    }

    /**
     * @scenario claim_axis=type_status
     *
     * @effects order_list_claim_summary_survives_deferred_join
     */
    public function test_summary_refund_count_includes_every_origin(): void
    {
        $order = $this->makeOrder();

        // 직접 환불 1건 + 취소 발 환불 1건 — 요약의 환불 건수는 **2** 다 (#599 후속 D6).
        // 건수는 DB 집계로 얻으므로, 집계 제약이 관계 제약과 어긋나면 여기서만 갈린다
        // (배지 상태는 정상 표시되어 건수만 조용히 틀린다).
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
            ->getJson($this->url())
            ->assertOk();

        $row = collect($response->json('data.data'))->firstWhere('order_number', $order->order_number);
        $summary = collect($row['claim_summary'])->keyBy('type');

        $this->assertSame(2, $summary['refund']['count']);
        // 최신 배지는 가장 나중 행을 따른다 — 두 행이 모두 실리므로 직접 환불이 최신은 아니다.
        $this->assertNotNull($summary['refund']['latest_claim_number']);
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors('claim_return_statuses.0');

        $this->actingAs($this->admin)
            ->getJson($this->url('claim_presence=sometimes'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('claim_presence');
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
            ->getJson($this->url())
            ->assertOk();
        $row = collect($summary->json('data.data'))->firstWhere('order_number', $legacy->order_number);
        $refund = collect($row['claim_summary'])->firstWhere('type', 'refund');
        $this->assertNotNull($refund, '출처 미상 환불이 요약에서 사라졌습니다');
        $this->assertSame(1, $refund['count']);
    }

    /**
     * @scenario claim_axis=type_status
     *
     * @effects order_list_claim_filter_narrows_result
     */
    public function test_refund_status_filter_includes_every_origin(): void
    {
        // 환불 상태 칩은 **출처로 거르지 않는다**(#599 후속 D6). 취소신청서와 환불신청서는
        // 같은 사건의 두 사본이 아니라 다른 사실을 기록하는 별개 문서이므로, 한쪽이 다른
        // 쪽을 대표하지 않는다 — 운영자가 "환불 신청 상태" 로 좁히면 취소 발 환불도 그
        // 상태를 가진 이상 함께 나와야 한다.
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

        $numbers = $this->orderNumbers($response);
        $this->assertContains($direct->order_number, $numbers);
        $this->assertContains($cancelOrigin->order_number, $numbers, '취소 발 환불도 환불 상태 칩에 걸린다 (D6)');
    }

    /**
     * 클레임 요약은 어떤 요청에도 실린다.
     *
     * 계약 변경 (확정, 6차 검증) — 종전에는 `with_claim_summary` 파라미터가 이 축을
     * 켰고, 그 파라미터의 문자열 불리언 정규화("true"/"false")와 해석 불가 값의 422 를
     * 이 케이스가 고정했다. 표시와 값 수신이 근거를 달리해 어긋나는 결함의 원인이라
     * 파라미터를 없앴다. 이제 확인할 것은 "요청이 무엇이든 실린다" 하나다.
     */
    public function test_claim_summary_is_served_regardless_of_query(): void
    {
        $order = $this->makeOrder();
        $this->makeReturn($order);

        foreach (['', 'per_page=10', 'claim_presence=any'] as $query) {
            $response = $this->actingAs($this->admin)->getJson($this->url($query))->assertOk();

            $this->assertArrayHasKey(
                'claim_summary',
                $response->json('data.data.0'),
                "쿼리 [{$query}] 응답에 claim_summary 가 없습니다",
            );
        }

        // 사라진 파라미터는 미지정 키라 검증에서 조용히 무시된다 — 422 가 아니다.
        $this->actingAs($this->admin)
            ->getJson($this->url('with_claim_summary=maybe'))
            ->assertOk();
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

    /**
     * `claim_presence=none` + 귀책 + 취소 유형만 지정하면 매칭 가능한 유형이 없다 — "그런 클레임이
     * 없는 주문" 은 전건이므로 결과가 비어서는 안 된다 (S6c 1차 이월 a — S10 소유).
     */
    public function test_presence_none_with_fault_and_cancel_only_returns_every_order(): void
    {
        $plain = $this->makeOrder();
        $cancelled = $this->makeOrder();
        OrderCancel::create([
            'order_id' => $cancelled->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);

        $res = $this->actingAs($this->admin)
            ->getJson($this->url('claim_presence=none&claim_cancel_types[]=full&claim_fault_types[]=seller'))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$plain->order_number, $cancelled->order_number],
            $this->orderNumbers($res),
        );

        // 대조군: `any` 로는 같은 조합이 0건이다 — 두 응답이 서로의 여집합이어야 한다.
        $any = $this->actingAs($this->admin)
            ->getJson($this->url('claim_presence=any&claim_cancel_types[]=full&claim_fault_types[]=seller'))
            ->assertOk();
        $this->assertSame([], $this->orderNumbers($any));
    }

    /**
     * 엑셀 내려받기가 화면과 같은 클레임 기준으로 걸러진다.
     *
     * 클레임 기준(D22)은 목록·내려받기 공용 필터 경로(`applyListFilters()`) 안에 있다 —
     * 내려받기 쪽이 빠뜨리면 화면에는 1건인데 파일에는 전건이 담긴다. 실패도 경고도 없이
     * **파일 내용만** 달라지므로, 운영자는 걸러진 결과를 받은 줄 알고 그대로 쓴다.
     *
     * @scenario claim_axis=type_status
     *
     * @effects order_list_claim_filter_narrows_result
     */
    public function test_export_applies_the_same_claim_criteria_as_the_list(): void
    {
        $matching = $this->makeOrder();
        $this->makeReturn($matching);
        $other = $this->makeOrder(); // 클레임 없는 주문 — 걸러져야 한다

        $filters = ['claim_return_statuses' => ['requested']];

        $exported = app(OrderRepositoryInterface::class)->getForExport($filters);

        $this->assertSame(
            [$matching->order_number],
            $exported->pluck('order_number')->all(),
            '내려받기가 클레임 기준을 무시했다 — 화면과 파일의 대상이 갈린다'
        );

        // 화면과 같은 술어를 쓰는지 함께 본다. 두 경로가 같은 답을 내야 계약이 성립한다.
        $listed = $this->actingAs($this->admin)
            ->getJson($this->url('claim_return_statuses[]=requested'))
            ->assertOk();

        $this->assertSame(
            $this->orderNumbers($listed),
            $exported->pluck('order_number')->all(),
            '같은 필터인데 목록과 내려받기의 대상이 다르다'
        );

        // 명시 ID 내려받기(선택 행 저장)는 그 선택이 대상이다 — 클레임 기준을 덧씌우지 않는다.
        $byIds = app(OrderRepositoryInterface::class)->getForExport($filters, [$other->id]);
        $this->assertSame([$other->order_number], $byIds->pluck('order_number')->all());
    }
}
