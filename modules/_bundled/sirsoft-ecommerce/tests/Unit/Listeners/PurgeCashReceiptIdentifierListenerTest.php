<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\PurgeCashReceiptIdentifierListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 구매확정 시 식별번호 암호문 폐기 리스너 테스트 (D15)
 *
 * 암호문만 지우고 이력·receipt_url·마스킹 값은 국세청 신고 근거로 유지한다.
 */
class PurgeCashReceiptIdentifierListenerTest extends ModuleTestCase
{
    private const PROVIDER = 'tosspayments';

    private const IDENTIFIER = '01012345678';

    protected function setUp(): void
    {
        parent::setUp();

        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
    }

    private function registerProvider(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            fn (array $result, Order $order, string $provider, array $payload) => $provider === self::PROVIDER
                ? [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'receipt_key' => 'receipt-1',
                    'receipt_url' => 'https://example.test/receipt/receipt-1',
                    'issue_number' => '12345678',
                    'raw_response' => null,
                ]
                : $result,
        );
    }

    private function makeIssuedOrder(): Order
    {
        $order = Order::factory()->create([
            'total_amount' => 11000,
            'total_cash_equivalent_amount' => 11000,
            'total_tax_amount' => 11000,
            'total_tax_free_amount' => 0,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        $order = $order->fresh();

        app(CashReceiptService::class)->issue(
            $order,
            CashReceiptType::INCOME,
            self::IDENTIFIER,
            CashReceiptIdentifierType::PHONE,
        );

        return $order->fresh();
    }

    private function listener(): PurgeCashReceiptIdentifierListener
    {
        return app(PurgeCashReceiptIdentifierListener::class);
    }

    #[Test]
    public function 구매확정_훅을_구독한다(): void
    {
        $hooks = PurgeCashReceiptIdentifierListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_purchase_confirmed', $hooks);
        $this->assertSame('purgeIdentifier', $hooks['sirsoft-ecommerce.order.after_purchase_confirmed']['method']);
    }

    #[Test]
    public function 암호문은_지워지고_이력과_영수증_ur_l과_마스킹은_유지된다(): void
    {
        $this->registerProvider();
        $order = $this->makeIssuedOrder();

        // 사전 조건: 암호문이 저장되어 있다
        $this->assertSame(self::IDENTIFIER, $order->payment->cash_receipt_identifier_encrypted);

        $this->listener()->purgeIdentifier($order);

        $payment = $order->fresh()->payment;
        $this->assertNull($payment->cash_receipt_identifier_encrypted, '암호문은 폐기되어야 한다');

        // 국세청 신고 근거는 유지된다
        $this->assertTrue((bool) $payment->is_cash_receipt_issued);
        $this->assertSame('*******5678', $payment->cash_receipt_identifier);

        $receipt = OrderCashReceipt::where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($receipt);
        $this->assertSame('https://example.test/receipt/receipt-1', $receipt->receipt_url);
        $this->assertSame('*******5678', $receipt->identifier_masked);
    }

    #[Test]
    public function 폐기_후_재발급하려면_식별번호를_다시_입력해야_한다(): void
    {
        // 암호문이 없으면 syncFromOrder 는 재발급을 시도하지 않고 FAILED 이력만 남긴다 (조용한 실패 금지).
        $this->registerProvider();
        $order = $this->makeIssuedOrder();
        $this->listener()->purgeIdentifier($order);

        $order->fresh()->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);

        app(CashReceiptService::class)->syncFromOrder($order->fresh(), '부분환불');

        $failed = OrderCashReceipt::where('order_id', $order->id)
            ->where('error_code', 'IDENTIFIER_UNAVAILABLE')
            ->first();

        $this->assertNotNull($failed, '식별번호 부재 실패 이력이 남아야 한다');
    }

    #[Test]
    public function 중복_확정에도_안전하다(): void
    {
        $this->registerProvider();
        $order = $this->makeIssuedOrder();

        $this->listener()->purgeIdentifier($order);
        $this->listener()->purgeIdentifier($order->fresh());

        $this->assertNull($order->fresh()->payment->cash_receipt_identifier_encrypted);
    }

    #[Test]
    public function 결제_레코드가_없어도_예외를_던지지_않는다(): void
    {
        $order = Order::factory()->create();

        $this->listener()->purgeIdentifier($order);

        $this->assertTrue(true);
    }
}
