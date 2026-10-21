<?php

namespace Tests\Unit\Seo;

use App\Seo\PipeRegistry;
use Tests\TestCase;

/**
 * SEO localized 파이프 — 사이트 기본 언어 대체 (#148 D7)
 *
 * 현재 로케일 값이 없으면 종전처럼 ko 로 고정하지 않고 사이트 기본 언어(`app.site_locale`)로 대체한다.
 */
class PipeRegistryLocalizedSiteLocaleTest extends TestCase
{
    private PipeRegistry $registry;

    /**
     * 파이프 레지스트리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new PipeRegistry;
    }

    /**
     * localized 파이프가 현재 로케일 값이 없으면 사이트 기본 언어로 대체합니다.
     *
     * @scenario shape=three_letter
     *
     * @effects localized_object_picks_current_then_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_localized_pipe_falls_back_to_site_locale(): void
    {
        config(['app.site_locale' => 'en']);
        $this->registry->setLocale('fil');

        $result = $this->registry->execute('localized', ['ko' => '상품명', 'en' => 'Product', 'fil' => ' ']);

        $this->assertSame('Product', $result);
    }
}
