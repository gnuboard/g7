<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class AdminVbankRefundControllerTest extends PluginTestCase
{
    private const TID = 'NICE_VBANK_TID_001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createCancelSequences();
    }

    public function test_admin_vbank_refund_updates_order_domain_after_pg_cancel(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = $this->createPaidVbankOrder();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->once())
            ->method('useStoredCredentials')
            ->with(false, 'SRLIVE001');
        $apiServiceMock->expects($this->once())
            ->method('cancelPayment')
            ->with(
                self::TID,
                $order->order_number,
                30000,
                '가상계좌 환불',
                0,
                '1234567890',
                '004',
                '홍길동',
            )
            ->willReturn([
                'ResultCode' => '2001',
                'ResultMsg' => '취소 성공',
                'TID' => self::TID,
                'CancelAmt' => '30000',
            ]);

        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund', [
            'tid' => self::TID,
            'moid' => $order->order_number,
            'cancel_amt' => 30000,
            'cancel_msg' => '가상계좌 환불',
            'refund_acct_no' => '1234567890',
            'refund_bank_cd' => '004',
            'refund_acct_nm' => '홍길동',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $payment = $order->payment->fresh();
        $refund = OrderRefund::query()->where('order_id', $order->id)->first();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::CANCELLED, $payment->payment_status);
        $this->assertEquals(30000.0, (float) $payment->cancelled_amount);
        $this->assertSame('completed', $payment->payment_meta['vbank_refund_status'] ?? null);
        $this->assertNotNull($refund);
        $this->assertEquals(30000.0, (float) $refund->refund_amount);
    }

    public function test_admin_vbank_refund_rejects_amount_mismatch_before_pg_cancel(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = $this->createPaidVbankOrder();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->never())->method('cancelPayment');
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund', [
            'tid' => self::TID,
            'moid' => $order->order_number,
            'cancel_amt' => 29999,
            'refund_acct_no' => '1234567890',
            'refund_bank_cd' => '004',
            'refund_acct_nm' => '홍길동',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(0.0, (float) $order->payment->cancelled_amount);
    }

    public function test_admin_vbank_refund_requires_orders_update_permission(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $order = $this->createPaidVbankOrder();

        $apiServiceMock = $this->createMock(NicePaymentsApiService::class);
        $apiServiceMock->expects($this->never())->method('cancelPayment');
        $this->app->instance(NicePaymentsApiService::class, $apiServiceMock);

        $response = $this->actingAs($admin)->postJson('/api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund', [
            'tid' => self::TID,
            'moid' => $order->order_number,
            'cancel_amt' => 30000,
            'refund_acct_no' => '1234567890',
            'refund_bank_cd' => '004',
            'refund_acct_nm' => '홍길동',
        ]);

        $response->assertForbidden();
    }

    private function createPaidVbankOrder(): Order
    {
        $user = User::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => 30000,
            'total_shipping_amount' => 0,
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => 30000,
            'subtotal_price' => 30000,
            'subtotal_paid_amount' => 30000,
            'subtotal_discount_amount' => 0,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'product_snapshot' => [
                'id' => null,
                'name' => ['ko' => '테스트상품', 'en' => 'Test Product'],
                'product_code' => null,
                'sku' => null,
                'brand_id' => null,
                'list_price' => 30000,
                'selling_price' => 30000,
                'currency_code' => 'KRW',
                'stock_quantity' => 100,
                'tax_status' => 'taxable',
                'tax_rate' => 10,
                'has_options' => false,
                'option_groups' => null,
                'thumbnail_url' => null,
            ],
            'option_snapshot' => [
                'id' => null,
                'option_code' => null,
                'option_values' => null,
                'option_name' => '기본',
                'price_adjustment' => 0,
                'list_price' => 30000,
                'selling_price' => 30000,
                'currency_code' => 'KRW',
                'stock_quantity' => 100,
                'weight' => 0.5,
                'volume' => 0.01,
                'sku' => null,
                'is_default' => true,
            ],
        ]);

        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'cancelled_amount' => 0,
            'paid_at' => now(),
            'transaction_id' => self::TID,
            'vbank_number' => '0987654321',
            'vbank_name' => '국민은행',
            'payment_meta' => [
                'mid' => 'SRLIVE001',
                'is_test_mode' => false,
                'vbank_tid' => self::TID,
                'vbank_num' => '0987654321',
            ],
        ]);

        return $order->fresh(['payment', 'options', 'shippings']);
    }

    private function createCancelSequences(): void
    {
        foreach ([SequenceType::CANCEL, SequenceType::REFUND] as $type) {
            $cfg = $type->getDefaultConfig();
            Sequence::firstOrCreate(
                ['type' => $type->value],
                [
                    'algorithm' => $cfg['algorithm']->value,
                    'prefix' => $cfg['prefix'],
                    'current_value' => 0,
                    'increment' => 1,
                    'min_value' => 1,
                    'max_value' => $cfg['max_value'],
                    'cycle' => false,
                    'pad_length' => $cfg['pad_length'],
                ]
            );
        }
    }
}
