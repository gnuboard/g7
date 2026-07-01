<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderPayment Resource 테스트
 *
 * 결제수단별 account_info 요약이 PaymentMethodEnum 기준으로 생성되는지 검증한다.
 */
class OrderPaymentResourceTest extends ModuleTestCase
{
    private function makePayment(PaymentMethodEnum $method, array $attributes = []): OrderPaymentResource
    {
        $order = OrderFactory::new()->create();
        $payment = OrderPaymentFactory::new()->forOrder($order)->create(array_merge([
            'payment_method' => $method,
        ], $attributes));

        return new OrderPaymentResource($payment);
    }

    public function test_card_account_info(): void
    {
        $resource = $this->makePayment(PaymentMethodEnum::CARD, [
            'card_name' => '신한카드',
            'card_number_masked' => '1234-****-****-5678',
            'card_installment_months' => 3,
        ]);

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame('신한카드 1234-****-****-5678 (3개월)', $array['account_info']);
    }

    public function test_vbank_account_info(): void
    {
        $resource = $this->makePayment(PaymentMethodEnum::VBANK, [
            'vbank_name' => '국민은행',
            'vbank_number' => '12345678901234',
        ]);

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame('국민은행 12345678901234', $array['account_info']);
    }

    /**
     * 무통장입금(DBANK) account_info 가 dbank_name/dbank_account 로 생성되는지 검증한다.
     *
     * 과거 'bank_transfer' 라는 PaymentMethodEnum 에 없는 문자열로 매칭해
     * 이 분기가 실행되지 않아 무통장입금 요약이 항상 비어 있던 회귀를 차단한다.
     */
    public function test_dbank_account_info(): void
    {
        $resource = $this->makePayment(PaymentMethodEnum::DBANK, [
            'dbank_name' => '우리은행',
            'dbank_account' => '1002-123-456789',
        ]);

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame('우리은행 1002-123-456789', $array['account_info']);
    }

    /**
     * vbank_due_at 은 머신 ISO8601 raw, vbank_due_at_formatted 는 사용자 타임존 표시용 문자열로 분리되는지 검증한다.
     *
     * order_complete 화면이 raw ISO(UTC) 를 그대로 노출하던 회귀를 차단한다.
     * (formatted 는 Y-m-d H:i:s 형식이라 'T' 구분자가 없어야 하고, raw 는 ISO8601 이라 'T' 를 포함한다.)
     */
    public function test_vbank_due_at_has_formatted_sibling(): void
    {
        $resource = $this->makePayment(PaymentMethodEnum::VBANK, [
            'vbank_name' => '국민은행',
            'vbank_number' => '12345678901234',
            'vbank_due_at' => '2026-02-07 23:59:59',
        ]);

        $array = $resource->toArray(Request::create('/'));

        // raw 는 머신 ISO8601 (PG 플러그인 JS injector 가 new Date() 로 파싱)
        $this->assertNotNull($array['vbank_due_at']);
        $this->assertStringContainsString('T', $array['vbank_due_at']);

        // formatted 는 사용자 타임존 표시용 Y-m-d H:i:s (구분자 'T' 없음)
        $this->assertNotNull($array['vbank_due_at_formatted']);
        $this->assertStringNotContainsString('T', $array['vbank_due_at_formatted']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $array['vbank_due_at_formatted']);
    }
}
