<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use App\Extension\HookManager;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptTransactionType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 현금영수증 리소스 노출 테스트 (A-5)
 *
 * OrderResource 는 활성 영수증 1건(cash_receipt)과 전체 이력(cash_receipts)을 노출한다.
 * 전체 이력은 관리자 화면의 "취소 성공 + 재발급 실패" 경고 배지 판정 근거다.
 * 어느 쪽도 식별번호 원본이나 프로바이더 원응답을 노출하지 않는다.
 */
class CashReceiptResourceTest extends ModuleTestCase
{
    private const PROVIDER = 'tosspayments';

    private const IDENTIFIER = '01012345678';

    private bool $issueShouldFail = false;

    private int $receiptSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issueShouldFail = false;
        $this->receiptSequence = 0;

        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);
    }

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
                    // 원응답에 민감 정보가 섞여 와도 리소스는 노출하지 않아야 한다.
                    'raw_response' => ['customerIdentityNumber' => self::IDENTIFIER],
                ];
            },
        );
    }

    private function makeOrder(): Order
    {
        $order = Order::factory()->create([
            'total_amount' => 11000,
            'total_cash_equivalent_amount' => 11000,
            'total_tax_amount' => 11000,
            'total_tax_free_amount' => 0,
        ]);

        OrderPayment::factory()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
            'receipt_url' => 'https://pg.example.test/slip/1',
        ]);

        return $order->fresh();
    }

    /**
     * 관계를 로드한 뒤 OrderResource 의 현금영수증 관련 필드만 직렬화합니다.
     *
     * 주문 리소스 전체를 json_encode 하면 미로드 관계(shippingAddress 등)의 리소스가
     * null 을 만나 터진다 — 본 테스트의 관심사가 아니므로 해당 필드만 뽑아 직렬화한다.
     *
     * @return array{payment: array, cash_receipt: array|null, cash_receipts: array}
     */
    private function resourceArray(Order $order): array
    {
        $order = $order->fresh();
        $order->load(['payment', 'cashReceipts']);

        $request = Request::create('/');
        $array = (new OrderResource($order))->toArray($request);

        return [
            'payment' => json_decode(json_encode($array['payment']), true),
            'cash_receipt' => json_decode(json_encode($array['cash_receipt']), true),
            'cash_receipts' => json_decode(json_encode($array['cash_receipts']), true),
        ];
    }

    #[Test]
    public function 현금영수증_발급이_결제영수증_주소를_덮어쓰지_않는다(): void
    {
        // payment.receipt_url = PG 매출전표, cash_receipts.receipt_url = 현금영수증.
        // 한 컬럼이 두 의미를 갖지 않도록 서로 침범하지 않아야 한다.
        $this->registerProvider();
        $order = $this->makeOrder();
        app(CashReceiptService::class)->issue(
            $order, CashReceiptType::INCOME, self::IDENTIFIER, CashReceiptIdentifierType::PHONE,
        );

        $payment = $order->fresh()->payment;
        $array = json_decode(json_encode(
            (new OrderPaymentResource($payment))->toArray(Request::create('/'))
        ), true);

        $this->assertSame('https://pg.example.test/slip/1', $array['receipt_url'], 'PG 매출전표가 보존되어야 한다');
        $this->assertSame('https://pg.example.test/slip/1', $array['pg_receipt_url']);
        $this->assertTrue($array['is_cash_receipt_issued']);
        $this->assertSame('*******5678', $array['cash_receipt_identifier']);
        $this->assertNotNull($array['cash_receipt_issued_at']);

        // 평문·암호문은 어디에도 없어야 한다
        $this->assertStringNotContainsString(self::IDENTIFIER, json_encode($array));
        $this->assertArrayNotHasKey('cash_receipt_identifier_encrypted', $array);
    }

    #[Test]
    public function 주문_리소스가_결제영수증과_현금영수증을_따로_노출한다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();
        app(CashReceiptService::class)->issue(
            $order, CashReceiptType::INCOME, self::IDENTIFIER, CashReceiptIdentifierType::PHONE,
        );

        $array = $this->resourceArray($order);

        // 두 영수증이 각각 다른 경로로 조회된다 (복합결제 도입 시 두 버튼을 동시 노출 가능)
        $this->assertSame('https://pg.example.test/slip/1', $array['payment']['pg_receipt_url']);

        $this->assertNotNull($array['cash_receipt']);
        $this->assertSame('https://example.test/receipt/receipt-1', $array['cash_receipt']['receipt_url']);
        $this->assertSame('*******5678', $array['cash_receipt']['identifier_masked']);
        $this->assertSame(CashReceiptType::INCOME->value, $array['cash_receipt']['receipt_type']);
    }

    #[Test]
    public function 발급_이력이_없으면_활성_영수증은_null_이다(): void
    {
        $order = $this->makeOrder();

        $array = $this->resourceArray($order);

        $this->assertNull($array['cash_receipt']);
        $this->assertCount(0, $array['cash_receipts']);
    }

    #[Test]
    public function 재발급_실패_상태는_활성없음_과_실패이력으로_드러난다(): void
    {
        // 관리자 화면의 "경고 배지 + 수동 재발급" 판정 근거.
        $this->registerProvider();
        $order = $this->makeOrder();
        $service = app(CashReceiptService::class);
        $service->issue($order, CashReceiptType::INCOME, self::IDENTIFIER, CashReceiptIdentifierType::PHONE);

        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.cancel',
            fn (array $result, Order $order, string $provider, string $receiptKey) => [
                'success' => true, 'error_code' => null, 'error_message' => null,
                'receipt_key' => $receiptKey, 'raw_response' => null,
            ],
        );

        $this->issueShouldFail = true;
        $order->fresh()->update(['total_cash_equivalent_amount' => 5500, 'total_tax_amount' => 5500]);
        $service->syncFromOrder($order->fresh(), '부분환불');

        $array = $this->resourceArray($order);

        $this->assertNull($array['cash_receipt'], '활성 영수증이 없어야 한다');

        // 최신순: 실패한 발급 → 취소 → 최초 발급
        $ledger = $array['cash_receipts'];
        $this->assertCount(3, $ledger);
        $this->assertSame(CashReceiptIssueStatus::FAILED->value, $ledger[0]['issue_status']);
        $this->assertSame('PROVIDER_ERROR', $ledger[0]['error_code']);
    }

    #[Test]
    public function 리소스는_거래유형과_발급상태의_표시명을_함께_노출한다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();
        app(CashReceiptService::class)->issue(
            $order, CashReceiptType::INCOME, self::IDENTIFIER, CashReceiptIdentifierType::PHONE,
        );

        $receipt = $this->resourceArray($order)['cash_receipt'];

        // 관리자 발급 이력 표는 원시값(issue / COMPLETED)이 아니라 표시명을 보여줘야 한다.
        $this->assertSame(CashReceiptTransactionType::ISSUE->label(), $receipt['transaction_type_label']);
        $this->assertSame(CashReceiptIssueStatus::COMPLETED->label(), $receipt['issue_status_label']);
    }

    #[Test]
    public function 취소_이력의_상태는_발급_완료로_표시되지_않는다(): void
    {
        $this->registerProvider();
        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.cancel',
            fn (array $result, Order $order, string $provider, string $receiptKey) => [
                'success' => true, 'error_code' => null, 'error_message' => null,
                'receipt_key' => $receiptKey, 'raw_response' => null,
            ],
        );

        $order = $this->makeOrder();
        $service = app(CashReceiptService::class);

        $service->issue($order, CashReceiptType::INCOME, self::IDENTIFIER, CashReceiptIdentifierType::PHONE);
        $service->cancelAll($order->fresh(), '테스트 취소');

        $ledger = $this->resourceArray($order)['cash_receipts'];
        $cancelRow = collect($ledger)->firstWhere('transaction_type', CashReceiptTransactionType::CANCEL);

        $this->assertNotNull($cancelRow, '취소 이력이 있어야 한다');

        // issue_status 는 "그 거래가 성공했는가" 이므로 COMPLETED 다. 그러나 취소 행에 "발급 완료" 라고
        // 쓰면 관리자가 오해한다 — 이력 표에는 거래유형과 무관한 중립 표현을 쓴다.
        $this->assertSame(CashReceiptIssueStatus::COMPLETED->value, $cancelRow['issue_status']);
        $this->assertStringNotContainsString('발급', $cancelRow['result_label']);
        $this->assertNotEmpty($cancelRow['result_label']);
    }

    #[Test]
    public function 발급에_실패한_이력도_표시용_일시를_가진다(): void
    {
        $this->registerProvider();
        $this->issueShouldFail = true;

        $order = $this->makeOrder();

        try {
            app(CashReceiptService::class)->issue(
                $order, CashReceiptType::INCOME, self::IDENTIFIER, CashReceiptIdentifierType::PHONE,
            );
        } catch (\Throwable) {
            // 발급 실패는 이력만 남기고 삼켜지거나 예외로 전파된다 — 어느 쪽이든 이력은 남는다.
        }

        $failed = $this->resourceArray($order)['cash_receipts'][0];

        // 실패 이력은 issued_at 이 null 이다. 그대로 두면 관리자 이력 표의 일시가 '-' 로 비어 버린다.
        $this->assertSame(CashReceiptIssueStatus::FAILED->value, $failed['issue_status']);
        $this->assertNull($failed['issued_at']);
        $this->assertNotEmpty($failed['occurred_at_formatted'], '실패 이력도 발생 시각을 표시할 수 있어야 한다');
    }

    #[Test]
    public function 리소스는_프로바이더_원응답을_노출하지_않는다(): void
    {
        $this->registerProvider();
        $order = $this->makeOrder();
        app(CashReceiptService::class)->issue(
            $order, CashReceiptType::INCOME, self::IDENTIFIER, CashReceiptIdentifierType::PHONE,
        );

        $array = $this->resourceArray($order);

        $this->assertArrayNotHasKey('raw_response', $array['cash_receipt']);
        $this->assertStringNotContainsString(self::IDENTIFIER, json_encode($array['cash_receipt']));
    }
}
