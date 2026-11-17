<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductProvisioner;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 관리자 에스크로 배송 등록·구매거절확인·CBT 시험 상품 생성 실패 응답의 사유 선택 (#858)
 *
 * KG이니시스가 돌려준 거절 사유(resultMsg)는 운영자에게 그대로 보이고, 통신 예외·DB 예외 원문
 * (결제대행사 API 주소·cURL 문구·SQL·DB 접속 정보)은 서버 로그에만 남고 응답에는 번역된 일반 문구가 실린다.
 */
class AdminEscrowFailureReasonTest extends PluginTestCase
{
    /** 통신 예외 원문 — 결제대행사 API 주소와 cURL 문구가 담긴다 */
    private const TRANSPORT_ERROR = 'cURL error 7: Failed to connect to iniapi.inicis.com port 443 after 21 ms: Could not connect to server for https://iniapi.inicis.com/api/v1/escrow';

    private User $adminUser;

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
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update', 'sirsoft-ecommerce.products.create']);
    }

    /**
     * 배송 등록 중 통신 예외가 나면 원문 대신 일반 문구를 싣고, 원문은 서버 로그에 남긴다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_delivery_transport_failure_returns_generic_reason(): void
    {
        Log::spy();
        $this->createEscrowPayment('ORD-858-DLV-1');
        $this->mockApi('registerEscrowDelivery', new ConnectionException(self::TRANSPORT_ERROR));

        $response = $this->registerDelivery('ORD-858-DLV-1');

        $response->assertStatus(500)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_kginicis::messages.escrow.delivery_failed'));
        $this->assertStringNotContainsString('inicis.com', $response->getContent());
        $this->assertStringNotContainsString('cURL', $response->getContent());

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => $message === 'KG Inicis: escrow delivery register exception'
                && ($context['error'] ?? '') === self::TRANSPORT_ERROR)
            ->once();
    }

    /**
     * 결제대행사가 거절하면 그 사유를 그대로 보이고, 사유가 비면 번역된 실패 문구를 보인다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_delivery_pg_rejection_keeps_result_message(): void
    {
        $this->createEscrowPayment('ORD-858-DLV-2');
        $this->mockApiReturning('registerEscrowDelivery', ['resultCode' => '01', 'resultMsg' => '[PG] 이미 등록된 송장입니다.']);

        $this->registerDelivery('ORD-858-DLV-2')
            ->assertStatus(502)
            ->assertJsonPath('errors.message.0', '[PG] 이미 등록된 송장입니다.');
    }

    /**
     * 결제대행사 거절 응답에 사유가 없으면 번역된 실패 문구를 보인다(종전 한국어 하드코딩).
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_delivery_pg_rejection_without_message_falls_back_to_translation(): void
    {
        $this->createEscrowPayment('ORD-858-DLV-3');
        $this->mockApiReturning('registerEscrowDelivery', ['resultCode' => '01']);

        $this->registerDelivery('ORD-858-DLV-3')
            ->assertStatus(502)
            ->assertJsonPath('errors.message.0', '배송등록에 실패했습니다.');

        app()->setLocale('en');
        $this->assertSame('Failed to register the delivery.', __('sirsoft-pay_kginicis::messages.escrow.delivery_rejected'));
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
    public function test_delivery_debug_mode_keeps_raw_message(): void
    {
        config(['app.debug' => true]);
        $this->createEscrowPayment('ORD-858-DLV-4');
        $this->mockApi('registerEscrowDelivery', new ConnectionException(self::TRANSPORT_ERROR));

        $this->registerDelivery('ORD-858-DLV-4')
            ->assertStatus(500)
            ->assertJsonPath('errors.message.0', self::TRANSPORT_ERROR);
    }

    /**
     * 구매거절확인 중 통신 예외가 나면 원문 대신 일반 문구를 싣는다.
     *
     * @scenario flow=escrow_deny_confirm
     *
     * @effects escrow_deny_confirm_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_deny_confirm_transport_failure_returns_generic_reason(): void
    {
        $this->createEscrowPayment('ORD-858-DNCF-1', ['escrow_confirm' => ['type' => 'deny']]);
        $this->mockApi('denyConfirmEscrow', new ConnectionException(self::TRANSPORT_ERROR));

        $response = $this->denyConfirm('ORD-858-DNCF-1');

        $response->assertStatus(500)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_kginicis::messages.escrow.deny_confirm_failed'));
        $this->assertStringNotContainsString('inicis.com', $response->getContent());
    }

    /**
     * 구매거절확인의 결제대행사 거절 사유와 「이미 완료」 안내는 번역 문구·PG 사유로 보인다.
     *
     * @scenario flow=escrow_deny_confirm
     *
     * @effects escrow_deny_confirm_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_deny_confirm_rejection_without_message_is_translated(): void
    {
        $this->createEscrowPayment('ORD-858-DNCF-2', ['escrow_confirm' => ['type' => 'deny']]);
        $this->mockApiReturning('denyConfirmEscrow', ['resultCode' => '01']);

        $this->denyConfirm('ORD-858-DNCF-2')
            ->assertStatus(502)
            ->assertJsonPath('errors.message.0', '구매거절확인에 실패했습니다.');
    }

    /**
     * 이미 구매거절확인을 마친 결제는 번역된 안내로 막는다(종전 한국어 하드코딩).
     *
     * @scenario flow=escrow_deny_confirm
     *
     * @effects escrow_deny_confirm_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_deny_confirm_already_done_message_is_translated(): void
    {
        $this->createEscrowPayment('ORD-858-DNCF-3', ['escrow_deny_confirm' => ['confirmed_at' => '2026-11-14 00:00:00']]);
        $this->mockApi('denyConfirmEscrow', new \LogicException('PG 를 부르면 안 된다'));

        $this->denyConfirm('ORD-858-DNCF-3')
            ->assertStatus(422)
            ->assertJsonPath('errors.message.0', '이미 구매거절확인이 완료되었습니다.');
    }

    /**
     * CBT 시험 상품 생성이 DB 예외로 실패하면 SQL·DB 접속 정보 대신 일반 문구를 싣는다.
     *
     * @scenario flow=cbt_test_product
     *
     * @effects cbt_test_product_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_cbt_test_product_db_failure_returns_generic_detail(): void
    {
        // 시험 상품 생성은 이커머스 공개 계약에 맡긴다(#916) — 계약이 DB 예외를 그대로 올려보내는 경우를 만든다.
        $mock = $this->createMock(ProductProvisioner::class);
        $mock->method('createSimpleProduct')->willThrowException(new QueryException(
            'mysql',
            'insert into `ecommerce_products` (`product_code`) values (?)',
            ['CBT-TEST-1'],
            new \PDOException('SQLSTATE[HY000] [2002] Connection refused'),
        ));
        $this->app->instance(ProductProvisioner::class, $mock);

        $response = $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->postJson('/api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product');

        $response->assertStatus(500)
            ->assertJsonPath('errors.detail', __('extensions.errors.internal_error'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('ecommerce_products', $response->getContent());
    }

    /**
     * API 서비스의 한 메서드가 예외를 던지도록 바꿔 끼웁니다.
     *
     * @param  string  $method  메서드 이름
     * @param  \Throwable  $exception  던질 예외
     * @return void 반환값 없음
     */
    private function mockApi(string $method, \Throwable $exception): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->method($method)->willThrowException($exception);
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    /**
     * API 서비스의 한 메서드가 주어진 PG 응답을 돌려주도록 바꿔 끼웁니다.
     *
     * @param  string  $method  메서드 이름
     * @param  array<string, mixed>  $pgResponse  PG 응답
     * @return void 반환값 없음
     */
    private function mockApiReturning(string $method, array $pgResponse): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->method($method)->willReturn($pgResponse);
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    /**
     * 배송 등록 API 를 관리자로 호출합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return TestResponse 응답
     */
    private function registerDelivery(string $orderNumber): TestResponse
    {
        return $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->postJson("/api/plugins/sirsoft-pay_kginicis/admin/orders/{$orderNumber}/escrow-delivery", [
                'invoice' => 'INV-858',
                'ex_code' => 'hanjin',
                'recv_name' => '홍길동',
                'recv_tel' => '010-1234-5678',
                'recv_post' => '12345',
                'recv_addr' => '서울시 강남구',
            ]);
    }

    /**
     * 구매거절확인 API 를 관리자로 호출합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return TestResponse 응답
     */
    private function denyConfirm(string $orderNumber): TestResponse
    {
        return $this->actingAs($this->adminUser)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->postJson("/api/plugins/sirsoft-pay_kginicis/admin/orders/{$orderNumber}/escrow-deny-confirm", [
                'dcnf_name' => '관리자',
            ]);
    }

    /**
     * 결제완료 에스크로 결제를 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  array<string, mixed>  $paymentMeta  결제 메타
     * @param  string  $tid  거래번호
     * @return OrderPayment 결제 레코드
     */
    private function createEscrowPayment(string $orderNumber, array $paymentMeta = [], string $tid = 'INIMX_CARDINIpayTest20260623123456'): OrderPayment
    {
        $order = OrderFactory::new()->paid()->create([
            'order_number' => $orderNumber,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_due_amount' => 0,
        ]);

        return OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => $tid,
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'is_escrow' => true,
            'payment_meta' => $paymentMeta,
        ]);
    }
}
