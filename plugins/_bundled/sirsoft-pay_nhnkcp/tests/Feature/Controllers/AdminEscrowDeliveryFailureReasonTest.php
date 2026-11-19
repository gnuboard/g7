<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpRejectedException;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 관리자 에스크로 배송 등록 실패 응답의 사유 선택 (#858)
 *
 * KCP 가 돌려준 거절 사유(res_msg)는 운영자에게 그대로 보이고, CLI 실행 실패 문구(서버 절대 경로 포함)는
 * 서버 로그에만 남고 응답에는 일반 문구가 실린다.
 */
class AdminEscrowDeliveryFailureReasonTest extends PluginTestCase
{
    /** CLI 실행 실패 문구에 담기는 서버 경로(예시 값) */
    private const SERVER_PATH = '/opt/example-site/plugins/sirsoft-pay_nhnkcp/bin/pp_cli';

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
     * CLI 실행 실패 문구의 서버 경로는 응답에 싣지 않고 일반 문구를 싣는다. 원문은 서버 로그에 남는다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_cli_failure_returns_generic_reason_and_logs_raw_message(): void
    {
        Log::spy();
        $raw = __('sirsoft-pay_nhnkcp::messages.errors.cli_binary_missing', ['path' => self::SERVER_PATH]);
        $this->mockEscrowDeliveryApiThrowing(new NhnKcpApiException($raw));

        $response = $this->register();

        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.message.0', __('sirsoft-pay_nhnkcp::messages.errors.escrow_delivery_failed'));
        $this->assertStringNotContainsString(self::SERVER_PATH, $response->getContent());

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => $message === 'KCP: escrow delivery register exception'
                && ($context['error'] ?? '') === $raw)
            ->once();
    }

    /**
     * KCP 가 거절하면 그 사유(res_msg)를 운영자에게 그대로 보인다.
     *
     * @scenario flow=escrow_delivery_register
     *
     * @effects escrow_register_failure_keeps_pg_reason
     *
     * @return void 반환값 없음
     */
    public function test_pg_rejection_keeps_result_message(): void
    {
        $this->mockEscrowDeliveryApiThrowing(new NhnKcpRejectedException('이미 배송등록된 거래입니다.'));

        $response = $this->register();

        $response->assertStatus(500)
            ->assertJsonPath('errors.message.0', '이미 배송등록된 거래입니다.');
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
        $raw = __('sirsoft-pay_nhnkcp::messages.errors.cli_binary_missing', ['path' => self::SERVER_PATH]);
        $this->mockEscrowDeliveryApiThrowing(new NhnKcpApiException($raw));

        $this->register()
            ->assertStatus(500)
            ->assertJsonPath('errors.message.0', $raw);
    }

    /**
     * 배송 등록 API 가 주어진 예외를 던지도록 서비스를 바꿔 끼웁니다.
     *
     * @param  \Throwable  $exception  던질 예외
     * @return void 반환값 없음
     */
    private function mockEscrowDeliveryApiThrowing(\Throwable $exception): void
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('registerEscrowDelivery')->willThrowException($exception);
        $this->app->instance(NhnKcpApiService::class, $mock);
    }

    /**
     * 에스크로 주문을 만들고 배송 등록 API 를 관리자로 호출합니다.
     *
     * @return TestResponse 응답
     */
    private function register(): TestResponse
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = $this->createEscrowOrder();

        return $this->actingAs($admin)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->postJson("/api/plugins/sirsoft-pay_nhnkcp/admin/orders/{$order->order_number}/escrow-delivery", [
                'deli_numb' => '1234567890',
                'deli_corp' => '04',
            ]);
    }

    /**
     * 결제완료 에스크로 주문을 만듭니다.
     *
     * @return Order 주문
     */
    private function createEscrowOrder(): Order
    {
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 30000,
            'total_amount' => 30000,
            'total_due_amount' => 0,
            'total_paid_amount' => 30000,
            'paid_at' => now(),
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => 'KCP_ESCROW_TNO_858',
            'paid_amount_local' => 30000,
            'paid_at' => now(),
            'is_escrow' => true,
            'payment_meta' => [
                'site_cd' => 'T0000',
                'is_test_mode' => true,
                'escw_yn' => 'Y',
            ],
        ]);

        return $order;
    }
}
