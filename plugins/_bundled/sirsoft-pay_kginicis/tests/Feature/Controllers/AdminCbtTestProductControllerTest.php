<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use Modules\Sirsoft\Ecommerce\Contracts\Catalog\ProductProvisioner;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class AdminCbtTestProductControllerTest extends PluginTestCase
{
    /**
     * 회귀 — Product 모델의 name 필드는 AsUnicodeJson 캐스트.
     * 컨트롤러가 plain string 을 전달하면 저장은 되지만 retrieve 시
     * array_key_first(null) 예외로 /shop/products 가 500.
     * 본 테스트는 컨트롤러가 항상 다국어 배열로 전달함을 보장.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cbt_test_product_name
     *
     * @effects cbt_test_product_name_is_multilingual_array
     */
    public function test_cbt_test_product_name_is_multilingual_array(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product');

        $response->assertSuccessful();
        $productId = $response->json('data.product_id');
        $this->assertNotNull($productId);

        $product = Product::find($productId);
        $this->assertNotNull($product);

        $rawName = $product->getRawOriginal('name');
        $decoded = json_decode((string) $rawName, true);
        $this->assertIsArray($decoded, 'name 은 다국어 JSON 객체여야 한다 (plain string 회귀 차단)');
        $this->assertArrayHasKey('ko', $decoded);
        $this->assertArrayHasKey('en', $decoded);
        $this->assertArrayHasKey('ja', $decoded);

        $rawDescription = $product->getRawOriginal('description');
        $decodedDesc = json_decode((string) $rawDescription, true);
        $this->assertIsArray($decodedDesc, 'description 도 다국어 JSON 객체여야 한다');
    }

    /**
     * 시험 상품은 상점 기본 통화와 무관하게 결제 때 정확히 100엔이 되는 가격으로 저장된다.
     *
     * 이커머스 상품 가격은 기본 통화로만 저장된다 — 기본 통화가 원화인 상점에서 종전처럼 100 을 그대로 넣으면 100원 상품이
     * 되어 엔화 결제 금액이 환율만큼 달라졌다. 계약이 지금 환율로 기본 통화 가격을 역산해 저장하므로, 저장 가격을 다시 엔화로
     * 환산하면 정확히 100 이다. 상품 통화 칸은 기본 통화다.
     *
     * @scenario case=cbt_test_product_jpy_price
     *
     * @effects cbt_test_product_charges_exactly_100_yen
     *
     * @return void 반환값 없음
     */
    public function test_cbt_test_product_is_priced_at_exactly_100_yen_in_any_default_currency(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product');

        $response->assertSuccessful();
        $product = Product::findOrFail($response->json('data.product_id'));
        $currency = app(CurrencyConversionService::class);

        $this->assertSame($currency->getDefaultCurrency(), $product->currency_code, '가격은 기본 통화로 저장된다');
        $this->assertEquals(100, $currency->convertToCurrency((int) $product->selling_price, 'JPY')['price'], '판매가는 엔화로 정확히 100엔');
        $this->assertEquals(100, $currency->convertToCurrency((int) $product->list_price, 'JPY')['price'], '정가도 엔화로 100엔');
    }

    /**
     * 쇼핑몰 통화 설정에 엔화가 없으면 100엔 가격을 정할 수 없어 422 와 안내 문구로 멈춘다 — 일반 500 으로 끝나 원인을 알 수 없던 경로.
     *
     * @scenario case=cbt_test_product_jpy_price
     *
     * @effects cbt_test_product_requires_jpy_currency
     *
     * @return void 반환값 없음
     */
    public function test_cbt_test_product_without_jpy_currency_returns_422_with_guidance(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $this->actingAs($admin);
        $provisioner = Mockery::mock(ProductProvisioner::class);
        $provisioner->shouldReceive('createSimpleProduct')->andThrow(new InvalidArgumentException('JPY 통화 없음'));
        $this->app->instance(ProductProvisioner::class, $provisioner);

        $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product')
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-pay_kginicis::messages.cbt_test_product.jpy_required'));
    }

    /**
     * 시험 상품 생성은 관리자 인증 없이 부를 수 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=admin_auth_required
     *
     * @effects cbt_test_product_endpoint_requires_admin
     */
    public function test_cbt_test_product_endpoint_requires_admin(): void
    {
        // 인증 없이 호출 — 401 또는 redirect
        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product');

        $this->assertContains(
            $response->getStatusCode(),
            [401, 403, 302],
            '비인증 호출은 401/403/302 중 하나여야 한다',
        );
    }

    /**
     * CBT 결제의 관리자 거래 상태 조회는 국내 조회 API 대신 로컬 결제 기록으로 답한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cbt_transaction_status
     *
     * @effects cbt_admin_transaction_status_uses_local_confirmation
     */
    public function test_admin_transaction_status_uses_local_cbt_confirmation_instead_of_korean_inquiry(): void
    {
        Http::fake();

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $this->actingAs($admin);

        $order = OrderFactory::new()->create([
            'order_number' => 'JP-ORDER-ADMIN-CBT-001',
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'JPY',
            'currency_snapshot' => self::jpyCurrencySnapshot(),
            'subtotal_amount' => 100,
            'total_amount' => 100,
            'total_due_amount' => 100,
            'total_paid_amount' => 100,
            'item_count' => 1,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => PaymentMethodEnum::VBANK,
            'pg_provider' => 'kginicis',
            'transaction_id' => 'INIJPGCVS_CBTTEST00120260522160833186429',
            'paid_amount_local' => 100,
            'paid_amount_base' => 100,
            'currency' => 'JPY',
            'currency_snapshot' => self::jpyCurrencySnapshot(),
            'payment_meta' => [
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'cbt_sid' => 'SID-ADMIN-CBT-001',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'currency' => 'JPY',
                'pay_method' => 'CVS',
                'cvs_status' => 'paid',
                'pg_raw_response' => [
                    'resultCode' => '00',
                    'paymethod' => 'CVS',
                    'amount' => '100',
                    'currencyCd' => 'JPY',
                    'orderId' => 'JP-ORDER-ADMIN-CBT-001',
                    'applDt' => '20260522',
                    'applTm' => '160833',
                    'confNo' => '999999999999999999',
                    'paymentTerm' => '20260530235959',
                ],
            ],
        ]);

        $response = $this->getJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/JP-ORDER-ADMIN-CBT-001/transaction-status');

        $response->assertOk()
            ->assertJsonPath('data._is_cbt', true)
            ->assertJsonPath('data._is_local_confirmation', true)
            ->assertJsonPath('data._pay_method', 'CVS')
            ->assertJsonPath('data._pay_method_label', '일본 편의점결제')
            ->assertJsonPath('data._currency', 'JPY')
            ->assertJsonPath('data._moid', 'JP-ORDER-ADMIN-CBT-001');

        Http::assertNothingSent();
    }

    /**
     * 로컬 기록이 없는 CBT 거래번호 직접 조회도 국내 조회 API 를 부르지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cbt_direct_tid_query
     *
     * @effects cbt_direct_tid_query_skips_korean_inquiry_without_local_row
     */
    public function test_admin_direct_cbt_tid_query_does_not_call_korean_inquiry_without_local_row(): void
    {
        Http::fake();

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/transaction/query', [
            'tid' => 'INIJPGCARDCBTTEST00120260522160833186429',
        ]);

        $response->assertOk()
            ->assertJsonPath('data._is_cbt', true)
            ->assertJsonPath('data._is_local_confirmation', true)
            ->assertJsonPath('data.tid', 'INIJPGCARDCBTTEST00120260522160833186429')
            ->assertJsonPath('data._currency', 'JPY');

        Http::assertNothingSent();
    }

    /**
     * tid 게이트 상한은 저장 컬럼(transaction_id varchar(100))이 SSoT (회귀 고정).
     *
     * 국내 TID(30자) 길이로 조이면 40자 CBT TID 조회가 422 로 깨진다 — 위 테스트가
     * 그 회귀를 잡았다. 여기서는 반대편 경계(101자 초과 거부)를 고정한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=tid_length_gate
     *
     * @effects transaction_query_rejects_tid_over_storage_column_length
     */
    public function test_transaction_query_rejects_tid_over_storage_column_length(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $this->actingAs($admin);

        $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/transaction/query', [
            'tid' => str_repeat('T', 101),
        ])->assertStatus(422);
    }
}
