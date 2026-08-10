<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\IssueCashReceiptOnDepositListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 입금완료 자동 발급 리스너 테스트 (D11 / A-8-4)
 *
 * 입금이 확인되는 두 경로(after_payment_complete / after_deposit_recorded)를 모두 덮는지,
 * 0원 주문·미신청·자진발급·멱등·발급실패를 각각 올바르게 처리하는지 검증한다.
 */
class IssueCashReceiptOnDepositListenerTest extends ModuleTestCase
{
    private const PROVIDER = 'tosspayments';

    private const IDENTIFIER = '01012345678';

    private bool $issueShouldFail = false;

    private int $receiptSequence = 0;

    /** @var array<int, array> 발급 훅이 받은 payload 기록 */
    private array $issueCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->issueShouldFail = false;
        $this->receiptSequence = 0;
        $this->issueCalls = [];

        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
    }

    /**
     * 발급 프로바이더 리스너를 등록합니다.
     */
    private function registerProvider(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            function (array $result, Order $order, string $provider, array $payload) {
                if ($provider !== self::PROVIDER) {
                    return $result;
                }

                $this->issueCalls[] = $payload;

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
    }

    /**
     * 무통장 결제완료 주문을 생성합니다.
     *
     * @param  array<string, mixed>  $paymentOverrides  결제 레코드 오버라이드
     */
    private function makeOrder(int $cashEquivalent = 11000, array $paymentOverrides = []): Order
    {
        $order = Order::factory()->create([
            'total_amount' => $cashEquivalent,
            'total_cash_equivalent_amount' => $cashEquivalent,
            'total_tax_amount' => $cashEquivalent,
            'total_tax_free_amount' => 0,
        ]);

        OrderPayment::factory()->create(array_merge([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ], $paymentOverrides));

        return $order->fresh();
    }

    /**
     * 현금영수증을 신청한 무통장 주문을 생성합니다.
     */
    private function makeRequestedOrder(int $cashEquivalent = 11000): Order
    {
        return $this->makeOrder($cashEquivalent, [
            'is_cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::INCOME->value,
            'cash_receipt_identifier_type' => CashReceiptIdentifierType::PHONE,
            'cash_receipt_identifier_encrypted' => self::IDENTIFIER,
        ]);
    }

    private function listener(): IssueCashReceiptOnDepositListener
    {
        return app(IssueCashReceiptOnDepositListener::class);
    }

    private function activeReceipt(Order $order): ?OrderCashReceipt
    {
        return app(CashReceiptService::class)->findActiveReceipt($order->fresh());
    }

    // ─────────────────────────────────────────────
    // 두 훅 경로 모두 발급된다
    // ─────────────────────────────────────────────

    #[Test]
    public function 구독_훅은_결제완료와_입금기록_두_경로다(): void
    {
        $hooks = IssueCashReceiptOnDepositListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_payment_complete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.order.after_deposit_recorded', $hooks);
        $this->assertSame('handleDeposit', $hooks['sirsoft-ecommerce.order.after_payment_complete']['method']);
        $this->assertSame('handleDeposit', $hooks['sirsoft-ecommerce.order.after_deposit_recorded']['method']);
    }

    #[Test]
    public function after_payment_complete_발화시_발급된다(): void
    {
        $this->registerProvider();
        HookListenerRegistrar::register(IssueCashReceiptOnDepositListener::class);
        $order = $this->makeRequestedOrder();

        // 큐를 타지 않고 즉시 검증하기 위해 핸들러를 직접 호출한다 (큐 디스패치는 별도 테스트).
        $this->listener()->handleDeposit($order);

        $receipt = $this->activeReceipt($order);
        $this->assertNotNull($receipt);
        $this->assertSame(CashReceiptIssueStatus::COMPLETED, $receipt->issue_status);
        $this->assertSame(11000, (int) $receipt->amount);
    }

    #[Test]
    public function after_deposit_recorded_입금만_기록_경로에서도_발급된다(): void
    {
        // 이 훅이 없으면 관리자 "입금만 기록" 경로에서 자동발급이 통째로 누락된다.
        $this->registerProvider();
        $order = $this->makeRequestedOrder();

        // 훅 인자가 ($order, $amount) 2개인 경로 — 리스너는 첫 인자만 쓴다.
        $this->listener()->handleDeposit($order, 11000.0);

        $this->assertNotNull($this->activeReceipt($order));
    }

    // ─────────────────────────────────────────────
    // 0원 주문 방어 (A-8-4 ②)
    // ─────────────────────────────────────────────

    #[Test]
    public function 전액_마일리지_0원_무통장주문에는_발급을_시도하지_않는다(): void
    {
        // $isZeroPayable 은 결제수단과 무관하게 잔여 결제액만 보므로 이 주문도
        // after_payment_complete 를 발화시킨다. 현금성 금액이 0 이므로 발급 대상이 아니다.
        $this->registerProvider();
        $order = $this->makeRequestedOrder(0);

        $this->listener()->handleDeposit($order);

        $this->assertCount(0, $this->issueCalls, '발급 API 가 호출되면 안 된다');
        $this->assertSame(0, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    // ─────────────────────────────────────────────
    // 발급 조건 가드
    // ─────────────────────────────────────────────

    #[Test]
    public function 무통장이_아니면_발급하지_않는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, [
            'payment_method' => PaymentMethodEnum::CARD,
            'is_cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::INCOME->value,
            'cash_receipt_identifier_encrypted' => self::IDENTIFIER,
        ]);

        $this->listener()->handleDeposit($order);

        $this->assertCount(0, $this->issueCalls);
    }

    #[Test]
    public function 미결제_상태면_발급하지_않는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder(11000, [
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'is_cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::INCOME->value,
            'cash_receipt_identifier_encrypted' => self::IDENTIFIER,
        ]);

        $this->listener()->handleDeposit($order);

        $this->assertCount(0, $this->issueCalls);
    }

    #[Test]
    public function 프로바이더_미설정시_발급하지_않는다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', '');
        $this->registerProvider();
        $order = $this->makeRequestedOrder();

        $this->listener()->handleDeposit($order);

        $this->assertCount(0, $this->issueCalls);
        $this->assertSame(0, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    // ─────────────────────────────────────────────
    // 미신청 + 자진발급
    // ─────────────────────────────────────────────

    #[Test]
    public function 미신청이고_자진발급_of_f_면_아무것도_하지_않는다(): void
    {
        $this->registerProvider();
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_self_issue', false);
        $order = $this->makeOrder();

        $this->listener()->handleDeposit($order);

        $this->assertCount(0, $this->issueCalls);
        $this->assertSame(0, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    #[Test]
    public function 미신청이고_자진발급_o_n_이면_국세청_지정번호로_소득공제_발급한다(): void
    {
        $this->registerProvider();
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_self_issue', true);
        $order = $this->makeOrder();

        $this->listener()->handleDeposit($order);

        $this->assertCount(1, $this->issueCalls);
        $this->assertSame(CashReceiptIdentifierType::SELF_ISSUE_NUMBER, $this->issueCalls[0]['identifier']);
        // 자진발급은 제도상 소득공제용만 가능하다.
        $this->assertSame(CashReceiptType::INCOME->value, $this->issueCalls[0]['type']);

        $this->assertSame(CashReceiptType::INCOME, $this->activeReceipt($order)->receipt_type);
    }

    #[Test]
    public function 신청했으나_식별번호를_복호화할_수_없으면_자진발급으로_대체하지_않는다(): void
    {
        // 구매자가 지정한 식별번호로만 발급해야 한다 — 관리자 수동 발급 경로로 남긴다.
        $this->registerProvider();
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_self_issue', true);
        $order = $this->makeOrder(11000, [
            'is_cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::INCOME->value,
            'cash_receipt_identifier_encrypted' => null,
        ]);

        $this->listener()->handleDeposit($order);

        $this->assertCount(0, $this->issueCalls);
    }

    // ─────────────────────────────────────────────
    // 멱등 / 실패
    // ─────────────────────────────────────────────

    #[Test]
    public function 이미_발급된_주문에는_중복_발급하지_않는다(): void
    {
        // 두 훅이 한 입금에 모두 도달할 수 있다.
        $this->registerProvider();
        $order = $this->makeRequestedOrder();

        $this->listener()->handleDeposit($order);
        $this->listener()->handleDeposit($order->fresh());

        $this->assertCount(1, $this->issueCalls, '발급 API 는 1회만 호출되어야 한다');
        $this->assertSame(1, OrderCashReceipt::where('order_id', $order->id)->count());
    }

    #[Test]
    public function 발급_실패시_faile_d_이력이_남고_예외는_전파되지_않는다(): void
    {
        // 입금확인 자체는 성공 상태로 유지되어야 한다.
        $this->registerProvider();
        $this->issueShouldFail = true;
        $order = $this->makeRequestedOrder();

        $this->listener()->handleDeposit($order);

        $receipt = OrderCashReceipt::where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($receipt);
        $this->assertSame(CashReceiptIssueStatus::FAILED, $receipt->issue_status);
        $this->assertSame('PROVIDER_ERROR', $receipt->error_code);

        // 활성 영수증은 없다 → 관리자 수동 발급 경로가 열려 있다.
        $this->assertNull($this->activeReceipt($order));
    }

    #[Test]
    public function 프로바이더가_예외를_던져도_삼키고_로그만_남긴다(): void
    {
        Log::spy();

        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            function () {
                throw new \RuntimeException('PG 통신 실패');
            },
        );

        $order = $this->makeRequestedOrder();

        // 입금확인 트랜잭션을 깨뜨리지 않는다 (예외 미전파).
        $this->listener()->handleDeposit($order);

        // 조용한 실패 금지 — 예외를 삼켰다면 반드시 로그로 남아야 한다.
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($order) {
                $this->assertStringContainsString('IssueCashReceiptOnDepositListener', $message);
                $this->assertSame($order->id, $context['order_id']);
                $this->assertStringContainsString('PG 통신 실패', $context['error']);

                // 식별번호는 로그에 절대 노출되지 않는다.
                $this->assertStringNotContainsString(self::IDENTIFIER, $message.json_encode($context));

                return true;
            });
    }

    #[Test]
    public function 발급_실패시_경고_로그를_남긴다(): void
    {
        // 프로바이더가 예외 없이 success=false 를 반환한 경우 — 관리자 수동 발급 유도.
        Log::spy();
        $this->registerProvider();
        $this->issueShouldFail = true;
        $order = $this->makeRequestedOrder();

        $this->listener()->handleDeposit($order);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                if (! str_contains($message, 'IssueCashReceiptOnDepositListener')) {
                    return false;
                }

                $this->assertSame('PROVIDER_ERROR', $context['error_code']);
                $this->assertStringNotContainsString(self::IDENTIFIER, $message.json_encode($context));

                return true;
            });
    }

    // ─────────────────────────────────────────────
    // 큐 처리 (sync=true 아님)
    // ─────────────────────────────────────────────

    #[Test]
    public function 리스너는_sync_가_아니라_큐로_디스패치된다(): void
    {
        // 외부 발급 API 호출을 입금확인 트랜잭션에 묶으면 입금확인 자체가 실패한다.
        $this->assertArrayNotHasKey(
            'sync',
            IssueCashReceiptOnDepositListener::getSubscribedHooks()['sirsoft-ecommerce.order.after_payment_complete'],
            'sync 를 명시하면 안 된다 (기본 큐 처리)',
        );

        Queue::fake();
        HookListenerRegistrar::register(IssueCashReceiptOnDepositListener::class);

        $order = $this->makeRequestedOrder();
        HookManager::doAction('sirsoft-ecommerce.order.after_payment_complete', $order);

        Queue::assertPushed(
            DispatchHookListenerJob::class,
            fn ($job) => $job->listenerClass === IssueCashReceiptOnDepositListener::class
                && $job->method === 'handleDeposit',
        );
    }
}
