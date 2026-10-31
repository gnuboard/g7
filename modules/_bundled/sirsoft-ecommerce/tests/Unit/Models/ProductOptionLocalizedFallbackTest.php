<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Support\LocalizedText;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 옵션 이름·값의 언어 대체 — 빈 언어 칸을 건너뛴다
 *
 * 상품 폼의 옵션 처리기는 지원 언어마다 칸을 만들어 보내므로, 영어로만 입력한 옵션 그룹은
 * `{"ko": null, "en": "Color"}` 로 저장된다. 지역화가 「요청 언어 ?? 대체 언어 ?? 첫 값」 순이라
 * 한국어 화면에서는 세 단계가 모두 null(첫 값도 ko)이어서 빈 문자열이 됐고, 사용자 상품 화면의
 * 옵션 고르기 안내가 「을(를) 선택해주세요」 로 이름 없이 나왔다(이커머스 전수 실측 S5-D39, 2026-10-08).
 */
class ProductOptionLocalizedFallbackTest extends ModuleTestCase
{
    /**
     * 비어 있지 않은 첫 값을 고른다(요청 언어 → 대체 언어 → 나머지 순).
     *
     * @return void 반환값 없음
     *
     * @scenario case=c01
     *
     * @effects option_text_falls_back_past_empty_locales
     */
    public function test_pick_skips_null_and_blank_locales(): void
    {
        config(['app.fallback_locale' => 'ko']);

        $this->assertSame('Color', LocalizedText::pick(['ko' => null, 'en' => 'Color'], 'ko'));
        $this->assertSame('Color', LocalizedText::pick(['ko' => '', 'en' => 'Color', 'ja' => ''], 'ko'));
        $this->assertSame('색상', LocalizedText::pick(['ko' => '색상', 'en' => ''], 'en'));
        $this->assertSame('Color', LocalizedText::pick(['ko' => '색상', 'en' => 'Color'], 'en'));
        $this->assertSame('plain', LocalizedText::pick('plain', 'ko'));
        $this->assertSame('', LocalizedText::pick(['ko' => null, 'en' => ''], 'ko'));
    }

    /**
     * 영어로만 입력한 옵션 그룹 이름이 한국어 응답에서 영어로 대체된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=c02
     *
     * @effects option_text_falls_back_past_empty_locales
     */
    public function test_option_group_name_falls_back_when_request_locale_is_empty(): void
    {
        config(['app.fallback_locale' => 'ko']);

        $product = Product::make();
        $product->option_groups = [[
            'name' => ['ko' => null, 'en' => 'Color', 'ja' => null],
            'values' => [['ko' => '빨강', 'en' => 'Red'], ['ko' => null, 'en' => 'Blue']],
        ]];

        $groups = $product->getOptionGroupsForApi('ko');

        $this->assertSame('Color', $groups[0]['name_localized']);
        $this->assertSame(['빨강', 'Blue'], $groups[0]['values_localized']);
    }

    /**
     * 옵션값의 키(옵션 이름)도 빈 언어를 건너뛰어, 한국어 응답에서 키가 사라지지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=c03
     *
     * @effects option_text_falls_back_past_empty_locales
     */
    public function test_option_values_key_falls_back_when_request_locale_is_empty(): void
    {
        config(['app.fallback_locale' => 'ko']);

        $option = ProductOption::make();
        $option->option_values = [[
            'key' => ['ko' => null, 'en' => 'Color'],
            'value' => ['ko' => '빨강', 'en' => 'Red'],
        ]];
        $option->option_name = ['ko' => null, 'en' => 'Red'];

        $this->assertSame(['Color' => '빨강'], $option->getLocalizedOptionValues('ko'));
        $this->assertSame('Red', $option->getLocalizedOptionName('ko'));
    }
}
