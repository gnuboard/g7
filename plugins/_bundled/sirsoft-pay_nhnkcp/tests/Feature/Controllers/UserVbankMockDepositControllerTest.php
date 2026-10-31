<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 테스트 모드 가상계좌 모의입금 정보 API 가 공통 응답 형식으로 답하는지 검증한다.
 *
 * 조건이 맞으면 data 에 KCP 테스트 페이지에 넣을 값을, 맞지 않으면 404 가 아니라 data.available=false 를 준다.
 */
class UserVbankMockDepositControllerTest extends PluginTestCase
{
    /**
     * 테스트 모드에서 본인의 입금 대기 가상계좌 주문이면 모의입금 값을 data 에 담아 준다.
     *
     * @scenario case=owner_test_mode
     *
     * @effects mock_info_returned_in_envelope
     *
     * @return void 반환값 없음
     */
    public function test_owner_in_test_mode_receives_mock_info_in_data(): void
    {
        $this->mockTestMode(true);
        [$user, $order] = $this->createWaitingVbankOrder();

        $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/vbank-mock-deposit-info")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.trade_no', 'KCP_TNO_MOCK_001')
            ->assertJsonPath('data.account_no', '12345678901234')
            ->assertJsonPath('data.is_admin_view', false)
            ->assertJsonMissingPath('available');
    }

    /**
     * 운영 모드에서는 404 가 아니라 data.available=false 로 답한다.
     *
     * @scenario case=owner_live_mode
     *
     * @effects unavailable_as_data_flag
     *
     * @return void 반환값 없음
     */
    public function test_live_mode_answers_unavailable_in_data(): void
    {
        $this->mockTestMode(false);
        [$user, $order] = $this->createWaitingVbankOrder();

        $this->actingAs($user)
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/vbank-mock-deposit-info")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.available', false)
            ->assertJsonMissingPath('data.trade_no');
    }

    /**
     * 다른 회원의 주문이면 모의입금 값을 주지 않는다.
     *
     * @scenario case=other_member_test_mode
     *
     * @effects unavailable_as_data_flag
     *
     * @return void 반환값 없음
     */
    public function test_other_member_answers_unavailable_in_data(): void
    {
        $this->mockTestMode(true);
        [, $order] = $this->createWaitingVbankOrder();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/plugins/sirsoft-pay_nhnkcp/user/orders/{$order->order_number}/vbank-mock-deposit-info")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonMissingPath('data.account_no');
    }

    /**
     * 회원의 입금 대기 가상계좌 주문을 만듭니다.
     *
     * @return array{0: User, 1: Order} 회원과 주문
     */
    private function createWaitingVbankOrder(): array
    {
        $user = User::factory()->create();
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-KCP-MOCK-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'total_amount' => 1000,
            'total_due_amount' => 1000,
            'total_paid_amount' => 0,
            'paid_at' => null,
        ]);
        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'nhnkcp',
            'transaction_id' => 'KCP_TNO_MOCK_001',
            'vbank_number' => '12345678901234',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'payment_meta' => [],
        ]);

        return [$user, $order];
    }

    /**
     * 플러그인 설정의 테스트 모드를 고정합니다.
     *
     * @param  bool  $isTestMode  테스트 모드 여부
     * @return void 반환값 없음
     */
    private function mockTestMode(bool $isTestMode): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn(['is_test_mode' => $isTestMode]);
        $this->app->instance(PluginSettingsService::class, $mock);
    }
}
