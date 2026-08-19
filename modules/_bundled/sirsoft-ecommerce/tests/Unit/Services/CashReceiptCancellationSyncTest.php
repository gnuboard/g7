<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\RegistersTestCashReceiptProvider;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 주문 취소 → 현금영수증 동기화 연동 테스트 (A-7)
 *
 * syncFromOrder() 는 취소 트랜잭션이 커밋된 뒤에 호출된다. 따라서:
 *   - 재발급이 실패해도 주문 취소는 롤백되지 않는다
 *   - 실패 이력(FAILED)이 원장에 남아 관리자 수동 재발급의 근거가 된다
 */
class CashReceiptCancellationSyncTest extends ModuleTestCase
{
    use RegistersTestCashReceiptProvider;

    private const PROVIDER = 'tosspayments';

    private const IDENTIFIER = '01012345678';

    private bool $issueShouldFail = false;

    private int $receiptSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issueShouldFail = false;
        $this->receiptSequence = 0;

        $this->registerCashReceiptProvider(self::PROVIDER);
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
    }

    /**
     * 발급/취소 프로바이더 리스너를 등록합니다.
     */
    private function registerProvider(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            function (array $result, Order $order, string $provider, array $payload) {
                if ($provider !== self::PROVIDER) {
                    return $result;
                }

                if ($this->issueShouldFail) {
                    return [
                        'success' => false,
                        'error_code' => 'PROVIDER_ERROR',
                        'error_message' => '발급 실패',
                        'receipt_key' => null,
                        'receipt_url' => null,
                        'issue_number' => null,
                        'raw_response' => null,
                    ];
                }

                $key = 'receipt-'.(++$this->receiptSequence);

                return [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'receipt_key' => $key,
                    'receipt_url' => "https://example.test/receipt/{$key}",
                    'issue_number' => '12345678',
                    'raw_response' => null,
                ];
            },
        );

        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.cancel',
            fn (array $result, Order $order, string $provider, string $receiptKey) => $provider === self::PROVIDER
                ? [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'receipt_key' => $receiptKey,
                    'raw_response' => null,
                ]
                : $result,
        );
    }

    /**
     * 현금영수증이 발급된 무통장 결제완료 주문(옵션 2개)을 생성합니다.
     *
     * @return array{order: Order, options: array<int, OrderOption>}
     */
    private function makeIssuedOrder(int $unitPrice = 10000, int $optionCount = 2): array
    {
        $total = $unitPrice * $optionCount;

        $order = Order::factory()->create([
            'user_id' => User::factory()->create()->id,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'total_paid_amount' => $total,
            'total_due_amount' => 0,
            'total_cancelled_amount' => 0,
            'cancellation_count' => 0,
            'paid_at' => now(),
            'promotions_applied_snapshot' => [],
            'shipping_policy_applied_snapshot' => [],
            // 무통장 실입금액 = 전액 현금성. 전액 과세.
            'total_cash_equivalent_amount' => $total,
            'total_tax_amount' => $total,
            'total_tax_free_amount' => 0,
        ]);

        $snapshot = [
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

        $options = [];
        for ($i = 0; $i < $optionCount; $i++) {
            $options[] = OrderOption::factory()->forOrder($order)->create(array_merge([
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'subtotal_price' => $unitPrice,
                'subtotal_paid_amount' => $unitPrice,
                'subtotal_discount_amount' => 0,
                'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            ], $snapshot));
        }

        OrderPayment::factory()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_amount_local' => $total,
            'paid_amount_base' => $total,
            'paid_at' => now(),
        ]);

        $order = $order->fresh();

        app(CashReceiptService::class)->issue(
            $order,
            CashReceiptType::INCOME,
            self::IDENTIFIER,
            CashReceiptIdentifierType::PHONE,
        );

        return ['order' => $order->fresh(), 'options' => $options];
    }

    private function activeReceipt(Order $order): ?OrderCashReceipt
    {
        return app(CashReceiptService::class)->findActiveReceipt($order->fresh());
    }

    #[Test]
    public function 부분취소시_전체취소_후_잔여금액으로_재발급된다(): void
    {
        $this->registerProvider();
        ['order' => $order, 'options' => $options] = $this->makeIssuedOrder(10000, 2);

        $this->assertSame(20000, (int) $this->activeReceipt($order)->amount);

        app(OrderCancellationService::class)->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
            reason: '단순변심',
            reasonDetail: null,
            cancelledBy: null,
            cancelPg: false,
        );

        $active = $this->activeReceipt($order);
        $this->assertNotNull($active, '잔여 금액에 대한 영수증이 재발급되어야 한다');
        $this->assertSame(10000, (int) $active->amount, '잔여 10000원으로 재발급');
        $this->assertSame(CashReceiptType::INCOME, $active->receipt_type, '발급 용도가 유지된다');

        // 원장: 최초 발급 + 전체취소 + 재발급 = 3행
        $this->assertSame(3, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    #[Test]
    public function 전액취소시_영수증은_전체취소만_되고_재발급되지_않는다(): void
    {
        $this->registerProvider();
        ['order' => $order] = $this->makeIssuedOrder(10000, 2);

        app(OrderCancellationService::class)->cancelOrder(
            order: $order,
            reason: '단순변심',
            reasonDetail: null,
            cancelledBy: null,
            cancelPg: false,
        );

        $this->assertNull($this->activeReceipt($order), '활성 영수증이 없어야 한다');

        $cancelRow = OrderCashReceipt::where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::CANCEL)
            ->first();
        $this->assertNotNull($cancelRow);
        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $cancelRow->issue_status);
    }

    #[Test]
    public function 취소는_성공하고_재발급만_실패해도_주문취소_트랜잭션은_롤백되지_않는다(): void
    {
        $this->registerProvider();
        ['order' => $order, 'options' => $options] = $this->makeIssuedOrder(10000, 2);

        // 최초 발급은 성공했고, 재발급 시점부터 프로바이더가 실패한다.
        $this->issueShouldFail = true;

        app(OrderCancellationService::class)->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
            reason: '단순변심',
            reasonDetail: null,
            cancelledBy: null,
            cancelPg: false,
        );

        // (a) 주문 취소는 확정되어 있어야 한다
        $this->assertSame(1, OrderCancel::where('order_id', $order->id)->count(), '취소 레코드가 남아야 한다');
        $this->assertSame(
            OrderStatusEnum::CANCELLED,
            $options[0]->fresh()->option_status,
            '취소된 옵션 상태가 확정되어야 한다',
        );

        // (b) 이력 2행: 전체취소 COMPLETED + 재발급 FAILED
        $cancelRow = OrderCashReceipt::where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::CANCEL)
            ->first();
        $this->assertNotNull($cancelRow);
        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $cancelRow->issue_status);

        $failedIssue = OrderCashReceipt::where('order_id', $order->id)
            ->where('transaction_type', CashReceiptTransactionType::ISSUE)
            ->where('issue_status', CashReceiptIssueStatus::FAILED)
            ->first();
        $this->assertNotNull($failedIssue, '재발급 실패 이력이 원장에 남아야 한다');
        $this->assertSame('PROVIDER_ERROR', $failedIssue->error_code);

        // (c) 활성 영수증 없음 → 관리자 수동 재발급 경로가 열린다
        $this->assertNull($this->activeReceipt($order));
    }

    #[Test]
    public function 프로바이더가_예외를_던져도_주문취소는_확정된다(): void
    {
        // syncFromOrder 는 이미 커밋된 취소를 되돌릴 수 없다 — 예외를 삼키고 로그만 남긴다.
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            fn (array $result, Order $order, string $provider, array $payload) => $provider === self::PROVIDER
                ? [
                    'success' => true, 'error_code' => null, 'error_message' => null,
                    'receipt_key' => 'receipt-1', 'receipt_url' => 'https://example.test/r/1',
                    'issue_number' => '1', 'raw_response' => null,
                ]
                : $result,
        );
        HookManager::addFilter('sirsoft-ecommerce.cash_receipt.cancel', function () {
            throw new \RuntimeException('PG 통신 실패');
        });

        ['order' => $order, 'options' => $options] = $this->makeIssuedOrder(10000, 2);

        app(OrderCancellationService::class)->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
            reason: '단순변심',
            reasonDetail: null,
            cancelledBy: null,
            cancelPg: false,
        );

        $this->assertSame(1, OrderCancel::where('order_id', $order->id)->count(), '주문 취소는 확정되어야 한다');
    }

    #[Test]
    public function 발급_이력이_없는_주문의_취소는_영수증을_건드리지_않는다(): void
    {
        $this->registerProvider();
        ['order' => $order, 'options' => $options] = $this->makeIssuedOrder(10000, 2);

        // 발급 이력을 지우고 취소 — syncFromOrder 는 no-op 이어야 한다.
        OrderCashReceipt::where('order_id', $order->id)->delete();

        app(OrderCancellationService::class)->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
            reason: '단순변심',
            reasonDetail: null,
            cancelledBy: null,
            cancelPg: false,
        );

        $this->assertSame(0, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    // ─────────────────────────────────────────────
    // recoverFailedIssue() — 관리자 수동 복구
    // ─────────────────────────────────────────────

    /**
     * "취소 성공 + 재발급 실패" 중간 상태를 만듭니다.
     *
     * @return Order 활성 영수증이 사라진 주문
     */
    private function makeReissueFailedState(): Order
    {
        ['order' => $order, 'options' => $options] = $this->makeIssuedOrder(10000, 2);

        $this->issueShouldFail = true;

        app(OrderCancellationService::class)->cancelOrderOptions(
            order: $order,
            cancelItems: [['order_option_id' => $options[0]->id, 'cancel_quantity' => 1]],
            reason: '단순변심',
            reasonDetail: null,
            cancelledBy: null,
            cancelPg: false,
        );

        $this->assertNull($this->activeReceipt($order), '사전 조건: 활성 영수증이 없어야 한다');

        return $order->fresh();
    }

    #[Test]
    public function recover_는_재발급_실패_상태를_잔여금액으로_복구한다(): void
    {
        // syncFromOrder 는 활성 영수증이 없으면 no-op 이므로 이 상태를 복구하지 못한다.
        $this->registerProvider();
        $order = $this->makeReissueFailedState();

        $this->issueShouldFail = false;

        $receipt = app(CashReceiptService::class)->recoverFailedIssue($order->fresh());

        $this->assertNotNull($receipt);
        $this->assertSame(10000, (int) $receipt->amount, '잔여 금액으로 발급되어야 한다');
        $this->assertSame(CashReceiptType::INCOME, $receipt->receipt_type, '최초 발급 용도를 유지한다');
        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $receipt->issue_status);
    }

    #[Test]
    public function recover_는_활성_영수증이_있으면_금액_동기화로_위임한다(): void
    {
        $this->registerProvider();
        ['order' => $order] = $this->makeIssuedOrder(10000, 2);

        // 금액만 바뀐 상태 (취소 없이 직접 조작)
        $order->fresh()->update(['total_cash_equivalent_amount' => 5000, 'total_tax_amount' => 5000]);

        $receipt = app(CashReceiptService::class)->recoverFailedIssue($order->fresh());

        $this->assertNotNull($receipt);
        $this->assertSame(5000, (int) $receipt->amount);
    }

    #[Test]
    public function recover_는_암호문이_폐기되었으면_실패이력만_남기고_발급하지_않는다(): void
    {
        // 구매확정으로 암호문이 폐기된 경우 — 관리자가 식별번호를 재입력해야 한다.
        $this->registerProvider();
        $order = $this->makeReissueFailedState();
        $this->issueShouldFail = false;

        app(CashReceiptService::class)->purgeIdentifier($order->fresh());

        $receipt = app(CashReceiptService::class)->recoverFailedIssue($order->fresh());

        $this->assertNull($receipt);
        $this->assertNotNull(
            OrderCashReceipt::where('order_id', $order->id)->where('error_code', 'IDENTIFIER_UNAVAILABLE')->first(),
            '식별번호 부재 실패 이력이 남아야 한다',
        );
    }

    #[Test]
    public function recover_는_발급_시도_이력이_없으면_아무것도_하지_않는다(): void
    {
        // 복구가 아니라 신규 발급이다 — issue() 를 써야 한다.
        $this->registerProvider();
        ['order' => $order] = $this->makeIssuedOrder(10000, 2);
        OrderCashReceipt::where('order_id', $order->id)->delete();

        $this->assertNull(app(CashReceiptService::class)->recoverFailedIssue($order->fresh()));
        $this->assertSame(0, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    #[Test]
    public function recover_는_발급대상_금액이_0이면_아무것도_하지_않는다(): void
    {
        // 전액 환불된 주문 — 활성 영수증이 없는 것이 정상 결과다.
        $this->registerProvider();
        $order = $this->makeReissueFailedState();
        $order->fresh()->update(['total_cash_equivalent_amount' => 0]);

        $this->assertNull(app(CashReceiptService::class)->recoverFailedIssue($order->fresh()));
    }
}
