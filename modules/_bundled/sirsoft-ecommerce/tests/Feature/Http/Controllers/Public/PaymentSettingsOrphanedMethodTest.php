<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 결제 설정 API 의 고아 결제수단 차단 테스트
 *
 * 회귀 배경: 토스페이먼츠 플러그인의 주문서형 결제를 끄면 toss_* 결제수단이 available
 * 카탈로그에서 빠지지만 저장값의 is_active 는 true 로 남는다. 관리자 화면은 고아 표시로
 * 차단하는데 체크아웃은 is_active 만 보므로, 공개 API 가 걸러 주지 않으면 비활성화한
 * 결제수단이 주문서에서 그대로 선택 가능했다. 플러그인 삭제/비활성 시에도 동일하다.
 */
class PaymentSettingsOrphanedMethodTest extends ModuleTestCase
{
    private const PAYMENT_ENDPOINT = '/api/modules/sirsoft-ecommerce/settings/payment';

    private const CHECKOUT_ENDPOINT = '/api/modules/sirsoft-ecommerce/settings/checkout';

    private const ORPHANED_ID = 'toss_kakaopay';

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        $this->saveOrderSettings([
            'payment_methods' => [
                ['id' => 'card', 'sort_order' => 1, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'payment_complete'],
                ['id' => 'dbank', 'sort_order' => 3, 'is_active' => true, 'min_order_amount' => 0, 'stock_deduction_timing' => 'order_placed'],
                [
                    // 공급 플러그인이 더 이상 제공하지 않는 결제수단 (필터 미등록 → available 부재)
                    'id' => self::ORPHANED_ID,
                    'sort_order' => 121,
                    'is_active' => true,
                    'min_order_amount' => 0,
                    'stock_deduction_timing' => 'payment_complete',
                    '_cached_name' => ['ko' => '카카오페이 (토스페이먼츠)', 'en' => 'KakaoPay (TossPayments)'],
                    '_cached_icon' => 'wallet',
                    '_cached_source' => 'plugin:sirsoft-tosspayments',
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_payment_endpoint_excludes_orphaned_method(): void
    {
        $response = $this->getJson(self::PAYMENT_ENDPOINT);

        $response->assertOk();
        $ids = array_column($response->json('data.order_settings.payment_methods'), 'id');

        $this->assertNotContains(self::ORPHANED_ID, $ids);
        $this->assertContains('card', $ids);
        $this->assertContains('dbank', $ids);
    }

    public function test_checkout_endpoint_excludes_orphaned_method(): void
    {
        $response = $this->getJson(self::CHECKOUT_ENDPOINT);

        $response->assertOk();
        $ids = array_column($response->json('data.order_settings.payment_methods'), 'id');

        $this->assertNotContains(self::ORPHANED_ID, $ids);
        $this->assertContains('card', $ids);
    }

    /**
     * order_settings.json 을 테스트 격리 경로에 기록합니다.
     *
     * @param  array  $settings  저장할 주문설정 배열
     */
    private function saveOrderSettings(array $settings): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put(
            $this->storagePath.'/order_settings.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
