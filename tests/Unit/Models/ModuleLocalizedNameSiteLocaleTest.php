<?php

namespace Tests\Unit\Models;

use App\Models\Module;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 모듈 이름·설명의 대체 언어가 사이트 기본 언어를 따르는지 테스트 (#148 D7).
 *
 * 종전에는 env 고정 `app.fallback_locale` 로 대체해, 기본 언어가 일본어인 사이트에서도 필리핀어
 * 화면의 빈 이름이 한국어(또는 env 값)로 보였다. 이제 요청 언어 → 사이트 기본 언어 → 영어 순이다.
 */
#[Group('core')]
class ModuleLocalizedNameSiteLocaleTest extends TestCase
{
    /**
     * 요청 언어 값이 없으면 사이트 기본 언어(ja) 값을 쓴다.
     *
     * @scenario shape=three_letter
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_name_falls_back_to_site_locale(): void
    {
        config(['app.site_locale' => 'ja', 'app.fallback_locale' => 'ko']);

        $module = new Module;
        $module->name = ['ko' => '모듈', 'en' => 'Module', 'ja' => 'モジュール'];
        $module->description = ['ko' => '설명', 'en' => 'Description', 'ja' => '説明', 'fil' => ''];

        $this->assertSame('モジュール', $module->getLocalizedName('fil'));
        $this->assertSame('説明', $module->getLocalizedDescription('fil'));
    }

    /**
     * 사이트 기본 언어 값도 없으면 영어, 그것도 없으면 첫 값.
     *
     * @scenario shape=region_tagged
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_name_falls_back_to_en_then_first_value(): void
    {
        config(['app.site_locale' => 'ja', 'app.fallback_locale' => 'ko']);

        $module = new Module;
        $module->name = ['ko' => '모듈', 'en' => 'Module'];
        $this->assertSame('Module', $module->getLocalizedName('fil-PH'));

        $module->name = ['ko' => '모듈'];
        $this->assertSame('모듈', $module->getLocalizedName('fil-PH'));
    }
}
