<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 카테고리 이름의 대체 언어가 사이트 기본 언어를 따르는지 테스트 (#148 D7).
 *
 * 종전 `app.fallback_locale`(env 고정, 기본 ko) 색인이라, 기본 언어가 영어인 사이트의 필리핀어 화면에서
 * 이름이 비면 한국어로 보였다. 이제 요청 언어 → 사이트 기본 언어 → 영어 → 첫 값 순이다.
 */
class CategoryLocalizedNameSiteLocaleTest extends ModuleTestCase
{
    /**
     * 요청 언어 값이 없거나 비면 사이트 기본 언어(en)를 쓴다.
     *
     * @scenario shape=three_letter
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_name_falls_back_to_site_locale(): void
    {
        config(['app.site_locale' => 'en', 'app.fallback_locale' => 'ko']);

        $category = new Category;
        $category->name = ['ko' => '신발', 'en' => 'Shoes', 'fil' => ''];

        $this->assertSame('Shoes', $category->getLocalizedName('fil'));
        $this->assertSame('신발', $category->getLocalizedName('ko'));
    }

    /**
     * 체인에 없으면 첫 값을 쓴다(종전 꼬리 보존).
     *
     * @scenario shape=region_tagged
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_name_falls_back_to_first_value(): void
    {
        config(['app.site_locale' => 'ja', 'app.fallback_locale' => 'ko']);

        $category = new Category;
        $category->name = ['de' => 'Schuhe'];

        $this->assertSame('Schuhe', $category->getLocalizedName('fil-PH'));
    }
}
