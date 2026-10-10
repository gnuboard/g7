<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 클레임 API Feature 테스트 (S6a)
 *
 * 검증 축:
 * - 목록 — 유형 서브탭 페이지네이션 + 유형별 건수 뱃지 + 상태 배열 필터(유형별 어휘 게이트)
 *   + 검색 4종 + 유형에 없는 필터 축 prohibited + 권한 403
 * - 상세 — 관리자 확장 필드 + 결제 블록 + 404
 * - 단건 전이 — 승인-결제 상호작용(pay_on_approval 결제 요청 우회 / pay_on_request 미수납 422
 *   / 청구 없는 결제 요청 422 = paymentNotRequired 배선) + 쓰기 게이트(paid 거절) + 전이 게이트 422
 * - 완료(D26) — completion 페이로드 필수, create_refund_request false 시 order_refunds 0건 / true 시 1건
 * - 일괄 전이 — 부분 성공 계약(게이트 위반 skip 리포트)
 * - 상품 단위 전이(D25) — 상위 스코프 422(다른 신청서의 행) + 완료 경로
 * - 송장 — 회수 송장 등록 + collecting 자동 전이 + 불완전 422
 * - 첨부 — 운영자 대리 첨부(uploadFor)·일괄 정리(deleteFor) 소비
 * - 선교환 — 완료 페이로드 `completion.unreturned` 의 미회수 완료 · 목록 회수 지연 필터(교환 전용)
 */
class ClaimControllerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 클레임 권한 2종만 가진 운영자와 첨부 저장소 모의를 세웁니다.
     *
     * 운영자에게 `settings.read` 를 주지 않는 것이 이 클래스의 전제다 — 기본 역할 `manager`
     * 의 실제 권한 구성이고, 설정 응답에 기대는 계약이 그 운영자에게 도달하는지를 재기 위함이다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
        ]);

        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true)->byDefault();
        $storage->allows('delete')->andReturn(true)->byDefault();
        $storage->allows('exists')->andReturn(true)->byDefault();
        $storage->allows('getDisk')->andReturn('local')->byDefault();
        $storage->allows('response')->andReturn(null)->byDefault();
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);
    }

    /**
     * 통화 설정과 Mockery 기대치를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->clearTestCurrencySettings();
        Mockery::close();
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
     * 반품 신청서를 서비스 경로로 접수합니다. (기본: 환불금 차감 — 자동 승인 없이 REQUESTED 유지)
     *
     * @param  Order|null  $order  대상 주문 (없으면 배송완료 주문을 새로 만든다)
     * @param  ClaimChargeMethodEnum|string|null  $chargeMethod  청구 방식
     * @return OrderReturn 접수된 반품 신청서
     */
    protected function makeReturn(?Order $order = null, ClaimChargeMethodEnum|string|null $chargeMethod = ClaimChargeMethodEnum::DEDUCT_FROM_REFUND): OrderReturn
    {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => $chargeMethod,
        ]);
    }

    /**
     * 교환 신청서를 서비스 경로로 접수합니다. (동일 옵션 교환 — 왕복 배송비 청구)
     *
     * @param  Order|null  $order  대상 주문 (없으면 배송완료 주문을 새로 만든다)
     * @param  ClaimChargeMethodEnum|string|null  $chargeMethod  청구 방식
     * @return OrderExchange 접수된 교환 신청서
     */
    protected function makeExchange(?Order $order = null, ClaimChargeMethodEnum|string|null $chargeMethod = ClaimChargeMethodEnum::PAY_ON_APPROVAL): OrderExchange
    {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => $chargeMethod,
        ]);
    }

    /**
     * 직접 환불신청서를 서비스 경로로 접수합니다. (상품준비중 — 환불신청 분기)
     *
     * @param  Order|null  $order  대상 주문 (없으면 정책 배송비가 붙은 상품준비중 주문을 만든다)
     * @return OrderRefund 접수된 환불 신청서
     */
    protected function makeRefundRequest(?Order $order = null): OrderRefund
    {
        $policy = $this->createClaimShippingPolicy();
        $order ??= $this->moveOrderTo(
            $this->attachPolicyShippings($this->createSingleItemClaimOrder(29000, 1, $policy), $policy),
            OrderStatusEnum::PREPARING
        );

        return app(OrderRefundRequestService::class)->request($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'reason_detail' => '단순 변심',
            'requested_by' => $order->user_id,
        ])['refund'];
    }

    /**
     * 반품 상품 행이 가리키는 환불신청서 ID 를 반환합니다. (S7 — 헤더 `refund_id` 대신 상품 행 연결)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return int|null 환불신청서 ID (연결된 행이 없으면 null)
     */
    protected function refundIdOf(OrderReturn $return): ?int
    {
        $id = OrderReturnOption::query()
            ->where('order_return_id', $return->id)
            ->whereNotNull('refund_id')
            ->max('refund_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * 반품 신청서를 회수도착까지 진행시킵니다. (완료 직전 상태)
     *
     * @param  OrderReturn  $return  진행시킬 반품 신청서
     * @return OrderReturn 회수도착 상태의 반품 신청서
     */
    protected function moveReturnToCollected(OrderReturn $return): OrderReturn
    {
        $service = app(OrderReturnService::class);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));

        return $service->transition($return, ReturnStatusEnum::COLLECTED);
    }

    // ================================================================
    // 목록
    // ================================================================

    /**
     * 목록 행은 축소 표현이다 — 상품 전량·첨부까지 실으면 한 화면이 수백 건을 나르고, 서브탭
     * 건수·페이지네이션이 그 행 수와 어긋나면 운영자는 없는 페이지를 넘긴다.
     *
     * @effects list_returns_subtab_rows_with_counts_and_pagination
     *
     * @return void 반환값 없음
     */
    public function test_list_returns_subtab_rows_with_counts_and_pagination(): void
    {
        $return = $this->makeReturn();
        $this->makeExchange();

        $response = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return');

        $response->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $return->return_number)
            ->assertJsonPath('data.data.0.claim_type', 'return')
            ->assertJsonPath('data.data.0.status', 'requested')
            ->assertJsonPath('data.data.0.item_summary.item_count', 1)
            ->assertJsonPath('data.counts.return', 1)
            ->assertJsonPath('data.counts.exchange', 1)
            ->assertJsonPath('data.counts.refund', 0)
            ->assertJsonPath('data.counts.cancel', 0)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.abilities.can_update_status', true);

        // 목록 행은 축소 표현이다 — 상품 전량(items)·첨부(images)를 싣지 않는다.
        $this->assertArrayNotHasKey('items', $response->json('data.data.0'));
        $this->assertArrayNotHasKey('images', $response->json('data.data.0'));
    }

    /**
     * 유형을 지정하지 않은 진입은 교환 탭이다. 기본이 흔들리면 북마크·뒤로가기로 들어온
     * 운영자가 같은 주소에서 매번 다른 목록을 본다.
     *
     * @effects list_defaults_to_the_exchange_subtab
     *
     * @return void 반환값 없음
     */
    public function test_list_defaults_to_the_exchange_subtab(): void
    {
        $exchange = $this->makeExchange();
        $this->makeReturn();

        $this->actingAs($this->admin)->getJson($this->base().'/claims')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $exchange->exchange_number);
    }

    /**
     * 상태 어휘는 유형마다 다르다. 다른 유형의 상태를 조용히 무시하면 오타가 「결과 없음」으로
     * 위장하므로 422 로 끊는다.
     *
     * @effects list_filters_by_status_array_and_gates_vocabulary_per_type
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_by_status_array_and_gates_vocabulary_per_type(): void
    {
        $approved = $this->makeReturn();
        app(OrderReturnService::class)->transition($approved, ReturnStatusEnum::APPROVED);
        $this->makeReturn();

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return&statuses[]=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'approved');

        // 재발송중은 교환 어휘다 — 반품 탭에서는 422 (오타·다른 유형 상태가 빈 목록으로 위장하지 않게).
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return&statuses[]=redelivering')
            ->assertStatus(422);
    }

    /**
     * 클레임번호 검색이 그 번호의 행 하나만 집는지 잠근다 — 부분일치로 번져도 화면에는
     * 그냥 여러 건으로 보일 뿐이다.
     *
     * @effects list_searches_by_claim_number
     *
     * @return void 반환값 없음
     */
    public function test_list_searches_by_claim_number(): void
    {
        $target = $this->makeReturn();
        $this->makeReturn();

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=return&search_field=claim_number&search_keyword='.$target->return_number)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target->id);
    }

    /**
     * 회원 구분 필터는 **주문의 주인**으로 가른다 — 운영자 대리 접수는 비회원이 아니다.
     *
     * `requested_by` 는 비회원 신청과 운영자 대리 접수가 똑같이 `null` 이다. 그 값으로 가르면
     * **회원 주문의 운영자 접수 건이 「비회원」 목록에 선다** — 운영자는 연락 수단을 잘못 고른다.
     * 모델의 `isGuestRequest()` 는 이미 주문의 `user_id` 로 판정한다(같은 판정을 목록도 쓴다).
     *
     * 실측(#599 세션A 2026-09-21): `?type=refund&requester_type=guest` 에
     * 회원 주문 `20260921-1316400577` 의 운영자 접수 `RF20260921-1412250846`·`RF20260921-1416278729` 가 잡혔다.
     *
     * @scenario claim_type=refund, requester=admin_on_behalf
     *
     * @effects claim_list_requester_filter_reads_order_owner
     *
     * @return void 반환값 없음
     */
    public function test_list_requester_filter_does_not_treat_admin_filed_member_claims_as_guest(): void
    {
        $refund = $this->makeRefundRequest();

        // 운영자 대리 접수 — 신청자 컬럼이 비고 주문 주인만 남는다.
        $refund->forceFill(['requested_by' => null, 'refund_source_type' => 'admin'])->save();

        $this->assertNotNull($refund->order->user_id, '회원 주문이어야 이 축이 성립한다');

        // 비회원 목록에 서면 안 된다.
        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=refund&requester_type=guest')
            ->assertOk()
            ->assertJsonMissing(['id' => $refund->id]);

        // 회원 목록에는 서야 한다.
        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=refund&requester_type=member')
            ->assertOk()
            ->assertJsonFragment(['id' => $refund->id]);
    }

    /**
     * 비회원 주문의 신청서는 비회원 목록에 선다. (대조군)
     *
     * 대조군이 없으면 「언제나 회원」 으로 굳어도 위 케이스가 통과한다.
     *
     * @scenario claim_type=refund, requester=guest
     *
     * @effects claim_list_requester_filter_reads_order_owner
     *
     * @return void 반환값 없음
     */
    public function test_list_requester_filter_lists_guest_order_claims_as_guest(): void
    {
        $refund = $this->makeRefundRequest();

        // 비회원 주문 — 주문의 주인이 없다.
        $refund->order->forceFill(['user_id' => null])->save();
        $refund->forceFill(['requested_by' => null])->save();

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=refund&requester_type=guest')
            ->assertOk()
            ->assertJsonFragment(['id' => $refund->id]);

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=refund&requester_type=member')
            ->assertOk()
            ->assertJsonMissing(['id' => $refund->id]);
    }

    /**
     * 그 유형에 없는 축(취소의 귀책 · 환불의 청구 방식)을 조용히 무시하면 **필터가 걸리지 않은
     * 목록이 필터 결과로 보인다**. 그래서 무시가 아니라 422 다.
     *
     * @effects list_prohibits_type_foreign_filter_axes
     *
     * @return void 반환값 없음
     */
    public function test_list_prohibits_type_foreign_filter_axes(): void
    {
        // 취소에는 귀책 축이 없다 — 조용히 무시하면 필터가 안 걸린 목록이 필터 결과로 보인다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=cancel&fault_types[]=seller')
            ->assertStatus(422);

        // 환불에는 청구 방식 축이 없다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=refund&charge_methods[]=pay_on_request')
            ->assertStatus(422);
    }

    /**
     * 금액 축은 목록 「금액」 컬럼이 그리는 값과 같은 컬럼을 봐야 한다. 최대 < 최소 는 결과가
     * 늘 비는 조합이라 게이트가 막는다 — 빈 목록으로 위장하지 않게.
     *
     * @effects amount_range_reads_the_column_the_list_amount_shows, max_amount_alone_is_a_valid_combination
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_by_amount_range_per_type(): void
    {
        // 금액 축은 목록 '금액' 컬럼이 그리는 값과 같아야 한다 — 유형마다 근거 컬럼이 다르다.
        $cheap = $this->makeExchange();
        $cheap->update(['total_charge_amount' => 3000]);
        $pricey = $this->makeExchange();
        $pricey->update(['total_charge_amount' => 30000]);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&min_amount=10000')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $pricey->exchange_number);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&max_amount=10000')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $cheap->exchange_number);

        // 최대 < 최소 는 결과가 늘 비는 조합이라 게이트가 막는다 (빈 목록으로 위장하지 않게).
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&min_amount=5000&max_amount=1000')
            ->assertStatus(422);
    }

    /**
     * 환불의 목록 금액은 「환불액 + 환불 마일리지」 합이다. 한쪽만 보면 운영자가 화면에 보이는
     * 값으로 걸었는데 걸리지 않는다.
     *
     * @effects refund_amount_range_sums_pg_and_mileage
     *
     * @return void 반환값 없음
     */
    public function test_refund_amount_range_sums_pg_and_mileage(): void
    {
        // 환불은 '환불액 + 환불 마일리지' 가 목록 금액이다 — 한쪽만 보면 합계로 거른 결과와 어긋난다.
        $order = $this->deliveredClaimOrder();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF-AMOUNT-1',
            'refund_status' => 'requested',
            'refund_method' => 'pg',
            'refund_amount' => 4000,
            'refund_points_amount' => 3000,
            'items_snapshot' => [],
        ]);

        // 합 7,000 — PG 금액(4,000)만 보면 걸리지 않는 구간으로 검증한다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=refund&min_amount=5000')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $refund->refund_number);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=refund&max_amount=6999')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * 취소의 금액은 자기 컬럼이 아니라 **연결된 환불 행**에 있다. 자기 테이블만 보면 취소 탭의
     * 금액 필터가 언제나 0 건이 된다.
     *
     * @effects cancel_amount_range_reads_the_linked_refund
     *
     * @return void 반환값 없음
     */
    public function test_cancel_amount_range_reads_the_linked_refund(): void
    {
        // 취소는 자기 테이블에 금액이 없다 — 목록과 같은 근거(연결된 환불)로 걸러야 한다.
        $order = $this->deliveredClaimOrder();
        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-AMOUNT-1',
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);
        OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_number' => 'RF-CANCEL-1',
            'refund_status' => 'completed',
            'refund_method' => 'pg',
            'refund_amount' => 12000,
            'refund_points_amount' => 0,
            'items_snapshot' => [],
        ]);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=cancel&min_amount=10000')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $cancel->cancel_number);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=cancel&max_amount=9999')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * 통화 필터는 그 통화로 기록된 건만 집고, 등록되지 않은 코드는 빈 목록이 아니라 422 다 —
     * 오타가 「결과 없음」으로 위장하지 않게.
     *
     * @effects amount_range_reads_the_column_the_list_amount_shows
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_by_currency(): void
    {
        $exchange = $this->makeExchange();

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&currency_code='.$exchange->currency_code)
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        // 등록되지 않은 통화는 빈 목록이 아니라 422 다 — 오타가 "결과 없음" 으로 위장하지 않게.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&currency_code=ZZZ')
            ->assertStatus(422);
    }

    /**
     * 이 필터가 거르는 값은 거래 시점에 동결된 통화다. 운영자가 그 통화를 설정에서 내려도
     * 그 값으로 기록된 신청서는 남으므로, 게이트가 현재 등록분만 받으면 그 신청서는 어떤
     * 필터 조합으로도 도달 불가가 되고 그 통화가 실린 URL 은 목록 전체를 422 로 만든다.
     *
     * @effects currency_gate_accepts_codes_recorded_on_claims_even_when_settings_dropped_them, currency_gate_rejects_codes_never_used
     *
     * @return void 반환값 없음
     */
    public function test_list_accepts_currency_that_was_removed_from_settings(): void
    {
        $settings = app(EcommerceSettingsService::class);

        $currencies = collect($settings->getSetting('language_currency.currencies', []));
        $baseCode = $settings->getSetting('language_currency.default_currency');

        // 기본 통화가 아닌 등록 통화 하나를 골라 실제로 내린다 (기본 통화는 내려지지 않는다).
        $target = $currencies->pluck('code')->filter()->first(fn ($code) => $code !== $baseCode);
        $this->assertNotNull($target, '테스트 환경에 기본 통화 외 등록 통화가 필요하다.');

        // 내리기 전에는 당연히 통과한다 — 뒤 단언이 "원래 되던 것" 을 재확인하는 게 아님을 고정한다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&currency_code='.$target)
            ->assertOk();

        $settings->saveSettings([
            'language_currency' => [
                'default_currency' => $baseCode,
                'currencies' => $currencies->reject(fn ($c) => ($c['code'] ?? null) === $target)->values()->all(),
            ],
        ]);

        // 내려간 것이 맞는지 먼저 확인한다 (설정이 안 바뀌었으면 아래 단언은 무의미하다).
        $this->assertNotContains(
            $target,
            collect($settings->getSetting('language_currency.currencies', []))->pluck('code')->all(),
            '대상 통화가 등록 목록에서 내려가야 한다.'
        );
        $this->assertContains(
            $target,
            (array) $settings->getSetting('language_currency.removed_default_currencies', []),
            '내린 통화는 removed_default_currencies 에 기록돼야 한다.'
        );

        // 내려간 뒤에도 게이트를 통과한다 (결과가 0건인 것과 422 는 다르다).
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&currency_code='.$target)
            ->assertOk();

        // 한 번도 등록된 적 없는 코드는 여전히 거절한다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&currency_code=ZZZ')
            ->assertStatus(422);
    }

    /**
     * tombstone(`removed_default_currencies`)은 **기본 제공 통화**의 삭제만 남긴다. 운영자가
     * 직접 추가한 통화를 내리면 설정에 아무 흔적도 남지 않으므로, 설정 어휘만 보는 게이트는
     * 그 통화로 동결된 신청서를 다시 도달 불가로 만든다. 마지막 근거는 실제로 기록된 값이다.
     *
     * @effects currency_gate_accepts_codes_recorded_on_claims_even_when_settings_dropped_them, currency_gate_rejects_codes_never_used
     *
     * @return void 반환값 없음
     */
    public function test_list_accepts_currency_the_admin_added_and_then_removed(): void
    {
        $settings = app(EcommerceSettingsService::class);

        $baseCode = $settings->getSetting('language_currency.default_currency');
        $currencies = collect($settings->getSetting('language_currency.currencies', []));
        $this->assertNotContains('GBP', $currencies->pluck('code')->all(), 'GBP 는 기본 제공 통화가 아니어야 한다.');

        // 운영자가 직접 추가한 통화 (defaults.json 밖 — tombstone 대상이 아니다)
        $added = array_merge((array) $currencies->first(), ['code' => 'GBP', 'is_default' => false]);
        $settings->saveSettings([
            'language_currency' => [
                'default_currency' => $baseCode,
                'currencies' => $currencies->push($added)->values()->all(),
            ],
        ]);

        // 그 통화로 신청서가 동결된다.
        $exchange = $this->makeExchange();
        $exchange->currency_code = 'GBP';
        $exchange->save();

        // 운영자가 그 통화를 다시 내린다.
        $settings->saveSettings([
            'language_currency' => [
                'default_currency' => $baseCode,
                'currencies' => $currencies->reject(fn ($c) => ($c['code'] ?? null) === 'GBP')->values()->all(),
            ],
        ]);

        $this->assertNotContains(
            'GBP',
            collect($settings->getSetting('language_currency.currencies', []))->pluck('code')->all(),
            '추가했던 통화가 내려가야 한다.'
        );
        $this->assertNotContains(
            'GBP',
            (array) $settings->getSetting('language_currency.removed_default_currencies', []),
            '관리자 추가 통화는 tombstone 되지 않는다 — 이 테스트의 전제다.'
        );

        // 설정 어느 곳에도 남지 않았지만, 그 값으로 기록된 신청서가 있으므로 도달 가능해야 한다.
        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=exchange&currency_code=GBP')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $exchange->exchange_number);

        // 어디에도 쓰인 적 없는 코드는 여전히 거절한다 (오타가 "결과 없음" 으로 위장하지 않는다).
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&currency_code=ZZZ')
            ->assertStatus(422);
    }

    /**
     * 청구 결제수단도 통화와 같은 규칙이다 — 설정 어휘를 벗어난 값은 실제 기록이 있을 때만
     * 통과한다. 길이만 검사하면 오타가 조용히 0건으로 통과해 필터가 걸린 것처럼 보인다.
     *
     * @effects charge_payment_gate_accepts_methods_recorded_on_claim_payments, charge_payment_gate_rejects_methods_never_used
     *
     * @return void 반환값 없음
     */
    public function test_list_gates_charge_payment_method_by_settings_then_records(): void
    {
        // 설정 어휘(`claim.charge_payment_methods` = card/point)는 그대로 통과한다.
        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=exchange&charge_payment_methods[]=card')
            ->assertOk();

        // 설정에도 없고 기록도 없는 값은 거절한다.
        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=exchange&charge_payment_methods[]=never_used_method')
            ->assertStatus(422);

        // 같은 값으로 추가결제가 기록되면 도달 가능해진다 (운영자가 수단을 내린 뒤에도 남는 건).
        $exchange = $this->makeExchange();
        ClaimPayment::create([
            'payable_type' => ClaimTypeEnum::EXCHANGE->value,
            'payable_id' => $exchange->id,
            'order_id' => $exchange->order_id,
            'payment_number' => 'CP-GATE-1',
            'payment_method' => 'never_used_method',
            'payment_status' => 'pending',
            'amount' => 3000,
            'currency_code' => $exchange->currency_code,
        ]);

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=exchange&charge_payment_methods[]=never_used_method')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $exchange->exchange_number);
    }

    /**
     * 추가결제 수단 필터는 결제 행을 가진 건만 집는다. 추가결제가 성립하지 않는 유형(환불·취소)
     * 에는 축 자체가 없어 422 다.
     *
     * @effects list_filters_by_charge_payment_method
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_by_charge_payment_method(): void
    {
        $paid = $this->makeExchange();
        ClaimPayment::create([
            'payable_type' => ClaimTypeEnum::EXCHANGE->value,
            'payable_id' => $paid->id,
            'order_id' => $paid->order_id,
            'payment_number' => 'CP-FILTER-1',
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'amount' => 6000,
            'currency_code' => $paid->currency_code,
        ]);
        $this->makeExchange();

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=exchange&charge_payment_methods[]=card')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.claim_number', $paid->exchange_number);

        // 추가결제가 성립하지 않는 유형에는 축 자체가 없다.
        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=refund&charge_payment_methods[]=card')
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->getJson($this->base().'/claims?type=cancel&charge_payment_methods[]=card')
            ->assertStatus(422);
    }

    /**
     * 취소는 REQUESTED 로 생성돼 파이프라인 완료 시 COMPLETED 로 간다. 중간·실패 경로의 미종결
     * 행이 목록에서 종결로 보이면 운영자가 처리할 건을 지나친다.
     *
     * @effects cancel_subtab_reports_is_final_from_the_cancel_status
     *
     * @return void 반환값 없음
     */
    public function test_cancel_subtab_reports_is_final_from_the_cancel_status(): void
    {
        $order = $this->deliveredClaimOrder();

        // 취소는 REQUESTED 로 생성된 뒤 파이프라인 완료 시 COMPLETED 로 전이한다 — 중간·실패
        // 경로의 미종결 행이 목록에서 종결(is_final=true)로 보이면 무음 오표시다.
        OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-TEST-REQ-1',
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::REQUESTED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=cancel')
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'requested')
            ->assertJsonPath('data.data.0.is_final', false);
    }

    /**
     * 클레임 읽기 권한이 없는 운영자에게 목록은 403 이다.
     *
     * @effects list_requires_the_claims_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_list_requires_the_claims_read_permission(): void
    {
        $noPermission = $this->createAdminUser([]);

        $this->actingAs($noPermission)->getJson($this->base().'/claims')->assertForbidden();
    }

    /**
     * 완료 처리 모달(D26)의 초기 선택값은 목록 응답이 나른다.
     *
     * 설정 엔드포인트(`admin/settings`)는 `settings.read` 게이트인데 이 화면의 라우트는
     * `claims.read` 다 — 기본 역할 `manager` 는 클레임 권한만 갖고 설정 권한이 없어, 모달이
     * 설정 응답에서 기본값을 읽으면 그 운영자에게만 조용히 도달하지 않는다. 그러면 상점이
     * 재고 복구를 꺼 두어도 모달은 켜진 채 열리고 그대로 실행된다(오류 없음).
     *
     * @effects completion_defaults_reach_claims_read_operator
     *
     * @return void 반환값 없음
     */
    public function test_list_carries_completion_defaults_for_a_claims_scoped_operator(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.complete_defaults', [
            'create_refund_request' => false,
            'restore_stock' => false,
        ]);

        // `$this->admin` 은 클레임 권한 2종만 가진 운영자다 — 설정 권한이 없다.
        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return')
            ->assertOk()
            ->assertJsonPath('data.complete_defaults.create_refund_request', false)
            ->assertJsonPath('data.complete_defaults.restore_stock', false);
    }

    /**
     * 설정이 비어 있어도 계약 기본값(둘 다 참)이 실린다 — 화면이 형태를 분기하지 않게.
     *
     * @effects completion_defaults_reach_claims_read_operator
     *
     * @return void 반환값 없음
     */
    public function test_list_completion_defaults_fall_back_to_the_contract_defaults(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.complete_defaults', []);

        $this->actingAs($this->admin)->getJson($this->base().'/claims')
            ->assertOk()
            ->assertJsonPath('data.complete_defaults.create_refund_request', true)
            ->assertJsonPath('data.complete_defaults.restore_stock', true);
    }

    /**
     * 불리언이 아닌 저장값도 형제 `claim.*` 설정과 같은 강도로 해석된다.
     *
     * 설정 저장 경로에는 이 키를 불리언으로 강제하는 규칙이 없다(단건 저장은 `present` 뿐이고
     * 정규화도 불리언을 캐스팅하지 않는다). 느슨하게 읽으면 무너지는 방향이 "끈 것을 켜진
     * 것으로" 라, 운영자가 꺼 둔 재고 복구·환불신청서가 의도 없이 실행된다.
     *
     * @effects completion_defaults_reach_claims_read_operator
     *
     * @return void 반환값 없음
     */
    public function test_list_completion_defaults_coerce_non_boolean_stored_values(): void
    {
        foreach ([['0', '0'], [0, 0], ['false', 'false']] as [$createRefund, $restoreStock]) {
            app(EcommerceSettingsService::class)->setSetting('claim.complete_defaults', [
                'create_refund_request' => $createRefund,
                'restore_stock' => $restoreStock,
            ]);

            $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return')
                ->assertOk()
                ->assertJsonPath('data.complete_defaults.create_refund_request', false)
                ->assertJsonPath('data.complete_defaults.restore_stock', false);
        }

        // 해석할 수 없는 값은 계약 기본값(참)으로 떨어진다 — 오타가 조용히 기능을 끄지 않게.
        app(EcommerceSettingsService::class)->setSetting('claim.complete_defaults', [
            'create_refund_request' => 'yes-please',
            'restore_stock' => 'yes-please',
        ]);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return')
            ->assertOk()
            ->assertJsonPath('data.complete_defaults.create_refund_request', true)
            ->assertJsonPath('data.complete_defaults.restore_stock', true);
    }

    // ================================================================
    // 상세
    // ================================================================

    /**
     * 관리자 확장 필드는 **추가만** 된다 — 같은 리소스를 쓰는 사용자 화면의 키가 빠지면 이쪽을
     * 넓히는 작업이 저쪽을 조용히 깬다.
     *
     * @effects show_returns_admin_fields_and_payment_block
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_admin_fields_and_payment_block(): void
    {
        $return = $this->makeReturn();

        $response = $this->actingAs($this->admin)->getJson($this->base()."/returns/{$return->id}");

        $response->assertOk()
            ->assertJsonPath('data.claim.claim_number', $return->return_number)
            ->assertJsonPath('data.claim.requester_name', $return->order->getOrdererName())
            ->assertJsonPath('data.claim.outstanding_amount', 0);

        // 관리자 확장 필드는 추가만 된다 — 유저 응답 키는 그대로 존재한다.
        $claim = $response->json('data.claim');
        $this->assertArrayHasKey('admin_memo', $claim);
        $this->assertArrayHasKey('processed_by', $claim);
        $this->assertArrayHasKey('payments', $claim);
        $this->assertArrayHasKey('items', $claim);
    }

    /**
     * 없는 신청서의 상세는 404 다.
     *
     * @effects show_is_404_for_a_missing_claim
     *
     * @return void 반환값 없음
     */
    public function test_show_is_404_for_a_missing_claim(): void
    {
        $this->actingAs($this->admin)->getJson($this->base().'/returns/999999')->assertNotFound();
    }

    // ================================================================
    // 단건 전이 — 승인·결제 상호작용
    // ================================================================

    /**
     * 청구가 없는 신청서의 승인은 승인으로 끝나고 처리자가 기록된다 — 결제 요청이 함께 일어나면
     * 청구액 0 원짜리 결제 대기 건이 목록에 선다.
     *
     * @effects status_approves_a_claim_without_outstanding_charge
     *
     * @return void 반환값 없음
     */
    public function test_status_approves_a_claim_without_outstanding_charge(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'approved')
            ->assertJsonPath('data.payment_requested', false);

        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);
        $this->assertSame($this->admin->id, $return->fresh()->processed_by);
    }

    /**
     * 승인 시 청구 건은 승인이 아니라 **결제 요청**으로 간다. 승인 시각이 비어 있어야 미수납 건이
     * 승인된 것으로 서지 않는다.
     *
     * @effects status_approve_with_pay_on_approval_outstanding_routes_to_payment_request
     *
     * @return void 반환값 없음
     */
    public function test_status_approve_with_pay_on_approval_outstanding_routes_to_payment_request(): void
    {
        $return = $this->makeReturn(chargeMethod: ClaimChargeMethodEnum::PAY_ON_APPROVAL);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.payment_requested', true)
            ->assertJsonPath('data.claim.status', 'payment_pending');

        // 승인이 아니라 결제 요청이 일어났다 — CP 결제 대기 건이 만들어지고 승인 시각은 비어 있다.
        $payment = ClaimPayment::query()
            ->where('payable_type', ClaimTypeEnum::RETURN->value)
            ->where('payable_id', $return->id)
            ->first();

        $this->assertNotNull($payment);
        $this->assertTrue($payment->isPending());
        $this->assertNull($return->fresh()->approved_at);
    }

    /**
     * 신청 시점 미수납 건을 승인하면 청구액이 회수되지 않은 채 진행된다 — 거절이 정답이고,
     * 상태도 그대로 남아야 한다.
     *
     * @effects status_approve_with_pay_on_request_outstanding_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_status_approve_with_pay_on_request_outstanding_is_rejected(): void
    {
        // 신청 시점 결제 미수납 건 — 승인하면 청구액이 회수되지 않은 채 진행되므로 거절한다.
        $order = $this->deliveredClaimOrder();
        ['claim' => $return] = app(UserClaimService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request',
        ], [], 'card');

        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'approved',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_outstanding'));

        $this->assertSame(ReturnStatusEnum::PAYMENT_PENDING, $return->fresh()->return_status);
    }

    /**
     * 환불금 차감 신청서에는 결제할 청구가 없다 — 결제 요청은 paymentNotRequired 로 거절된다.
     *
     * @effects status_payment_pending_without_charge_is_payment_not_required
     *
     * @return void 반환값 없음
     */
    public function test_status_payment_pending_without_charge_is_payment_not_required(): void
    {
        // 환불금 차감 신청서에는 결제할 청구가 없다 — 결제 요청은 paymentNotRequired 로 거절된다.
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'payment_pending',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_not_required'));

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    /**
     * `paid` 는 결제 확정이, `withdrawn` 은 신청자 행위가 소유한다. 전용 경로가 있는 상태를 직접
     * 지정하면 그 경로의 부수 처리를 건너뛴 채 상태만 바뀐다.
     *
     * @effects status_write_gate_rejects_owned_path_statuses
     *
     * @return void 반환값 없음
     */
    public function test_status_write_gate_rejects_owned_path_statuses(): void
    {
        $return = $this->makeReturn();

        // paid 는 결제 확정, withdrawn 은 신청자 행위 소유다 — 직접 지정은 검증 단계에서 막힌다.
        foreach (['paid', 'withdrawn', 'requested'] as $owned) {
            $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
                'status' => $owned,
            ])->assertStatus(422);
        }

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    /**
     * 쓰기 게이트를 지난 뒤에도 전이 게이트가 한 번 더 선다 — 두 층을 하나로 합치면 한쪽이
     * 열려도 초록이 된다.
     *
     * @effects status_transition_gate_rejects_an_impossible_jump
     *
     * @return void 반환값 없음
     */
    public function test_status_transition_gate_rejects_an_impossible_jump(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'collected',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
                'from' => ReturnStatusEnum::REQUESTED->label(),
                'to' => ReturnStatusEnum::COLLECTED->label(),
            ]));
    }

    /**
     * 거절 사유가 저장돼야 신청자가 왜 거절됐는지 본다 — 저장되지 않아도 거절 자체는 성공한다.
     *
     * @effects rejecting_a_return_stores_its_reason
     *
     * @return void 반환값 없음
     */
    public function test_status_rejection_stores_the_reject_reason(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'rejected',
            'reject_reason' => '재고 확인 결과 반품 대상이 아닙니다.',
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'rejected');

        $this->assertSame('재고 확인 결과 반품 대상이 아닙니다.', $return->fresh()->reject_reason);
    }

    // ================================================================
    // 완료 처리 (D26)
    // ================================================================

    /**
     * 완료 페이로드(재고 복구·환불신청서 생성) 없는 완료는 422 다. 받아 주면 운영자가 고르지
     * 않은 기본값으로 되돌릴 수 없는 처리가 일어난다.
     *
     * @effects status_completed_requires_the_completion_payload
     *
     * @return void 반환값 없음
     */
    public function test_status_completed_requires_the_completion_payload(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
        ])->assertStatus(422);

        $this->assertSame(ReturnStatusEnum::COLLECTED, $return->fresh()->return_status);
    }

    /**
     * 운영자가 환불신청서를 끄고 완료하면 환불 행이 생기지 않는다 — 끈 것이 켜지면 이중 환불의
     * 입구가 된다.
     *
     * @effects status_completed_without_refund_request_creates_no_order_refund
     *
     * @return void 반환값 없음
     */
    public function test_status_completed_without_refund_request_creates_no_order_refund(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => true],
                'create_refund_request' => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'completed');

        $this->assertSame(0, OrderRefund::query()->where('order_id', $return->order_id)->count());
        $this->assertNull($this->refundIdOf($return));
    }

    /**
     * 켜고 완료하면 환불 행이 **하나** 생기고 신청서가 그것을 가리킨다.
     *
     * @effects status_completed_with_refund_request_creates_the_order_refund
     *
     * @return void 반환값 없음
     */
    public function test_status_completed_with_refund_request_creates_the_order_refund(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => true],
                'create_refund_request' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'completed');

        $fresh = $return->fresh();
        $this->assertNotNull($this->refundIdOf($fresh));
        $this->assertSame(1, OrderRefund::query()->where('order_id', $return->order_id)->count());
    }

    // ================================================================
    // 일괄 전이 — 부분 성공 계약
    // ================================================================

    /**
     * 일괄 전이는 **부분 성공이 정상 결과**다. 건너뛴 건과 그 사유를 돌려주지 않으면 운영자는
     * 전건 성공으로 읽고, 남은 건은 아무도 다시 보지 않는다.
     *
     * @effects bulk_status_reports_partial_success_with_skip_reasons
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_reports_partial_success_with_skip_reasons(): void
    {
        $approvable = $this->makeReturn();
        $rejected = $this->makeReturn();
        app(OrderReturnService::class)->transition($rejected, ReturnStatusEnum::REJECTED);

        $response = $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$approvable->id, $rejected->id, 999999],
            'status' => 'approved',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.succeeded.0', $approvable->id)
            ->assertJsonPath('data.payment_requested', []);

        $skipped = collect($response->json('data.skipped'));
        $this->assertCount(2, $skipped);
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_not_found'),
            $skipped->firstWhere('id', 999999)['message']
        );
        $this->assertSame($rejected->return_number, $skipped->firstWhere('id', $rejected->id)['claim_number']);
        $this->assertSame(ReturnStatusEnum::APPROVED, $approvable->fresh()->return_status);
        $this->assertSame(ReturnStatusEnum::REJECTED, $rejected->fresh()->return_status);
    }

    /**
     * 읽기 권한만 가진 운영자는 일괄 전이를 할 수 없다.
     *
     * @effects bulk_status_requires_the_update_permission
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_requires_the_update_permission(): void
    {
        $readOnly = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($readOnly)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [1],
            'status' => 'approved',
        ])->assertForbidden();
    }

    /**
     * 형식이 틀린 목표 상태(배열)는 서버 오류가 아니라 입력 오류(422)로 돌아간다.
     *
     * 규칙이 실패해도 사후 검증(송장 필요 여부)은 돈다 — 목표 상태를 문자열로 바꾸던 사후 검증이 배열에서
     * 500 으로 멈췄다(16차 병합 후속 브라우저 실측, 트러블슈팅 `[case:backend-46]`). 오류 문구는 필드 키 대신
     * 칸 이름을 싣는다.
     *
     * @effects bulk_status_rejects_malformed_status_as_validation_error
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_rejects_malformed_status_as_validation_error(): void
    {
        $response = $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [1],
            'status' => ['approved'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertStringContainsString(
            __('sirsoft-ecommerce::validation.request_attributes.claim_status'),
            $response->json('errors.status.0')
        );
    }

    // ================================================================
    // 상품 단위 일괄 전이 (D25)
    // ================================================================

    /**
     * 다른 신청서의 상품 행은 서비스의 무음 탈락(「0 건 처리됨」)에 닿기 전에 422 로 드러난다.
     *
     * @effects option_bulk_rejects_items_of_another_claim_with_422
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_rejects_items_of_another_claim_with_422(): void
    {
        $mine = $this->makeReturn();
        $other = $this->makeReturn();
        $foreignItem = $other->fresh('returnOptions')->returnOptions->first();

        // 다른 신청서의 상품 행은 서비스의 무음 탈락("0건 처리됨")에 닿기 전에 422 로 드러난다.
        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$mine->id}/options/bulk-status", [
            'items' => [['id' => $foreignItem->id]],
            'status' => 'approved',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.id']);
    }

    /**
     * 상품 행이 전이되면 헤더가 따라간다(syncHeaderFromOptions) — 따라가지 않으면 목록의 상태와
     * 상세의 상품 상태가 갈린다.
     *
     * @effects option_bulk_transitions_items_and_syncs_the_header
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_transitions_items_and_syncs_the_header(): void
    {
        $return = $this->makeReturn();
        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'collection_ready',
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $item->id)
            ->assertJsonPath('data.skipped', []);

        // 단일 상품이 전이됐으므로 헤더가 따라간다 (syncHeaderFromOptions).
        $this->assertSame(ReturnStatusEnum::COLLECTION_READY, $return->fresh()->return_status);
    }

    /**
     * 옵션 단위 완료도 단건과 같은 완료 페이로드를 따른다 — 입구마다 계약이 다르면 그 입구로
     * 들어온 완료만 환불신청서 없이 끝난다.
     *
     * @effects option_bulk_completed_completes_the_selected_items
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_completed_completes_the_selected_items(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => false],
                'create_refund_request' => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $item->id)
            ->assertJsonPath('data.claim.status', 'completed');

        $this->assertSame(ReturnStatusEnum::COMPLETED, $item->fresh()->option_status);
        $this->assertSame(0, OrderRefund::query()->where('order_id', $return->order_id)->count());
    }

    /**
     * 스칼라 배열은 422 다 — 하위호환으로 받으면 스코프 분기가 둘이 되고, 배포 중 두 형태가
     * 섞여도 아무 데도 드러나지 않는다.
     *
     * @effects option_bulk_rejects_a_scalar_item_array_with_422
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_rejects_a_scalar_item_array_with_422(): void
    {
        $return = $this->makeReturn();
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [$item->id],
            'status' => 'approved',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0']);
    }

    /**
     * 같은 행을 두 줄로 보내면 각 줄이 따로 잔여를 보고 통과한 뒤 합계가 넘는다.
     *
     * @effects option_bulk_rejects_duplicate_item_ids_with_422
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_rejects_duplicate_item_ids_with_422(): void
    {
        $return = $this->makeReturn();
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id, 'quantity' => 1], ['id' => $item->id, 'quantity' => 1]],
            'status' => 'approved',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    /**
     * 환불신청서도 상품 단위 전이를 받는다 — 스코프는 환불 상품 테이블이다.
     *
     * 3항 연산자로 두 유형만 갈라내던 시절 환불은 **반품 테이블로 떨어져** 항상 422 이거나,
     * 우연히 같은 ID 가 있으면 남의 행을 통과시켰다.
     *
     * @effects option_bulk_transitions_refund_items_in_their_own_scope
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_transitions_refund_items_in_their_own_scope(): void
    {
        $refund = $this->makeRefundRequest();
        $item = $refund->fresh('refundOptions')->refundOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/refunds/{$refund->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $item->id);

        $this->assertSame('approved', $item->fresh()->option_status->value);
    }

    /**
     * 환불 축에서도 스코프는 따로 선다 — 한 유형에서 통과한 것이 다른 유형을 보증하지 않는다.
     *
     * @effects option_bulk_rejects_refund_items_of_another_claim_with_422
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_rejects_refund_items_of_another_claim_with_422(): void
    {
        $mine = $this->makeRefundRequest();
        $other = $this->makeRefundRequest();
        $foreignItem = $other->fresh('refundOptions')->refundOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/refunds/{$mine->id}/options/bulk-status", [
            'items' => [['id' => $foreignItem->id]],
            'status' => 'approved',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.id']);
    }

    /**
     * 수량을 실으면 그 행이 갈라지고, 응답이 `split` 로 갈린 결과를 돌려준다.
     *
     * @effects option_bulk_splits_an_item_by_quantity
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_splits_an_item_by_quantity(): void
    {
        $order = $this->deliveredClaimOrder(29000, 3);
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 3]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id, 'quantity' => 2]],
            'status' => 'rejected',
            'reject_reason' => '2개는 개봉 상품입니다.',
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $item->id)
            ->assertJsonPath('data.split.0.quantity', 2)
            ->assertJsonPath('data.split.0.remainder_quantity', 1);
    }

    /**
     * 교환신청서도 수량을 실어 상품 단위로 가른다.
     *
     * 교환은 `new_order_option_id`(재발송 주문옵션) 가드와 재고 승계가 붙은 **가장 복잡한
     * 유형**인데 API 층의 수량 지정 경로가 반품·환불로만 잠겨 있었다. 세 유형을 같은 형태로
     * 잠가 두지 않으면 한 유형에서만 스코프가 깨져도 드러나지 않는다.
     *
     * @effects option_bulk_splits_an_exchange_item_by_quantity
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_splits_an_exchange_item_by_quantity(): void
    {
        $order = $this->deliveredClaimOrder(29000, 3);
        $exchange = app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 3]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL,
        ]);
        $item = $exchange->fresh('exchangeOptions')->exchangeOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/exchanges/{$exchange->id}/options/bulk-status", [
            'items' => [['id' => $item->id, 'quantity' => 2]],
            'status' => 'rejected',
            'reject_reason' => '2개는 개봉 상품입니다.',
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $item->id)
            ->assertJsonPath('data.split.0.quantity', 2)
            ->assertJsonPath('data.split.0.remainder_quantity', 1);
    }

    /**
     * 교환 축에서도 다른 신청서의 상품 행은 422 다.
     *
     * 스코프 분기는 유형마다 따로 서므로 한 유형에서 통과한 것이 다른 유형을 보증하지 않는다.
     *
     * @effects option_bulk_rejects_exchange_items_of_another_claim_with_422
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_rejects_exchange_items_of_another_claim_with_422(): void
    {
        $mine = $this->makeExchange();
        $other = $this->makeExchange();
        $foreignItem = $other->fresh('exchangeOptions')->exchangeOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/exchanges/{$mine->id}/options/bulk-status", [
            'items' => [['id' => $foreignItem->id]],
            'status' => 'rejected',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.id']);
    }

    /**
     * 취소신청서는 전이 축이 없다 — 라우트 어휘에도 없지만 컨트롤러가 한 번 더 세운다.
     *
     * @effects option_bulk_is_not_available_for_cancel_claims
     *
     * @return void 반환값 없음
     */
    public function test_option_bulk_is_not_available_for_cancel_claims(): void
    {
        $this->actingAs($this->admin)->patchJson($this->base().'/cancels/1/options/bulk-status', [
            'items' => [['id' => 1]],
            'status' => 'approved',
        ])->assertStatus(404);
    }

    /**
     * 상품 행 리소스가 **행별** 전이 선택지를 싣는다 — 화면이 상태 어휘를 리터럴로 들지 않게.
     *
     * @effects claim_detail_items_carry_their_own_transition_options
     *
     * @return void 반환값 없음
     */
    public function test_claim_detail_items_carry_their_own_transition_options(): void
    {
        $return = $this->makeReturn();

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/returns/{$return->id}")
            ->assertOk();

        $options = $response->json('data.claim.items.0.transition_options');

        $this->assertIsArray($options);
        $this->assertNotSame([], $options);
        $this->assertContains('approved', array_column($options, 'value'));
        // 결제대기는 신청서 단위 축이라 상품 행 어휘에 서지 않는다.
        $this->assertNotContains('payment_pending', array_column($options, 'value'));
        $this->assertTrue($response->json('data.claim.items.0.is_splittable'));
    }

    // ================================================================
    // 완료 페이로드(`completion`) 계약 — 3진입점 동형 (D26)
    // ================================================================

    /**
     * 일괄 전이 진입점도 같은 `completion` 페이로드를 받고 같은 효과를 낸다.
     *
     * D26 은 "세 진입점이 모두 같은 모달을 연다" 가 핵심이라 셋이 **하나의 계약**을 써야 한다.
     * 단건(`status`)과 상품 단위(`options/bulk-status`)만 단언돼 있어 일괄(`claims/bulk-status`)의
     * 완료 축이 빠져도 전 스위트가 green 이었다 — 그 경로만 페이로드를 무시해도 드러나지 않는다.
     *
     * @effects bulk_status_completed_honors_the_same_completion_payload
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_completed_honors_the_same_completion_payload(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$return->id],
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => true],
                'create_refund_request' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $return->id);

        // '지금 접수' 를 골랐으므로 환불신청서가 1건 생긴다 — 단건 경로와 같은 결과다.
        $fresh = $return->fresh();
        $this->assertSame(ReturnStatusEnum::COMPLETED, $fresh->return_status);
        $this->assertNotNull($this->refundIdOf($fresh));
        $this->assertSame(1, OrderRefund::query()->where('order_id', $return->order_id)->count());
    }

    /**
     * 일괄 전이도 '접수하지 않음' 을 그대로 존중한다 (자동 접수 금지 — D26).
     *
     * @effects bulk_status_completed_without_refund_request_creates_no_order_refund
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_completed_without_refund_request_creates_no_order_refund(): void
    {
        $return = $this->moveReturnToCollected($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$return->id],
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => false],
                'create_refund_request' => false,
            ],
        ])->assertOk()
            ->assertJsonPath('data.succeeded.0', $return->id);

        $this->assertSame(0, OrderRefund::query()->where('order_id', $return->order_id)->count());
        $this->assertNull($this->refundIdOf($return));
    }

    /**
     * 완료 전이인데 `completion` 페이로드가 없으면 세 진입점 모두 422 다.
     *
     * 기본값 하드코딩 금지(D26)의 계약면이다 — 한 경로만 옵션을 임의 기본값으로 채우면
     * 운영자가 고르지 않은 재고 복구·환불 접수가 조용히 실행된다.
     *
     * @effects every_completion_entry_point_requires_the_completion_payload
     *
     * @return void 반환값 없음
     */
    public function test_every_completion_entry_point_requires_the_completion_payload(): void
    {
        $single = $this->moveReturnToCollected($this->makeReturn());
        $bulk = $this->moveReturnToCollected($this->makeReturn());
        $option = $this->moveReturnToCollected($this->makeReturn());
        $optionItem = $option->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$single->id}/status", [
            'status' => 'completed',
        ])->assertStatus(422);

        $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$bulk->id],
            'status' => 'completed',
        ])->assertStatus(422);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$option->id}/options/bulk-status", [
            'items' => [['id' => $optionItem->id]],
            'status' => 'completed',
        ])->assertStatus(422);

        // 셋 다 거절됐으므로 어느 신청서도 완료로 넘어가지 않았다.
        $this->assertSame(ReturnStatusEnum::COLLECTED, $single->fresh()->return_status);
        $this->assertSame(ReturnStatusEnum::COLLECTED, $bulk->fresh()->return_status);
        $this->assertSame(ReturnStatusEnum::COLLECTED, $option->fresh()->return_status);
    }

    // ================================================================
    // 송장
    // ================================================================

    /**
     * 회수 송장 저장은 송장만 기록하고 상태는 옮기지 않는다 (#599 후속 운영 결정 — 저장과 전이는 별개).
     * 예전에는 회수준비중에서 저장하면 회수중으로 함께 옮겼는데, 승인 상태에서 먼저 저장한 송장은
     * 전이를 끌지 못해 신청서가 회수준비중에 멈췄다.
     *
     * @effects collection_tracking_saves_without_moving_the_status
     *
     * @return void 반환값 없음
     */
    public function test_collection_tracking_saves_without_moving_the_status(): void
    {
        $return = $this->makeReturn();
        $service = app(OrderReturnService::class);
        $service->transition($return, ReturnStatusEnum::APPROVED);
        $service->transition($return->fresh(), ReturnStatusEnum::COLLECTION_READY);

        $carrier = ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);

        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/tracking", [
            'direction' => 'collection',
            'carrier_id' => $carrier->id,
            'tracking_number' => '1234567890',
            'items' => [['id' => $item->id]],
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'collection_ready')
            ->assertJsonPath('data.claim.items.0.collection_tracking_number', '1234567890')
            ->assertJsonPath('data.claim.items.0.status', 'collection_ready');
    }

    /**
     * 재발송 송장 저장도 같은 형태다 — 재발송준비중에서 저장해도 발송중으로 옮기지 않는다.
     *
     * @effects redelivery_tracking_saves_without_moving_the_status
     *
     * @return void 반환값 없음
     */
    public function test_redelivery_tracking_saves_without_moving_the_status(): void
    {
        // 왕복 배송비 0원 교환 — 미수납 청구 없이 승인부터 재발송준비중까지 진행시킨다.
        $order = $this->deliveredClaimOrder(exchangeFee: 0);
        $exchange = app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]);

        $service = app(OrderExchangeService::class);
        $exchange = $exchange->fresh();

        foreach ([
            ExchangeStatusEnum::APPROVED,
            ExchangeStatusEnum::COLLECTION_READY,
            ExchangeStatusEnum::COLLECTING,
            ExchangeStatusEnum::COLLECTED,
            ExchangeStatusEnum::REDELIVERY_READY,
        ] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        $carrier = ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);

        $item = $exchange->fresh('exchangeOptions')->exchangeOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/exchanges/{$exchange->id}/options/tracking", [
            'direction' => 'redelivery',
            'carrier_id' => $carrier->id,
            'tracking_number' => '9876543210',
            'items' => [['id' => $item->id]],
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'redelivery_ready')
            ->assertJsonPath('data.claim.items.0.redelivery_tracking_number', '9876543210');
    }

    /**
     * 택배사와 송장번호는 **쌍**이다 — 한쪽만 받으면 조회 불가능한 송장이 등록된 것으로 선다.
     *
     * @effects collection_tracking_rejects_an_incomplete_pair
     *
     * @return void 반환값 없음
     */
    public function test_collection_tracking_rejects_an_incomplete_pair(): void
    {
        $return = $this->approveReturn($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/tracking", [
            'direction' => 'collection',
            'tracking_number' => '1234567890',
            'items' => [['id' => $item->id]],
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_tracking_incomplete'));
    }

    // ================================================================
    // 운영자 대리 첨부 / 첨부 일괄 정리
    // ================================================================

    /**
     * 운영자 대리 첨부의 업로드와 일괄 정리가 실제 첨부 목록에 반영되는지 잠근다 — 응답만
     * 성공하고 목록이 그대로면 화면에서는 지워진 것처럼 보인다.
     *
     * @effects admin_uploads_and_clears_claim_attachments
     *
     * @return void 반환값 없음
     */
    public function test_admin_uploads_and_clears_claim_attachments(): void
    {
        $return = $this->makeReturn();

        $upload = $this->actingAs($this->admin)->post($this->base()."/returns/{$return->id}/images", [
            'image' => UploadedFile::fake()->image('evidence.jpg'),
        ]);

        $upload->assertCreated()
            ->assertJsonPath('data.image.claimable_type', 'return')
            ->assertJsonPath('data.image.claimable_id', $return->id);

        $this->assertCount(1, app(ClaimAttachmentService::class)->listFor(ClaimTypeEnum::RETURN, $return->id));

        $this->actingAs($this->admin)->deleteJson($this->base()."/returns/{$return->id}/images")
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertCount(0, app(ClaimAttachmentService::class)->listFor(ClaimTypeEnum::RETURN, $return->id));
    }

    /**
     * 없는 신청서에 첨부를 올리면 404 다 — 받아 주면 주인 없는 첨부가 저장소에 쌓인다.
     *
     * @effects image_upload_is_404_for_a_missing_claim
     *
     * @return void 반환값 없음
     */
    public function test_image_upload_is_404_for_a_missing_claim(): void
    {
        $this->actingAs($this->admin)->post($this->base().'/returns/999999/images', [
            'image' => UploadedFile::fake()->image('evidence.jpg'),
        ])->assertNotFound();
    }

    /**
     * 공용 업로더는 파일을 `file` 로 보낸다 — 종전 계약(`image`)만 받으면 무조건 422 다.
     *
     * @effects admin_upload_accepts_the_shared_uploader_field_name
     *
     * @return void 반환값 없음
     */
    public function test_admin_upload_accepts_the_shared_uploader_field_name(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->post($this->base()."/returns/{$return->id}/images", [
            'file' => UploadedFile::fake()->image('evidence.jpg'),
        ])
            ->assertCreated()
            // 업로더는 응답을 `data.data ?? data` 로 읽어 **첨부 하나**로 해석한다.
            ->assertJsonPath('data.claimable_type', 'return')
            ->assertJsonPath('data.original_filename', 'evidence.jpg')
            // 기존 호출부를 위한 별칭도 한 마이너 동안 함께 실린다.
            ->assertJsonPath('data.image.claimable_type', 'return');
    }

    /**
     * 운영자가 올린 첨부는 **운영자 업로드**로 기록된다.
     *
     * @effects admin_upload_is_recorded_as_a_staff_upload
     *
     * @return void 반환값 없음
     */
    public function test_admin_upload_is_recorded_as_a_staff_upload(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->post($this->base()."/returns/{$return->id}/images", [
            'file' => UploadedFile::fake()->image('evidence.jpg'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.uploader_type', ClaimImageUploaderTypeEnum::ADMIN->value)
            ->assertJsonPath('data.uploader_type_label', ClaimImageUploaderTypeEnum::ADMIN->label());
    }

    /**
     * 첨부 한 장만 지운다 — 전체 삭제만 있으면 개인정보 사진 하나를 지우려고 정상 증빙까지 잃는다.
     *
     * @effects admin_deletes_a_single_attachment
     *
     * @return void 반환값 없음
     */
    public function test_admin_deletes_a_single_attachment(): void
    {
        $return = $this->makeReturn();
        $service = app(ClaimAttachmentService::class);

        $first = $service->uploadFor(
            UploadedFile::fake()->image('a.jpg'), ClaimTypeEnum::RETURN, $return->id, $this->admin->id, ClaimImageUploaderTypeEnum::ADMIN
        );
        $service->uploadFor(
            UploadedFile::fake()->image('b.jpg'), ClaimTypeEnum::RETURN, $return->id, $this->admin->id, ClaimImageUploaderTypeEnum::ADMIN
        );

        $this->actingAs($this->admin)
            ->deleteJson($this->base()."/returns/{$return->id}/images/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertCount(1, $service->listFor(ClaimTypeEnum::RETURN, $return->id));
    }

    /**
     * 다른 신청서의 첨부 id 를 넣으면 404 다.
     *
     * 권한 게이트는 "클레임을 다룰 수 있다" 만 말한다 — 소유권을 따로 묻지 않으면 아무
     * 신청서 상세에서나 남의 첨부를 지울 수 있다.
     *
     * @effects deleting_an_attachment_of_another_claim_is_404
     *
     * @return void 반환값 없음
     */
    public function test_deleting_an_attachment_of_another_claim_is_404(): void
    {
        $mine = $this->makeReturn();
        $other = $this->makeReturn();
        $service = app(ClaimAttachmentService::class);

        $foreign = $service->uploadFor(
            UploadedFile::fake()->image('a.jpg'), ClaimTypeEnum::RETURN, $other->id, $this->admin->id, ClaimImageUploaderTypeEnum::ADMIN
        );

        $this->actingAs($this->admin)
            ->deleteJson($this->base()."/returns/{$mine->id}/images/{$foreign->id}")
            ->assertNotFound();

        $this->assertCount(1, $service->listFor(ClaimTypeEnum::RETURN, $other->id));
    }

    /**
     * 상세 봉투가 첨부 상한·허용 확장자를 싣는다 — 화면이 리터럴로 적으면 설정을 바꾼
     * 순간 화면과 서버가 갈린다.
     *
     * @effects claim_detail_carries_attachment_settings
     *
     * @return void 반환값 없음
     */
    public function test_claim_detail_carries_attachment_settings(): void
    {
        $return = $this->makeReturn();

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/returns/{$return->id}")
            ->assertOk();

        $settings = $response->json('data.attachment_settings');

        $this->assertIsArray($settings);
        $this->assertSame(app(ClaimAttachmentService::class)->maxImages(), $settings['max_images']);
        $this->assertSame(app(ClaimAttachmentService::class)->maxSizeKb(), $settings['image_max_size_kb']);
        $this->assertSame(ClaimAttachmentService::ALLOWED_EXTENSIONS, $settings['allowed_extensions']);
        $this->assertStringContainsString('.jpg', (string) $settings['accept']);
        $this->assertStringNotContainsString('image/*', (string) $settings['accept']);
    }

    // ================================================================
    // 신청서 상태 변경 칸 — 심사 선택지만, 눌러도 거절될 선택지는 싣지 않는다 (#599 후속)
    // ================================================================

    /**
     * 신청서 단위 칸의 선택지를 상세 응답에서 값 목록으로 꺼냅니다.
     *
     * @param  string  $segment  유형 세그먼트 (returns/exchanges/refunds)
     * @param  int  $id  신청서 ID
     * @return array<int, string> 선택지 값
     */
    protected function headerOptionValues(string $segment, int $id): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/{$segment}/{$id}")
            ->assertOk();

        return array_column((array) $response->json('data.transition_options'), 'value');
    }

    /**
     * 회수·발송 단계와 완료는 신청서 칸에 서지 않는다 — 상품이 움직이는 단계는 상품 칸의 몫이고,
     * 완료는 [완료 처리] 가 유일한 입구다. 예전에는 회수도착 반품에 「반품완료」 가 서서 누르면
     * "완료 모달에서 진행해주세요" 안내만 뜨고 아무 일도 일어나지 않았다.
     *
     * @effects claim_header_options_exclude_logistics_and_completion
     *
     * @return void 반환값 없음
     */
    public function test_header_options_exclude_logistics_and_completion(): void
    {
        $collected = $this->moveReturnToCollected($this->makeReturn());
        $this->assertSame([], $this->headerOptionValues('returns', $collected->id));

        $approved = app(OrderReturnService::class)->transition($this->makeReturn(), ReturnStatusEnum::APPROVED);
        $this->assertSame([], $this->headerOptionValues('returns', $approved->id));
    }

    /**
     * 고객이 더 낼 돈이 없는 신청서에는 「결제대기」 가 서지 않는다 — 누르면 `claim_payment_not_required`
     * 로 거절된다. 낼 돈이 있는 신청서에는 그대로 선다(운영 결정: 결제대기는 남긴다).
     *
     * @effects claim_header_payment_pending_only_when_money_is_owed
     *
     * @return void 반환값 없음
     */
    public function test_header_payment_pending_only_when_money_is_owed(): void
    {
        $deduct = $this->makeReturn();
        $this->assertNotContains('payment_pending', $this->headerOptionValues('returns', $deduct->id));
        $this->assertContains('approved', $this->headerOptionValues('returns', $deduct->id));

        $owed = $this->makeExchange();
        $this->assertContains('payment_pending', $this->headerOptionValues('exchanges', $owed->id));
    }

    /**
     * 신청 시점 결제(`pay_on_request`)의 미수납 건에는 「승인」 이 서지 않는다 — 누르면
     * `claim_payment_outstanding` 으로 거절된다. 승인 시점 결제는 승인이 결제 요청으로 이어지므로 선다.
     *
     * @effects claim_header_hides_approval_blocked_by_outstanding_payment
     *
     * @return void 반환값 없음
     */
    public function test_header_hides_approval_blocked_by_outstanding_payment(): void
    {
        $onRequest = $this->makeExchange(null, ClaimChargeMethodEnum::PAY_ON_REQUEST);
        $this->assertNotContains('approved', $this->headerOptionValues('exchanges', $onRequest->id));

        $onApproval = $this->makeExchange(null, ClaimChargeMethodEnum::PAY_ON_APPROVAL);
        $this->assertContains('approved', $this->headerOptionValues('exchanges', $onApproval->id));
    }

    /**
     * 상품 일부가 신청서보다 앞서 있으면 신청서 칸에 「반려」 가 서지 않는다 — 신청서 단위 반려는
     * 살아 있는 상품 전부가 반려로 갈 수 있어야 하고(`assertLivingItemsCanTransitionTo`), 앞선
     * 상품이 있으면 `claim_transition_not_allowed` 로 거절된다.
     *
     * @effects claim_header_hides_rejection_when_items_are_ahead
     *
     * @return void 반환값 없음
     */
    public function test_header_hides_rejection_when_items_are_ahead(): void
    {
        $order = $this->deliveredClaimOrder(29000, 3);
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 3]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $this->assertContains('rejected', $this->headerOptionValues('returns', $return->id));

        $item = $return->fresh('returnOptions')->returnOptions->first();
        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id, 'quantity' => 1]],
            'status' => 'approved',
        ])->assertOk();

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertNotContains('rejected', $this->headerOptionValues('returns', $return->id));
    }

    /**
     * 상세 응답이 지금 완료할 수 있는 상품 수를 싣는다 — [완료 처리] 버튼의 활성·안내 건수가
     * 이 값을 읽는다. 회수도착 전에는 0 이다(예전에는 버튼이 켜져 있어 모달까지 열린 뒤 거절됐다).
     *
     * @effects claim_detail_carries_completable_item_count
     *
     * @return void 반환값 없음
     */
    public function test_claim_detail_carries_completable_item_count(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->getJson($this->base()."/returns/{$return->id}")
            ->assertOk()
            ->assertJsonPath('data.completable_item_count', 0);

        $this->moveReturnToCollected($return);

        $this->actingAs($this->admin)->getJson($this->base()."/returns/{$return->id}")
            ->assertOk()
            ->assertJsonPath('data.completable_item_count', 1);
    }

    // ================================================================
    // 반려 사유 — 세 입구(신청서 칸·상품 칸·목록 일괄) 모두 사유 없이는 반려하지 않는다
    // ================================================================

    /**
     * 사유 없는 반려는 세 입구 모두 422 다 — 비워 보내면 구매자 화면의 반려 사유가 빈 칸으로 남는다.
     *
     * @effects claim_rejection_requires_reason_on_every_entry
     *
     * @return void 반환값 없음
     */
    public function test_rejection_requires_reason_on_every_entry(): void
    {
        $return = $this->makeReturn();
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(422)->assertJsonValidationErrors(['reject_reason']);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'rejected',
            'reject_reason' => '   ',
        ])->assertStatus(422)->assertJsonValidationErrors(['reject_reason']);

        $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$return->id],
            'status' => 'rejected',
        ])->assertStatus(422)->assertJsonValidationErrors(['reject_reason']);

        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
    }

    /**
     * 상품 칸 반려의 사유는 반려된 상품 행에 남고(S3), 상품 전부를 반려해 신청서가 따라 반려될 때는
     * 신청서에도 남는다(`syncHeaderFromOptions` → `stampFor(REJECTED)`). 일부만 반려해 신청서가 그대로면
     * 신청서 사유는 비어 있다 — 나머지 상품이 승인돼도 구매자 화면에 반려 사유가 남던 결함.
     *
     * @effects claim_item_rejection_reason_reaches_the_claim
     *
     * @return void 반환값 없음
     */
    public function test_item_rejection_reason_reaches_the_claim(): void
    {
        $whole = $this->makeReturn();
        $wholeItem = $whole->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$whole->id}/options/bulk-status", [
            'items' => [['id' => $wholeItem->id]],
            'status' => 'rejected',
            'reject_reason' => '사용 흔적이 있어 반품할 수 없습니다.',
        ])->assertOk();

        $whole->refresh();
        $this->assertSame(ReturnStatusEnum::REJECTED, $whole->return_status);
        $this->assertSame('사용 흔적이 있어 반품할 수 없습니다.', $whole->reject_reason);

        $order = $this->deliveredClaimOrder(29000, 3);
        $partial = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 3]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $partialItem = $partial->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$partial->id}/options/bulk-status", [
            'items' => [['id' => $partialItem->id, 'quantity' => 1]],
            'status' => 'rejected',
            'reject_reason' => '1개는 개봉 상품입니다.',
        ])->assertOk();

        $partial->refresh();
        $this->assertSame(ReturnStatusEnum::REQUESTED, $partial->return_status);
        $this->assertNull($partial->reject_reason);
        $this->assertSame('1개는 개봉 상품입니다.', OrderReturnOption::find($partialItem->id)->reject_reason);
        $this->assertSame(ReturnStatusEnum::REJECTED, OrderReturnOption::find($partialItem->id)->option_status);
    }

    /**
     * 목록 일괄 반려의 사유가 고른 신청서 전부에 들어간다.
     *
     * @effects claim_bulk_rejection_stores_reason_on_every_claim
     *
     * @return void 반환값 없음
     */
    public function test_bulk_rejection_stores_reason_on_every_claim(): void
    {
        $first = $this->makeReturn();
        $second = $this->makeReturn();

        $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$first->id, $second->id],
            'status' => 'rejected',
            'reject_reason' => '반품 기간이 지났습니다.',
        ])->assertOk();

        $this->assertSame('반품 기간이 지났습니다.', $first->fresh()->reject_reason);
        $this->assertSame('반품 기간이 지났습니다.', $second->fresh()->reject_reason);
    }

    // ================================================================
    // 회수중·발송중 송장 필수 · 건너뛰기 · 완료 앞당기기 (#599 후속 운영 결정)
    // ================================================================

    /**
     * 승인·회수준비중 이후 단계로 반품을 옮깁니다. (서비스 경로)
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return OrderReturn 승인된 반품 신청서
     */
    protected function approveReturn(OrderReturn $return): OrderReturn
    {
        return app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);
    }

    /**
     * 왕복 배송비 0원 교환을 접수합니다. (미수납 청구 없이 승인부터 진행시킬 수 있다)
     *
     * @return OrderExchange 접수된 교환 신청서
     */
    protected function makeFreeExchange(): OrderExchange
    {
        $order = $this->deliveredClaimOrder(exchangeFee: 0);

        return app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ])->fresh();
    }

    /**
     * 상품 칸의 회수중은 택배사·운송장이 있어야 들어간다 — 없으면 422, 있으면 승인에서 곧바로
     * 회수중이 되고(건너뛰기) 송장이 신청서에 남으며 신청서도 한 번에 따라가 알림이 한 통만 난다.
     *
     * @effects claim_item_logistics_transition_requires_tracking
     *
     * @return void 반환값 없음
     */
    public function test_item_collecting_requires_tracking_and_can_skip_collection_ready(): void
    {
        $return = $this->approveReturn($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();
        $carrier = $this->testClaimCarrier();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'collecting',
        ])->assertStatus(422)->assertJsonValidationErrors(['carrier_id', 'tracking_number']);

        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);

        $seen = [];
        HookManager::addAction('sirsoft-ecommerce.claim.return.status_changed', function ($claim, $from, $to) use (&$seen) {
            $seen[] = "{$from}>{$to}";
        });

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'collecting',
            'carrier_id' => $carrier->id,
            'tracking_number' => ' CJ-1234 ',
        ])->assertOk();

        $fresh = $return->fresh('returnOptions');
        $this->assertSame(ReturnStatusEnum::COLLECTING, $fresh->return_status);
        $this->assertSame(ReturnStatusEnum::COLLECTING, $fresh->returnOptions->first()->option_status);
        $this->assertSame($carrier->id, (int) $fresh->returnOptions->first()->collection_carrier_id);
        $this->assertSame('CJ-1234', $fresh->returnOptions->first()->collection_tracking_number);
        // 회수준비중을 거치지 않았다 — 고객에게는 「회수중」 한 통만 나간다.
        $this->assertSame(['approved>collecting'], $seen);
    }

    /**
     * 신청서 전이 API 로 회수중을 지시해도 송장이 없으면 422 다 — 화면에서 숨긴 선택지라도 API 는 열려 있다.
     *
     * @effects claim_header_logistics_transition_requires_tracking
     *
     * @return void 반환값 없음
     */
    public function test_header_collecting_requires_tracking(): void
    {
        $return = $this->approveReturn($this->makeReturn());

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'collecting',
        ])->assertStatus(422)->assertJsonValidationErrors(['carrier_id', 'tracking_number']);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/status", [
            'status' => 'collecting',
            'carrier_id' => $this->testClaimCarrier()->id,
            'tracking_number' => 'HD-0001',
        ])->assertOk();

        $this->assertSame(ReturnStatusEnum::COLLECTING, $return->fresh()->return_status);
        $this->assertSame('HD-0001', $return->fresh('returnOptions')->returnOptions->first()->collection_tracking_number);
    }

    /**
     * 목록 일괄 회수중은 신청서마다 송장을 받는다 — 한 건이라도 빠지면 422, 모두 있으면 각자 자기 송장이 남는다.
     *
     * @effects claim_bulk_logistics_transition_requires_tracking_per_claim
     *
     * @return void 반환값 없음
     */
    public function test_bulk_collecting_requires_tracking_per_claim(): void
    {
        $first = $this->approveReturn($this->makeReturn());
        $second = $this->approveReturn($this->makeReturn());
        $carrier = $this->testClaimCarrier();

        $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$first->id, $second->id],
            'status' => 'collecting',
        ])->assertStatus(422)->assertJsonValidationErrors(['trackings']);

        $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$first->id, $second->id],
            'status' => 'collecting',
            'trackings' => [['id' => $first->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'A-1']],
        ])->assertStatus(422)->assertJsonValidationErrors(['trackings']);

        $this->actingAs($this->admin)->postJson($this->base().'/claims/bulk-status', [
            'type' => 'return',
            'ids' => [$first->id, $second->id],
            'status' => 'collecting',
            'trackings' => [
                ['id' => $first->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'A-1'],
                ['id' => $second->id, 'carrier_id' => $carrier->id, 'tracking_number' => 'B-2'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data.succeeded');

        $this->assertSame('A-1', $first->fresh('returnOptions')->returnOptions->first()->collection_tracking_number);
        $this->assertSame('B-2', $second->fresh('returnOptions')->returnOptions->first()->collection_tracking_number);
        $this->assertSame(ReturnStatusEnum::COLLECTING, $second->fresh()->return_status);
    }

    /**
     * 운송장 번호는 DB 칸 길이(50자)까지만 받는다 — 넘으면 저장이 SQL 오류(500)로 끊기던 것을 422 로 세운다.
     *
     * @effects claim_tracking_number_is_capped_at_the_column_length
     *
     * @return void 반환값 없음
     */
    public function test_tracking_number_is_capped_at_the_column_length(): void
    {
        $return = $this->approveReturn($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();
        $carrier = $this->testClaimCarrier();
        $tooLong = str_repeat('1', 51);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/tracking", [
            'direction' => 'collection',
            'carrier_id' => $carrier->id,
            'tracking_number' => $tooLong,
            'items' => [['id' => $item->id]],
        ])->assertStatus(422)->assertJsonValidationErrors(['tracking_number']);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'collecting',
            'carrier_id' => $carrier->id,
            'tracking_number' => $tooLong,
        ])->assertStatus(422)->assertJsonValidationErrors(['tracking_number']);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/tracking", [
            'direction' => 'collection',
            'carrier_id' => $carrier->id,
            'tracking_number' => str_repeat('1', 50),
            'items' => [['id' => $item->id]],
        ])->assertOk();
    }

    /**
     * 고객이 직접 보낸 물건은 승인에서 곧바로 회수도착이 된다 — 송장 없이도 되고 도착 시각이 남는다.
     *
     * @effects claim_approved_can_skip_to_collected_without_tracking
     *
     * @return void 반환값 없음
     */
    public function test_approved_return_can_skip_to_collected_without_tracking(): void
    {
        $return = $this->approveReturn($this->makeReturn());
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'collected',
        ])->assertOk();

        $fresh = $return->fresh();
        $this->assertSame(ReturnStatusEnum::COLLECTED, $fresh->return_status);
        $this->assertNotNull($fresh->collection_completed_at);
        $this->assertNull($fresh->collection_tracking_number);
    }

    /**
     * 회수중인 반품도 완료(선환불)할 수 있다 — 회수도착을 건너뛰므로 도착 기록을 완료 시각으로 남긴다.
     *
     * @effects return_completion_while_collecting_stamps_arrival
     *
     * @return void 반환값 없음
     */
    public function test_return_can_be_completed_while_collecting(): void
    {
        $return = app(OrderReturnService::class)->transition(
            $this->approveReturn($this->makeReturn()),
            ReturnStatusEnum::COLLECTING,
            $this->claimTrackingFor(ReturnStatusEnum::COLLECTING),
        );
        $item = $return->fresh('returnOptions')->returnOptions->first();

        $this->actingAs($this->admin)->getJson($this->base()."/returns/{$return->id}")
            ->assertOk()
            ->assertJsonPath('data.completable_item_count', 1);

        $this->actingAs($this->admin)->patchJson($this->base()."/returns/{$return->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'completed',
            'completion' => ['restore_stock_by_item' => [$item->id => false], 'create_refund_request' => false],
        ])->assertOk()->assertJsonPath('data.succeeded.0', $item->id);

        $fresh = $return->fresh();
        $this->assertSame(ReturnStatusEnum::COMPLETED, $fresh->return_status);
        $this->assertNotNull($fresh->collection_completed_at, '건너뛴 회수도착 기록이 남지 않았습니다.');
    }

    /**
     * 교환은 회수도착에서 곧바로 발송도착이 된다(직접 전달) — 발송도착이 하던 일(교환품 새 주문상품 생성·배송완료)이
     * 그대로 일어난다.
     *
     * @effects exchange_collected_can_skip_to_redelivered
     *
     * @return void 반환값 없음
     */
    public function test_exchange_collected_can_skip_to_redelivered(): void
    {
        $service = app(OrderExchangeService::class);
        $exchange = $this->makeFreeExchange();

        foreach ([ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTED] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        $item = $exchange->fresh('exchangeOptions')->exchangeOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/exchanges/{$exchange->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'redelivered',
        ])->assertOk();

        $fresh = $exchange->fresh('exchangeOptions');
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $fresh->exchange_status);
        $this->assertNotNull($fresh->redelivered_at);

        $successor = OrderOption::find($fresh->exchangeOptions->first()->new_order_option_id);
        $this->assertNotNull($successor, '발송도착으로 건너뛰었는데 교환품 새 주문상품이 없습니다.');
        $this->assertSame(OrderStatusEnum::DELIVERED, $successor->option_status);
        // 송장 없이 직접 건넨 교환품도 배송 행이 배송완료가 된다 — 송장이 없어 상태만 옮기는 경로다.
        $this->assertSame(ShippingStatusEnum::DELIVERED, OrderShipping::where('order_option_id', $successor->id)->first()?->shipping_status);
    }

    /**
     * 발송중인 교환품도 완료할 수 있다 — 발송도착을 건너뛰므로 새 주문상품을 배송완료로 옮기고 도착 기록을 남긴다.
     *
     * @effects exchange_completion_while_redelivering_marks_the_successor_delivered
     *
     * @return void 반환값 없음
     */
    public function test_exchange_can_be_completed_while_redelivering(): void
    {
        $service = app(OrderExchangeService::class);
        $exchange = $this->makeFreeExchange();

        foreach ([ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTED, ExchangeStatusEnum::REDELIVERING] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        $item = $exchange->fresh('exchangeOptions')->exchangeOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/exchanges/{$exchange->id}/options/bulk-status", [
            'items' => [['id' => $item->id]],
            'status' => 'completed',
            'completion' => ['restore_stock_by_item' => [$item->id => false], 'create_refund_request' => false],
        ])->assertOk()->assertJsonPath('data.succeeded.0', $item->id);

        $fresh = $exchange->fresh('exchangeOptions');
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $fresh->exchange_status);
        $this->assertNotNull($fresh->redelivered_at, '건너뛴 발송도착 기록이 남지 않았습니다.');
        $this->assertSame(
            OrderStatusEnum::DELIVERED,
            OrderOption::find($fresh->exchangeOptions->first()->new_order_option_id)->option_status,
        );
        $this->assertSame(
            ShippingStatusEnum::DELIVERED,
            OrderShipping::where('order_option_id', $fresh->exchangeOptions->first()->new_order_option_id)->first()?->shipping_status,
            '완료로 건너뛴 발송도착이 교환품 배송 행을 배송완료로 옮기지 않았습니다.',
        );
    }

    /**
     * 교환품이 발송도착하면 새 주문상품뿐 아니라 그 배송 행도 배송완료가 된다 — 구매자 주문상세의 「배송 현황」
     * 카드는 배송 행을 읽으므로, 배송 행을 두면 상품 줄은 배송완료인데 카드는 배송중으로 남는다(#599 후속 실주문 실측).
     *
     * @effects exchange_redelivered_marks_the_successor_shipping_delivered
     *
     * @return void 반환값 없음
     */
    public function test_exchange_redelivered_marks_the_successor_shipping_delivered(): void
    {
        $service = app(OrderExchangeService::class);
        $exchange = $this->makeFreeExchange();

        foreach ([ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTED, ExchangeStatusEnum::REDELIVERING, ExchangeStatusEnum::REDELIVERED] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        $successorId = $exchange->fresh('exchangeOptions')->exchangeOptions->first()->new_order_option_id;
        $shipping = OrderShipping::where('order_option_id', $successorId)->first();

        $this->assertSame(ShippingStatusEnum::DELIVERED, $shipping?->shipping_status);
        $this->assertNotNull($shipping?->delivered_at);
    }

    /**
     * 발송 뒤 재발송 송장을 정정하면 교환품 새 주문상품의 배송 조회 송장도 함께 고쳐진다 — 고객 주문 화면은
     * 배송 행을 읽으므로 신청서만 고치면 정정한 송장이 고객에게 닿지 않는다.
     *
     * @effects claim_redelivery_tracking_correction_reaches_the_successor_shipping
     *
     * @return void 반환값 없음
     */
    public function test_redelivery_tracking_correction_reaches_the_successor_shipping(): void
    {
        $service = app(OrderExchangeService::class);
        $exchange = $this->makeFreeExchange();

        foreach ([ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTED, ExchangeStatusEnum::REDELIVERING] as $step) {
            $exchange = $service->transition($exchange, $step, $this->claimTrackingFor($step));
        }

        $item = $exchange->fresh('exchangeOptions')->exchangeOptions->first();
        $successorId = $item->new_order_option_id;

        // 발송중 행의 송장 정정 — 그 상품 행의 후속 주문옵션 배송 행이 따라 바뀐다(R11).
        $this->actingAs($this->admin)->patchJson($this->base()."/exchanges/{$exchange->id}/options/tracking", [
            'direction' => 'redelivery',
            'carrier_id' => $this->testClaimCarrier()->id,
            'tracking_number' => 'FIXED-9999',
            'items' => [['id' => $item->id]],
        ])->assertOk();

        $this->assertSame('FIXED-9999', OrderShipping::where('order_option_id', $successorId)->first()?->tracking_number);
    }

    /**
     * 목록 응답이 택배사 선택지와 저장된 송장을 나른다 — 회수중·발송중 일괄 모달이 이 값으로 미리 채운다.
     * 송장은 상품 행에 있으므로 살아 있는 행이 모두 같은 송장일 때만 미리 채움(`tracking_prefill`)이 선다.
     * 배송사 엔드포인트는 `settings.read` 게이트라 클레임 권한만 가진 운영자에게는 목록 응답이 유일한 통로다.
     *
     * @effects claim_list_carries_carriers_and_saved_tracking
     *
     * @return void 반환값 없음
     */
    public function test_list_carries_carriers_and_saved_tracking(): void
    {
        $return = $this->approveReturn($this->makeReturn());
        $carrier = $this->testClaimCarrier();
        $itemId = (int) $return->fresh('returnOptions')->returnOptions->first()->id;
        app(OrderReturnService::class)->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'SAVED-1');

        $response = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return')->assertOk();

        $this->assertNotEmpty($response->json('data.carriers'));
        $row = collect($response->json('data.data'))->firstWhere('id', $return->id);
        $this->assertSame('SAVED-1', $row['tracking_prefill']['collection']['tracking_number']);
        $this->assertSame($carrier->id, $row['tracking_prefill']['collection']['carrier_id']);
        $this->assertSame('SAVED-1', $row['tracking_summary']['collection']['first']);
    }

    // ================================================================
    // 선교환 — 미회수 완료 · 회수 지연 필터 (D1~D3 · §3-5)
    // ================================================================

    /**
     * 교환품을 먼저 보낸(발송중) 선교환을 만듭니다. (왕복 배송비 0원 — 미수납 없이 승인)
     *
     * @return OrderExchange 발송중 선교환
     */
    protected function redeliveringAdvanceExchange(): OrderExchange
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);

        $service = app(OrderExchangeService::class);
        $exchange = $service->setExchangeMode($this->makeFreeExchange(), ExchangeModeEnum::ADVANCE);
        $exchange = $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED);

        return $service->transition($exchange, ExchangeStatusEnum::REDELIVERING, [
            'carrier_id' => $this->testClaimCarrier()->id,
            'tracking_number' => 'ADV-RD-'.$exchange->id,
        ])->fresh(['exchangeOptions']);
    }

    /**
     * 선교환의 헌 물건이 출발하지 않은 행은 완료 페이로드의 `completion.unreturned`(행 ID => 사유)로만 완료된다 —
     * 상품 단위·신청서 단위 두 완료 입구가 같은 페이로드를 미회수 완료로 옮긴다. 미회수 표시 없이 완료하거나
     * 사유가 비어 있으면 422 이고, 미회수 행은 재고 복구를 골라도 재고가 늘지 않는다(헌 물건이 오지 않았다).
     *
     * @effects advance_exchange_unreturned_completion_payload_reaches_the_service
     *
     * @return void 반환값 없음
     */
    public function test_advance_exchange_unreturned_completion_payload_reaches_the_service(): void
    {
        $exchange = $this->redeliveringAdvanceExchange();
        $item = $exchange->exchangeOptions->first();
        $productOptionId = (int) OrderOption::find($item->order_option_id)->product_option_id;
        $stockBefore = (int) ProductOption::find($productOptionId)->stock_quantity;
        $endpoint = $this->base()."/exchanges/{$exchange->id}/options/bulk-status";

        // 미회수 표시 없이는 완료할 수 없다 — 헌 물건이 출발하지 않았다.
        $this->actingAs($this->admin)->patchJson($endpoint, [
            'items' => [['id' => $item->id]],
            'status' => 'completed',
            'completion' => ['restore_stock_by_item' => [$item->id => true], 'create_refund_request' => false],
        ])->assertStatus(422);

        // 사유가 빈 미회수는 받지 않는다.
        $this->actingAs($this->admin)->patchJson($endpoint, [
            'items' => [['id' => $item->id]],
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => true],
                'create_refund_request' => false,
                'unreturned' => [$item->id => '   '],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_unreturned_reason_required'));

        $this->assertSame(ExchangeStatusEnum::REDELIVERING, OrderExchangeOption::find($item->id)->option_status);

        $this->actingAs($this->admin)->patchJson($endpoint, [
            'items' => [['id' => $item->id]],
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$item->id => true],
                'create_refund_request' => false,
                'unreturned' => [$item->id => '고객 연락 두절'],
            ],
        ])->assertOk()->assertJsonPath('data.succeeded.0', $item->id);

        $row = OrderExchangeOption::find($item->id);
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $row->option_status);
        $this->assertNotNull($row->unreturned_at);
        $this->assertSame('고객 연락 두절', $row->unreturned_reason);
        $this->assertFalse((bool) $row->is_stock_restored);
        $this->assertSame($stockBefore, (int) ProductOption::find($productOptionId)->stock_quantity, '미회수 행의 재고가 복구됐습니다.');
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $exchange->fresh()->exchange_status);

        // 신청서 단위 완료 입구도 같은 페이로드를 같은 뜻으로 받는다.
        $header = $this->redeliveringAdvanceExchange();
        $headerItem = $header->exchangeOptions->first();

        $this->actingAs($this->admin)->patchJson($this->base()."/exchanges/{$header->id}/status", [
            'status' => 'completed',
            'completion' => [
                'restore_stock_by_item' => [$headerItem->id => false],
                'create_refund_request' => false,
                'unreturned' => [$headerItem->id => '회수 거부'],
            ],
        ])->assertOk();

        $headerRow = OrderExchangeOption::find($headerItem->id);
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $headerRow->option_status);
        $this->assertSame('회수 거부', $headerRow->unreturned_reason);
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $header->fresh()->exchange_status);
    }

    /**
     * 완료 미리보기는 「미회수로 완료」 로 고른 행(`unreturned_item_ids`)을 실행과 같게 센다 — 완료 대상에 넣고
     * 재고 복구 수에서는 뺀다(D26 「미리보기 = 실행」 · D3). 고르지 않으면 헌 물건이 출발하지 않은 행은 완료
     * 대상이 아니고, 후보가 아닌 행 ID 는 조용히 빠진다(실행은 422 로 막는다).
     *
     * @effects advance_exchange_completion_preview_counts_unreturned_rows_like_execution
     *
     * @return void 반환값 없음
     */
    public function test_completion_preview_counts_unreturned_rows_like_execution(): void
    {
        $exchange = $this->redeliveringAdvanceExchange();
        $itemId = (int) $exchange->exchangeOptions->first()->id;
        $endpoint = $this->base().'/claims/completion-preview';
        $claim = ['id' => $exchange->id, 'restore_stock_by_item' => [$itemId => true]];

        // 미회수를 고르지 않으면 완료할 행이 없다 — 헌 물건이 출발하지 않았다.
        $this->actingAs($this->admin)->postJson($endpoint, [
            'claim_type' => 'exchange',
            'create_refund_request' => false,
            'claims' => [$claim],
        ])->assertOk()
            ->assertJsonPath('data.claims.0.completable_item_count', 0)
            ->assertJsonPath('data.claims.0.unreturned_eligible_items.0.id', $itemId);

        // 미회수로 고르면 완료 대상에 들고, 재고 복구를 켜 두어도 복구 수에는 들지 않는다.
        $this->actingAs($this->admin)->postJson($endpoint, [
            'claim_type' => 'exchange',
            'create_refund_request' => false,
            'claims' => [$claim + ['unreturned_item_ids' => [$itemId, 999999]]],
        ])->assertOk()
            ->assertJsonPath('data.claims.0.completable_item_ids', [$itemId])
            ->assertJsonPath('data.claims.0.completable_item_count', 1)
            ->assertJsonPath('data.claims.0.stock_restore_count', 0)
            ->assertJsonPath('data.claims.0.stock_discard_count', 1);
    }

    /**
     * 목록의 회수 지연 필터(`collection_overdue=1`)는 반송 기한이 지났는데 헌 물건이 출발하지 않은 선교환만 돌려준다 —
     * 회수 송장이 적힌 선교환·일반 교환은 빠진다. 선교환은 교환에만 있으므로 반품 탭에서 이 축은 422(prohibited)다.
     *
     * @effects list_collection_overdue_filter_returns_only_overdue_advance_exchanges
     *
     * @return void 반환값 없음
     */
    public function test_list_collection_overdue_filter_returns_only_overdue_advance_exchanges(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

        $overdue = $this->redeliveringAdvanceExchange();
        $onTime = $this->redeliveringAdvanceExchange();
        app(OrderExchangeService::class)->writeItemTracking(
            $onTime,
            ClaimTrackingDirectionEnum::COLLECTION,
            [$onTime->exchangeOptions->first()->id],
            $this->testClaimCarrier()->id,
            'CL-ON-TIME',
        );
        $standard = $this->makeFreeExchange();

        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00'));

        $all = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange')
            ->assertOk()->json('data.data');
        $this->assertEqualsCanonicalizing(
            [$overdue->exchange_number, $onTime->exchange_number, $standard->exchange_number],
            array_column($all, 'claim_number'),
        );

        $rows = $this->actingAs($this->admin)->getJson($this->base().'/claims?type=exchange&collection_overdue=1')
            ->assertOk()->json('data.data');
        $this->assertSame([$overdue->exchange_number], array_column($rows, 'claim_number'));
        $this->assertTrue((bool) $rows[0]['is_collection_overdue']);

        $this->actingAs($this->admin)->getJson($this->base().'/claims?type=return&collection_overdue=1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['collection_overdue']);
    }
}
