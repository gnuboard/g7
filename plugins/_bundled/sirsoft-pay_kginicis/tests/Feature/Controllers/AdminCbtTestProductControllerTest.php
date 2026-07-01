<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class AdminCbtTestProductControllerTest extends PluginTestCase
{
    /**
     * 회귀 — Product 모델의 name 필드는 AsUnicodeJson 캐스트.
     * 컨트롤러가 plain string 을 전달하면 저장은 되지만 retrieve 시
     * array_key_first(null) 예외로 /shop/products 가 500.
     * 본 테스트는 컨트롤러가 항상 다국어 배열로 전달함을 보장.
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

    public function test_admin_transaction_status_uses_local_cbt_confirmation_instead_of_korean_inquiry(): void
    {
        Http::fake();

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $this->actingAs($admin);

        $order = OrderFactory::new()->create([
            'order_number' => 'JP-ORDER-ADMIN-CBT-001',
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'JPY',
            'currency_snapshot' => ['JPY' => 1.0],
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
            'currency_snapshot' => ['JPY' => 1.0],
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
}
