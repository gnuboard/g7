<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 결제 정보 리소스 테스트
 */
class OrderPaymentResourceTest extends ModuleTestCase
{
    /**
     * 무통장(dbank) 결제의 계좌 요약(account_info)이 은행명·계좌번호·예금주로 구성된다.
     *
     * 회귀 배경: getAccountInfo() 가 존재하지 않는 'bank_transfer' 케이스로 분기해
     * dbank 주문의 account_info 가 항상 null 이던 결함(실제 무통장 수단 값은 'dbank').
     */
    public function test_account_info_for_dbank_payment_summarizes_bank_account(): void
    {
        $payment = OrderPaymentFactory::new()->make([
            'payment_method' => PaymentMethodEnum::DBANK,
            'dbank_name' => '국민은행',
            'dbank_account' => '01026154146',
            'dbank_holder' => '정정홍',
        ]);

        $array = (new OrderPaymentResource($payment))->toArray(Request::create('/'));

        $this->assertSame('국민은행 01026154146 (정정홍)', $array['account_info']);
    }

    /**
     * 카드 결제의 계좌 요약은 카드사명 + 마스킹 카드번호 (+ 일시불/할부)로 구성된다.
     */
    public function test_account_info_for_card_payment_summarizes_card(): void
    {
        $payment = OrderPaymentFactory::new()->make([
            'payment_method' => PaymentMethodEnum::CARD,
            'card_name' => '신한카드',
            'card_number_masked' => '1234-****-****-5678',
            'card_installment_months' => 0,
        ]);

        $array = (new OrderPaymentResource($payment))->toArray(Request::create('/'));

        $this->assertStringContainsString('신한카드', $array['account_info']);
        $this->assertStringContainsString('1234-****-****-5678', $array['account_info']);
    }

    /**
     * 에스크로 여부가 API 로 노출된다.
     *
     * 회귀 배경(#454): is_escrow 는 DB·모델·부분취소 차단 로직(PaymentRefundListener)에는
     * 있었지만 Resource 가 내보내지 않아, 이 값을 참조하는 주문 상세 화면(관리자/회원)의
     * 에스크로 표시가 항상 false 로 평가되어 렌더되지 않았다.
     */
    public function test_is_escrow_is_exposed(): void
    {
        $escrow = OrderPaymentFactory::new()->make([
            'payment_method' => PaymentMethodEnum::VBANK,
            'is_escrow' => true,
        ]);

        $normal = OrderPaymentFactory::new()->make([
            'payment_method' => PaymentMethodEnum::VBANK,
            'is_escrow' => false,
        ]);

        $request = Request::create('/');

        $this->assertTrue((new OrderPaymentResource($escrow))->toArray($request)['is_escrow']);
        $this->assertFalse((new OrderPaymentResource($normal))->toArray($request)['is_escrow']);
    }
}
