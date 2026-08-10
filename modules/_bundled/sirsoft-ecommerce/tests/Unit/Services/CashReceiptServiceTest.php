<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCashReceiptRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 현금영수증 서비스 테스트 (A-1 / A-7 / D7 / D15)
 *
 * syncFromOrder() 는 "왜" 금액이 바뀌었는지 모르는 무상태 재계산기다.
 * 감소/증가/무변동/전액취소/이력없음/과세비율변동 6케이스를 검증한다.
 */
class CashReceiptServiceTest extends ModuleTestCase
{
    private const PROVIDER = 'tosspayments';

    private const IDENTIFIER = '01012345678';

    /** @var array<int, array{provider: string, payload: array}> 발급 훅이 받은 호출 기록 */
    private array $issueCalls = [];

    /** @var array<int, array{provider: string, receipt_key: string}> 취소 훅이 받은 호출 기록 */
    private array $cancelCalls = [];

    private int $receiptSequence = 0;

    /** 발급 훅이 실패를 반환할지 여부 (테스트 도중 전환 가능) */
    private bool $issueShouldFail = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issueCalls = [];
        $this->cancelCalls = [];
        $this->receiptSequence = 0;
        $this->issueShouldFail = false;

        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
    }

    /**
     * 프로바이더 리스너를 등록합니다.
     *
     * ModuleTestCase 가 HookManager static 상태를 테스트마다 스냅샷/복원하므로 별도 정리 불필요.
     */
    private function registerProvider(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            function (array $result, Order $order, string $provider, array $payload) {
                if ($provider !== self::PROVIDER) {
                    return $result;
                }

                $this->issueCalls[] = ['provider' => $provider, 'payload' => $payload];

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
                    'raw_response' => [
                        'receiptKey' => $key,
                        'customerIdentityNumber' => self::IDENTIFIER,
                        'amount' => $payload['amount'],
                    ],
                ];
            },
        );

        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.cancel',
            function (array $result, Order $order, string $provider, string $receiptKey) {
                if ($provider !== self::PROVIDER) {
                    return $result;
                }

                $this->cancelCalls[] = ['provider' => $provider, 'receipt_key' => $receiptKey];

                return [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'receipt_key' => $receiptKey,
                    'raw_response' => ['receiptKey' => $receiptKey, 'status' => 'CANCELED'],
                ];
            },
        );
    }

    /**
     * 무통장 주문을 생성합니다.
     */
    private function makeOrder(int $cashEquivalent, int $taxable, int $taxFree): Order
    {
        $order = Order::factory()->create([
            'total_amount' => $cashEquivalent,
            'total_cash_equivalent_amount' => $cashEquivalent,
            'total_tax_amount' => $taxable,
            'total_tax_free_amount' => $taxFree,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        return $order->fresh();
    }

    private function service(): CashReceiptService
    {
        return app(CashReceiptService::class);
    }

    /**
     * 주문의 현재 활성 영수증을 반환합니다.
     */
    private function activeReceipt(Order $order): ?OrderCashReceipt
    {
        return app(OrderCashReceiptRepositoryInterface::class)->findActiveReceipt($order->fresh());
    }

    #[Test]
    public function 발급하면_이력과_결제요약이_함께_갱신된다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);

        $receipt = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $receipt->issue_status);
        $this->assertSame(CashReceiptTransactionType::ISSUE, $receipt->transaction_type);
        $this->assertSame(11000, (int) $receipt->amount);
        $this->assertSame(0, (int) $receipt->tax_free_amount);
        $this->assertNotNull($receipt->receipt_url);

        $payment = $order->fresh()->payment;
        $this->assertTrue($payment->is_cash_receipt_issued);
        $this->assertSame('income', $payment->cash_receipt_type);
        $this->assertSame(CashReceiptIdentifierType::PHONE, $payment->cash_receipt_identifier_type);
        $this->assertNotNull($payment->cash_receipt_issued_at);
    }

    #[Test]
    public function 식별번호는_마스킹되어_저장되고_원본은_암호화된다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);

        $receipt = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        // 이력에는 마스킹 값만
        $this->assertSame('*******5678', $receipt->identifier_masked);
        $this->assertStringNotContainsString(self::IDENTIFIER, (string) $receipt->identifier_masked);

        // 암호문 왕복
        $payment = $order->fresh()->payment;
        $this->assertSame(self::IDENTIFIER, $payment->cash_receipt_identifier_encrypted);

        // DB 원시값에 평문이 없어야 한다
        $raw = DB::table('ecommerce_order_payments')->where('id', $payment->id)->first();
        $this->assertNotSame(self::IDENTIFIER, $raw->cash_receipt_identifier_encrypted);
        $this->assertStringNotContainsString(self::IDENTIFIER, (string) $raw->cash_receipt_identifier_encrypted);
        $this->assertStringNotContainsString(self::IDENTIFIER, (string) $raw->cash_receipt_identifier);

        // 이력 테이블 어느 컬럼에도 평문·암호문이 없어야 한다
        $rawReceipt = DB::table('ecommerce_order_cash_receipts')->where('id', $receipt->id)->first();
        foreach ((array) $rawReceipt as $value) {
            $this->assertStringNotContainsString(self::IDENTIFIER, (string) $value);
        }
    }

    #[Test]
    public function 원응답의_민감키는_마스킹되어_보관된다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);

        $receipt = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame('***', $receipt->raw_response['customerIdentityNumber']);
        $this->assertSame(11000, $receipt->raw_response['amount']);
        $this->assertSame('receipt-1', $receipt->raw_response['receiptKey']);
    }

    /**
     * 금액 변동(감소) 시 전체취소 후 잔액으로 재발급하는지 확인
     *
     * @effects amount_change_triggers_cancel_then_reissue
     */
    #[Test]
    public function sync_금액감소시_전체취소_후_전액_재발급한다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        // 부분환불로 금액 감소
        $order->update([
            'total_cash_equivalent_amount' => 5500,
            'total_tax_amount' => 5500,
        ]);

        $this->service()->syncFromOrder($order->fresh(), '부분환불');

        $this->assertCount(1, $this->cancelCalls, '전체취소가 1회 호출되어야 한다');
        $this->assertCount(2, $this->issueCalls, '재발급이 수행되어야 한다');
        $this->assertSame(5500, $this->issueCalls[1]['payload']['amount']);

        $active = $this->activeReceipt($order);
        $this->assertSame(5500, (int) $active->amount);
        $this->assertSame(CashReceiptType::INCOME, $active->receipt_type, '발급 용도가 유지되어야 한다');
    }

    #[Test]
    public function sync_금액증가시에도_전체취소_후_전액_재발급한다(): void
    {
        // 금액 증가 흐름은 현행 코드에 없지만, syncFromOrder 는 방향과 무관해야 한다 (D17).
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $order->update([
            'total_cash_equivalent_amount' => 14000,
            'total_tax_amount' => 14000,
        ]);

        $this->service()->syncFromOrder($order->fresh(), '반품배송비 청구');

        $this->assertCount(1, $this->cancelCalls);
        $this->assertSame(14000, $this->issueCalls[1]['payload']['amount']);
        $this->assertSame(14000, (int) $this->activeReceipt($order)->amount);
    }

    #[Test]
    public function sync_변동이_없으면_아무것도_하지_않는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->service()->syncFromOrder($order->fresh());
        $this->service()->syncFromOrder($order->fresh());

        $this->assertCount(0, $this->cancelCalls, '멱등: 취소가 호출되지 않아야 한다');
        $this->assertCount(1, $this->issueCalls, '멱등: 재발급이 호출되지 않아야 한다');
        $this->assertSame(1, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    #[Test]
    public function sync_발급액이_0이하이면_전체취소만_수행한다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $order->update([
            'total_cash_equivalent_amount' => 0,
            'total_tax_amount' => 0,
        ]);

        $this->service()->syncFromOrder($order->fresh(), '전액취소');

        $this->assertCount(1, $this->cancelCalls);
        $this->assertCount(1, $this->issueCalls, '재발급을 시도하지 않아야 한다');
        $this->assertNull($this->activeReceipt($order));
        $this->assertFalse($order->fresh()->payment->is_cash_receipt_issued);
    }

    #[Test]
    public function sync_발급_이력이_없으면_아무것도_하지_않는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);

        $this->service()->syncFromOrder($order, '부분환불');

        $this->assertCount(0, $this->issueCalls);
        $this->assertCount(0, $this->cancelCalls);
        $this->assertSame(0, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    #[Test]
    public function sync_과세면세_비율이_바뀌면_면세액이_재계산된다(): void
    {
        $this->registerProvider();
        // 과세 6000 + 면세 4000 = 10000
        $order = $this->makeOrder(10000, 6000, 4000);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);
        $this->assertSame(4000, $this->issueCalls[0]['payload']['tax_free_amount']);

        // 면세 상품만 부분환불 → 과세 6000 만 잔존
        $order->update([
            'total_cash_equivalent_amount' => 6000,
            'total_tax_amount' => 6000,
            'total_tax_free_amount' => 0,
        ]);

        $this->service()->syncFromOrder($order->fresh(), '면세상품 환불');

        $this->assertSame(6000, $this->issueCalls[1]['payload']['amount']);
        $this->assertSame(0, $this->issueCalls[1]['payload']['tax_free_amount']);
        $this->assertSame(0, (int) $this->activeReceipt($order)->tax_free_amount);
    }

    #[Test]
    public function 취소는_성공했는데_재발급이_실패하면_faile_d_이력이_남는다(): void
    {
        // 첫 발급은 성공시키고, 이후 재발급만 실패시킨다.
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        // 발급 훅을 실패 버전으로 교체
        $this->issueShouldFail = true;

        $order->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);
        $this->service()->syncFromOrder($order->fresh(), '부분환불');

        $receipts = OrderCashReceipt::where('order_id', $order->id)->orderBy('id')->get();

        $this->assertCount(3, $receipts, 'issue(성공) + cancel(성공) + issue(실패)');
        $this->assertSame(CashReceiptTransactionType::CANCEL, $receipts[1]->transaction_type);
        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $receipts[1]->issue_status);
        $this->assertSame(CashReceiptTransactionType::ISSUE, $receipts[2]->transaction_type);
        $this->assertSame(CashReceiptIssueStatus::FAILED, $receipts[2]->issue_status);
        $this->assertSame('PROVIDER_ERROR', $receipts[2]->error_code);

        // 활성 영수증이 없는 상태 — 관리자 수동 재발급 대상
        $this->assertNull($this->activeReceipt($order));
    }

    #[Test]
    public function sync_는_예외를_던지지_않아_환불_트랜잭션을_롤백시키지_않는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->issueShouldFail = true;

        $order->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);

        // 예외가 전파되지 않아야 한다
        $this->service()->syncFromOrder($order->fresh(), '부분환불');

        $this->assertTrue(true, 'syncFromOrder 가 예외를 던지지 않았다');
    }

    #[Test]
    public function 암호문이_없으면_재발급을_시도하지_않고_faile_d_이력을_남긴다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        // 구매확정으로 암호문 폐기
        $this->service()->purgeIdentifier($order->fresh());

        $order->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);
        $this->service()->syncFromOrder($order->fresh(), '부분환불');

        $this->assertCount(0, $this->cancelCalls, '취소도 시도하지 않아야 한다');
        $this->assertCount(1, $this->issueCalls, '재발급을 시도하지 않아야 한다');

        $failed = OrderCashReceipt::where('order_id', $order->id)
            ->where('issue_status', CashReceiptIssueStatus::FAILED->value)
            ->first();
        $this->assertNotNull($failed);
        $this->assertSame('IDENTIFIER_UNAVAILABLE', $failed->error_code);
    }

    #[Test]
    public function 복호화가_실패하면_재발급을_시도하지_않고_실패_이력을_남긴다(): void
    {
        // APP_KEY 로테이션 등으로 기존 암호문을 복호화할 수 없게 된 경우.
        // "암호문 부재"(null) 와는 다른 분기다 — encrypted cast 가 DecryptException 을 던진다.
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        // 다른 키로 암호화된 것처럼 보이는 암호문을 심는다 (복호화 불가).
        DB::table('ecommerce_order_payments')
            ->where('id', $order->fresh()->payment->id)
            ->update(['cash_receipt_identifier_encrypted' => 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyIsIm1hYyI6ImJvZ3VzIn0=']);

        // 복호화 시도 자체가 예외를 던지는지 먼저 확인 (테스트 전제 고정)
        $this->expectDecryptionToFail($order->fresh()->payment);

        $order->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);

        // syncFromOrder 는 예외를 전파하지 않는다
        $this->service()->syncFromOrder($order->fresh(), '부분환불');

        $this->assertCount(0, $this->cancelCalls, '취소를 시도하지 않아야 한다');
        $this->assertCount(1, $this->issueCalls, '재발급을 시도하지 않아야 한다');

        $failed = OrderCashReceipt::where('order_id', $order->id)
            ->where('issue_status', CashReceiptIssueStatus::FAILED->value)
            ->first();
        $this->assertNotNull($failed, '복호화 실패도 FAILED 이력으로 남아야 한다');
        $this->assertSame('IDENTIFIER_UNAVAILABLE', $failed->error_code);
    }

    /**
     * 결제의 암호문이 실제로 복호화 불가 상태인지 확인합니다 (테스트 전제 고정).
     */
    private function expectDecryptionToFail(OrderPayment $payment): void
    {
        try {
            $payment->cash_receipt_identifier_encrypted;
            $this->fail('복호화가 실패해야 하는데 성공했다 — 테스트 전제가 깨졌다');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DecryptException::class, $e);
        }
    }

    #[Test]
    public function 식별번호는_어떤_로그에도_기록되지_않는다(): void
    {
        // 발급 실패 / 취소 실패 / 복호화 실패 3경로 모두에서 Log 인자를 캡처해 검사한다.
        Log::spy();

        $this->registerProvider();
        $this->issueShouldFail = true;
        $order = $this->makeOrder(11000, 11000, 0);

        // ① 발급 실패 경로
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        // ② 복호화 실패 경로 (암호문 폐기 후 sync)
        $this->issueShouldFail = false;
        $this->service()->issue($order->fresh(), CashReceiptType::INCOME, self::IDENTIFIER);
        $this->service()->purgeIdentifier($order->fresh());
        $order->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);
        $this->service()->syncFromOrder($order->fresh(), '부분환불');

        $logged = [];
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []) use (&$logged) {
                $logged[] = $message.' '.json_encode($context, JSON_UNESCAPED_UNICODE);

                return true;
            });

        $this->assertNotEmpty($logged, 'Log::warning 이 최소 1회는 호출되어야 한다');

        foreach ($logged as $entry) {
            $this->assertStringNotContainsString(self::IDENTIFIER, $entry, "식별번호 평문이 로그에 노출됨: {$entry}");
            $this->assertStringNotContainsString('5678', $entry, "식별번호 뒤 4자리가 로그에 노출됨: {$entry}");
            $this->assertStringNotContainsString('cash_receipt_identifier', $entry, "식별번호 필드가 로그에 노출됨: {$entry}");
        }
    }

    #[Test]
    public function 구매확정_암호문_폐기는_이력과_영수증_ur_l을_보존한다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $receipt = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->service()->purgeIdentifier($order->fresh());

        $payment = $order->fresh()->payment;
        $this->assertNull($payment->cash_receipt_identifier_encrypted, '암호문은 폐기되어야 한다');
        $this->assertSame('*******5678', $payment->cash_receipt_identifier, '마스킹은 유지');

        // 현금영수증 URL 은 이력 테이블이 보관한다. payment.receipt_url 은 PG 영수증(카드 매출전표)
        // 전용 컬럼이므로 현금영수증 발급이 건드리지 않는다.
        $this->assertNotNull($receipt->fresh()->receipt_url, '현금영수증 URL 은 이력에 유지');
        $this->assertSame(1, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    #[Test]
    public function 구매확정_암호문_폐기는_멱등이다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->service()->purgeIdentifier($order->fresh());
        $this->service()->purgeIdentifier($order->fresh());

        $this->assertNull($order->fresh()->payment->cash_receipt_identifier_encrypted);
    }

    #[Test]
    public function 프로바이더가_미설정이면_발급하지_않고_실패_이력을_남긴다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', '');
        $order = $this->makeOrder(11000, 11000, 0);

        $receipt = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame(CashReceiptIssueStatus::FAILED, $receipt->issue_status);
        $this->assertSame('PROVIDER_NOT_CONFIGURED', $receipt->error_code);
        $this->assertFalse($order->fresh()->payment->is_cash_receipt_issued);
    }

    #[Test]
    public function 어떤_리스너도_처리하지_않으면_실패_이력을_남긴다(): void
    {
        $order = $this->makeOrder(11000, 11000, 0);

        $receipt = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame(CashReceiptIssueStatus::FAILED, $receipt->issue_status);
        $this->assertSame('NO_PROVIDER_HANDLED', $receipt->error_code);
    }

    #[Test]
    public function 발급_대상액이_0이면_발급을_시도하지_않는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(0, 0, 0);

        $receipt = $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame(CashReceiptIssueStatus::FAILED, $receipt->issue_status);
        $this->assertSame('NO_ISSUABLE_AMOUNT', $receipt->error_code);
        $this->assertCount(0, $this->issueCalls);
    }

    #[Test]
    public function cancel_all_은_활성_영수증이_없으면_no_op이다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);

        $this->assertTrue($this->service()->cancelAll($order));
        $this->assertCount(0, $this->cancelCalls);
    }

    #[Test]
    public function 발급액_계산은_현금성_금액을_기준으로_면세를_안분한다(): void
    {
        // 현금성 10000, 과세 6000 + 면세 4000 → 분류합 10000 == 현금성 → 그대로
        $order = $this->makeOrder(10000, 6000, 4000);
        $this->assertSame(
            ['amount' => 10000, 'tax_free_amount' => 4000],
            $this->service()->calculateIssuableAmount($order),
        );

        // 마일리지 사용으로 현금성(5000)이 분류합(10000)보다 작음 → 면세 비율 40% 안분
        $order->update(['total_cash_equivalent_amount' => 5000]);
        $this->assertSame(
            ['amount' => 5000, 'tax_free_amount' => 2000],
            $this->service()->calculateIssuableAmount($order->fresh()),
        );
    }

    /**
     * 현금영수증 금액은 구매자가 실제로 낸 금액(결제 통화 청구액)이어야 한다.
     *
     * base 통화 금액(total_cash_equivalent_amount)을 그대로 쓰면 base≠결제 통화 주문에서
     * 실제 청구액과 다른 금액으로 세금 증빙이 발행된다. 국세청에 신고되는 금액이라
     * 화면 표시 오류가 아니라 증빙 오류다.
     */
    #[Test]
    public function 발급액은_결제_통화_기준_실청구액을_따른다(): void
    {
        // base JPY 31,000 (base_unit 100) / 결제 통화 KRW rate 1 → 실청구 310 KRW
        $order = $this->makeOrder(31000, 31000, 0);
        $order->update([
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'JPY',
                'order_currency' => 'KRW',
                'exchange_rate' => 1,
                'base_unit' => 100,
                'exchange_rates' => [
                    'JPY' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'round', 'decimal_places' => 0, 'base_unit' => 100],
                    'KRW' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1000],
                ],
            ],
        ]);

        $this->assertSame(
            ['amount' => 310, 'tax_free_amount' => 0],
            $this->service()->calculateIssuableAmount($order->fresh()),
        );
    }

    /**
     * 단일통화 상점(base == 결제 통화)은 종전과 동일해야 한다 — 회귀 방지.
     */
    #[Test]
    public function 단일통화_상점의_발급액은_종전과_동일하다(): void
    {
        $order = $this->makeOrder(11000, 10000, 1000);
        $order->update([
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'exchange_rate' => 1,
                'base_unit' => 1,
                'exchange_rates' => [
                    'KRW' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'base_unit' => 1],
                ],
            ],
        ]);

        $this->assertSame(
            ['amount' => 11000, 'tax_free_amount' => 1000],
            $this->service()->calculateIssuableAmount($order->fresh()),
        );
    }

    #[Test]
    public function 현금성_금액이_0이면_발급액도_0이다(): void
    {
        $order = $this->makeOrder(0, 11000, 0);

        $this->assertSame(
            ['amount' => 0, 'tax_free_amount' => 0],
            $this->service()->calculateIssuableAmount($order),
        );
    }

    #[Test]
    public function 최초_발급의_회차는_1이다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);

        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame(1, $this->issueCalls[0]['payload']['issue_sequence']);
    }

    #[Test]
    public function 재발급의_회차는_증가한다(): void
    {
        // 토스는 동일 orderId 재사용을 중복 거부하므로 재발급마다 회차가 달라야 한다.
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $order->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);
        $this->service()->syncFromOrder($order->fresh(), '부분환불');

        $this->assertSame(1, $this->issueCalls[0]['payload']['issue_sequence']);
        $this->assertSame(2, $this->issueCalls[1]['payload']['issue_sequence'], '재발급은 2회차');
    }

    #[Test]
    public function 발급이_실패해도_다음_회차는_증가한다(): void
    {
        // 프로바이더에 요청이 도달한 뒤 응답만 실패했을 수 있다.
        // 같은 식별자를 재사용하면 중복 거부되므로 실패한 시도도 회차를 소모해야 한다.
        $this->registerProvider();
        $this->issueShouldFail = true;
        $order = $this->makeOrder(11000, 11000, 0);

        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);
        $this->assertSame(1, $this->issueCalls[0]['payload']['issue_sequence']);

        $this->issueShouldFail = false;
        $this->service()->issue($order->fresh(), CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame(2, $this->issueCalls[1]['payload']['issue_sequence'], '실패한 1회차를 건너뛴다');
    }

    #[Test]
    public function 취소_이력은_발급_회차를_소모하지_않는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->service()->cancelAll($order->fresh(), '전액취소');

        // 취소 1건이 남았지만 발급 회차는 issue 이력만 센다
        $repository = app(OrderCashReceiptRepositoryInterface::class);
        $this->assertSame(1, $repository->countIssues($order->fresh()));

        $this->service()->issue($order->fresh(), CashReceiptType::INCOME, self::IDENTIFIER);
        $this->assertSame(2, $this->issueCalls[1]['payload']['issue_sequence']);
    }

    #[Test]
    public function 주문_삭제_시_현금영수증_이력도_명시적으로_삭제된다(): void
    {
        // DB CASCADE 에 의존하지 않고 Service 가 명시적으로 삭제해야 한다.
        $this->registerProvider();
        $order = $this->makeOrder(11000, 11000, 0);
        $this->service()->issue($order, CashReceiptType::INCOME, self::IDENTIFIER);

        $this->assertSame(1, OrderCashReceipt::where('order_id', $order->id)->count());

        app(OrderService::class)->delete($order->fresh());

        $this->assertSame(0, OrderCashReceipt::where('order_id', $order->id)->count());
    }
}
