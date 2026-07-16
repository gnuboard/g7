<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature\Controllers;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

class EscrowCommonNotifyControllerTest extends PluginTestCase
{
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    private function createEscrowOrder(string $tno = 'KCP_ESCROW_TNO_REAL'): Order
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
            'transaction_id' => $tno,
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

    private function postEscrowNotify(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.238.36.58'])
            ->post('/plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify', $payload);
    }

    private function assertKcpNotifyRetry(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
        $this->assertStringNotContainsString('value="0000"', $response->getContent());
    }

    private function assertKcpNotifyOk(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('name="result"', $response->getContent());
        $this->assertStringContainsString('value="0000"', $response->getContent());
    }

    public function test_escrow_common_notify_retries_on_tno_mismatch_before_hooks(): void
    {
        $order = $this->createEscrowOrder('KCP_ESCROW_TNO_REAL');
        $hookCalled = false;

        HookManager::addAction(
            'sirsoft-pay_nhnkcp.escrow.purchase_confirmed',
            function () use (&$hookCalled): void {
                $hookCalled = true;
            }
        );

        $response = $this->postEscrowNotify([
            'site_cd' => 'T0000',
            'tno' => 'ATTACKER_ESCROW_TNO',
            'order_no' => $order->order_number,
            'tx_cd' => 'TX02',
            'cl_status' => '2',
        ]);

        $this->assertKcpNotifyRetry($response);
        $this->assertFalse($hookCalled, '검증 실패한 에스크로 통보는 hook 을 발행하지 않아야 합니다.');
    }

    public function test_escrow_common_notify_processes_matching_tno(): void
    {
        $order = $this->createEscrowOrder('KCP_ESCROW_TNO_REAL');
        $hookCalled = false;

        HookManager::addAction(
            'sirsoft-pay_nhnkcp.escrow.purchase_confirmed',
            function () use (&$hookCalled): void {
                $hookCalled = true;
            }
        );

        $response = $this->postEscrowNotify([
            'site_cd' => 'T0000',
            'tno' => 'KCP_ESCROW_TNO_REAL',
            'order_no' => $order->order_number,
            'tx_cd' => 'TX02',
            'cl_status' => '2',
        ]);

        $this->assertKcpNotifyOk($response);
        $this->assertTrue($hookCalled);
    }

    public function test_escrow_common_notify_does_not_dispatch_duplicate_payload_twice(): void
    {
        $order = $this->createEscrowOrder('KCP_ESCROW_TNO_REAL');
        $hookCount = 0;

        HookManager::addAction(
            'sirsoft-pay_nhnkcp.escrow.purchase_confirmed',
            function () use (&$hookCount): void {
                $hookCount++;
            }
        );

        $payload = [
            'site_cd' => 'T0000',
            'tno' => 'KCP_ESCROW_TNO_REAL',
            'order_no' => $order->order_number,
            'tx_cd' => 'TX02',
            'tx_tm' => '20260624164000',
            'cl_status' => '2',
        ];

        $first = $this->postEscrowNotify($payload);
        $second = $this->postEscrowNotify($payload);

        $this->assertKcpNotifyOk($first);
        $this->assertKcpNotifyOk($second);
        $this->assertSame(1, $hookCount, '동일 에스크로 통보 replay 는 hook 을 한 번만 발행해야 합니다.');
    }
}
