<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\RegistersTestCashReceiptProvider;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 현금영수증 — 영세 금액·부가세 분할 테스트 (#612)
 *
 * 영세(세율 0%)는 쇼핑몰 장부에서 과세표준에 들지만, 국세청·결제사에는 부가세가 없는 금액이다.
 * 발급 면세액은 「면세 + 영세」이고(P2), 발급 페이로드의 부가세는 주문 부가세를 과세 몫(영세 제외)
 * 비율로 나눈 값이다(②) — /11 고정 분할은 5% 세율·영세가 섞인 주문에서 부가세를 과다 신고한다.
 */
class CashReceiptZeroRatedTest extends ModuleTestCase
{
    use RegistersTestCashReceiptProvider;

    private const PROVIDER = 'tosspayments';

    /** @var array<int, array> 발급 훅이 받은 페이로드 */
    private array $payloads = [];

    /**
     * 테스트용 프로바이더를 등록하고 발급 페이로드를 기록하게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->payloads = [];
        $this->registerCashReceiptProvider(self::PROVIDER);
        app(EcommerceSettingsService::class)->setSetting('order_settings.cash_receipt_provider', self::PROVIDER);

        HookManager::addFilter(
            'sirsoft-ecommerce.cash_receipt.issue',
            function (array $result, Order $order, string $provider, array $payload) {
                if ($provider !== self::PROVIDER) {
                    return $result;
                }

                $this->payloads[] = $payload;

                return [
                    'success' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'receipt_key' => 'receipt-612',
                    'receipt_url' => 'https://example.test/receipt/612',
                    'issue_number' => '612',
                    'raw_response' => null,
                ];
            },
        );
    }

    /**
     * 장부 금액을 지정한 무통장 결제 완료 주문을 만듭니다.
     *
     * @param  array<string, int>  $ledger  주문 장부 컬럼 (total_* 값)
     * @return Order 새로 읽은 주문
     */
    private function makeOrder(array $ledger): Order
    {
        $order = Order::factory()->create($ledger + [
            'total_zero_rated_amount' => 0,
            'total_tax_free_amount' => 0,
            'total_vat_amount' => 0,
        ]);

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::DBANK,
            'payment_status' => PaymentStatusEnum::PAID,
        ]);

        return $order->fresh();
    }

    /**
     * 발급 면세액에 영세 금액이 합산되는지 확인합니다.
     *
     * 과세 11,000(부가세 1,000) + 영세 10,000 + 면세 5,000 = 26,000 → 면세 몫 15,000.
     *
     * @effects cash_receipt_tax_free_includes_zero_rated
     *
     * @return void 반환값 없음
     */
    public function test_issuable_tax_free_includes_zero_rated(): void
    {
        $order = $this->makeOrder([
            'total_amount' => 26000,
            'total_cash_equivalent_amount' => 26000,
            'total_tax_amount' => 21000,
            'total_zero_rated_amount' => 10000,
            'total_vat_amount' => 1000,
            'total_tax_free_amount' => 5000,
        ]);

        $service = app(CashReceiptService::class);
        $target = $service->calculateIssuableAmount($order);

        $this->assertSame(['amount' => 26000, 'tax_free_amount' => 15000], $target);
        $this->assertSame(1000, $service->calculateIssuableVatAmount($order, $target));
    }

    /**
     * 마일리지로 현금성 금액이 줄어도 면세·부가세가 같은 비율로 안분되는지 확인합니다.
     *
     * 현금성 13,000 / 분류합 26,000 → 면세 몫 7,500, 과세 몫 5,500, 부가세 = 5,500 × 1,000 / 11,000 = 500.
     *
     * @effects cash_receipt_payload_carries_vat
     *
     * @return void 반환값 없음
     */
    public function test_vat_share_follows_the_cash_equivalent_ratio(): void
    {
        $order = $this->makeOrder([
            'total_amount' => 13000,
            'total_cash_equivalent_amount' => 13000,
            'total_tax_amount' => 21000,
            'total_zero_rated_amount' => 10000,
            'total_vat_amount' => 1000,
            'total_tax_free_amount' => 5000,
        ]);

        $service = app(CashReceiptService::class);
        $target = $service->calculateIssuableAmount($order);

        $this->assertSame(['amount' => 13000, 'tax_free_amount' => 7500], $target);
        $this->assertSame(500, $service->calculateIssuableVatAmount($order, $target));
    }

    /**
     * 5% 세율 상품만 든 주문의 발급 부가세가 /11 이 아니라 주문 부가세를 따르는지 확인합니다.
     *
     * 과세 10,500(5% → 부가세 500). /11 이면 955 로 과다 신고된다.
     *
     * @effects cash_receipt_payload_carries_vat
     *
     * @return void 반환값 없음
     */
    public function test_reduced_rate_order_vat_is_not_divided_by_eleven(): void
    {
        $order = $this->makeOrder([
            'total_amount' => 10500,
            'total_cash_equivalent_amount' => 10500,
            'total_tax_amount' => 10500,
            'total_vat_amount' => 500,
        ]);

        $service = app(CashReceiptService::class);

        $this->assertSame(500, $service->calculateIssuableVatAmount($order, $service->calculateIssuableAmount($order)));
    }

    /**
     * 발급 요청 페이로드에 면세(영세 포함)와 부가세가 함께 실리는지 확인합니다.
     *
     * 영세만 든 주문은 전액이 면세 몫이고 부가세는 0 이다.
     *
     * @effects cash_receipt_tax_free_includes_zero_rated
     * @effects cash_receipt_payload_carries_vat
     *
     * @return void 반환값 없음
     */
    public function test_issue_payload_carries_zero_rated_as_tax_free_and_vat(): void
    {
        $order = $this->makeOrder([
            'total_amount' => 13000,
            'total_cash_equivalent_amount' => 13000,
            'total_tax_amount' => 13000,
            'total_zero_rated_amount' => 13000,
        ]);

        app(CashReceiptService::class)->issue($order, CashReceiptType::INCOME, '01012345678');

        $this->assertCount(1, $this->payloads);
        $this->assertSame(13000, $this->payloads[0]['amount']);
        $this->assertSame(13000, $this->payloads[0]['tax_free_amount']);
        $this->assertSame(0, $this->payloads[0]['vat_amount']);
    }
}
