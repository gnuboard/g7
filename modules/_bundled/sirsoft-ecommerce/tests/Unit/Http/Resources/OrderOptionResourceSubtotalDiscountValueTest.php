<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderOptionResource 무할인 소계 비교용 숫자 보조 필드 회귀 테스트 (U19②)
 *
 * 과거: subtotal_discount_amount 는 모델 decimal:2 캐스트로 JSON 직렬화 시 문자열
 * ("0.00") 로 출력되어, 마이페이지 레이아웃의 `=== 0` strict 비교가 항상 false 가
 * 되며 무할인 항목 소계가 화면에서 사라지던 결함을 정정했었다.
 *
 * 현재: raw 금액 응답을 통화 소수 자릿수로 정규화하면서(MP08 다통화 후속), 기본 통화가
 * 0자리(KRW/JPY)면 subtotal_discount_amount 자체가 정수(0/3000)로 응답된다. 따라서
 * `=== 0` 비교는 raw 필드만으로도 성립한다. 보조 필드 subtotal_discount_amount_value
 * (항상 float)는 타 소비처 호환을 위해 계속 노출한다.
 *
 * 테스트 기본 통화: ModuleTestCase 기본 설정(KRW, 0자리) 기준 → raw 는 정수.
 */
class OrderOptionResourceSubtotalDiscountValueTest extends ModuleTestCase
{
    /**
     * 기본 통화를 0자리(KRW)로 **명시 고정**한다.
     *
     * raw 금액은 기본 통화의 `decimal_places` 로 정규화되므로, 0자리면 정수(0)·2자리면
     * 소수(0.0)가 나온다. 이 클래스의 단언은 0자리를 전제하는데, 같은 프로세스에서 먼저
     * 돈 테스트가 상점 설정을 2자리 통화로 남기면 그 전제가 조용히 깨진다(단독 실행은
     * 통과하고 묶어 돌릴 때만 실패하는 형태 — 실제로 관측됐다).
     *
     * 부팅 시 로드된 운영 설정이 단위 테스트로 스며드는 것과 같은 성질이므로, 전제를
     * 주석이 아니라 코드로 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $languageCurrency = [
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => '원'], 'is_default' => true, 'decimal_places' => 0, 'base_unit' => 1000, 'exchange_rate' => null],
            ],
        ];

        app(EcommerceSettingsService::class)->setSetting('language_currency', $languageCurrency);
        config(['g7_settings.modules.sirsoft-ecommerce.language_currency' => $languageCurrency]);
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    /**
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
        parent::tearDown();
    }

    /**
     * @scenario discount_state=none
     *
     * @effects no_discount_exposes_the_numeric_field_as_float_zero, raw_amount_is_normalized_to_the_base_currency_decimal_places
     */
    public function test_무할인_옵션은_숫자형_보조필드가_정확히_0_float로_노출된다(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'subtotal_discount_amount' => 0,
        ]);

        $array = (new OrderOptionResource($option->fresh()))->toArray(Request::create('/'));

        // raw 필드: 0자리 통화 정규화로 정수 0 (과거 "0.00" 문자열에서 변경)
        $this->assertSame(0, $array['subtotal_discount_amount']);

        // 숫자 보조 필드: 항상 float 로 노출 (타 소비처 호환)
        $this->assertArrayHasKey('subtotal_discount_amount_value', $array);
        $this->assertIsFloat($array['subtotal_discount_amount_value']);
        $this->assertSame(0.0, $array['subtotal_discount_amount_value']);
    }

    /**
     * @scenario discount_state=positive
     *
     * @effects positive_discount_exposes_the_numeric_field_as_a_positive_float, raw_amount_is_normalized_to_the_base_currency_decimal_places
     */
    public function test_할인_옵션은_숫자형_보조필드가_양수_float로_노출된다(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'subtotal_discount_amount' => 3000,
        ]);

        $array = (new OrderOptionResource($option->fresh()))->toArray(Request::create('/'));

        // raw 필드: 0자리 통화 정규화로 정수 3000 (과거 "3000.00" 문자열에서 변경)
        $this->assertSame(3000, $array['subtotal_discount_amount']);
        $this->assertArrayHasKey('subtotal_discount_amount_value', $array);
        $this->assertIsFloat($array['subtotal_discount_amount_value']);
        $this->assertSame(3000.0, $array['subtotal_discount_amount_value']);
        $this->assertTrue($array['subtotal_discount_amount_value'] > 0);
    }
}
