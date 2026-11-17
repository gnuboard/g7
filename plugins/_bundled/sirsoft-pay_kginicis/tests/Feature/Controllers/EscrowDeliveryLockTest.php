<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDeliveryController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDenyConfirmController;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 관리자 에스크로 배송등록·구매거절확인의 주문 단위 직렬화 회귀 테스트
 *
 * 두 요청이 겹치면(더블클릭·재시도) 둘 다 "아직 처리 전"을 보고 PG 에 같은 요청을 두 번 보냈다. 이제 주문 단위
 * 잠금(`escrow-delivery`·`escrow-deny-confirm`) 안에서 결제 메타를 다시 읽고, 잠금을 다른 요청이 쥐고 있으면
 * PG 를 부르지 않고 409 를 돌려준다.
 *
 * 단일 프로세스 테스트라 진짜 동시 요청은 만들 수 없으므로, 다른 요청이 잠금을 쥔 상태는 테스트가 같은 키의
 * 잠금을 먼저 잡아 재현한다. 잠금 대기 시간은 컨트롤러를 대기 0초 하위 클래스로 바꿔 테스트가 10초씩 기다리지
 * 않게 한다(잠금 키 계산은 실제 트레이트를 그대로 쓴다).
 *
 * @scenario-manifest plugins/_bundled/sirsoft-pay_kginicis/tests/scenarios/escrow-request-serialization.yaml
 */
class EscrowDeliveryLockTest extends PluginTestCase
{
    private const DELIVERY_URL = '/api/plugins/sirsoft-pay_kginicis/admin/orders/%s/escrow-delivery';

    private const DENY_CONFIRM_URL = '/api/plugins/sirsoft-pay_kginicis/admin/orders/%s/escrow-deny-confirm';

    private User $adminUser;

    /**
     * 주문 수정 권한 관리자와 대기 0초 컨트롤러를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $this->app->bind(AdminEscrowDeliveryController::class, fn ($app) => $this->deliveryControllerWithoutWait($app->make(KgInicisApiService::class), $app->make(PaymentGatewayBridge::class)));
        $this->app->bind(AdminEscrowDenyConfirmController::class, fn ($app) => $this->denyConfirmControllerWithoutWait($app->make(KgInicisApiService::class), $app->make(PaymentGatewayBridge::class)));
    }

    /**
     * 다른 요청이 배송등록 잠금을 쥐고 있으면 PG 를 부르지 않고 409(escrow_in_progress)를 돌려주는지 확인합니다.
     *
     * @scenario request=escrow_delivery, contention=lock_held
     *
     * @effects escrow_request_rejected_without_pg_call_while_locked
     *
     * @return void 반환값 없음
     */
    public function test_delivery_register_returns_409_without_pg_call_while_lock_is_held(): void
    {
        $orderNumber = 'ORD-ESCROW-LOCK-DLV-001';
        $this->createEscrowPayment($orderNumber);
        $this->bindApiExpectingNoCall();

        $held = Cache::lock($this->deliveryLockKey($orderNumber), 30);
        $this->assertTrue($held->get(), '테스트가 잠금을 먼저 잡지 못하면 경합을 재현하지 못한 것이다.');

        try {
            $response = $this->actingAs($this->adminUser)
                ->postJson(sprintf(self::DELIVERY_URL, $orderNumber), $this->deliveryPayload());
        } finally {
            $held->release();
        }

        $response->assertStatus(409)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_kginicis::messages.errors.escrow_in_progress'));
    }

    /**
     * 잠금 안에서 다시 읽은 메타에 배송등록이 이미 있으면 신규 등록(I)은 PG 를 부르지 않고 409 로 거절하는지 확인합니다.
     *
     * @scenario request=escrow_delivery, contention=already_processed
     *
     * @effects escrow_request_rejected_when_already_processed
     *
     * @return void 반환값 없음
     */
    public function test_new_delivery_register_is_rejected_when_already_registered(): void
    {
        $orderNumber = 'ORD-ESCROW-LOCK-DLV-002';
        $this->createEscrowPayment($orderNumber, [
            'escrow_delivery' => ['invoice' => 'INV-OLD', 'report' => 'I'],
        ]);
        $this->bindApiExpectingNoCall();

        $response = $this->actingAs($this->adminUser)
            ->postJson(sprintf(self::DELIVERY_URL, $orderNumber), $this->deliveryPayload(['report' => 'I']));

        $response->assertStatus(409)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_kginicis::messages.errors.escrow_delivery_already_registered'));
    }

    /**
     * 이미 등록된 배송을 변경(U)하는 정상 경로는 잠금 뒤에도 PG 로 한 번 전송되는지 확인합니다.
     *
     * @scenario request=escrow_delivery, contention=sequential
     *
     * @effects escrow_change_and_first_request_still_succeed
     *
     * @return void 반환값 없음
     */
    public function test_delivery_change_is_still_sent_after_registration(): void
    {
        $orderNumber = 'ORD-ESCROW-LOCK-DLV-003';
        $this->createEscrowPayment($orderNumber, [
            'escrow_delivery' => ['invoice' => 'INV-OLD', 'report' => 'I'],
        ]);

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())->method('registerEscrowDelivery')
            ->willReturn(['resultCode' => '00', 'resultMsg' => 'OK']);
        $this->app->instance(KgInicisApiService::class, $mock);

        $this->actingAs($this->adminUser)
            ->postJson(sprintf(self::DELIVERY_URL, $orderNumber), $this->deliveryPayload(['report' => 'U', 'invoice' => 'INV-NEW']))
            ->assertOk();

        $this->assertSame('INV-NEW', $this->paymentMeta($orderNumber)['escrow_delivery']['invoice'] ?? null);
        $this->assertTrue(
            Cache::lock($this->deliveryLockKey($orderNumber), 1)->get(),
            '처리가 끝나면 주문 잠금이 풀려야 한다.'
        );
    }

    /**
     * 다른 요청이 거절확인 잠금을 쥐고 있으면 PG 를 부르지 않고 409(escrow_in_progress)를 돌려주는지 확인합니다.
     *
     * @scenario request=escrow_deny_confirm, contention=lock_held
     *
     * @effects escrow_request_rejected_without_pg_call_while_locked
     *
     * @return void 반환값 없음
     */
    public function test_deny_confirm_returns_409_without_pg_call_while_lock_is_held(): void
    {
        $orderNumber = 'ORD-ESCROW-LOCK-DNCF-001';
        $this->createEscrowPayment($orderNumber, ['escrow_confirm' => ['type' => 'deny']]);
        $this->bindApiExpectingNoCall();

        $held = Cache::lock($this->denyConfirmLockKey($orderNumber), 30);
        $this->assertTrue($held->get(), '테스트가 잠금을 먼저 잡지 못하면 경합을 재현하지 못한 것이다.');

        try {
            $response = $this->actingAs($this->adminUser)
                ->postJson(sprintf(self::DENY_CONFIRM_URL, $orderNumber), ['dcnf_name' => '관리자']);
        } finally {
            $held->release();
        }

        $response->assertStatus(409)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_kginicis::messages.errors.escrow_in_progress'));
        $this->assertArrayNotHasKey('escrow_deny_confirm', $this->paymentMeta($orderNumber));
    }

    /**
     * 거절확인을 두 번 보내면 PG 는 한 번만 불리고 두 번째는 409(이미 확인)로 거절되는지 확인합니다.
     * 두 번째 요청이 escrow_in_progress 가 아니라 already_confirmed 를 받는 것은 첫 요청이 잠금을 풀었다는 뜻이다.
     *
     * @scenario request=escrow_deny_confirm, contention=already_processed
     *
     * @effects escrow_request_rejected_when_already_processed, escrow_change_and_first_request_still_succeed
     *
     * @return void 반환값 없음
     */
    public function test_second_deny_confirm_is_rejected_and_pg_called_once(): void
    {
        $orderNumber = 'ORD-ESCROW-LOCK-DNCF-002';
        $this->createEscrowPayment($orderNumber, ['escrow_confirm' => ['type' => 'deny']]);

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())->method('denyConfirmEscrow')
            ->willReturn(['resultCode' => '00', 'resultMsg' => 'OK']);
        $this->app->instance(KgInicisApiService::class, $mock);

        $this->actingAs($this->adminUser)
            ->postJson(sprintf(self::DENY_CONFIRM_URL, $orderNumber), ['dcnf_name' => '관리자'])
            ->assertOk();

        $this->actingAs($this->adminUser)
            ->postJson(sprintf(self::DENY_CONFIRM_URL, $orderNumber), ['dcnf_name' => '관리자'])
            ->assertStatus(409)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_kginicis::messages.errors.escrow_deny_already_confirmed'));

        $this->assertArrayHasKey('escrow_deny_confirm', $this->paymentMeta($orderNumber));
    }

    /**
     * 경합 없이 처음 보내는 거절확인은 잠금 뒤에도 PG 로 한 번 전송되고, 처리가 끝나면 주문 잠금이 풀리는지 확인합니다.
     *
     * @scenario request=escrow_deny_confirm, contention=sequential
     *
     * @effects escrow_change_and_first_request_still_succeed
     *
     * @return void 반환값 없음
     */
    public function test_first_deny_confirm_is_sent_once_and_releases_lock(): void
    {
        $orderNumber = 'ORD-ESCROW-LOCK-DNCF-003';
        $this->createEscrowPayment($orderNumber, ['escrow_confirm' => ['type' => 'deny']]);

        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->once())->method('denyConfirmEscrow')
            ->willReturn(['resultCode' => '00', 'resultMsg' => 'OK']);
        $this->app->instance(KgInicisApiService::class, $mock);

        $this->actingAs($this->adminUser)
            ->postJson(sprintf(self::DENY_CONFIRM_URL, $orderNumber), ['dcnf_name' => '관리자'])
            ->assertOk();

        $this->assertArrayHasKey('escrow_deny_confirm', $this->paymentMeta($orderNumber));
        $this->assertTrue(
            Cache::lock($this->denyConfirmLockKey($orderNumber), 1)->get(),
            '처리가 끝나면 주문 잠금이 풀려야 한다.'
        );
    }

    /**
     * PG API 가 한 번도 불리지 않아야 하는 가짜 서비스를 등록합니다.
     *
     * @return void 반환값 없음
     */
    private function bindApiExpectingNoCall(): void
    {
        $mock = $this->createMock(KgInicisApiService::class);
        $mock->expects($this->never())->method('useEscrowCredentials');
        $mock->expects($this->never())->method('registerEscrowDelivery');
        $mock->expects($this->never())->method('denyConfirmEscrow');
        $this->app->instance(KgInicisApiService::class, $mock);
    }

    /**
     * 배송등록 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 요청 본문
     */
    private function deliveryPayload(array $overrides = []): array
    {
        return array_merge([
            'invoice' => 'INV-001',
            'ex_code' => 'hanjin',
            'recv_name' => '홍길동',
            'recv_tel' => '010-1234-5678',
            'recv_post' => '12345',
            'recv_addr' => '서울시 강남구',
        ], $overrides);
    }

    /**
     * 배송등록 주문 잠금 키를 실제 트레이트 계산으로 구합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return string 잠금 키
     */
    private function deliveryLockKey(string $orderNumber): string
    {
        return $this->callbackLockKeyFor('escrow-delivery', $orderNumber);
    }

    /**
     * 거절확인 주문 잠금 키를 실제 트레이트 계산으로 구합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return string 잠금 키
     */
    private function denyConfirmLockKey(string $orderNumber): string
    {
        return $this->callbackLockKeyFor('escrow-deny-confirm', $orderNumber);
    }

    /**
     * 주문 잠금 키를 콜백 직렬화 트레이트(기존 공용 계산)로 구합니다.
     *
     * 컨트롤러의 상수가 아니라 잠금 문맥 이름(`escrow-delivery`·`escrow-deny-confirm`)을 직접 적는다 — 다른 요청이 같은
     * 키로 잡은 잠금을 컨트롤러가 존중하는지(동작)만 재고, 컨트롤러 내부 이름이 없어서 실패하지 않게 한다.
     *
     * @param  string  $context  잠금 문맥 이름
     * @param  string  $orderNumber  주문번호
     * @return string 잠금 키
     */
    private function callbackLockKeyFor(string $context, string $orderNumber): string
    {
        $calculator = new class
        {
            use SerializesPaymentCallbacks;

            /**
             * 트레이트의 잠금 키 계산을 그대로 드러냅니다.
             *
             * @param  string  $context  잠금 문맥 이름
             * @param  string  $orderNumber  주문번호
             * @return string 잠금 키
             */
            public function key(string $context, string $orderNumber): string
            {
                return $this->callbackLockKey($context, $orderNumber);
            }
        };

        return $calculator->key($context, $orderNumber);
    }

    /**
     * 잠금을 기다리지 않는 배송등록 컨트롤러를 만듭니다.
     *
     * @param  KgInicisApiService  $api  PG API 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     * @return AdminEscrowDeliveryController 대기 0초 컨트롤러
     */
    private function deliveryControllerWithoutWait(KgInicisApiService $api, PaymentGatewayBridge $bridge): AdminEscrowDeliveryController
    {
        return new class($api, $bridge) extends AdminEscrowDeliveryController
        {
            /**
             * 잠금 대기 시간(초)
             *
             * @return int 대기하지 않음
             */
            protected function callbackLockWaitSeconds(): int
            {
                return 0;
            }
        };
    }

    /**
     * 잠금을 기다리지 않는 구매거절확인 컨트롤러를 만듭니다.
     *
     * @param  KgInicisApiService  $api  PG API 서비스
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     * @return AdminEscrowDenyConfirmController 대기 0초 컨트롤러
     */
    private function denyConfirmControllerWithoutWait(KgInicisApiService $api, PaymentGatewayBridge $bridge): AdminEscrowDenyConfirmController
    {
        return new class($api, $bridge) extends AdminEscrowDenyConfirmController
        {
            /**
             * 잠금 대기 시간(초)
             *
             * @return int 대기하지 않음
             */
            protected function callbackLockWaitSeconds(): int
            {
                return 0;
            }
        };
    }

    /**
     * 주문의 에스크로 결제 메타를 다시 읽습니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed> 결제 메타
     */
    private function paymentMeta(string $orderNumber): array
    {
        $payment = OrderPayment::query()
            ->whereHas('order', fn ($query) => $query->where('order_number', $orderNumber))
            ->firstOrFail();

        return (array) ($payment->payment_meta ?? []);
    }

    /**
     * KG이니시스 에스크로 결제가 붙은 결제완료 주문을 만듭니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  array<string, mixed>  $paymentMeta  결제 메타
     * @return OrderPayment 결제 레코드
     */
    private function createEscrowPayment(string $orderNumber, array $paymentMeta = []): OrderPayment
    {
        $order = OrderFactory::new()->paid()->create([
            'order_number' => $orderNumber,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_due_amount' => 0,
        ]);

        OrderAddressFactory::new()->forOrder($order)->shipping()->create([
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
            'address_detail' => '비공개 주소',
        ]);

        return OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INIMX_CARDINIpayTest20261113000001',
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'is_escrow' => true,
            'payment_meta' => $paymentMeta,
        ]);
    }
}
