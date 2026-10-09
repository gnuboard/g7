<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 환불 실행 확인 창의 계좌 줄·입력 칸은 「지금 고른 환불 수단으로 실행하면 돈이 계좌로 나가는가」로 선다.
 *
 * 종전 상세 응답은 **저장된 수단** 하나로 판정한 `refund_account_required` 만 내려, 운영자가 상세에서 수단을
 * 바꾸면 창이 어긋났다 — 「마일리지로 환불」로 바꿔도 저장 계좌 줄이 그대로 보였고, 계좌가 없는 신청서는 계좌
 * 칸이 뜨고 실행 버튼이 잠겼다(서버는 막지 않음). 판정은 실행 게이트와 같은 술어(`ClaimRefundAccountPolicy`)를
 * 수단별로 내려 화면이 고른다 — 화면이 결제수단 코드로 재현하면 확장 결제수단에서 갈린다. 입금된 가상계좌는
 * PG 환불이어도 PG 가 고객 계좌로 송금하므로 계좌가 필요하다.
 *
 * 시나리오 매니페스트: `tests/scenarios/claim-refund-account-by-method.yaml`
 */
class ClaimRefundAccountByMethodTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화를 준비하고 PG 환불 훅을 구독 상태로 둡니다 — 구독자가 없으면 카드 주문의 PG 환불이 계좌로 떨어진다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
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
     * 결제수단을 바꿔 끼운 결제완료 주문에 승인된 환불신청서를 붙입니다.
     *
     * @param  string  $paymentMethod  결제수단 코드
     * @param  string|null  $bankCode  신청서에 저장된 환불 은행 (없으면 null)
     * @return OrderRefund 환불신청서 (order·payment 로드됨)
     */
    private function refundFor(string $paymentMethod, ?string $bankCode = null): OrderRefund
    {
        $order = $this->paidClaimOrder();
        $order->payment?->forceFill(['payment_method' => $paymentMethod, 'pg_provider' => 'testpg'])->save();

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-'.uniqid(),
            'refund_status' => RefundStatusEnum::APPROVED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 10000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
            'refund_bank_code' => $bankCode,
            'refund_bank_account' => $bankCode ? '1234567890' : null,
            'refund_bank_holder' => $bankCode ? '홍길동' : null,
        ]);

        return $refund->fresh(['order.payment']);
    }

    /**
     * 상세 응답의 수단별 판정을 꺼냅니다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return array<string, array{used: bool, required: bool, required_if_cash: bool}> 수단 => 판정
     */
    private function byMethod(OrderRefund $refund): array
    {
        $array = (new ClaimResource($refund))->withReasonLabels([])->toArray(request());

        $this->assertArrayHasKey('refund_account_by_method', $array, '상세 응답에 수단별 계좌 판정이 없습니다.');

        return $array['refund_account_by_method'];
    }

    /**
     * 입금된 가상계좌 주문: 계좌 환불·PG 환불 모두 돈이 계좌로 나가고(계좌 필요), 마일리지 환불만 아니다.
     *
     * @scenario payment=vbank_deposited, stored_account=none
     *
     * @effects refund_account_judged_per_refund_method
     *
     * @return void 반환값 없음
     */
    public function test_deposited_virtual_account_needs_an_account_for_pg_and_bank(): void
    {
        $byMethod = $this->byMethod($this->refundFor('vbank'));

        $this->assertSame(['used' => true, 'required' => true, 'required_if_cash' => true], $byMethod[RefundMethodEnum::PG->value]);
        $this->assertSame(['used' => true, 'required' => true, 'required_if_cash' => true], $byMethod[RefundMethodEnum::BANK->value]);
        $this->assertSame(['used' => false, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::POINTS->value]);
    }

    /**
     * 카드 주문: PG 환불은 카드 취소라 계좌가 쓰이지 않고, 계좌 수동 환불을 고르면 계좌가 필요하다.
     *
     * @scenario payment=card, stored_account=none
     *
     * @effects refund_account_judged_per_refund_method
     *
     * @return void 반환값 없음
     */
    public function test_card_order_needs_an_account_only_for_bank_refund(): void
    {
        $byMethod = $this->byMethod($this->refundFor('card'));

        $this->assertSame(['used' => false, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::PG->value]);
        $this->assertSame(['used' => true, 'required' => true, 'required_if_cash' => true], $byMethod[RefundMethodEnum::BANK->value]);
        $this->assertSame(['used' => false, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::POINTS->value]);
    }

    /**
     * 계좌가 이미 적힌 신청서는 계좌가 쓰이는 수단에서도 다시 요구하지 않는다(줄은 보인다).
     *
     * @scenario payment=vbank_deposited, stored_account=present
     *
     * @effects refund_account_judged_per_refund_method
     *
     * @return void 반환값 없음
     */
    public function test_stored_account_is_shown_but_not_required_again(): void
    {
        $byMethod = $this->byMethod($this->refundFor('vbank', '004'));

        $this->assertSame(['used' => true, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::PG->value]);
        $this->assertSame(['used' => true, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::BANK->value]);
        $this->assertSame(['used' => false, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::POINTS->value]);
    }

    /**
     * 카드 주문에 계좌가 적혀 있어도 PG 환불(카드 취소)에서는 그 계좌 줄을 보이지 않는다 — 「마일리지로 환불」
     * 계좌 줄과 같은 결함의 PG 판이다. 계좌 수동 환불을 고르면 보이고, 이미 적혀 있어 다시 요구하지 않는다.
     *
     * @scenario payment=card, stored_account=present
     *
     * @effects refund_account_judged_per_refund_method
     *
     * @return void 반환값 없음
     */
    public function test_card_order_hides_the_stored_account_for_pg_refund(): void
    {
        $byMethod = $this->byMethod($this->refundFor('card', '004'));

        $this->assertSame(['used' => false, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::PG->value]);
        $this->assertSame(['used' => true, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::BANK->value]);
        $this->assertSame(['used' => false, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::POINTS->value]);
    }

    /**
     * 저장 현금이 0원(마일리지 몫만)인 신청서도 「현금이 있다면」 판정은 계좌를 요구한다 — 운영자가 현금을 입력하고 계좌로
     * 실행하면 실행 게이트가 본문 금액으로 계좌를 요구하므로, 화면이 그 판정을 입력값과 합쳐 계좌 칸을 세운다.
     *
     * @scenario payment=vbank_deposited, stored_account=none
     *
     * @effects refund_account_judged_per_refund_method
     *
     * @return void 반환값 없음
     */
    public function test_zero_cash_refund_still_reports_the_account_needed_once_cash_is_entered(): void
    {
        $refund = $this->refundFor('vbank');
        $refund->forceFill(['refund_amount' => 0, 'refund_points_amount' => 3000])->save();

        $byMethod = $this->byMethod($refund->fresh(['order.payment']));

        $this->assertSame(['used' => true, 'required' => false, 'required_if_cash' => true], $byMethod[RefundMethodEnum::BANK->value]);
        $this->assertSame(['used' => false, 'required' => false, 'required_if_cash' => false], $byMethod[RefundMethodEnum::POINTS->value]);
    }

    /**
     * 목록 행은 판정하지 않는다 — 행마다 주문·결제행을 읽는 N+1 을 만들지 않는다.
     *
     * @effects refund_account_judged_per_refund_method
     *
     * @return void 반환값 없음
     */
    public function test_list_rows_skip_the_per_method_judgement(): void
    {
        $refund = $this->refundFor('vbank');
        $array = (new ClaimResource($refund))->forList()->withReasonLabels([])->toArray(request());

        $this->assertNull($array['refund_account_by_method'] ?? null);
    }
}
