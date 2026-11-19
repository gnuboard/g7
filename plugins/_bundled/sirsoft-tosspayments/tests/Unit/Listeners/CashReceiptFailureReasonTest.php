<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Listeners\RegisterCashReceiptProviderListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 현금영수증 발급 실패 사유의 원문 가림 (#858)
 *
 * 리스너가 돌려준 실패 사유는 현금영수증 이력에 남아 관리자 주문 상세 결제 정보와 구매자 응답에 그대로 보인다.
 * 토스페이먼츠가 오류 코드와 함께 돌려준 거절 사유는 그대로 싣고, 통신 예외 원문(결제대행사 API 주소·cURL 문구)은
 * 일반 문구로 바꾼다. 실제 API 서비스를 쓰고 HTTP 만 가짜로 바꾼다.
 *
 * 시나리오 매니페스트: `tests/scenarios/cash-receipt-failure-reason.yaml`
 */
class CashReceiptFailureReasonTest extends PluginTestCase
{
    private const PROVIDER_ID = 'tosspayments';

    /**
     * 운영 모드·한국어 로케일로 시작합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        app()->setLocale('ko');
    }

    /**
     * 현금영수증 발급을 실행하고 결과를 돌려줍니다.
     *
     * @return array<string, mixed> 리스너 발급 결과
     */
    private function issue(): array
    {
        return (new RegisterCashReceiptProviderListener)->issue(
            ['success' => false, 'error_code' => 'NO_PROVIDER_HANDLED', 'error_message' => 'no provider', 'receipt_key' => null, 'receipt_url' => null, 'issue_number' => null, 'raw_response' => null],
            Order::factory()->create(),
            self::PROVIDER_ID,
            [
                'type' => 'income',
                'identifier' => '01012345678',
                'identifier_type' => 'phone',
                'amount' => 11000,
                'tax_free_amount' => 0,
                'order_name' => '테스트 상품',
                'order_number' => 'ORD20261114858',
                'issue_sequence' => 1,
            ],
        );
    }

    /**
     * 통신 예외 원문은 이력 사유에 싣지 않고 일반 문구를 싣는다.
     *
     * @scenario case=issue_transport
     *
     * @effects cash_receipt_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_transport_failure_returns_generic_reason(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://api.tosspayments.com/v1/cash-receipts'));

        $result = $this->issue();

        $this->assertFalse($result['success']);
        $this->assertSame('결제대행사와 통신하는 중 오류가 발생해 현금영수증을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.', $result['error_message']);
    }

    /**
     * 토스페이먼츠가 오류 코드와 함께 거절하면 그 사유를 그대로 싣는다.
     *
     * @scenario case=issue_pg_rejected
     *
     * @effects cash_receipt_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_keeps_pg_reason(): void
    {
        Http::fake(['api.tosspayments.com/*' => Http::response(['code' => 'INVALID_REQUEST', 'message' => '현금영수증 발급 가능 금액을 초과했습니다.'], 400)]);

        $this->assertSame('현금영수증 발급 가능 금액을 초과했습니다.', $this->issue()['error_message']);
    }
}
