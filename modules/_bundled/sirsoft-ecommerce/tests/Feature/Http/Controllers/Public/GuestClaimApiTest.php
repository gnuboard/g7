<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 클레임 API Feature 테스트 (S5 · D8)
 *
 * 검증 축 (토큰 인증):
 * - 모든 클레임 경로가 `X-Guest-Order-Token` 없이는 404 (존재 여부 비노출)
 * - 신청 가능 옵션 / 미리보기 / 반품·환불 신청 / 이 주문의 클레임 목록 / 상세 / 수정 / 철회
 * - 첨부 업로드·삭제는 토큰 주문 소속만
 * - 마일리지 결제는 비회원에게 열리지 않는다 (422)
 * - 다른 주문의 토큰으로는 이 주문의 신청서에 닿지 않는다
 */
class GuestClaimApiTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const PASSWORD = 'guest12';

    private const PHONE = '010-1234-5678';

    /**
     * 통화·사유를 심고, PG 환불 훅과 첨부 저장소를 성공으로 고정합니다.
     *
     * 비회원 축이 재는 것은 **토큰이 지키는가·같은 계약을 쓰는가**이지 PG·디스크가 붙었는가가
     * 아니다 — 고정하지 않으면 전 축이 환경 때문에 같은 이유로 실패해 축이 무의미해진다.
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

        $storage = Mockery::mock(StorageInterface::class);
        $storage->allows('put')->andReturn(true)->byDefault();
        $storage->allows('delete')->andReturn(true)->byDefault();
        $storage->allows('exists')->andReturn(true)->byDefault();
        $storage->allows('getDisk')->andReturn('local')->byDefault();
        $storage->allows('response')->andReturn(null)->byDefault();
        $this->app->when(ClaimAttachmentService::class)->needs(StorageInterface::class)->give(fn () => $storage);
    }

    /**
     * 테스트가 심은 통화 설정과 목(mock)을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 배송완료 비회원 주문과 유효 토큰을 만듭니다.
     *
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  int  $quantity  주문옵션 수량
     * @param  array  $paymentOverrides  결제행에 덮어쓸 값 (결제수단 등)
     * @return array{0: Order, 1: string} [주문, 토큰]
     */
    protected function guestOrderWithToken(
        OrderStatusEnum $status = OrderStatusEnum::DELIVERED,
        int $quantity = 1,
        array $paymentOverrides = [],
    ): array {
        $order = $status === OrderStatusEnum::DELIVERED
            ? $this->deliveredClaimOrder(quantity: $quantity, paymentOverrides: $paymentOverrides)
            : $this->paidClaimOrder(quantity: $quantity, paymentOverrides: $paymentOverrides);
        $order->forceFill(['user_id' => null, 'guest_lookup_password_hash' => Hash::make(self::PASSWORD)])->save();
        OrderAddress::where('order_id', $order->id)->update(['orderer_phone' => self::PHONE]);

        if ($status !== OrderStatusEnum::DELIVERED) {
            $order = $this->moveOrderTo($order->fresh(['options']), $status);
        }

        $token = app(GuestOrderAuthService::class)->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.9')['token'];

        return [$order->fresh(['options', 'shippingAddress']), $token];
    }

    /**
     * 그 주문의 비회원 API 접두 경로를 돌려줍니다.
     *
     * @param  Order  $order  대상 주문
     * @return string API 접두 경로
     */
    protected function base(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}";
    }

    /**
     * 주문의 첫 옵션 하나를 신청 항목 배열로 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @param  int  $quantity  신청 수량
     * @return array<int, array{order_option_id: int, quantity: int}> 신청 항목 배열
     */
    protected function items(Order $order, int $quantity = 1): array
    {
        return [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]];
    }

    /**
     * 토큰 없이·잘못된 토큰으로는 비회원 클레임 경로 전부가 404 다.
     *
     * 한 경로만 열려 있어도 주문번호만 아는 사람이 그 주문의 클레임을 읽고 만들 수 있다.
     * 404 인 것은 의도다 — 403 이면 그 주문번호가 실재한다는 사실이 새어 나간다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, entry=without_token, screen=order_section
     *
     * @effects guest_claim_routes_are_token_protected
     */
    public function test_every_claim_route_is_token_protected(): void
    {
        [$order] = $this->guestOrderWithToken();

        $this->getJson($this->base($order).'/claimable-options')->assertNotFound();
        $this->postJson($this->base($order).'/claims/preview', ['type' => 'return', 'items' => $this->items($order)])->assertNotFound();
        $this->postJson($this->base($order).'/returns', ['items' => $this->items($order), 'reason_code' => 'changed_mind'])->assertNotFound();
        $this->getJson($this->base($order).'/claims')->assertNotFound();
        $this->postJson($this->base($order).'/claims/images', [])->assertNotFound();
        $this->postJson($this->base($order).'/claim-payments/CP1/pay', [])->assertNotFound();
        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => 'invalid-token'])->assertNotFound();
    }

    /**
     * 토큰이 있으면 작성 화면의 두 진입(신청 가능 상품·미리보기)이 열린다.
     *
     * 이 둘은 작성 화면이 그려지기 전에 부르는 유일한 쌍이다 — 한쪽만 살아 있으면 화면은
     * 상품 목록을 그리고 금액 칸만 빈다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=apply
     *
     * @effects guest_claim_datasources_carry_the_lookup_token
     */
    public function test_claimable_options_and_preview_work_with_the_token(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        $this->getJson($this->base($order).'/claimable-options', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.options.0.claimable_types', ['exchange', 'return']);

        $this->postJson($this->base($order).'/claims/preview', [
            'type' => 'return', 'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.total_charge_amount', 3000)
            ->assertJsonPath('data.requires_payment', false);
    }

    /**
     * 비회원 반품 접수는 신청자 없이 접수되고, 그 신청서를 비회원이 다시 열고 철회할 수 있다.
     *
     * `requested_by` 가 비는 것이 비회원 신청의 정의다 — 회원 경로와 서비스를 공유하므로
     * 이 자리에 값이 들어가면 남의 신청서가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=detail
     *
     * @effects guest_claim_routes_allow_guest_and_use_claim_number
     */
    public function test_guest_return_request_is_filed_without_a_requester(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        $response = $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token]);

        $response->assertCreated()
            ->assertJsonPath('data.claim.claim_type', 'return')
            ->assertJsonPath('data.claim.is_guest_request', true)
            ->assertJsonPath('data.claim.requested_by', null);

        $number = $response->json('data.claim.claim_number');

        // 이 주문의 클레임 목록 / 상세 / 수정 / 철회
        // 목록은 회원 신청내역과 같은 계약이다 — 유형 서브탭 하나(`?type=`)를 페이지 단위로 읽는다.
        $this->getJson($this->base($order).'/claims?type=return', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.claim_number', $number);

        // 상세는 회원과 같은 봉투다 — 진행단계 배열과 상태 아이콘이 같은 자리에 실린다.
        $detail = $this->getJson($this->base($order)."/claims/{$number}", ['X-Guest-Order-Token' => $token])
            ->assertOk()->assertJsonPath('data.claim.claim_number', $number)
            ->assertJsonPath('data.progress_steps.0.value', 'requested')
            ->assertJsonPath('data.progress_steps.0.icon', 'status-request');
        $this->assertSame(
            ReturnStatusEnum::from($detail->json('data.claim.status'))->icon(),
            $detail->json('data.claim.status_icon'),
            '상태 아이콘은 상태 Enum 이 정한 값이어야 합니다 — 뱃지와 스텝퍼가 같은 그림을 그립니다.'
        );

        $this->patchJson($this->base($order)."/claims/{$number}", ['reason_detail' => '비회원 수정'], ['X-Guest-Order-Token' => $token])
            ->assertOk()->assertJsonPath('data.claim.reason_detail', '비회원 수정');

        $this->postJson($this->base($order)."/claims/{$number}/withdraw", [], ['X-Guest-Order-Token' => $token])
            ->assertOk()->assertJsonPath('data.claim.status', 'withdrawn');
    }

    /**
     * 비회원 교환 접수는 왕복 배송비 청구로 결제 진입 4키와 함께 `payment_pending` 이 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, entry=with_token, screen=apply
     *
     * @effects guest_claim_routes_allow_guest_and_use_claim_number
     */
    public function test_guest_exchange_request_enters_payment_pending(): void
    {
        // 비회원 신청 3유형 중 교환만 이 스위트에 없었다 — 라우트를 지워도 전 스위트가 green 인
        // 상태였다. 교환은 왕복 배송비를 청구하므로 접수 즉시 결제 진입 4키가 함께 나가야 한다.
        $this->registerTestPgProvider();
        [$order, $token] = $this->guestOrderWithToken();

        $this->postJson($this->base($order).'/exchanges', [
            'items' => $this->items($order),
            'reason_code' => 'size_mismatch',
            'charge_method' => 'pay_on_request',
            'payment_method' => 'card',
        ], ['X-Guest-Order-Token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.claim.claim_type', 'exchange')
            ->assertJsonPath('data.claim.is_guest_request', true)
            ->assertJsonPath('data.claim.requested_by', null)
            ->assertJsonPath('data.claim.status', 'payment_pending')
            ->assertJsonPath('data.requires_pg_payment', true)
            ->assertJsonPath('data.pg_payment_handler', 'sirsoft-testpg.requestPayment')
            ->assertJsonPath('data.pg_payment_data.amount', 6000)
            ->assertJsonPath('data.payment.payment_status', 'pending');
    }

    /**
     * 비회원 추가결제의 승인 콜백이 결제를 확정한다 — 이 라우트가 죽어도 red 가 나지 않던 면.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, entry=with_token, screen=apply
     *
     * @effects guest_claim_payment_result_banner_stands_on_the_lookup_form
     */
    public function test_guest_pg_approval_confirms_the_claim_payment(): void
    {
        // 비회원 추가결제의 '확정' 경로. 시작(pay)은 실패 케이스만 있었고 승인 콜백은 호출조차
        // 되지 않아, 이 라우트가 죽어도 red 가 나지 않았다.
        $this->registerTestPgProvider();
        $this->fakeClaimPaymentApprove(true);
        [$order, $token] = $this->guestOrderWithToken();

        $result = app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request', 'requested_by' => null,
        ], [], 'card');
        $number = $result['payment']->payment_number;

        $this->postJson($this->base($order)."/claim-payments/{$number}/approve", [
            'callback' => ['paymentKey' => 'pk'],
        ], ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.payment.payment_status', 'paid');

        // 수납이 끝난 신청서는 자동 승인 여부와 무관하게 `paid` 를 거친다.
        $this->assertSame('paid', $result['claim']->fresh()->return_status->value);

        // 다른 주문의 토큰으로는 이 결제 건에 닿지 않는다.
        [$other, $otherToken] = $this->guestOrderWithToken();
        $this->postJson($this->base($other)."/claim-payments/{$number}/approve", [
            'callback' => ['paymentKey' => 'pk'],
        ], ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
    }

    /**
     * 비회원 카드 결제완료 주문의 환불신청은 `auto` 분기로 접수와 동시에 취소까지 간다.
     *
     * PG 로 되돌아가는 수단은 사람이 손댈 일이 없어 멈출 자리가 없다 — 회원 축과 같은 판정이다.
     *
     * @return void 반환값 없음
     *
     * @scenario item_selection=single_full, payment_state=card_paid
     *
     * @effects cancellation_record_is_created_only_on_refund_completion
     */
    public function test_guest_refund_request_branches_to_auto_advance(): void
    {
        [$order, $token] = $this->guestOrderWithToken(OrderStatusEnum::PAYMENT_COMPLETE);

        $this->postJson($this->base($order).'/refund-requests', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ], ['X-Guest-Order-Token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.branch', 'auto');

        $this->assertSame(OrderStatusEnum::CANCELLED, $order->fresh()->order_status);
    }

    /**
     * 비회원 무통장 결제완료 주문도 `auto` 분기에서 **환불신청 상태로 접수된다**. (P16)
     *
     * 비회원 경로가 회원 경로와 같은 술어를 지나는지 잠근다 — 접수 서비스는 하나지만 입구가
     * 둘이라, 한쪽만 게이트를 지나면 비회원 무통장 주문이 조용히 자동 완료된다.
     *
     * 계좌는 이 축과 직교한다 — 돈이 계좌로 돌아가는 주문이라 접수 시점에 받을 계좌가 있어야
     * 한다(T2-D5, 운영 결정 2026-09-23). 여기서는 계좌를 채워 `auto`/`requested` 축만 본다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_guest_api
     *
     * @effects stopped_payment_method_holds_refund_at_requested
     */
    public function test_guest_refund_request_of_a_bank_transfer_order_stops_at_requested(): void
    {
        [$order, $token] = $this->guestOrderWithToken(
            OrderStatusEnum::PAYMENT_COMPLETE,
            paymentOverrides: ['payment_method' => 'dbank'],
        );

        $this->postJson($this->base($order).'/refund-requests', [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'refund_bank' => ['bank_code' => '003', 'account_number' => '01012345678', 'holder' => 'MEAS599'],
        ], ['X-Guest-Order-Token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.branch', 'auto')
            ->assertJsonPath('data.claim.status', 'requested');

        $this->assertSame(
            OrderStatusEnum::PAYMENT_COMPLETE,
            $order->fresh()->order_status,
            '송금 전에는 주문을 취소로 닫지 않는다',
        );
    }

    /**
     * 상품 행이 소계와 단가의 보조 통화를 자기 값으로 들고 온다.
     *
     * 두 값 모두 정상 서식이라 옆 필드가 흘러들어도 화면에 흔적이 남지 않는다 —
     * 소계는 2026-09-01, 단가는 S3-D6 실측에서 각각 드러났다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, entry=with_token, screen=edit
     *
     * @effects guest_claim_cards_keep_shared_values
     */
    public function test_claim_item_rows_carry_the_subtotals_own_multi_currency(): void
    {
        // 화면은 상품 행에 소계(`subtotal_amount_formatted`)와 그 보조 통화를 나란히 그린다.
        // 소계의 보조 통화가 응답에 없으면 그 줄이 다른 필드로 흘러가는데, 두 값 모두 정상
        // 서식이라 어긋난 흔적이 남지 않는다 — 2026-09-01 실측에서 동일상품 교환이 화면에
        // `28,000원 / $0.00`(교환 차액) 으로 나갔고, 차액 필드가 없는 반품은 그 줄이 통째로 비었다.
        // 유형마다 저장 컬럼이 다르므로 네 유형 전부가 이 키를 실어야 같은 카드가 같은 것을 그린다.
        // 수량 3 중 2 만 신청한다 — 소계를 주문 수량으로 만들면 이 조합에서만 어긋나므로,
        // 수량 1 픽스처로는 그 축이 죽은 채 초록이 된다(이 테스트를 세울 때 실제로 그랬다).
        [$order, $token] = $this->guestOrderWithToken(quantity: 3);

        $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order, 2), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token])->assertCreated();

        $items = $this->getJson($this->base($order).'/claims?type=return', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->json('data.data.0.items');

        $this->assertNotEmpty($items, '반품 신청서의 상품 행이 비어 있으면 이 축을 잴 수 없다.');

        foreach ($items as $item) {
            $this->assertArrayHasKey('mc_subtotal_amount', $item);
            $this->assertNotSame([], $item['mc_subtotal_amount'], '소계의 보조 통화가 비면 화면의 그 줄이 통째로 사라진다.');

            $this->assertSame(2, $item['quantity'], '신청 수량이 주문 수량과 같으면 수량 축이 죽는다.');

            $orderOption = $order->options()->findOrFail($item['order_option_id']);
            $unitPrices = $orderOption->mc_unit_price ?? [];
            $this->assertNotEmpty($unitPrices, '주문옵션에 통화별 단가가 없으면 이 축의 기대값을 세울 수 없다.');

            // 단가의 보조 통화도 함께 내려야 한다 (S3-D6).
            //
            // 화면은 단가 줄 아래에 `claimItem.mc_unit_price` 로 보조 통화를 병기한다. 이 키가
            // 없으면 그 자리가 **다른 필드의 보조 통화로 흘러간다** — 교환 신청서 수정 화면에서
            // `28,000원` 줄에 교환 차액(-3,000)의 `$-3.00 / ¥-345` 가 붙었다(실측
            // EX20260915-0853360266). 서식이 정상이라 화면만 보면 흔적이 남지 않는다.
            // 바로 위 `mc_subtotal_amount` 가 2026-09-01 에 같은 이유로 추가됐고, 단가 자리만
            // 남아 있었다.
            $this->assertArrayHasKey('mc_unit_price', $item, '단가의 보조 통화가 없으면 화면이 다른 필드 값을 그린다.');
            $this->assertNotSame([], $item['mc_unit_price'], '단가의 보조 통화가 비면 그 줄이 사라지거나 옆 필드로 흘러간다.');

            foreach ($unitPrices as $code => $unitPrice) {
                $this->assertArrayHasKey($code, $item['mc_unit_price'], "통화 {$code} 의 단가가 빠졌다.");
                $this->assertEqualsWithDelta(
                    $unitPrice,
                    $item['mc_unit_price'][$code]['amount'],
                    0.01,
                    "통화 {$code} 의 단가가 주문옵션 단가와 다르다 — 다른 필드 값이 흘러들었을 수 있다."
                );
            }

            foreach ($unitPrices as $code => $unitPrice) {
                $this->assertArrayHasKey($code, $item['mc_subtotal_amount'], "통화 {$code} 의 소계가 빠졌다.");
                $this->assertArrayHasKey('formatted', $item['mc_subtotal_amount'][$code]);
                // 소계는 단가 × **클레임 수량**이다 — 주문 수량으로 만들면 부분 반품에서 어긋난다.
                $this->assertEqualsWithDelta(
                    $unitPrice * $item['quantity'],
                    $item['mc_subtotal_amount'][$code]['amount'],
                    0.01,
                    "통화 {$code} 의 소계가 단가×수량과 다르다.",
                );
            }
        }
    }

    /**
     * 토큰과 주문번호가 어긋나면 미들웨어가 신청서에 닿기 전에 404 로 막는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=detail
     *
     * @effects guest_claim_routes_are_token_protected
     */
    public function test_another_orders_token_cannot_reach_this_orders_claim(): void
    {
        [$order, $token] = $this->guestOrderWithToken();
        [$other, $otherToken] = $this->guestOrderWithToken();
        $number = $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token])->json('data.claim.claim_number');

        $this->getJson($this->base($other)."/claims/{$number}", ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
        $this->postJson($this->base($other)."/claims/{$number}/withdraw", [], ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
        // 토큰과 주문번호가 어긋나면 미들웨어가 404 로 막는다.
        $this->getJson($this->base($order)."/claims/{$number}", ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
    }

    /**
     * 첨부는 업로드한 주문의 토큰에만 열리고, 거절된 신청은 신청서를 남기지 않는다.
     *
     * 접수가 자체 트랜잭션으로 커밋된 뒤 첨부 귀속에서 거절하면 사용자는 422 를 보는데
     * 신청서는 살아남아 그 상품의 수량을 잠근다(재신청 불가).
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=apply
     *
     * @effects guest_claim_image_upload_is_bounded_more_tightly
     */
    public function test_guest_image_upload_attach_and_delete(): void
    {
        [$order, $token] = $this->guestOrderWithToken();
        [$other, $otherToken] = $this->guestOrderWithToken();

        $upload = $this->postJson($this->base($order).'/claims/images', [
            'image' => UploadedFile::fake()->image('claim.jpg'),
        ], ['X-Guest-Order-Token' => $token]);
        $upload->assertCreated()->assertJsonPath('data.claimable_type', ClaimImage::PENDING_CLAIMABLE_TYPE);
        $imageId = $upload->json('data.id');

        // 다른 주문의 토큰으로는 삭제할 수 없고, 신청에 귀속시킬 수도 없다.
        $this->deleteJson($this->base($other)."/claims/images/{$imageId}", [], ['X-Guest-Order-Token' => $otherToken])->assertNotFound();
        $this->postJson($this->base($other).'/returns', [
            'items' => $this->items($other), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'image_ids' => [$imageId],
        ], ['X-Guest-Order-Token' => $otherToken])->assertStatus(422);

        // 거절된 신청은 신청서를 남기지 않아야 한다 — 접수가 자체 트랜잭션으로 커밋된 뒤 첨부 귀속에서
        // 거절하면, 사용자는 422 를 보는데 신청서는 살아남아 그 상품의 수량 잠금까지 잡는다(재신청 불가).
        $this->assertSame(0, OrderReturn::where('order_id', $other->id)->count());

        $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'image_ids' => [$imageId],
        ], ['X-Guest-Order-Token' => $token])->assertCreated()->assertJsonPath('data.claim.images.0.id', $imageId);

        $this->assertSame('return', ClaimImage::find($imageId)->claimable_type);
    }

    /**
     * 비회원도 신청서에 붙은 첨부를 미귀속 삭제 경로(DELETE)로 지울 수 없다.
     *
     * 회원 경로와 같은 결함이 토큰 경로에도 있다 — 토큰만 있으면 접수된 신청서의 증빙과
     * 운영자가 올린 사진까지 지울 수 있었다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=edit
     *
     * @effects guest_cannot_delete_an_attached_image_through_the_pending_route
     */
    public function test_guest_cannot_delete_an_attached_image(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        $imageId = $this->postJson($this->base($order).'/claims/images', [
            'image' => UploadedFile::fake()->image('proof.jpg'),
        ], ['X-Guest-Order-Token' => $token])->assertCreated()->json('data.id');

        $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'image_ids' => [$imageId],
        ], ['X-Guest-Order-Token' => $token])->assertCreated();

        $this->deleteJson($this->base($order)."/claims/images/{$imageId}", [], ['X-Guest-Order-Token' => $token])->assertNotFound();

        $this->assertNotNull(ClaimImage::find($imageId), '신청서에 붙은 증빙이 토큰 경로로 지워지면 안 됩니다.');
    }

    /**
     * 주문상세의 「이 주문의 클레임」 목록이 상품명을 싣고, 사유 카탈로그를 1회만 읽는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=order_section
     *
     * @effects guest_order_claim_section_reuses_the_member_list_partial
     */
    public function test_guest_order_claim_list_carries_product_names_and_reads_reasons_in_one_query(): void
    {
        // 이 목록은 주문상세의 "이 주문의 클레임" 섹션을 그린다. 두 축이 조용히 깨질 수 있다:
        //  ① 목록이 `.orderOption` 을 안 실으면 `whenLoaded` 가 정상 동작한 결과로 상품명·썸네일이
        //     오류 없이 전부 null 이 된다 (회원 목록·상세는 싣는다).
        //  ② 사유 라벨을 행마다 되읽으면 목록 1회가 건수만큼의 추가 쿼리가 된다.
        [$order, $token] = $this->guestOrderWithToken(quantity: 2);
        $option = $order->options->first();

        // 시드 사유의 표시명은 코드와 같아 라벨 해석 실패를 가릴 수 있다 — 구분되는 이름을 준다.
        ClaimReason::where('type', 'return')->where('code', 'changed_mind')
            ->update(['name' => ['ko' => '단순 변심', 'en' => 'Changed mind']]);
        ClaimReason::where('type', 'return')->where('code', 'size_mismatch')
            ->update(['name' => ['ko' => '사이즈 불일치', 'en' => 'Size mismatch']]);

        foreach (['changed_mind', 'size_mismatch'] as $reason) {
            $this->postJson($this->base($order).'/returns', [
                'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
                'reason_code' => $reason,
                'charge_method' => 'deduct_from_refund',
            ], ['X-Guest-Order-Token' => $token])->assertCreated();
        }

        $reasonQueries = 0;
        DB::listen(function ($query) use (&$reasonQueries) {
            if (str_contains($query->sql, 'ecommerce_claim_reasons')) {
                $reasonQueries++;
            }
        });

        $rows = $this->getJson($this->base($order).'/claims?type=return', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->json('data.data');

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertNotNull(
                $row['items'][0]['product_name'],
                '비회원 목록의 상품명이 null 이면 화면이 빈 칸으로 뜬다 — 목록 조회가 `.orderOption` 을 싣는지 확인할 것.',
            );
        }

        // 테스트 로케일(en)의 표시명으로 해석되어야 한다 — 코드 그대로면 카탈로그 해석이 실패한 것이다.
        $this->assertSame(
            ['Changed mind', 'Size mismatch'],
            collect($rows)->pluck('reason_label')->sort()->values()->all(),
            '사유 라벨이 카탈로그 표시명으로 해석되지 않았습니다.',
        );

        // 사유 카탈로그 조회는 **행 수와 무관**해야 한다 — 이 응답에 실린 유형은 반품 하나뿐이므로
        // 묶음 조회면 1회다. 행마다 되읽으면 행 수(2)만큼 나온다.
        $this->assertSame(
            1,
            $reasonQueries,
            "사유 카탈로그 조회가 {$reasonQueries}회입니다 — 행 수에 비례하면 목록이 커질수록 쿼리가 늘어납니다.",
        );
    }

    /**
     * 비회원은 마일리지로 추가결제를 낼 수 없고, 거절된 요청은 결제행을 건드리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=apply
     *
     * @effects guest_claim_options_hide_the_payment_method_the_server_refuses
     */
    public function test_guest_cannot_pay_with_mileage(): void
    {
        [$order, $token] = $this->guestOrderWithToken();
        $result = app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'pay_on_request', 'requested_by' => null,
        ], [], 'card');

        $this->postJson($this->base($order)."/claim-payments/{$result['payment']->payment_number}/pay", ['payment_method' => 'point'], ['X-Guest-Order-Token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_point_payment_requires_member'));

        // 거절된 요청은 결제행을 건드리지 않아야 한다 — 가드가 쓰기 뒤에 있으면 422 를 받고도
        // 결제 수단이 마일리지(`amount=0`·`pg_provider=null`)로 바뀐 채 커밋된다.
        $fresh = $result['payment']->fresh();
        $this->assertSame('card', $fresh->payment_method);
        $this->assertSame(0.0, (float) $fresh->points_amount);
        $this->assertEqualsWithDelta((float) $result['payment']->amount, (float) $fresh->amount, 0.001);
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $fresh->payment_status);
    }

    /**
     * 마일리지로 낸 비회원 접수는 신청서도 결제행도 남기지 않고 422 로 끝난다.
     *
     * 가드가 결제행을 만든 뒤에만 서면 그 결제행은 다시 열리지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=apply
     *
     * @effects guest_claim_options_hide_the_payment_method_the_server_refuses
     */
    public function test_a_guest_requesting_with_mileage_leaves_no_claim_and_no_payment_row(): void
    {
        // 마일리지-회원 가드가 결제행을 **만든 뒤**에만 서면, 신청은 422 인데 신청서(수량 잠금)와
        // `point` 가 박힌 결제행이 커밋된다. 그 결제행은 클라이언트가 다른 수단을 명시하지 않는 한
        // 다시 열리지 않는다. FormRequest 는 허용 목록만 보므로 서비스가 유일한 판정 지점이다.
        [$order, $token] = $this->guestOrderWithToken();

        $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request',
            'payment_method' => 'point',
        ], ['X-Guest-Order-Token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_point_payment_requires_member'));

        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count(), '거절된 신청이 신청서를 남기면 그 수량이 잠겨 재신청이 막힙니다.');
        $this->assertSame(0, ClaimPayment::query()->count(), '거절된 신청이 결제행을 남기면 그 건은 다시 열리지 않습니다.');
    }

    /**
     * 비회원 섹션도 회원 목록과 같이 배송사 관계와 주문번호를 싣는다.
     *
     * 빠지면 오류 없이 전 행 null 이 되어 배송조회 열만 비고, 응답은 정상으로 보인다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=order_section
     *
     * @effects guest_order_claim_section_reuses_the_member_list_partial
     */
    public function test_the_order_claim_section_carries_the_carrier_name_and_order_number(): void
    {
        // 회원 목록은 `order`·배송사 관계를 싣는다. 비회원 섹션만 빠지면 오류 없이 그 필드가 전 행
        // null 이 되어 배송조회 열이 비고, 화면은 정상 응답을 받은 것으로 보인다.
        [$order, $token] = $this->guestOrderWithToken();
        $carrier = ShippingCarrier::query()->create([
            'code' => 'claim-track-test', 'name' => ['ko' => 'CJ대한통운'], 'type' => 'domestic',
            'tracking_url' => 'https://track.example.test/{tracking_number}',
            'is_active' => true, 'sort_order' => 1,
        ]);

        $result = app(UserClaimService::class)->requestReturn($order, [
            'items' => $this->items($order), 'reason_code' => 'changed_mind', 'charge_method' => 'deduct_from_refund', 'requested_by' => null,
        ]);
        // 송장은 신청서 헤더가 아니라 **상품 행**에 있다(#599 — 다중 송장).
        $result['claim']->returnOptions()->update([
            'collection_carrier_id' => $carrier->id,
            'collection_tracking_number' => '651234567890',
        ]);

        $response = $this->getJson($this->base($order).'/claims?type=return', ['X-Guest-Order-Token' => $token])->assertOk();

        $row = $response->json('data.data.0');
        $this->assertSame($order->order_number, $row['order_number']);
        $this->assertNotNull($row['items'][0]['collection_carrier_name'] ?? null, '배송사 관계가 없으면 이름이 무음 null 이 됩니다.');
        $this->assertSame('https://track.example.test/651234567890', $row['items'][0]['collection_tracking_url'] ?? null);
    }

    /**
     * 환불 계좌는 같은 토큰으로 열리는 모든 비회원 경로에서 같은 강도로 가려진다.
     *
     * 한 경로만 가리면 나머지가 그대로 우회로가 된다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, entry=with_token, screen=detail
     *
     * @effects guest_claim_cards_keep_shared_values
     */
    public function test_the_refund_bank_account_is_masked_on_every_guest_facing_path(): void
    {
        // 마스킹은 한 경로만 가려도 다른 경로가 그대로 우회로가 된다 — 같은 토큰으로 열리는
        // 주문상세와 클레임 응답이 **같은 강도**여야 한다.
        [$order, $token] = $this->guestOrderWithToken();

        $order->payment()->update([
            'refund_bank_code' => '004',
            'refund_bank_account' => '110123456789',
            'refund_bank_holder' => '홍길동',
        ]);

        $payment = $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}",
            ['X-Guest-Order-Token' => $token],
        )->assertOk()->json('data.payment');

        $this->assertSame('********6789', $payment['refund_bank_account']);
        $this->assertSame('홍**', $payment['refund_bank_holder']);
        $this->assertStringNotContainsString('110123456789', json_encode($payment, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 한 주문의 신청 목록은 유형별로 나뉘고 페이지 단위로 끊어 온다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=order_section
     *
     * @effects guest_order_claim_list_is_paginated_by_type
     */
    public function test_the_order_claim_list_is_paginated_and_scoped_to_one_type(): void
    {
        // 한 주문의 신청 건수가 그 주문의 상품 수에 묶인다는 전제는 성립하지 않는다 — 철회는
        // 행을 남기므로(수량 잠금만 풀린다) 신청↔철회를 반복하면 계속 쌓인다. 페이지네이션이
        // 없으면 그 전량이 한 응답에 실리고, 응답은 정상 200 이라 부풀었다는 사실이 드러나지 않는다.
        [$order, $token] = $this->guestOrderWithToken();
        $option = $order->options->first();

        for ($i = 0; $i < 3; $i++) {
            $result = app(UserClaimService::class)->requestReturn($order, [
                'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
                'reason_code' => 'changed_mind',
                'charge_method' => 'deduct_from_refund',
                'requested_by' => null,
            ]);

            // 철회해도 행은 남는다 — 다음 신청이 같은 수량을 다시 잠글 수 있게 풀어 준다.
            app(UserClaimService::class)->withdraw(ClaimTypeEnum::RETURN, $result['claim']);
        }

        $first = $this->getJson($this->base($order).'/claims?type=return&per_page=2', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.has_more_pages', true);

        $this->assertCount(2, $first->json('data.data'));

        $second = $this->getJson($this->base($order).'/claims?type=return&per_page=2&page=2', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.has_more_pages', false);

        $this->assertCount(1, $second->json('data.data'));

        // 두 페이지가 서로 다른 행을 싣는다 — 정렬이 전순서가 아니면 겹치거나 샌다.
        $this->assertEmpty(array_intersect(
            collect($first->json('data.data'))->pluck('claim_number')->all(),
            collect($second->json('data.data'))->pluck('claim_number')->all(),
        ));

        // 유형 서브탭이 곧 목록 정체성이다 — 다른 유형 탭에는 이 행들이 서지 않는다.
        $this->getJson($this->base($order).'/claims?type=exchange', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    /**
     * 유형을 지정하지 않으면 접수 건이 있는 첫 유형이 열린다 — 회원 주문상세 섹션과 같은 규칙.
     * 결제완료 주문의 취소·환불 신청은 즉시취소로 분기하므로 취소만 있는 주문이 된다 — 실사용 화면의
     * 스크린샷으로 지적된 바로 그 형태(취소 1건인데 교환 탭의 빈 안내가 먼저 보이던 것)다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=cancel, entry=with_token, screen=order_section
     *
     * @effects guest_order_claim_list_default_type_is_first_with_requests
     */
    public function test_the_order_claim_list_picks_the_first_type_with_requests_when_type_is_absent(): void
    {
        [$order, $token] = $this->guestOrderWithToken(OrderStatusEnum::PAYMENT_COMPLETE);

        $this->postJson($this->base($order).'/refund-requests', [
            'items' => $this->items($order), 'reason_code' => 'changed_mind',
        ], ['X-Guest-Order-Token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.branch', 'auto');

        // 미지정 → 우선순위상 취소가 먼저 열린다. **환불도 1건**이다(#599 후속 D6) —
        // 취소신청서와 환불신청서는 같은 사건의 두 사본이 아니라 다른 사실을 기록하는 별개
        // 문서이므로 한쪽이 다른 쪽을 대표하지 않는다. 예전에는 취소 발 환불을 환불 탭에서
        // 빼, 결제완료 상품을 환불한 고객에게 환불 이력이 아예 보이지 않았다.
        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'cancel')
            ->assertJsonPath('data.counts.cancel', 1)
            ->assertJsonPath('data.counts.refund', 1)
            ->assertJsonPath('data.counts.exchange', 0)
            ->assertJsonPath('data.data.0.claim_type', 'cancel');

        // 빈 문자열은 미지정과 같다 — 주소에 유형이 없을 때 화면이 이렇게 보낸다
        $this->getJson($this->base($order).'/claims?type=&page=1', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'cancel');

        // 지정하면 그 유형이다 — 지정한 유형을 서버가 바꾸지 않는다. 이 주문의 환불 탭에는
        // 자동 전이가 만든 환불신청서 1건이 서 있다(D6).
        $this->getJson($this->base($order).'/claims?type=refund', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'refund')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.data.0.claim_type', 'refund')
            ->assertJsonPath('data.counts.cancel', 1);

        // 신청이 한 건도 없는 유형은 여전히 비어 있다 — "지정하면 바꾸지 않는다" 의 원 의도다.
        $this->getJson($this->base($order).'/claims?type=exchange', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.claim_type', 'exchange')
            ->assertJsonPath('data.pagination.total', 0);
    }

    /**
     * 어휘 밖 유형은 422 로 거절하고, 유형을 생략하면 기본 서브탭으로 연다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=cancel, entry=with_token, screen=order_section
     *
     * @effects guest_order_claim_list_rejects_a_type_outside_the_vocabulary
     */
    public function test_the_order_claim_list_rejects_a_type_outside_the_vocabulary(): void
    {
        // 게이트가 없으면 오타·구버전 유형이 422 없이 빈 목록으로 응답되어, 사용자에게는
        // "해당 신청 없음" 과 구분되지 않는다 (회원 목록과 같은 계약 — 요청도 같은 한 벌이다).
        [$order, $token] = $this->guestOrderWithToken();

        $this->getJson($this->base($order).'/claims?type=nonexistent', ['X-Guest-Order-Token' => $token])
            ->assertStatus(422);

        // 유형을 생략하면 서브탭 기본값(교환)이다 — 회원 목록과 같은 기본값이어야 두 화면의
        // 첫 진입이 같은 탭을 연다.
        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);

        // 페이지 상한 거절의 **안내 문구가 실제 상한과 같아야 한다**. 주문 목록의 문구를 빌려
        // 쓰던 시절에는 이 목록의 상한이 50 인데 "최대 100" 이라고 안내했다 — 사용자는 그 수를
        // 믿고 다시 보내다 같은 거절을 받는다(2026-09-01 브라우저 실측).
        $message = $this->getJson($this->base($order).'/claims?type=return&per_page=51', ['X-Guest-Order-Token' => $token])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('50', (string) $message);
        $this->assertStringNotContainsString('100', (string) $message);
    }

    /**
     * 비회원에게는 쓸 수 없는 마일리지가 청구 결제수단 목록에서 빠진다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, entry=with_token, screen=apply
     *
     * @effects guest_claim_options_hide_the_payment_method_the_server_refuses
     */
    public function test_the_claimable_options_hide_mileage_from_a_guest(): void
    {
        // 마일리지는 회원 원장에서 차감하므로 비회원에게 열리지 않는다 — 그런데 화면은 이
        // 응답의 허용 목록을 그대로 라디오로 그린다. 목록에 남겨 두면 비회원에게 고를 수 없는
        // 수단이 보이고, 고르고 제출해야 422 로 거절된다(2026-09-01 브라우저 실측 — 신청서
        // 작성 화면에 '마일리지' 선택지가 서 있었다). 거절하는 지점과 보여 주는 목록은 같은
        // 판정을 써야 한다.
        [$order, $token] = $this->guestOrderWithToken();

        $methods = $this->getJson($this->base($order).'/claimable-options', ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->json('data.settings.charge_payment_methods');

        $this->assertIsArray($methods);
        $this->assertNotContains('point', $methods, '비회원에게 마일리지 결제 수단이 노출됩니다.');
        // 모집단이 비면 red — 목록을 통째로 비워도 통과하는 상태를 막는다.
        $this->assertNotEmpty($methods);
    }

    /**
     * 토큰 없는 404 는 돌아갈 조회 화면 주소를 함께 실어 온다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, entry=without_token, screen=detail
     *
     * @effects guest_claim_screens_clear_the_token_and_return_to_lookup
     */
    public function test_the_token_404_carries_the_lookup_screen_address(): void
    {
        // 이 404 를 받는 화면은 남은 토큰을 비우고 조회 폼으로 안내해야 하는데, 그 주소는
        // 상점 주소 설정(route_path/no_route)에 따라 달라져 화면이 스스로 조립할 수 없다.
        // 화면이 `_global.shopBase` 로 조립하는 폴백에 기대면 그 값이 서기 전에 해소되는
        // 응답(blocking 데이터소스)에서 `undefined/guest/orders` 라는 상대 경로가 만들어져
        // 없는 화면이 열린다(2026-09-01 브라우저 실측 — 신청서 상세에 토큰 없이 진입한 경로).
        [$order] = $this->guestOrderWithToken();

        foreach ([
            $this->base($order).'/claims',
            $this->base($order).'/claims/RT00000000-0001',
            $this->base($order).'/claimable-options',
        ] as $uri) {
            $response = $this->getJson($uri)->assertNotFound();

            $redirect = $response->json('errors.redirect_to');

            $this->assertIsString($redirect, "{$uri} 의 404 에 돌아갈 주소가 없습니다.");
            $this->assertStringStartsWith('/', $redirect, '상대 경로면 화면이 현재 주소 뒤에 이어 붙여 없는 화면으로 간다.');
            $this->assertStringEndsWith('/guest/orders', $redirect);
        }
    }

    /**
     * TTL 이 지난 토큰은 더 이상 클레임 경로에 닿지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, entry=expired_token, screen=order_section
     *
     * @effects guest_claim_routes_are_token_protected
     */
    public function test_an_expired_token_no_longer_reaches_the_claim_routes(): void
    {
        // 토큰은 HMAC 안에 만료시각을 담고 있어 서버 상태 없이 만료된다. TTL 이 지난 뒤에도
        // 열려 있으면 발급 한 번으로 그 주문의 클레임 경로가 영구히 열린 채가 된다.
        // 만료는 '없는 토큰' 과 같은 처리를 받는다 — 404 로 주문 존재 여부를 노출하지 않는다.
        [$order, $token] = $this->guestOrderWithToken();

        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])->assertOk();

        $this->travel(GuestOrderAuthService::TOKEN_TTL_MINUTES + 1)->minutes();

        $this->getJson($this->base($order).'/claims', ['X-Guest-Order-Token' => $token])->assertNotFound();
        $this->getJson($this->base($order).'/claimable-options', ['X-Guest-Order-Token' => $token])->assertNotFound();

        $this->travelBack();
    }

    /**
     * 없는 결제번호는 결제 전용 문구의 404 다 — 회원 축과 같은 트레이트를 쓰므로 함께 잠근다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, entry=with_token, screen=detail
     *
     * @effects guest_claim_screens_clear_the_token_and_return_to_lookup
     */
    public function test_missing_payment_is_404_with_the_payment_specific_message(): void
    {
        // 회원 축과 같은 문구여야 한다 — 두 축이 같은 트레이트를 쓰는데 한쪽만 고쳐지는 일이 실제로 있었다.
        // 기대 문구는 요청 뒤에 해석한다 — 요청 처리 중 로케일이 정해진다.
        [$order, $token] = $this->guestOrderWithToken();

        $this->postJson($this->base($order).'/claim-payments/CP-NOPE/pay', ['payment_method' => 'card'], ['X-Guest-Order-Token' => $token])
            ->assertNotFound()
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_not_found'));

        $this->postJson($this->base($order).'/claim-payments/CP-NOPE/approve', ['callback' => ['x' => 1]], ['X-Guest-Order-Token' => $token])
            ->assertNotFound()
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.claim_payment_not_found'));

        $this->assertNotSame(
            __('sirsoft-ecommerce::exceptions.claim_payment_not_found'),
            __('sirsoft-ecommerce::exceptions.claim_not_found'),
        );
    }

    // ================================================================
    // 취소 미리보기 비대칭 해소 (S3 2차 확정 ② — 비회원 면)
    // ================================================================

    /**
     * 클레임 코스에 잠긴 상품은 비회원 취소 미리보기도 422 다.
     *
     * 회원 축과 같은 이유다 — `previewRefund()` 기본 가드가 세 면에 새 422 경로를 만들었는데
     * 비회원 컨트롤러는 관리자와 다른 메시지 키(`order_cancel_failed`)를 쓴다. 이 면을
     * 단언하지 않으면 매핑이 끊겨도 회귀가 초록으로 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=refund, entry=with_token, screen=apply
     *
     * @effects guest_claim_datasources_carry_the_lookup_token
     */
    public function test_estimate_refund_is_422_for_items_locked_by_a_claim_course(): void
    {
        [$order, $token] = $this->guestOrderWithToken();

        $this->postJson($this->base($order).'/returns', [
            'items' => $this->items($order),
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
        ], ['X-Guest-Order-Token' => $token])->assertStatus(201);

        $response = $this->postJson($this->base($order).'/estimate-refund', [
            'items' => [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
        ], ['X-Guest-Order-Token' => $token]);

        $response->assertStatus(422);

        // 비회원도 회원·관리자와 같은 사유를 받는다 — 세 면이 같은 키를 쓴다.
        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.claim_option_in_course', [
                'status' => OrderStatusEnum::RETURN_IN_PROGRESS->label(),
            ]),
            $response->json('message'),
        );
    }
}
