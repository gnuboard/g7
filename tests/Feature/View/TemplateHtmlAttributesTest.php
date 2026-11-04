<?php

namespace Tests\Feature\View;

use App\Services\TemplateSettingsService;
use App\Support\ExtensionStoragePath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 템플릿 표준 키 `<html>` 속성 렌더링 테스트 (#434 §12)
 *
 * 켜진 템플릿의 저장값 `branding.accent`·`branding.color_scheme_default` 를 사용자 셸(app)·관리자 셸(admin)·SEO 봇 화면(seo)의
 * `<html>` 에 `data-g7-accent`·`data-g7-color-scheme-default` 로 싣는다. 선언하지 않았거나 선택지 밖 값은 싣지 않는다.
 */
class TemplateHtmlAttributesTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 테스트가 만든 픽스처를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanUpTemplateSettingsFixtures();

        parent::tearDown();
    }

    /**
     * 표준 키 두 개(포인트 색·기본 화면 모드)를 선언한 템플릿을 켜고 값을 저장합니다.
     *
     * @param  string  $identifier  식별자
     * @param  string  $type  화면 종류 (user|admin)
     * @param  string  $accent  저장할 포인트 색
     * @param  string  $scheme  저장할 기본 화면 모드
     * @return void 반환값 없음
     */
    private function activateWithStandardKeys(string $identifier, string $type, string $accent, string $scheme): void
    {
        $declaration = $this->defaultDeclaration();
        $declaration['schema']['branding']['color_scheme_default'] = ['type' => 'enum', 'options' => ['system', 'light', 'dark']];
        $declaration['defaults']['branding']['color_scheme_default'] = 'system';
        $this->makeSettingsTemplate($identifier, $type, 'active', $declaration);

        $this->assertTrue(app(TemplateSettingsService::class)->save($identifier, ['branding' => ['accent' => $accent, 'color_scheme_default' => $scheme]]));
        $this->resetFaviconResolution();
    }

    /**
     * HTML 에서 `<html …>` 여는 태그를 뽑습니다.
     *
     * @param  string  $html  문서
     * @return string 여는 태그
     */
    private function htmlTag(string $html): string
    {
        $this->assertSame(1, preg_match('/<html\b[^>]*>/i', $html, $matches), '<html> 태그가 있어야 한다');

        return $matches[0];
    }

    /**
     * 사용자 셸 `<html>` 에 켜진 사용자 템플릿의 저장값이 실린다.
     *
     * @scenario case=service
     *
     * @effects standard_attributes_rendered
     *
     * @return void 반환값 없음
     */
    public function test_app_shell_html_carries_standard_attributes(): void
    {
        $this->activateWithStandardKeys('qa434-usr', 'user', 'green', 'dark');

        $tag = $this->htmlTag($this->get('/')->getContent());

        $this->assertStringContainsString('data-g7-accent="green"', $tag);
        $this->assertStringContainsString('data-g7-color-scheme-default="dark"', $tag);
    }

    /**
     * 관리자 셸 `<html>` 에는 켜진 관리자 템플릿 값이 실린다(사용자 템플릿 값이 아니다).
     *
     * @scenario case=service
     *
     * @effects standard_attributes_rendered
     *
     * @return void 반환값 없음
     */
    public function test_admin_shell_html_carries_admin_template_attributes(): void
    {
        $this->activateWithStandardKeys('qa434-usr', 'user', 'green', 'dark');
        $this->activateWithStandardKeys('qa434-adm', 'admin', 'blue', 'light');

        $tag = $this->htmlTag($this->get('/admin')->getContent());

        $this->assertStringContainsString('data-g7-accent="blue"', $tag);
        $this->assertStringContainsString('data-g7-color-scheme-default="light"', $tag);
        $this->assertStringNotContainsString('green', $tag);
    }

    /**
     * SEO 봇 화면(`seo` 뷰) `<html>` 에도 사용자 템플릿 값이 실린다.
     *
     * @scenario case=service
     *
     * @effects standard_attributes_rendered
     *
     * @return void 반환값 없음
     */
    public function test_seo_view_html_carries_standard_attributes(): void
    {
        $this->activateWithStandardKeys('qa434-usr', 'user', 'green', 'dark');

        $tag = $this->htmlTag(view('seo', FaviconTagsRenderingTest::seoViewData())->render());

        $this->assertStringContainsString('data-g7-accent="green"', $tag);
        $this->assertStringContainsString('data-g7-color-scheme-default="dark"', $tag);
    }

    /**
     * 저장 파일의 선택지 밖 값(손으로 고친 파일)은 세 화면 어디에도 속성으로 나가지 않는다.
     *
     * @scenario case=service
     *
     * @effects standard_attributes_rendered
     *
     * @return void 반환값 없음
     */
    public function test_out_of_enum_values_are_not_rendered(): void
    {
        $this->activateWithStandardKeys('qa434-usr', 'user', 'green', 'dark');
        File::put(ExtensionStoragePath::template('qa434-usr', 'settings').'/branding.json', json_encode(['accent' => 'red', 'color_scheme_default' => 'sepia']));
        $this->resetFaviconResolution();

        foreach ([$this->get('/')->getContent(), view('seo', FaviconTagsRenderingTest::seoViewData())->render()] as $html) {
            $tag = $this->htmlTag($html);
            $this->assertStringNotContainsString('data-g7-accent', $tag);
            $this->assertStringNotContainsString('data-g7-color-scheme-default', $tag);
        }
    }

    /**
     * 표준 키를 선언하지 않은 템플릿은 저장 파일에 값이 있어도 속성을 싣지 않는다.
     *
     * @scenario case=service
     *
     * @effects standard_attributes_rendered
     *
     * @return void 반환값 없음
     */
    public function test_undeclared_standard_keys_are_not_rendered(): void
    {
        $declaration = $this->defaultDeclaration();
        unset($declaration['schema']['branding']['accent'], $declaration['defaults']['branding']['accent'], $declaration['frontend_schema']['branding']['fields']['accent']);
        $this->makeSettingsTemplate('qa434-usr', 'user', 'active', $declaration);
        File::ensureDirectoryExists(ExtensionStoragePath::template('qa434-usr', 'settings'));
        File::put(ExtensionStoragePath::template('qa434-usr', 'settings').'/branding.json', json_encode(['accent' => 'green', 'color_scheme_default' => 'dark']));
        $this->resetFaviconResolution();

        $tag = $this->htmlTag($this->get('/')->getContent());

        $this->assertStringNotContainsString('data-g7-accent', $tag);
        $this->assertStringNotContainsString('data-g7-color-scheme-default', $tag);
    }
}
