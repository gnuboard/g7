<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 관리자 에스크로 배송 등록 실패 응답의 사유 선택 (#858)
 *
 * 결제대행사 거절 사유(ResultMsg)는 운영자에게 그대로 보이고, 통신 예외·HTTP 오류의 원문
 * (결제대행사 API 주소·cURL 문구)은 서버 로그에만 남고 응답에는 일반 문구가 실린다.
 * 실제 API 서비스를 쓰고 HTTP 만 가짜로 바꿔, 서비스가 던지는 예외 종류까지 함께 검증한다.
 */
class AdminEscrowFailureReasonTest extends PluginTestCase
{
    private const TID = 'NICE_ESCROW_TID_858';

    private const ENDPOINT = '/api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery';

    /** 통신 예외 원문 — 결제대행사 API 주소와 cURL 문구가 담긴다 */
    private const TRANSPORT_ERROR = 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://webapi.nicepay.co.kr/webapi/escrow_process.jsp';

    /**
     * 운영 모드(app.debug=false)·한국어 로케일로 시작합니다.
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
     * 통신 예외의 원문은 응답에 싣지 않고 일반 문구를 싣는다. 원문은 서버 로그에 남는다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_transport_failure_returns_generic_reason_and_logs_raw_message(): void
    {
        Log::spy();
        Http::fake(fn () => throw new ConnectionException(self::TRANSPORT_ERROR));

        $response = $this->registerDelivery();

        $response->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_nicepayments::messages.errors.escrow_delivery_failed'));
        $this->assertStringNotContainsString('nicepay.co.kr', $response->getContent());
        $this->assertStringNotContainsString('cURL', $response->getContent());

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => $message === 'NicePayments: escrow delivery registration failed'
                && ($context['error'] ?? '') === self::TRANSPORT_ERROR
                && ($context['exception_class'] ?? '') === ConnectionException::class)
            ->once();
    }

    /**
     * 결제대행사가 HTTP 오류로 답해도 상태 문구는 응답에 싣지 않는다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_http_error_returns_generic_reason(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $response = $this->registerDelivery();

        $response->assertStatus(502)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_nicepayments::messages.errors.escrow_delivery_failed'));
        $this->assertStringNotContainsString('HTTP 503', $response->getContent());
    }

    /**
     * 결제대행사가 거절하면 그 사유(ResultMsg)를 운영자에게 그대로 보인다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_keeps_result_message(): void
    {
        Http::fake(['*' => Http::response(['ResultCode' => 'C002', 'ResultMsg' => '이미 배송등록된 거래입니다.'])]);

        $response = $this->registerDelivery();

        $response->assertStatus(502)
            ->assertJsonPath('errors.message.0', '이미 배송등록된 거래입니다.');
    }

    /**
     * 결제대행사가 사유(ResultMsg) 없이 거절하면 영어 내부 문구 대신 번역된 거절 안내를 보인다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_without_message_falls_back_to_translation(): void
    {
        Http::fake(['*' => Http::response(['ResultCode' => 'C002'])]);

        $response = $this->registerDelivery();

        $response->assertStatus(502)
            ->assertJsonPath('errors.message.0', '나이스페이먼츠가 에스크로 배송 등록을 거절했습니다.');
    }

    /**
     * 디버그 모드에서는 코어 관례대로 원문을 그대로 둔다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_debug_mode_keeps_raw_message(): void
    {
        config(['app.debug' => true]);
        Http::fake(fn () => throw new ConnectionException(self::TRANSPORT_ERROR));

        $response = $this->registerDelivery();

        $response->assertStatus(502)
            ->assertJsonPath('errors.message.0', self::TRANSPORT_ERROR);
    }

    /**
     * 등록 API 를 관리자로 호출합니다.
     *
     * @return TestResponse 응답
     */
    private function registerDelivery(): TestResponse
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->createPaidEscrowPayment();

        return $this->actingAs($admin)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->postJson(self::ENDPOINT, [
                'tid' => self::TID,
                'delivery_name' => 'CJ대한통운',
                'tracking_number' => 'TRACK858',
                'buyer_address' => '서울시 테스트구 1',
                'register_name' => '운영자',
            ]);
    }

    /**
     * 배송 등록 대상인 결제완료 에스크로 결제를 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function createPaidEscrowPayment(): void
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_due_amount' => 0,
            'total_paid_amount' => 30000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nicepayments',
            'is_escrow' => true,
            'paid_amount_local' => 30000,
            'paid_at' => now(),
            'transaction_id' => self::TID,
            'payment_meta' => [
                'mid' => 'nicepay00m',
                'is_test_mode' => true,
            ],
        ]);
    }
}
