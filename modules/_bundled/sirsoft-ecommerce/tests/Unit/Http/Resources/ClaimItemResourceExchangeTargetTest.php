<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimItemResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 상품 행 리소스 — 교환 대상 표기 계약 테스트
 *
 * 목록 카드의 `⇄` 줄과 상세의 교환 대상 줄이 읽는 값이다. 이 필드가 비면 화면은 옵션명 없이
 * 차액만 그리는데, 응답은 200 이고 다른 필드는 정상이라 어긋난 흔적이 남지 않는다
 * (2026-09-01 4차 계획 검증 브라우저 실측에서 드러났다 — 옵션변경 교환이 하나도 없어
 * 그때까지 이 자리가 한 번도 실제로 그려진 적이 없었다).
 */
class ClaimItemResourceExchangeTargetTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HasMultiCurrencyPrices::clearCurrencySettingsCache();
    }

    /**
     * 신청 상품 행 리소스를 배열로 바꿉니다.
     *
     * @param  array  $snapshot  신규 옵션 스냅샷
     * @return array<string, mixed> 응답 배열
     */
    private function toArray(array $snapshot): array
    {
        $item = OrderExchangeOption::make([
            'new_product_option_id' => 77,
            'new_option_snapshot' => $snapshot,
            'unit_price' => 28000,
            'new_unit_price' => 27000,
            'price_difference' => -1000,
            'exchange_quantity' => 1,
        ]);

        return (new ClaimItemResource($item))->toArray(Request::create('/'));
    }

    /**
     * 교환 대상 옵션명은 스냅샷이 실제로 담는 키에서 나온다.
     *
     * 되돌림 축: 존재하지 않는 `option_label`/`name` 을 읽던 옛 형태로 되돌리면 항상 null 이 되어 red.
     */
    public function test_exchange_target_label_comes_from_the_snapshot_keys_that_exist(): void
    {
        $array = $this->toArray([
            'option_name' => ['ko' => '화이트/XL', 'en' => 'White/XL'],
            'option_value' => ['ko' => '색상: 화이트, 사이즈: XL', 'en' => 'Color: White, Size: XL'],
        ]);

        $this->assertSame('색상: 화이트, 사이즈: XL', $array['new_option_label'], '항목명까지 담긴 표기를 우선한다.');
    }

    /**
     * 자세한 표기가 없는 구형 스냅샷은 짧은 이름으로 떨어진다. (하위호환)
     */
    public function test_exchange_target_label_falls_back_to_the_short_option_name(): void
    {
        $array = $this->toArray(['option_name' => ['ko' => '화이트/XL']]);

        $this->assertSame('화이트/XL', $array['new_option_label']);
    }

    /**
     * 옵션명이 없는 스냅샷에서는 null 이다. (없는 값을 만들어 내지 않는다)
     */
    public function test_exchange_target_label_is_null_when_the_snapshot_has_no_name(): void
    {
        $this->assertNull($this->toArray(['custom_options' => ['selections' => [3 => 9]]])['new_option_label']);
    }

    /**
     * 추가옵션은 신청 시점에 동결된 이름·추가금으로 나온다.
     *
     * 되돌림 축: 리소스에서 `new_additional_options` 를 빼면 화면이 그 줄을 그릴 재료를 잃는다.
     */
    public function test_additional_options_are_exposed_with_frozen_names_and_prices(): void
    {
        $array = $this->toArray([
            'option_name' => ['ko' => '화이트/XL'],
            'additional_options_snapshot' => [
                ['additional_option_id' => 3, 'value_id' => 9, 'name' => ['ko' => '선물 포장'], 'price_adjustment' => 3000],
                ['additional_option_id' => 4, 'value_id' => 12, 'name' => ['ko' => '각인'], 'price_adjustment' => 0, 'custom_text' => 'G7'],
            ],
            'additional_options_total' => 3000,
        ]);

        $this->assertCount(2, $array['new_additional_options']);
        $this->assertSame('선물 포장', $array['new_additional_options'][0]['name']);
        $this->assertSame(3000, (int) $array['new_additional_options'][0]['price_adjustment']);
        $this->assertArrayHasKey('price_adjustment_formatted', $array['new_additional_options'][0]);
        $this->assertSame('각인', $array['new_additional_options'][1]['name']);
        $this->assertSame('G7', $array['new_additional_options'][1]['custom_text'], '직접입력 텍스트도 동결값이다.');
        $this->assertSame(3000, (int) $array['new_additional_options_total']);
    }

    /**
     * 추가옵션이 없으면 빈 배열이다. (화면이 그 줄을 세우지 않는 근거)
     */
    public function test_additional_options_are_empty_when_none_were_picked(): void
    {
        $array = $this->toArray(['option_name' => ['ko' => '화이트/XL']]);

        $this->assertSame([], $array['new_additional_options']);
        $this->assertSame(0, (int) $array['new_additional_options_total']);
    }
}
