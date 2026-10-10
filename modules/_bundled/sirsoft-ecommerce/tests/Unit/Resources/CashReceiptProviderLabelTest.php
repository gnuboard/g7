<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 주문 상세 「현금영수증」 카드는 발급사를 이름으로 보인다 (S3-D17, 2026-10-07 실측)
 *
 * 카드 제목 옆에 발급 프로바이더를 적는데, 응답이 프로바이더 **id** 만 실어 화면에 「현금영수증 tosspayments」처럼
 * 내부 식별자가 그대로 찍혔다(실측: 무통장 주문 20261007-0258571183 결제정보 탭). 등록된 프로바이더 목록은 이름
 * (`name`, 다국어 키 `name_key`)을 이미 들고 있다.
 */
class CashReceiptProviderLabelTest extends ModuleTestCase
{
    /**
     * 발급 프로바이더를 등록하고 선택합니다.
     *
     * @param  array  $entry  레지스트리 항목
     * @return void 반환값 없음
     */
    private function useProvider(array $entry): void
    {
        HookManager::addFilter('sirsoft-ecommerce.cash_receipt.registered_providers', function (array $providers) use ($entry) {
            $providers[] = $entry;

            return $providers;
        });

        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('order_settings.cash_receipt_provider', $entry['id']);
        $settings->clearCache();
    }

    /**
     * 무통장 결제행을 직렬화합니다.
     *
     * @return array 리소스 배열
     */
    private function serializeDbankPayment(): array
    {
        $order = Order::factory()->create();
        $payment = OrderPayment::factory()->create(['order_id' => $order->id, 'payment_method' => 'dbank']);

        return (new OrderPaymentResource($payment->fresh()))->toArray(request());
    }

    /**
     * 등록 항목의 이름을 표시명으로 낸다
     *
     * @scenario label_source=name
     *
     * @effects cash_receipt_provider_shows_display_name
     *
     * @return void 반환값 없음
     */
    public function test_payment_resource_exposes_the_provider_display_name(): void
    {
        $this->useProvider(['id' => 's3_receipt_pg', 'name' => '실측 발급사']);

        $result = $this->serializeDbankPayment();

        $this->assertSame('s3_receipt_pg', $result['cash_receipt_provider']);
        $this->assertSame('실측 발급사', $result['cash_receipt_provider_label'] ?? null, '발급사가 내부 id 로만 내려간다');
    }

    /**
     * 다국어 키가 있으면 현재 언어의 번역을 쓴다
     *
     * @scenario label_source=translation_key
     *
     * @effects cash_receipt_provider_name_follows_translation
     *
     * @return void 반환값 없음
     */
    public function test_provider_display_name_follows_the_translation_key(): void
    {
        app('translator')->addLines(['messages.s3_provider' => 'Receipt Issuer EN'], 'en', 's3test');
        app()->setLocale('en');
        $this->useProvider(['id' => 's3_receipt_pg', 'name' => '실측 발급사', 'name_key' => 's3test::messages.s3_provider']);

        $result = $this->serializeDbankPayment();

        $this->assertSame('Receipt Issuer EN', $result['cash_receipt_provider_label'] ?? null);
    }
}
