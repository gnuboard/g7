<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
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
 */
class ClaimControllerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

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

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        Mockery::close();
        parent::tearDown();
    }

    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 반품 신청서를 서비스 경로로 접수합니다. (기본: 환불금 차감 — 자동 승인 없이 REQUESTED 유지)
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
     * 반품 신청서를 회수도착까지 진행시킵니다. (완료 직전 상태)
     */
    protected function moveReturnToCollected(OrderReturn $return): OrderReturn
    {
        $service = app(OrderReturnService::class);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTING);

        return $service->transition($return, ReturnStatusEnum::COLLECTED);
    }

    // ================================================================
    // 목록
    // ================================================================

    /**
     * @effects list_returns_subtab_rows_with_counts_and_pagination
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
     * @effects list_defaults_to_the_exchange_subtab
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
     * @effects list_filters_by_status_array_and_gates_vocabulary_per_type
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
     * @effects list_searches_by_claim_number
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
     * @effects list_prohibits_type_foreign_filter_axes
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
     * @effects amount_range_reads_the_column_the_list_amount_shows, max_amount_alone_is_a_valid_combination
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
     * @effects refund_amount_range_sums_pg_and_mileage
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
     * @effects cancel_amount_range_reads_the_linked_refund
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
     * @effects amount_range_reads_the_column_the_list_amount_shows
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
     * @effects list_filters_by_charge_payment_method
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
     * @effects cancel_subtab_reports_is_final_from_the_cancel_status
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
     * @effects list_requires_the_claims_read_permission
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
     * @effects show_returns_admin_fields_and_payment_block
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
     * @effects show_is_404_for_a_missing_claim
     */
    public function test_show_is_404_for_a_missing_claim(): void
    {
        $this->actingAs($this->admin)->getJson($this->base().'/returns/999999')->assertNotFound();
    }

    // ================================================================
    // 단건 전이 — 승인·결제 상호작용
    // ================================================================

    /**
     * @effects status_approves_a_claim_without_outstanding_charge
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
     * @effects status_approve_with_pay_on_approval_outstanding_routes_to_payment_request
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
     * @effects status_approve_with_pay_on_request_outstanding_is_rejected
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
     * @effects status_payment_pending_without_charge_is_payment_not_required
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
     * @effects status_write_gate_rejects_owned_path_statuses
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
     * @effects status_transition_gate_rejects_an_impossible_jump
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
     * @effects rejecting_a_return_stores_its_reason
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
     * @effects status_completed_requires_the_completion_payload
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
     * @effects status_completed_without_refund_request_creates_no_order_refund
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
        $this->assertNull($return->fresh()->refund_id);
    }

    /**
     * @effects status_completed_with_refund_request_creates_the_order_refund
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
        $this->assertNotNull($fresh->refund_id);
        $this->assertSame(1, OrderRefund::query()->where('order_id', $return->order_id)->count());
    }

    // ================================================================
    // 일괄 전이 — 부분 성공 계약
    // ================================================================

    /**
     * @effects bulk_status_reports_partial_success_with_skip_reasons
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
     * @effects bulk_status_requires_the_update_permission
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

    // ================================================================
    // 상품 단위 일괄 전이 (D25)
    // ================================================================

    /**
     * @effects option_bulk_rejects_items_of_another_claim_with_422
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
     * @effects option_bulk_transitions_items_and_syncs_the_header
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
     * @effects option_bulk_completed_completes_the_selected_items
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
     * @effects option_bulk_rejects_refund_items_of_another_claim_with_422
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
        $this->assertNotNull($fresh->refund_id);
        $this->assertSame(1, OrderRefund::query()->where('order_id', $return->order_id)->count());
    }

    /**
     * 일괄 전이도 '접수하지 않음' 을 그대로 존중한다 (자동 접수 금지 — D26).
     *
     * @effects bulk_status_completed_without_refund_request_creates_no_order_refund
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
        $this->assertNull($return->fresh()->refund_id);
    }

    /**
     * 완료 전이인데 `completion` 페이로드가 없으면 세 진입점 모두 422 다.
     *
     * 기본값 하드코딩 금지(D26)의 계약면이다 — 한 경로만 옵션을 임의 기본값으로 채우면
     * 운영자가 고르지 않은 재고 복구·환불 접수가 조용히 실행된다.
     *
     * @effects every_completion_entry_point_requires_the_completion_payload
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
     * @effects collection_tracking_registers_and_moves_to_collecting
     */
    public function test_collection_tracking_registers_and_moves_to_collecting(): void
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

        $this->actingAs($this->admin)->putJson($this->base()."/returns/{$return->id}/collection-tracking", [
            'carrier_id' => $carrier->id,
            'tracking_number' => '1234567890',
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'collecting')
            ->assertJsonPath('data.claim.collection_tracking_number', '1234567890');
    }

    /**
     * @effects redelivery_tracking_registers_and_moves_to_redelivering
     */
    public function test_redelivery_tracking_registers_and_moves_to_redelivering(): void
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
            $exchange = $service->transition($exchange, $step);
        }

        $carrier = ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->putJson($this->base()."/exchanges/{$exchange->id}/redelivery-tracking", [
            'carrier_id' => $carrier->id,
            'tracking_number' => '9876543210',
        ])->assertOk()
            ->assertJsonPath('data.claim.status', 'redelivering')
            ->assertJsonPath('data.claim.redelivery_tracking_number', '9876543210');
    }

    /**
     * @effects collection_tracking_rejects_an_incomplete_pair
     */
    public function test_collection_tracking_rejects_an_incomplete_pair(): void
    {
        $return = $this->makeReturn();

        $this->actingAs($this->admin)->putJson($this->base()."/returns/{$return->id}/collection-tracking", [
            'tracking_number' => '1234567890',
        ])->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_tracking_incomplete'));
    }

    // ================================================================
    // 운영자 대리 첨부 / 첨부 일괄 정리
    // ================================================================

    /**
     * @effects admin_uploads_and_clears_claim_attachments
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
     * @effects image_upload_is_404_for_a_missing_claim
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
}
