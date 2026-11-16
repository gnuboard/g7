<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterCashReceiptProviderListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 현금영수증 발급·취소 실패 사유의 원문 가림 (#858)
 *
 * 리스너가 돌려준 실패 사유는 현금영수증 이력에 남아 관리자 주문 상세 결제 정보와 구매자 응답에 그대로 보인다.
 * KG이니시스가 돌려준 거절 사유(resultMsg)는 그대로 싣고, 통신 예외 원문(결제대행사 API 주소·cURL 문구)은
 * 일반 문구로 바꾼다. 실제 API 서비스를 쓰고 HTTP 만 가짜로 바꿔 서비스가 던지는 예외 종류까지 검증한다.
 *
 * 시나리오 매니페스트: `tests/scenarios/cash-receipt-failure-reason.yaml`
 */
class CashReceiptFailureReasonTest extends PluginTestCase
{
    private const PROVIDER_ID = 'kginicis';

    /**
     * 운영 모드·한국어 로케일과 KG 테스트 자격증명으로 시작합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        app()->setLocale('ko');

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
        ]);
        $this->app->instance(PluginSettingsService::class, $settings);
        $this->app->forgetInstance(KgInicisApiService::class);
    }

    /**
     * KG 결제가 달린 주문을 만듭니다.
     *
     * @return Order 결제행이 달린 주문
     */
    private function kgOrder(): Order
    {
        $order = Order::factory()->create();
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'pg_provider' => self::PROVIDER_ID,
            'transaction_id' => 'KG_TID_858',
            'buyer_name' => '홍길동',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01099998888',
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * 발급 훅 기본 반환값(쇼핑몰 CashReceiptService 와 같은 모양)을 돌려줍니다.
     *
     * @return array<string, mixed> 기본 발급 결과
     */
    private function defaultIssueResult(): array
    {
        return ['success' => false, 'error_code' => 'NO_PROVIDER_HANDLED', 'error_message' => 'no provider', 'receipt_key' => null, 'receipt_url' => null, 'issue_number' => null, 'raw_response' => null];
    }

    /**
     * 취소 훅 기본 반환값을 돌려줍니다.
     *
     * @return array<string, mixed> 기본 취소 결과
     */
    private function defaultCancelResult(): array
    {
        return ['success' => false, 'error_code' => 'NO_PROVIDER_HANDLED', 'error_message' => 'no provider', 'receipt_key' => null, 'raw_response' => null];
    }

    /**
     * 발급: 통신 예외 원문은 이력 사유에 싣지 않고 일반 문구를 싣는다.
     *
     * @scenario case=issue_transport
     *
     * @effects cash_receipt_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_issue_transport_failure_returns_generic_reason(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://iniapi.inicis.com/v2/pg/receipt'));

        $result = app(RegisterCashReceiptProviderListener::class)->issue($this->defaultIssueResult(), $this->kgOrder(), self::PROVIDER_ID, [
            'type' => 'income',
            'identifier' => '01012345678',
            'identifier_type' => 'phone',
            'amount' => 11000,
            'tax_free_amount' => 0,
            'order_name' => '테스트 상품',
            'order_number' => 'ORD20261114858',
            'issue_sequence' => 1,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('결제대행사와 통신하는 중 오류가 발생해 현금영수증을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.', $result['error_message']);
    }

    /**
     * 취소: KG이니시스 거절 사유는 그대로 싣는다.
     *
     * @scenario case=cancel_pg_rejected
     *
     * @effects cash_receipt_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_cancel_rejection_keeps_pg_reason(): void
    {
        Http::fake(['*/v2/pg/refund' => Http::response(['resultCode' => '01', 'resultMsg' => '[500626]기취소 거래'], 200)]);

        $result = app(RegisterCashReceiptProviderListener::class)->cancel($this->defaultCancelResult(), $this->kgOrder(), self::PROVIDER_ID, 'CR_TID_858');

        $this->assertFalse($result['success']);
        $this->assertSame('[500626]기취소 거래', $result['error_message']);
    }

    /**
     * 취소: 사유 없는 거절은 영어 내부 문구 대신 번역 문구로 싣는다.
     *
     * @scenario case=cancel_pg_rejected
     *
     * @effects cash_receipt_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_cancel_rejection_without_reason_is_translated(): void
    {
        Http::fake(['*/v2/pg/refund' => Http::response(['resultCode' => '01'], 200)]);

        $result = app(RegisterCashReceiptProviderListener::class)->cancel($this->defaultCancelResult(), $this->kgOrder(), self::PROVIDER_ID, 'CR_TID_858');

        $this->assertSame('KG이니시스가 현금영수증 취소를 거절했습니다.', $result['error_message']);
    }
}
