<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 주문 취소 시 환불계좌 조건부 검증 (#454 S3 / D13)
 *
 * 표시·필수 규칙:
 *   vbank + 입금완료 → 필수 (PG 환불 API 의 refundReceiveAccount)
 *   vbank + 입금전   → 선택 (미입금 취소는 PG 가 계좌를 요구하지 않음)
 *   dbank           → 선택 (관리자 수동 이체 참조)
 *   card            → 불필요
 *
 * 부분 입력(3필드 중 일부)은 결제수단과 무관하게 거부한다 — 그 상태로는 환불이 불가능하다.
 *
 * @scenario actor=admin, change_mode=manual
 *
 * @effects cancel_refund_bank_required_for_paid_vbank_422,
 *   cancel_refund_bank_optional_before_deposit,
 *   cancel_refund_bank_partial_input_rejected_422,
 *   cancel_refund_bank_persisted_to_payment_row
 */
class CancelOrderRefundBankTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.orders.update',
        ]);

        $settingsDir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (is_dir($settingsDir)) {
            foreach (glob($settingsDir.'/*.json') as $file) {
                @unlink($file);
            }
        }
        app(EcommerceSettingsService::class)->clearCache();
        Config::set('g7_settings.modules.sirsoft-ecommerce', []);

        $this->createCancelSequences();
    }

    private function createCancelSequences(): void
    {
        foreach ([SequenceType::CANCEL, SequenceType::REFUND] as $type) {
            $config = $type->getDefaultConfig();
            Sequence::firstOrCreate(
                ['type' => $type->value],
                [
                    'algorithm' => $config['algorithm']->value,
                    'prefix' => $config['prefix'],
                    'current_value' => 0,
                    'increment' => 1,
                    'min_value' => 1,
                    'max_value' => $config['max_value'],
                    'cycle' => false,
                    'pad_length' => $config['pad_length'],
                ]
            );
        }
    }

    /**
     * 결제완료 주문 + 결제행을 만든다.
     *
     * @param  array<string, mixed>  $paymentOverrides
     * @return array{order: Order, payment: OrderPayment}
     */
    private function createPaidOrder(array $paymentOverrides = []): array
    {
        $user = User::factory()->create();
        $unitPrice = 20000;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $unitPrice,
            'total_amount' => $unitPrice,
            'total_paid_amount' => $unitPrice,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        $snapshotOverride = [
            'product_snapshot' => [
                'id' => null, 'name' => ['ko' => 't', 'en' => 't'], 'product_code' => null,
                'sku' => null, 'brand_id' => null, 'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                'currency_code' => 'KRW', 'stock_quantity' => 100, 'tax_status' => 'taxable',
                'tax_rate' => 10, 'has_options' => false, 'option_groups' => null, 'thumbnail_url' => null,
            ],
            'option_snapshot' => [
                'id' => null, 'option_code' => null, 'option_values' => null, 'option_name' => 't',
                'price_adjustment' => 0, 'list_price' => $unitPrice, 'selling_price' => $unitPrice,
                'currency_code' => 'KRW', 'stock_quantity' => 100, 'weight' => 0, 'volume' => 0,
            ],
        ];

        OrderOption::factory()->forOrder($order)->create(array_merge([
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'subtotal_price' => $unitPrice,
            'subtotal_paid_amount' => $unitPrice,
            'subtotal_discount_amount' => 0,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ], $snapshotOverride));

        $payment = OrderPayment::factory()->forOrder($order)->create(array_merge([
            'payment_method' => PaymentMethodEnum::VBANK,
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => $unitPrice,
            'paid_amount_base' => $unitPrice,
            'paid_at' => now(),
            'refund_bank_code' => null,
            'refund_bank_name' => null,
            'refund_bank_account' => null,
            'refund_bank_holder' => null,
        ], $paymentOverrides));

        return compact('order', 'payment');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function cancel(Order $order, array $body = []): TestResponse
    {
        return $this->actingAs($this->adminUser)->postJson(
            "/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel",
            array_merge(['type' => 'full', 'reason' => 'changed_mind', 'cancel_pg' => false], $body)
        );
    }

    public function test_주문상세_응답이_환불계좌를_노출한다(): void
    {
        // 취소 모달은 주문 시 입력된 계좌를 프리필한다. 응답에 키가 없으면 프리필이 불가능하다.
        ['order' => $order] = $this->createPaidOrder([
            'refund_bank_code' => '088',
            'refund_bank_name' => '신한은행',
            'refund_bank_account' => '110-123-456789',
            'refund_bank_holder' => '홍길동',
        ]);

        $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('data.payment.refund_bank_code', '088')
            ->assertJsonPath('data.payment.refund_bank_name', '신한은행')
            ->assertJsonPath('data.payment.refund_bank_account', '110-123-456789')
            ->assertJsonPath('data.payment.refund_bank_holder', '홍길동');
    }

    // ------------------------------------------------- vbank 입금완료 = 필수

    public function test_가상계좌_입금완료_취소는_환불계좌가_없으면_거부된다(): void
    {
        ['order' => $order] = $this->createPaidOrder();

        $this->cancel($order)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'refund_bank.bank_code',
                'refund_bank.account_number',
                'refund_bank.holder',
            ]);

        $order->refresh();
        $this->assertNotSame(OrderStatusEnum::CANCELLED, $order->order_status, '검증 실패 시 취소되면 안 된다');
    }

    public function test_주문시_입력된_환불계좌가_있으면_취소_요청에_다시_넣지_않아도_된다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'refund_bank_code' => '004',
            'refund_bank_name' => '국민은행',
            'refund_bank_account' => '110-123-456789',
            'refund_bank_holder' => '홍길동',
        ]);

        $this->cancel($order)->assertOk();
    }

    public function test_가상계좌_입금완료_취소에_환불계좌를_주면_통과하고_결제행에_저장된다(): void
    {
        ['order' => $order, 'payment' => $payment] = $this->createPaidOrder();

        $this->cancel($order, [
            'refund_bank' => [
                'bank_code' => '004',
                'account_number' => '110-123-456789',
                'holder' => '홍길동',
            ],
        ])->assertOk();

        $payment->refresh();
        $this->assertSame('004', $payment->refund_bank_code);
        $this->assertSame('110-123-456789', $payment->refund_bank_account);
        $this->assertSame('홍길동', $payment->refund_bank_holder);
        $this->assertNotNull($payment->refund_bank_name, '은행명이 은행코드로 조회되어 저장되어야 한다');
    }

    // ------------------------------------------------- vbank 입금전 / dbank = 선택

    public function test_가상계좌_입금전_취소는_환불계좌가_없어도_된다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
        ]);

        $this->cancel($order)->assertOk();
    }

    public function test_무통장_취소는_환불계좌가_없어도_된다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'payment_method' => PaymentMethodEnum::DBANK,
        ]);

        $this->cancel($order)->assertOk();
    }

    public function test_카드_취소는_환불계좌가_없어도_된다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'payment_method' => PaymentMethodEnum::CARD,
        ]);

        $this->cancel($order)->assertOk();
    }

    // ------------------------------------------------- 부분 입력 거부 (결제수단 무관)

    public function test_무통장이라도_환불계좌_부분_입력은_거부된다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'payment_method' => PaymentMethodEnum::DBANK,
        ]);

        $this->cancel($order, ['refund_bank' => ['account_number' => '110-123-456789']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refund_bank.bank_code', 'refund_bank.holder']);
    }

    public function test_가상계좌_환불계좌_부분_입력은_거부된다(): void
    {
        ['order' => $order] = $this->createPaidOrder();

        $this->cancel($order, ['refund_bank' => ['bank_code' => '004', 'account_number' => '110-1']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refund_bank.holder']);
    }
}
