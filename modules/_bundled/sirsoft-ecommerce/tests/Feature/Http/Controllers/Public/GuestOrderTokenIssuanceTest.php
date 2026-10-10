<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 생성 응답의 비회원 조회 토큰 발급 (KVE-2026-2434)
 *
 * 결제창 닫힘 보고(PG 플러그인 close-report)는 주문 소유자만 받아 준다. 비회원의 소유 증명은
 * 주문을 실제로 만든 브라우저만 받는 주문 생성 응답의 조회 토큰이다 — 주문자 연락처는 제3자도
 * 알 수 있어 증명이 되지 못한다. 회원은 로그인으로 증명하므로 토큰을 받지 않는다.
 */
class GuestOrderTokenIssuanceTest extends ModuleTestCase
{
    private const ORDER_ENDPOINT = '/api/modules/sirsoft-ecommerce/user/orders';

    protected string $cartKey;

    protected Product $product;

    protected ProductOption $productOption;

    /**
     * 주문할 상품·옵션과 장바구니 키를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cartKey = Str::uuid()->toString();

        $this->product = Product::create([
            'name' => ['ko' => '토큰 발급 상품', 'en' => 'Token Product'],
            'product_code' => 'TOKEN-'.Str::random(8),
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

    /**
     * 비회원 주문 생성 응답에 그 주문의 조회 토큰과 만료시각이 실리고, 토큰은 그 주문에 대해 검증을 통과한다.
     *
     * @scenario actor=guest
     *
     * @effects guest_order_response_carries_order_token
     *
     * @return void 반환값 없음
     */
    public function test_guest_order_response_carries_verifiable_order_token(): void
    {
        $this->createTempOrder(null);

        $response = $this->postJson(self::ORDER_ENDPOINT, $this->orderPayload(true), ['X-Cart-Key' => $this->cartKey]);

        $response->assertStatus(201);
        $token = $response->json('data.guest_order_token');
        $orderNumber = $response->json('data.order.order_number');

        $this->assertIsString($token);
        $this->assertNotEmpty($response->json('data.guest_order_token_expires_at'));

        $verified = app(GuestOrderAuthService::class)->verifyToken($token, $orderNumber);
        $this->assertNotNull($verified);
        $this->assertSame($orderNumber, $verified->order_number);
    }

    /**
     * 비회원 주문의 조회 토큰은 다른 주문번호에 대해 검증을 통과하지 못한다.
     *
     * @scenario actor=guest
     *
     * @effects guest_order_token_is_bound_to_its_order
     *
     * @return void 반환값 없음
     */
    public function test_guest_order_token_cannot_be_used_for_another_order(): void
    {
        $this->createTempOrder(null);

        $response = $this->postJson(self::ORDER_ENDPOINT, $this->orderPayload(true), ['X-Cart-Key' => $this->cartKey]);

        $response->assertStatus(201);
        $other = Order::factory()->create(['user_id' => null]);

        $this->assertNull(app(GuestOrderAuthService::class)->verifyToken(
            $response->json('data.guest_order_token'),
            $other->order_number
        ));
    }

    /**
     * 회원 주문 생성 응답에는 비회원 조회 토큰을 싣지 않는다 — 회원은 로그인으로 소유를 증명한다.
     *
     * @scenario actor=member
     *
     * @effects member_order_response_has_no_guest_token
     *
     * @return void 반환값 없음
     */
    public function test_member_order_response_has_no_guest_token(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->createTempOrder($user->id);

        $response = $this->postJson(self::ORDER_ENDPOINT, $this->orderPayload(false), ['X-Cart-Key' => $this->cartKey]);

        $response->assertStatus(201)
            ->assertJsonMissingPath('data.guest_order_token')
            ->assertJsonMissingPath('data.guest_order_token_expires_at');
    }

    /**
     * 임시주문을 만듭니다 (옵션 2개, 30,000원).
     *
     * @param  int|null  $userId  회원 ID (비회원이면 null)
     * @return TempOrder 생성한 임시주문
     */
    private function createTempOrder(?int $userId): TempOrder
    {
        return TempOrder::create([
            'user_id' => $userId,
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
     * 무통장입금 주문 생성 요청 본문을 만듭니다.
     *
     * @param  bool  $asGuest  비회원 주문이면 조회 비밀번호를 함께 싣는다
     * @return array<string, mixed> 요청 본문
     */
    private function orderPayload(bool $asGuest): array
    {
        $payload = [
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
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ];

        if ($asGuest) {
            $payload['guest_lookup_password'] = 'guest1234';
            $payload['guest_lookup_password_confirmation'] = 'guest1234';
        }

        return $payload;
    }
}
