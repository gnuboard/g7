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
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderTaxInvoice;
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
 *   cancel_refund_bank_recorded_on_the_claim_row
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
     * 아직 입금되지 않은 무통장 주문을 만듭니다.
     *
     * 돌려줄 돈이 없는 주문이다 — 결제금액 0, 결제행은 `ready`, 상품은 주문대기.
     *
     * @return array{order: Order, payment: OrderPayment} 주문·결제행
     */
    private function createUnpaidBankTransferOrder(): array
    {
        $user = User::factory()->create();
        $unitPrice = 20000;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
            'subtotal_amount' => $unitPrice,
            'total_amount' => $unitPrice,
            'total_paid_amount' => 0,
            'total_due_amount' => $unitPrice,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => null,
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'subtotal_price' => $unitPrice,
            'subtotal_paid_amount' => 0,
            'subtotal_discount_amount' => 0,
            'option_status' => OrderStatusEnum::PENDING_ORDER,
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
        ]);

        $payment = OrderPayment::factory()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::READY,
            'paid_amount_local' => 0,
            'paid_amount_base' => 0,
            'paid_at' => null,
            'refund_bank_code' => null,
            'refund_bank_name' => null,
            'refund_bank_account' => null,
            'refund_bank_holder' => null,
        ]);

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

    /**
     * **돌려줄 돈이 없는 주문은 환불계좌를 요구하지 않는다.** (브라우저 실측 회귀)
     *
     * P9 는 "사람이 송금해야 하는 수단(무통장 등)은 계좌가 필수" 를 세웠는데, 그 판정이
     * **결제수단만** 보고 "돌려줄 돈이 있는가" 를 보지 않았다. 그래서 아직 입금되지 않은
     * 무통장 주문을 운영자가 취소하려 하면, 환불 예정액이 0원인데도 계좌를 내놓으라는 422 가
     * 나온다 — 존재하지 않는 환불을 위해 계좌를 지어내지 않는 한 **그 주문은 취소할 수 없다**.
     *
     * 만료 자동취소(`CancelPendingPaymentOrdersCommand`)는 서버 내부 경로라 이 검증을 지나지
     * 않으므로, 막히는 것은 **운영자의 수동 취소뿐**이고 그래서 눈에 띄기까지 오래 걸린다.
     *
     * @scenario surface=admin_cancel_modal, payment_method=dbank, refund_bank_requirement=nothing_to_refund_skip
     *
     * @effects cancel_refund_bank_skipped_when_nothing_to_refund
     *
     * @return void
     */
    public function test_미입금_주문은_환불계좌_없이_취소된다(): void
    {
        ['order' => $order] = $this->createUnpaidBankTransferOrder();

        $this->cancel($order)->assertOk();
    }

    /**
     * 같은 주문이라도 **입금이 끝났으면** 계좌를 요구한다 — 위 완화가 P9 를 되돌리지 않는다.
     *
     * @scenario surface=admin_cancel_modal, payment_method=dbank, refund_bank_requirement=dbank_required
     *
     * @effects cancel_refund_bank_required_for_dbank_with_money
     *
     * @return void
     */
    public function test_입금이_끝난_무통장_주문은_여전히_환불계좌가_필수다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'payment_method' => PaymentMethodEnum::DBANK,
        ]);

        $this->cancel($order)->assertStatus(422);
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

    /**
     * 입금완료 가상계좌 취소에 계좌를 주면 통과하고 **그 신청서 행**에 저장된다.
     *
     * 저장 자리가 결제행에서 신청서 행으로 옮겨졌다(#599 후속). 관리자 [주문취소]는 이제
     * 환불신청서를 만들 뿐이고 실제 송금은 [환불하기]가 하며, 결제행은 **그 환불을 실행하는
     * 시점**에 실행 대상 환불의 계좌로 갱신된다(`AppliesRefundBankAccount`) — 접수만으로
     * 결제행을 덮어쓰면, 계좌가 서로 다른 두 신청서가 있을 때 나중 접수가 앞선 환불의 송금
     * 대상을 바꿔 버린다.
     *
     * @scenario surface=admin_cancel_modal, payment_method=vbank, refund_bank_requirement=vbank_paid_required
     *
     * @effects cancel_refund_bank_recorded_on_the_claim_row
     */
    public function test_가상계좌_입금완료_취소에_환불계좌를_주면_통과하고_신청서에_저장된다(): void
    {
        ['order' => $order] = $this->createPaidOrder();

        $this->cancel($order, [
            'refund_bank' => [
                'bank_code' => '004',
                'account_number' => '110-123-456789',
                'holder' => '홍길동',
            ],
        ])->assertOk();

        $refund = OrderRefund::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('004', $refund->refund_bank_code);
        $this->assertSame('110-123-456789', $refund->refund_bank_account);
        $this->assertSame('홍길동', $refund->refund_bank_holder);
    }

    // ------------------------------------------------- vbank 입금전 / dbank = 선택

    public function test_가상계좌_입금전_취소는_환불계좌가_없어도_된다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
        ]);

        $this->cancel($order)->assertOk();
    }

    /**
     * 무통장 취소는 환불계좌가 **필수**다. (#599 후속 P9)
     *
     * 예전에는 입금완료 가상계좌만 계좌를 요구했다. 그래서 무통장 주문을 취소하면 **송금 대상
     * 계좌가 없는 채로** 환불이 `APPROVED` 에 앉았고, 운영자는 어디로 보내야 할지 모르는 환불
     * 건을 떠안았다 — 화면에도 오류에도 그 사실이 드러나지 않았다. 사람이 송금해야 하는
     * 수단은 입금 상태와 무관하게 계좌가 있어야 한다.
     *
     * @scenario surface=admin_cancel_modal, payment_method=dbank, refund_bank_requirement=dbank_required
     *
     * @effects cancel_refund_bank_required_for_dbank_with_money
     */
    public function test_무통장_취소는_환불계좌가_필수다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'payment_method' => PaymentMethodEnum::DBANK,
        ]);

        $this->cancel($order)->assertStatus(422);
    }

    /**
     * 계좌를 주면 통과한다 — 요구가 막는 것이 아니라 빠진 정보를 받아 내는 것이다.
     *
     * @scenario surface=admin_cancel_modal, payment_method=dbank, refund_bank_requirement=dbank_required
     *
     * @effects cancel_refund_bank_required_for_dbank_with_money
     */
    public function test_무통장_취소는_환불계좌를_주면_통과한다(): void
    {
        ['order' => $order] = $this->createPaidOrder([
            'payment_method' => PaymentMethodEnum::DBANK,
        ]);

        $this->cancel($order, [
            'refund_bank' => [
                'bank_code' => '004',
                'account_number' => '110-123-456789',
                'holder' => '홍길동',
            ],
        ])->assertOk();
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

    /**
     * 취소 뒤에도 남은 발급 완료 세금계산서를 주문상세가 알린다. (P15)
     *
     * 이 플래그는 **적재된 관계에서만** 판정한다 — Resource 는 행마다 실행되므로 그 자리에서
     * 관계를 재쿼리하면 주문 목록 한 페이지가 행 수만큼의 추가 쿼리를 부른다. 그래서 판정이
     * 성립하려면 상세 조회가 `taxInvoices` 를 실어 와야 한다(`OrderRepository::findWithRelations`).
     *
     * 그 짝이 깨지는 방식은 **조용하다** — eager load 한 줄이 빠지면 키가 응답에서 사라지고,
     * 화면은 `=== true` 로 판정하므로 경고 배지만 영영 뜨지 않는다. 오류도 로그도 없다.
     * 그래서 값과 부재를 함께 단언한다.
     *
     * @scenario actor=admin, change_mode=manual
     *
     * @effects order_detail_flags_a_stale_tax_invoice_after_cancellation
     */
    public function test_주문상세가_취소_뒤_남은_세금계산서를_알린다(): void
    {
        ['order' => $order] = $this->createPaidOrder();

        $endpoint = "/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}";

        // 취소가 없으면 남은 계산서가 있어도 알릴 것이 없다 — 대조군.
        OrderTaxInvoice::factory()->issued()->create(['order_id' => $order->id]);

        $this->actingAs($this->adminUser)
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.tax_invoice_needs_review', false);

        // 취소가 일어나면 같은 계산서가 '미처리' 로 드러나야 한다.
        $order->update(['total_cancelled_amount' => 10000]);

        $this->actingAs($this->adminUser)
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.tax_invoice_needs_review', true);
    }
}
