<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 해외 배송 주문의 상세주소 필수 검증 회귀 테스트 (#703)
 *
 * 회귀 배경: 주문 생성 검증이 `shipping.address_detail` 을 국가와 무관하게 필수로 요구했다.
 * 해외 배송 폼에는 상세주소 칸이 없고(주소 1·2행으로 대체) 배송국가를 바꾸면 이 값이 비워지므로,
 * 해외 주문은 결제하기를 누를 때마다 「상세 주소를 입력해주세요.」 422 로 막혔다.
 * 국내 주소(`address`)와 같은 짝(해외 주소 1행이 없을 때만 필수)으로 판정해야 한다.
 */
class CheckoutOverseasAddressDetailTest extends ModuleTestCase
{
    protected string $cartKey;

    protected Product $product;

    protected ProductOption $productOption;

    /**
     * 비회원 임시 주문에 담을 상품·옵션을 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cartKey = Str::uuid()->toString();

        $this->product = Product::create([
            'name' => ['ko' => '해외 배송 상품', 'en' => 'Overseas Product'],
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

        TempOrder::create([
            'user_id' => null,
            'cart_key' => $this->cartKey,
            'items' => [[
                'product_id' => $this->product->id,
                'product_option_id' => $this->productOption->id,
                'quantity' => 1,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $this->product->id,
                    'product_option_id' => $this->productOption->id,
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
     * 비회원 주문 생성 요청 페이로드를 만듭니다.
     *
     * @param  array<string, mixed>  $shipping  배송지 입력
     * @return array<string, mixed> 주문 생성 요청 본문
     */
    private function payload(array $shipping): array
    {
        return [
            'orderer' => [
                'name' => '홍길동',
                'phone' => '010-1234-5678',
                'email' => 'guest@test.com',
            ],
            'shipping' => array_merge([
                'recipient_name' => '김해외',
                'recipient_phone' => '010-9876-5432',
            ], $shipping),
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
        ];
    }

    /**
     * 해외 배송지(주소 1행 입력, 상세주소 없음)는 상세주소 필수 검증에 걸리지 않는다.
     *
     * 배송국가 전환 시 화면이 상세주소를 빈 문자열로 비우므로 빈 값 전송도 함께 검증한다.
     *
     * @scenario shipping_region=overseas, address_detail_input=missing
     *
     * @effects overseas_order_passes_without_address_detail
     *
     * @return void 없음
     */
    public function test_overseas_order_without_address_detail_passes_validation(): void
    {
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->payload([
                'country_code' => 'US',
                'zipcode' => '',
                'address' => '',
                'address_detail' => '',
                'address_line_1' => '1600 Amphitheatre Parkway',
                'address_line_2' => 'Building 40',
                'intl_city' => 'Mountain View',
                'intl_state' => 'CA',
                'intl_postal_code' => '94043',
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        // 검증을 통과했는지만 본다 — 이후 단계(상품의 해외 배송 가능 여부 등)는 이 테스트의 관심사가 아니다.
        // 해외 주소가 상세주소 없이 주문 주소로 저장되는 경로는 OrderProcessingShippingCountryTest(B2)가 고정한다.
        $response->assertJsonMissingValidationErrors([
            'shipping.address_detail',
            'shipping.address',
            'shipping.zipcode',
            'shipping.address_line_1',
        ]);
    }

    /**
     * 국내 배송지는 상세주소를 여전히 필수로 요구한다.
     *
     * @scenario shipping_region=domestic, address_detail_input=missing
     *
     * @effects domestic_order_still_requires_address_detail
     *
     * @return void 없음
     */
    public function test_domestic_order_without_address_detail_is_rejected(): void
    {
        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            $this->payload([
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
            ]),
            ['X-Cart-Key' => $this->cartKey]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shipping.address_detail']);
    }
}
