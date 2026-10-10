<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\RegistersTestCashReceiptProvider;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 현금영수증 발급·취소의 실패는 FAILED 이력으로 남는다 (B1 · B2 · B3)
 *
 * - B1·B2 프로바이더가 예외를 던지면 이력이 생기지 않아 관리자 화면에 재발급 버튼이 없었다(호출부는 로그만 남겼다).
 * - B3 결제 통화 환산이 실패하면 기준 통화 금액으로 그대로 발급해, 실제로 낸 금액과 다른 증빙이 신고될 수 있었다.
 *
 * 시나리오 매니페스트: `tests/scenarios/cash-receipt-failure-record.yaml`
 */
class CashReceiptFailureRecordTest extends ModuleTestCase
{
    use RegistersTestCashReceiptProvider;

    private const PROVIDER = 'tosspayments';

    /**
     * 테스트 프로바이더를 설정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registerCashReceiptProvider(self::PROVIDER);
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
    }

    /**
     * 무통장 결제완료 주문을 만듭니다.
     *
     * @param  array  $snapshot  통화 스냅샷
     * @return Order 주문
     */
    private function paidDbankOrder(array $snapshot = []): Order
    {
        $order = Order::factory()->create([
            'total_amount' => 11000,
            'total_cash_equivalent_amount' => 11000,
            'total_tax_amount' => 11000,
            'total_tax_free_amount' => 0,
            'currency_snapshot' => $snapshot,
        ]);
        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        return $order->fresh();
    }

    /**
     * 발급 프로바이더가 예외를 던지면 FAILED 발급 이력이 남는다(B1).
     *
     * @scenario item=b1_issue_exception
     *
     * @effects provider_exception_leaves_a_failed_receipt_record
     *
     * @return void 반환값 없음
     */
    public function test_issue_exception_leaves_a_failed_record(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.cash_receipt.issue', function () {
            throw new \RuntimeException('provider timeout');
        });
        $order = $this->paidDbankOrder();

        $receipt = app(CashReceiptService::class)->issue($order, CashReceiptType::INCOME, '01012345678');

        $this->assertSame(CashReceiptIssueStatus::FAILED, $receipt->issue_status);
        $this->assertSame('PROVIDER_EXCEPTION', $receipt->error_code);
    }

    /**
     * 취소 프로바이더가 예외를 던지면 FAILED 취소 이력이 남고 실패로 답한다(B2).
     *
     * @scenario item=b2_cancel_exception
     *
     * @effects provider_exception_leaves_a_failed_receipt_record
     *
     * @return void 반환값 없음
     */
    public function test_cancel_exception_leaves_a_failed_record(): void
    {
        $order = $this->paidDbankOrder();
        OrderCashReceipt::create([
            'order_id' => $order->id,
            'provider' => self::PROVIDER,
            'receipt_key' => 'rk-1',
            'transaction_type' => CashReceiptTransactionType::ISSUE->value,
            'receipt_type' => CashReceiptType::INCOME->value,
            'amount' => 11000,
            'tax_free_amount' => 0,
            'issue_status' => CashReceiptIssueStatus::COMPLETED->value,
            'issued_at' => now(),
        ]);
        HookManager::addFilter('sirsoft-ecommerce.cash_receipt.cancel', function () {
            throw new \RuntimeException('provider down');
        });

        $ok = app(CashReceiptService::class)->cancelAll($order->fresh(['payment']));

        $this->assertFalse($ok);
        $cancel = OrderCashReceipt::where('order_id', $order->id)->where('transaction_type', CashReceiptTransactionType::CANCEL->value)->first();
        $this->assertNotNull($cancel, '취소 실패 이력이 남지 않았다');
        $this->assertSame(CashReceiptIssueStatus::FAILED, $cancel->issue_status);
        $this->assertSame('PROVIDER_EXCEPTION', $cancel->error_code);
    }

    /**
     * 결제 통화 환산이 안 되면 기준 통화 금액으로 발급하지 않고 FAILED 이력과 사유를 남긴다(B3).
     *
     * @scenario item=b3_conversion_failure
     *
     * @effects unconvertible_amount_is_not_issued_in_base_currency
     *
     * @return void 반환값 없음
     */
    public function test_unconvertible_amount_is_not_issued(): void
    {
        $calls = 0;
        HookManager::addFilter('sirsoft-ecommerce.cash_receipt.issue', function (array $result) use (&$calls) {
            $calls++;

            return array_merge($result, ['success' => true, 'receipt_key' => 'rk-b3']);
        });
        // 결제 통화 환율이 없는 손상된 스냅샷
        $order = $this->paidDbankOrder(['base_currency' => 'KRW', 'order_currency' => 'USD', 'exchange_rates' => []]);

        $receipt = app(CashReceiptService::class)->issue($order, CashReceiptType::INCOME, '01012345678');

        $this->assertSame(0, $calls, '환산할 수 없는 금액으로 프로바이더를 불렀다');
        $this->assertSame(CashReceiptIssueStatus::FAILED, $receipt->issue_status);
        $this->assertSame('CURRENCY_CONVERSION_FAILED', $receipt->error_code);
    }
}
