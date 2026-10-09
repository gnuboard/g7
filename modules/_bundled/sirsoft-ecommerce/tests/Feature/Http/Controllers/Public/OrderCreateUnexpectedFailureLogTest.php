<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use RuntimeException;

/**
 * 주문하기가 알 수 없는 이유로 실패하면 원인을 서버 로그에 남기는지 검증한다 (EC-420)
 *
 * 배경: 주문 생성의 마지막 catch(Exception)는 종전에 아무것도 기록하지 않고 500 만 돌려줘 원인을 추적할 수
 * 없었다(이커머스 CHANGELOG 「주문하기가 알 수 없는 이유로 실패했을 때 …」). 이커머스 전수 실측 S4 에서 이 항목을
 * 재려 했으나 장애를 주입해 확인하는 테스트가 없어 새로 둔다 — 브라우저에서는 알 수 없는 예외를 일으킬 수 없다.
 */
class OrderCreateUnexpectedFailureLogTest extends ModuleTestCase
{
    protected string $cartKey;

    /**
     * 비회원 임시 주문(국내 배송, 무통장)을 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cartKey = Str::uuid()->toString();

        $product = Product::create([
            'name' => ['ko' => '장애 주입 상품', 'en' => 'Fault Product'],
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

        $option = ProductOption::create([
            'product_id' => $product->id,
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

        TempOrder::create([
            'user_id' => null,
            'cart_key' => $this->cartKey,
            'items' => [[
                'product_id' => $product->id,
                'product_option_id' => $option->id,
                'quantity' => 1,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $product->id,
                    'product_option_id' => $option->id,
                    'quantity' => 1,
                    'unit_price' => 15000,
                    'subtotal' => 15000,
                    'final_amount' => 15000,
                ]],
                'summary' => [
                    'subtotal' => 15000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 15000,
                    'final_amount' => 15000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * 주문 생성 단계가 예상하지 못한 예외를 던지면 500 과 함께 예외 종류·메시지·위치를 오류 로그로 남긴다.
     *
     * @scenario failure=unexpected_exception, stage=create_from_temp_order
     *
     * @effects unexpected_failure_logged_with_cause
     *
     * @return void 없음
     */
    public function test_unexpected_failure_is_logged_with_its_cause(): void
    {
        $this->mock(OrderProcessingService::class, function ($mock): void {
            // 주문 요청 검증이 결제 통화로 고를 수 있는 결제수단을 판정할 때 같은 서비스에 결제 통화를 묻는다
            // (CheckoutPaymentMethodFilter). 서비스를 통째로 목으로 바꾸므로 그 답을 준다 — 없으면 검증 단계에서
            // 목 예외가 나 주문 생성 단계까지 가지 못한다.
            $mock->shouldReceive('resolvePaymentCurrency')->andReturn('KRW');
            $mock->shouldReceive('createFromTempOrder')->andThrow(new RuntimeException('injected fault EC-420'));
        });
        Log::spy();

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/user/orders', [
            'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'guest@test.com'],
            'shipping' => [
                'recipient_name' => '김수령',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '4층',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => 15000,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
            'guest_lookup_password' => 'guest1234',
            'guest_lookup_password_confirmation' => 'guest1234',
        ], ['X-Cart-Key' => $this->cartKey]);

        $response->assertStatus(500);
        $response->assertJsonPath('message', __('sirsoft-ecommerce::messages.order.create_failed'));

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            return $message === 'Order create: unexpected failure'
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'injected fault EC-420'
                && str_contains((string) $context['file'], ':');
        })->once();
    }

    /**
     * 테스트 뒤 Mockery 기대를 정리합니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
