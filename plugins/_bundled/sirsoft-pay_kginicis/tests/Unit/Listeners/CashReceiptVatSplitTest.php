<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterCashReceiptProviderListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 현금영수증 발급의 공급가액·부가세 분할 (#612 ②)
 *
 * 이커머스 1.3.3 은 발급 페이로드에 주문 부가세를 과세 몫 비율로 나눈 `vat_amount` 를 싣는다. 종전처럼 과세 몫을
 * 11 로 나누면 5% 세율 상품·영세(세율 0%)가 섞인 주문에서 부가세가 과다 신고된다. 값이 없으면(구버전 이커머스)
 * 종전 /11 규칙을 그대로 쓴다.
 */
class CashReceiptVatSplitTest extends PluginTestCase
{
    /**
     * KG 테스트 자격증명을 주입합니다 — 발급 API 가 식별번호를 암호화하려면 INIAPI 키/IV 가 필요하다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
        ]);

        $this->app->instance(PluginSettingsService::class, $settings);
        $this->app->forgetInstance(KgInicisApiService::class);

        Http::fake([
            '*/v2/pg/receipt' => Http::response(['resultCode' => '00', 'resultMsg' => '정상', 'tid' => 'CR_TID_612', 'authNo' => 'AUTH_612'], 200),
        ]);
    }

    /**
     * KG 결제가 달린 주문으로 발급을 요청하고, KG 로 나간 요청 본문의 금액 필드를 돌려줍니다.
     *
     * @param  array<string, mixed>  $payload  코어 발급 페이로드 중 금액 키
     * @return array{price: int, supplyPrice: int, tax: int} 전송 금액
     */
    private function issueAndCapture(array $payload): array
    {
        $order = Order::factory()->create();
        OrderPayment::factory()->create(['order_id' => $order->id, 'pg_provider' => 'kginicis', 'transaction_id' => 'KG_TID_612_'.uniqid()]);

        (new RegisterCashReceiptProviderListener)->issue(
            ['success' => false, 'error_code' => null, 'error_message' => null, 'receipt_key' => null, 'receipt_url' => null, 'issue_number' => null, 'raw_response' => null],
            $order->fresh(['payment']),
            'kginicis',
            $payload + ['type' => 'income', 'identifier' => '01012345678', 'identifier_type' => 'phone', 'order_name' => '영세 상품', 'order_number' => 'ORD612', 'issue_sequence' => 1],
        );

        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            $d = $request->data()['data'] ?? [];
            $sent = ['price' => (int) $d['price'], 'supplyPrice' => (int) $d['supplyPrice'], 'tax' => (int) $d['tax']];

            return true;
        });

        return $sent;
    }

    /**
     * 이커머스가 실은 부가세를 그대로 쓰는지 확인합니다 — 영세만 든 주문은 부가세 0.
     *
     * @scenario payload_vat=present
     *
     * @effects issue_uses_ecommerce_vat_amount
     *
     * @return void 반환값 없음
     */
    public function test_uses_vat_amount_from_payload(): void
    {
        $this->assertSame(
            ['price' => 13000, 'supplyPrice' => 13000, 'tax' => 0],
            $this->issueAndCapture(['amount' => 13000, 'tax_free_amount' => 13000, 'vat_amount' => 0]),
        );

        // 5% 세율 상품 10,500 → 부가세 500 (/11 이면 955)
        $this->assertSame(
            ['price' => 10500, 'supplyPrice' => 10000, 'tax' => 500],
            $this->issueAndCapture(['amount' => 10500, 'tax_free_amount' => 0, 'vat_amount' => 500]),
        );
    }

    /**
     * 페이로드에 부가세가 없으면(구버전 이커머스) 과세 몫/11 로 나누는지 확인합니다.
     *
     * @scenario payload_vat=absent
     *
     * @effects issue_falls_back_to_one_eleventh_without_vat
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_one_eleventh_without_vat_amount(): void
    {
        $this->assertSame(
            ['price' => 11000, 'supplyPrice' => 10091, 'tax' => 909],
            $this->issueAndCapture(['amount' => 11000, 'tax_free_amount' => 1000]),
        );
    }

    /**
     * 실린 부가세가 과세 몫보다 크면 과세 몫으로 자르는지 확인합니다 — 총액 = 공급가액 + 부가세 보존.
     *
     * @scenario payload_vat=exceeds_taxable_share
     *
     * @effects issue_uses_ecommerce_vat_amount
     *
     * @return void 반환값 없음
     */
    public function test_vat_is_capped_by_taxable_share(): void
    {
        $this->assertSame(
            ['price' => 5000, 'supplyPrice' => 4000, 'tax' => 1000],
            $this->issueAndCapture(['amount' => 5000, 'tax_free_amount' => 4000, 'vat_amount' => 3000]),
        );
    }
}
