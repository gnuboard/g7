<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptType;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 주문 생성 시 현금영수증 신청 + 환불계좌 수집 검증 (#454 S3)
 *
 * 검증 축:
 *  - FormRequest 4단계 사슬: rules 명시 → getter → createFromTempOrder 시그니처 → createOrderPayment 저장
 *    (한 단계라도 빠지면 프론트가 값을 보내도 DB 에 남지 않는다)
 *  - 식별번호는 평문 컬럼에 마스킹본, 암호화 컬럼에 원본
 *  - 용도 × 식별번호 조합 (D10 — 지출증빙+휴대폰 허용 / 지출증빙+자진발급번호 거부)
 *  - 환불계좌 부분 입력 거부 (required_with 상당)
 *
 * @scenario actor=guest, change_mode=manual
 *
 * @effects checkout_cash_receipt_request_persisted, checkout_refund_bank_persisted,
 *   checkout_cash_receipt_identifier_masked_and_encrypted,
 *   checkout_refund_bank_partial_input_rejected_422,
 *   cash_receipt_request_not_persisted_for_non_dbank
 */
class CheckoutCashReceiptAndRefundBankTest extends ModuleTestCase
{
    protected string $cartKey;

    protected Product $product;

    protected ProductOption $productOption;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartKey = Str::uuid()->toString();

        $this->product = Product::create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'product_code' => 'TEST-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'list_price' => 20000,
            'selling_price' => 15000,
            'currency_code' => 'KRW',
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);

        $this->productOption = ProductOption::create([
            'product_id' => $this->product->id,
            'option_code' => 'OPT-'.Str::random(8),
            'option_values' => ['색상' => '검정'],
            'option_name' => null,
            'sku' => 'SKU-'.Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    protected function createTempOrder(): TempOrder
    {
        return TempOrder::create([
            'user_id' => null,
            'cart_key' => $this->cartKey,
            'items' => [[
                'product_id' => $this->product->id,
                'product_option_id' => $this->productOption->id,
                'quantity' => 2,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'subtotal' => 30000,
                    'final_amount' => 30000,
                ]],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'guest@test.com'],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 30000,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => '004',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
            'guest_lookup_password' => 'guest1234',
            'guest_lookup_password_confirmation' => 'guest1234',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function postOrder(array $overrides = []): TestResponse
    {
        $this->createTempOrder();

        return $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->payload($overrides),
            ['X-Cart-Key' => $this->cartKey]
        );
    }

    protected function latestPayment(): OrderPayment
    {
        $order = Order::latest('id')->firstOrFail();

        return $order->payment()->firstOrFail();
    }

    // ---------------------------------------------------------------- 현금영수증

    public function test_현금영수증_미신청_주문은_신청_플래그가_꺼진다(): void
    {
        $this->postOrder()->assertStatus(201);

        $payment = $this->latestPayment();

        $this->assertFalse((bool) $payment->is_cash_receipt_requested);
        $this->assertNull($payment->cash_receipt_type);
        $this->assertNull($payment->cash_receipt_identifier);
        $this->assertNull($payment->getRawOriginal('cash_receipt_identifier_encrypted'));
    }

    public function test_현금영수증_신청이_결제행에_저장된다(): void
    {
        $this->postOrder([
            'cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::INCOME->value,
            'cash_receipt_identifier_type' => CashReceiptIdentifierType::PHONE->value,
            'cash_receipt_identifier' => '010-1234-5678',
        ])->assertStatus(201);

        $payment = $this->latestPayment();

        $this->assertTrue((bool) $payment->is_cash_receipt_requested);
        $this->assertSame(CashReceiptType::INCOME->value, $payment->cash_receipt_type);
        $this->assertSame(CashReceiptIdentifierType::PHONE, $payment->cash_receipt_identifier_type);
    }

    public function test_식별번호는_평문_컬럼에_마스킹본_암호화_컬럼에_원본이_저장된다(): void
    {
        $this->postOrder([
            'cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::INCOME->value,
            'cash_receipt_identifier_type' => CashReceiptIdentifierType::PHONE->value,
            'cash_receipt_identifier' => '010-1234-5678',
        ])->assertStatus(201);

        $payment = $this->latestPayment();

        // 하이픈 제거 후 뒤 4자리만 노출
        $this->assertSame('*******5678', $payment->cash_receipt_identifier);

        // 원본은 복호화로만 얻는다 (raw 컬럼에 평문이 없어야 한다)
        $this->assertSame('01012345678', $payment->cash_receipt_identifier_encrypted);
        $this->assertStringNotContainsString(
            '01012345678',
            (string) $payment->getRawOriginal('cash_receipt_identifier_encrypted')
        );
    }

    public function test_지출증빙에_휴대폰번호를_쓸_수_있다(): void
    {
        // D10 — 휴대폰번호는 소득공제/지출증빙 양쪽에서 유효하다
        $this->postOrder([
            'cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::EXPENSE->value,
            'cash_receipt_identifier_type' => CashReceiptIdentifierType::PHONE->value,
            'cash_receipt_identifier' => '010-1234-5678',
        ])->assertStatus(201);

        $this->assertSame(CashReceiptType::EXPENSE->value, $this->latestPayment()->cash_receipt_type);
    }

    public function test_지출증빙에_자진발급번호는_거부된다(): void
    {
        // 자진발급 지정번호는 소득공제 전용 (제도상 지출증빙 자진발급 불가)
        $this->postOrder([
            'cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::EXPENSE->value,
            'cash_receipt_identifier_type' => CashReceiptIdentifierType::PHONE->value,
            'cash_receipt_identifier' => CashReceiptIdentifierType::SELF_ISSUE_NUMBER,
        ])->assertStatus(422)
            ->assertJsonPath('errors.cash_receipt_identifier.0', __('sirsoft-ecommerce::cash_receipt.validation.self_issue_income_only'));
    }

    public function test_사업자등록번호_체크섬이_틀리면_거부된다(): void
    {
        $this->postOrder([
            'cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::EXPENSE->value,
            'cash_receipt_identifier_type' => CashReceiptIdentifierType::BUSINESS->value,
            'cash_receipt_identifier' => '1234567890',
        ])->assertStatus(422);

        $this->assertSame(0, Order::count(), '검증 실패 시 주문 부산물이 남아서는 안 된다');
    }

    public function test_신청했으나_하위키가_없으면_거부된다(): void
    {
        $this->postOrder(['cash_receipt_requested' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'cash_receipt_type',
                'cash_receipt_identifier_type',
                'cash_receipt_identifier',
            ]);
    }

    /**
     * 무통장이 아닌 결제수단으로 신청정보가 넘어와도 결제행에 저장하지 않는다.
     *
     * 발급 자체는 CashReceiptService 가 dbank 로 차단하므로 기능 홀은 아니지만,
     * 발급될 수 없는 주문에 식별번호 암호문을 남기는 것은 불필요한 개인정보 보관이다.
     * getDbankInfo() 와 동일하게 getCashReceiptInfo() 도 결제수단으로 게이팅한다.
     *
     * @param  string  $paymentMethod  무통장이 아닌 결제수단
     */
    #[DataProvider('nonDbankPaymentMethodProvider')]
    public function test_무통장이_아니면_현금영수증_신청정보를_저장하지_않는다(string $paymentMethod): void
    {
        $this->postOrder([
            'payment_method' => $paymentMethod,
            // vbank 는 입금자명이 필수다(required_if). dbank 전용 계좌정보만 비운다.
            'depositor_name' => $paymentMethod === PaymentMethodEnum::VBANK->value ? '홍길동' : null,
            'dbank' => null,
            'cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::INCOME->value,
            'cash_receipt_identifier_type' => CashReceiptIdentifierType::PHONE->value,
            'cash_receipt_identifier' => '010-1234-5678',
        ])->assertStatus(201);

        $payment = $this->latestPayment();

        $this->assertFalse(
            (bool) $payment->is_cash_receipt_requested,
            "{$paymentMethod} 주문에 현금영수증 신청 플래그가 켜져서는 안 된다"
        );
        $this->assertNull($payment->cash_receipt_type);
        $this->assertNull($payment->cash_receipt_identifier_type);
        $this->assertNull($payment->cash_receipt_identifier, '마스킹본도 남기지 않는다');
        $this->assertNull(
            $payment->getRawOriginal('cash_receipt_identifier_encrypted'),
            '발급될 수 없는 주문에 식별번호 암호문을 보관해서는 안 된다'
        );
    }

    /**
     * 무통장(dbank)을 제외한 전 결제수단.
     *
     * @return array<string, array{string}>
     */
    public static function nonDbankPaymentMethodProvider(): array
    {
        $cases = [];

        foreach (PaymentMethodEnum::cases() as $method) {
            if ($method === PaymentMethodEnum::DBANK) {
                continue;
            }

            $cases[$method->value] = [$method->value];
        }

        return $cases;
    }

    // ---------------------------------------------------------------- 환불계좌

    public function test_환불계좌_미입력이_허용된다(): void
    {
        $this->postOrder()->assertStatus(201);

        $payment = $this->latestPayment();

        $this->assertNull($payment->refund_bank_code);
        $this->assertNull($payment->refund_bank_account);
        $this->assertNull($payment->refund_bank_holder);
    }

    public function test_환불계좌_전체_입력이_결제행에_저장된다(): void
    {
        $this->postOrder([
            'refund_bank' => [
                'bank_code' => '004',
                'account_number' => '110-123-456789',
                'holder' => '홍길동',
            ],
        ])->assertStatus(201);

        $payment = $this->latestPayment();

        $this->assertSame('004', $payment->refund_bank_code);
        $this->assertSame('110-123-456789', $payment->refund_bank_account);
        $this->assertSame('홍길동', $payment->refund_bank_holder);
        // 은행명은 은행코드로 조회해 함께 저장한다 (관리자 화면 표시용)
        $this->assertNotNull($payment->refund_bank_name);
    }

    /**
     * 환불계좌가 의미를 갖지 않는 결제수단이면 저장하지 않는다.
     *
     * 환불계좌는 무통장(관리자 수동 이체 대상)과 가상계좌(PG 환불 API 의 refundReceiveAccount)
     * 에서만 쓰인다. 카드/간편결제는 원거래 취소로 환불되므로 계좌가 필요 없다.
     *
     * 체크아웃 화면은 결제수단을 바꿔도 _local 의 환불계좌 입력값을 비우지 않으므로,
     * 무통장에서 계좌를 넣고 카드로 전환하면 그 값이 그대로 전송된다. 발급될 수 없는
     * 주문에 계좌정보를 남기지 않도록 현금영수증(getCashReceiptInfo)과 같은 축으로 게이팅한다.
     *
     * @param  string  $paymentMethod  환불계좌를 쓰지 않는 결제수단
     */
    #[DataProvider('nonRefundBankPaymentMethodProvider')]
    public function test_환불계좌를_쓰지_않는_결제수단이면_저장하지_않는다(string $paymentMethod): void
    {
        $this->postOrder([
            'payment_method' => $paymentMethod,
            'dbank' => null,
            'refund_bank' => [
                'bank_code' => '004',
                'account_number' => '110-123-456789',
                'holder' => '홍길동',
            ],
        ])->assertStatus(201);

        $payment = $this->latestPayment();

        $this->assertNull(
            $payment->refund_bank_code,
            "{$paymentMethod} 주문에 환불계좌가 저장되어서는 안 된다"
        );
        $this->assertNull($payment->refund_bank_account);
        $this->assertNull($payment->refund_bank_holder);
        $this->assertNull($payment->refund_bank_name);
    }

    /**
     * 환불계좌를 쓰지 않는 결제수단 (무통장·가상계좌 제외).
     *
     * @return array<string, array{string}>
     */
    public static function nonRefundBankPaymentMethodProvider(): array
    {
        $cases = [];

        foreach (PaymentMethodEnum::cases() as $method) {
            if (in_array($method, [PaymentMethodEnum::DBANK, PaymentMethodEnum::VBANK], true)) {
                continue;
            }

            $cases[$method->value] = [$method->value];
        }

        return $cases;
    }

    /**
     * @return array<string, array{array<string, mixed>, array<int, string>}>
     */
    public static function partialRefundBankProvider(): array
    {
        return [
            '계좌번호만' => [
                ['account_number' => '110-123-456789'],
                ['refund_bank.bank_code', 'refund_bank.holder'],
            ],
            '은행만' => [
                ['bank_code' => '004'],
                ['refund_bank.account_number', 'refund_bank.holder'],
            ],
            '예금주 누락' => [
                ['bank_code' => '004', 'account_number' => '110-123-456789'],
                ['refund_bank.holder'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $refundBank
     * @param  array<int, string>  $expectedErrors
     */
    #[DataProvider('partialRefundBankProvider')]
    public function test_환불계좌_부분_입력은_거부된다(array $refundBank, array $expectedErrors): void
    {
        $this->postOrder(['refund_bank' => $refundBank])
            ->assertStatus(422)
            ->assertJsonValidationErrors($expectedErrors);

        $this->assertSame(0, Order::count(), '검증 실패 시 주문 부산물이 남아서는 안 된다');
    }

    // ---------------------------------------------------------------- 사슬 무결성

    public function test_현금영수증과_환불계좌를_함께_보내면_둘_다_저장된다(): void
    {
        $this->postOrder([
            'cash_receipt_requested' => true,
            'cash_receipt_type' => CashReceiptType::INCOME->value,
            'cash_receipt_identifier_type' => CashReceiptIdentifierType::PHONE->value,
            'cash_receipt_identifier' => '010-1234-5678',
            'refund_bank' => [
                'bank_code' => '004',
                'account_number' => '110-123-456789',
                'holder' => '홍길동',
            ],
        ])->assertStatus(201);

        $payment = $this->latestPayment();

        $this->assertTrue((bool) $payment->is_cash_receipt_requested);
        $this->assertSame('004', $payment->refund_bank_code);
    }
}
