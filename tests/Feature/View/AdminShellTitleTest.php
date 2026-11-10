<?php

namespace Tests\Feature\View;

use App\Http\View\Composers\TemplateComposer;
use App\Seo\SeoTitleRuleExporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\View\View;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 관리자 셸 브라우저 탭 제목 계산 값 (`G7Config.seoTitle`, 코어 7.1.5 · engine-v1.88.0)
 *
 * 관리자 화면 탭 제목은 「{화면 제목} - {사이트 이름} 관리자」다. 엔진은 관리자 셸이 넘긴 사이트 이름과 언어별 접미사로만
 * 계산한다 — 화면 안에서 언어를 바꿔도 다시 받지 않게 접미사는 지원 언어 전부의 맵이다. 값이 없으면 엔진은 탭 제목을
 * 바꾸지 않는다(옛 동작).
 *
 * @scenario-manifest tests/scenarios/browser-tab-title-rule.yaml
 */
#[Group('core')]
class AdminShellTitleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 관리자 셸 컴포저가 뷰에 넘긴 값들을 모읍니다.
     *
     * @return array<string, mixed> 뷰 변수 이름 → 값
     */
    private function composeAdminShell(): array
    {
        $captured = [];
        $view = Mockery::mock(View::class);
        $view->shouldReceive('with')->andReturnUsing(function ($key, $value = null) use (&$captured, $view) {
            $captured[$key] = $value;

            return $view;
        });

        app(TemplateComposer::class)->compose($view);

        return $captured;
    }

    /**
     * 관리자 셸이 사이트 이름과 지원 언어별 관리자 접미사를 넘긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=admin_screen_title
     *
     * @effects admin_shell_exports_site_name_and_localized_suffix
     */
    public function test_admin_shell_exports_site_name_and_localized_suffix(): void
    {
        config(['g7_settings.core.general.site_name' => '지세븐 상점']);

        $seoTitle = $this->composeAdminShell()['seoTitle'] ?? null;

        $this->assertIsArray($seoTitle);
        $this->assertSame('지세븐 상점', $seoTitle['site_name']);
        $this->assertSame(' - {site_name} 관리자', $seoTitle['suffix']['ko']);
        $this->assertSame(' - {site_name} Admin', $seoTitle['suffix']['en']);
    }

    /**
     * 접미사는 넘겨받은 지원 언어마다 그 언어 문구로 만든다(빈 값·문자열 아닌 값은 건너뛴다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=admin_screen_title
     *
     * @effects admin_shell_exports_site_name_and_localized_suffix
     */
    public function test_exporter_builds_suffix_per_locale(): void
    {
        $export = app(SeoTitleRuleExporter::class)->exportForAdmin(['en', '', 'ko']);

        $this->assertSame(['en', 'ko'], array_keys($export['suffix']));
        $this->assertSame(' - {site_name} Admin', $export['suffix']['en']);
        $this->assertSame(' - {site_name} 관리자', $export['suffix']['ko']);
    }
}
