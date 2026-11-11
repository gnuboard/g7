<?php

namespace Tests\Feature\View;

use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * [case:network-strict-style-csp-inline-style-blocked] 코어가 그리는 HTML 에 인라인 스타일이 없다 (#163).
 *
 * 운영자가 웹서버나 환경설정에서 `style-src 'self'; style-src-attr 'none'` 을 강제하면 `<style>` 요소와
 * 태그의 `style="…"` 속성이 적용되지 않는다. 종전에는 셸의 지연 안내 숨김 규칙이 `<style>` 블록에 있어
 * 정상 화면 아래에 「화면을 불러오지 못했습니다」 안내가 늘 보였고, 오류·점검 화면은 무스타일로 그려졌다.
 *
 * 스타일은 코어 정적 파일 두 개(`g7-core.css` — 셸, `g7-standalone.css` — 독립 화면)로만 싣는다.
 *
 * @scenario case=admin_shell_no_inline_style
 *
 * @effects shell_html_has_no_inline_style, core_css_files_served
 */
class ShellNoInlineStyleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 인라인 스타일이 정당한 뷰 — 메일(클라이언트가 외부 CSS 를 버린다), 개발 대시보드(CSP 미들웨어 제외),
     * 검색봇용 SEO 화면(범위 밖으로 확정).
     */
    private const EXCLUDED = ['emails/', 'dev-dashboard.blade.php', 'seo.blade.php', 'seo-preview.blade.php'];

    /**
     * 코어 엔진 번들이 빌드되어 있어야 셸이 렌더된다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(public_path('build/core/template-engine.min.js'))) {
            $this->markTestSkipped('Core template engine not built. Run npm run build to generate build files.');
        }
    }

    /**
     * 번들 템플릿을 설치·활성화합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return void 반환값 없음
     */
    private function activate(string $identifier): void
    {
        $templateService = app(TemplateService::class);
        $templateService->installTemplate($identifier);
        $template = Template::where('identifier', $identifier)->firstOrFail();
        $templateService->activateTemplate($template->id);
    }

    /**
     * HTML 에 `<style>` 요소와 style 속성이 없는지 검사합니다.
     *
     * @param  string  $html  검사할 HTML
     * @param  string  $label  실패 메시지 접두사
     * @return void 반환값 없음
     */
    private function assertNoInlineStyle(string $html, string $label): void
    {
        // JSON 데이터 블록 안의 문자열(설정 값 등)은 마크업이 아니므로 걷어낸다
        $markup = preg_replace('#<script\b[^>]*>.*?</script>#s', '', $html);

        $this->assertSame(0, preg_match('/<style\b/i', $markup), "[{$label}] <style> 요소가 남아 있다");
        $this->assertSame(0, preg_match('/<[a-z][^>]*\sstyle\s*=/i', $markup), "[{$label}] style 속성이 남아 있다");
    }

    /**
     * 검사 대상 Blade 뷰 — `resources/views` 전체에서 제외 목록만 뺀다(새 뷰가 생기면 자동으로 들어온다).
     *
     * @return array<string, array{0: string}> 뷰 상대 경로
     */
    public static function coreViewProvider(): array
    {
        $base = dirname(__DIR__, 3).'/resources/views';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            if (! str_ends_with($relative, '.blade.php')) {
                continue;
            }
            foreach (self::EXCLUDED as $excluded) {
                if (str_starts_with($relative, $excluded) || $relative === $excluded) {
                    continue 2;
                }
            }
            $files[$relative] = [$relative];
        }
        ksort($files);

        return $files;
    }

    /**
     * 뷰 원본에 `<style>` 블록·style 속성이 없다(Blade 주석은 제외).
     *
     * @scenario case=core_views_no_inline_style
     *
     * @effects shell_html_has_no_inline_style
     *
     * @param  string  $relative  뷰 상대 경로
     * @return void 반환값 없음
     */
    #[DataProvider('coreViewProvider')]
    public function test_코어_뷰_원본에_인라인_스타일이_없다(string $relative): void
    {
        $source = file_get_contents(resource_path('views/'.$relative));
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertNoInlineStyle($source, $relative);
    }

    /**
     * 관리자 셸 HTML — 인라인 스타일 0, 코어 CSS 링크는 템플릿 CSS 앞.
     *
     * @scenario case=admin_shell_no_inline_style
     *
     * @effects shell_html_has_no_inline_style, core_css_files_served
     *
     * @return void 반환값 없음
     */
    public function test_관리자_셸에_인라인_스타일이_없다(): void
    {
        $this->activate('sirsoft-admin_basic');

        $html = $this->get('/admin/login')->assertStatus(200)->getContent();

        $this->assertNoInlineStyle($html, 'admin');
        $core = strpos($html, 'data-g7-core-css="core"');
        $template = strpos($html, 'data-g7-template-css');
        $this->assertNotFalse($core, '코어 CSS 링크가 없다');
        $this->assertNotFalse($template, '템플릿 CSS 링크가 없다');
        $this->assertLessThan($template, $core, '코어 CSS 는 템플릿 CSS 앞에 실려야 한다');
        $this->assertStringNotContainsString('data-g7-core-css="standalone"', $html, '셸에 독립 화면용 전역 리셋이 실리면 템플릿 여백이 무너진다');
    }

    /**
     * 사용자 셸 HTML — 인라인 스타일 0.
     *
     * @scenario case=user_shell_no_inline_style
     *
     * @effects shell_html_has_no_inline_style, core_css_files_served
     *
     * @return void 반환값 없음
     */
    public function test_사용자_셸에_인라인_스타일이_없다(): void
    {
        $this->activate('sirsoft-basic');

        $html = $this->get('/')->assertStatus(200)->getContent();

        $this->assertNoInlineStyle($html, 'user');
        $this->assertStringContainsString('data-g7-core-css="core"', $html);
        $this->assertStringContainsString('id="g7-shell-stall"', $html);
    }

    /**
     * 독립 화면 뷰 목록 — 오류 화면 5종, 묶음·대체 오류 화면 5종, IP 제한 403, 점검, DB 계정 오류.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}> 뷰 이름과 데이터
     */
    public static function standaloneViewProvider(): array
    {
        return [
            'errors.401' => ['errors.401', []],
            'errors.403' => ['errors.403', []],
            'errors.403 (IP 제한)' => ['errors.403', ['ipRestricted' => true, 'clientIp' => '203.0.113.7']],
            'errors.404' => ['errors.404', []],
            'errors.500' => ['errors.500', []],
            'errors.503' => ['errors.503', []],
            // 전용 화면이 없던 응답 — 묶음 뷰(4xx·5xx)와, 프레임워크 자체 뷰를 가리는 402·419·429
            'errors.4xx' => ['errors.4xx', ['exception' => new HttpException(405)]],
            'errors.5xx' => ['errors.5xx', ['exception' => new HttpException(502)]],
            'errors.402' => ['errors.402', ['exception' => new HttpException(402)]],
            'errors.419' => ['errors.419', ['exception' => new HttpException(419)]],
            'errors.429' => ['errors.429', ['exception' => new HttpException(429)]],
            'maintenance' => ['maintenance', []],
            'database-credential-error' => ['database-credential-error', ['reason' => 'blocked']],
        ];
    }

    /**
     * 독립 화면 HTML — 인라인 스타일 0, 독립 화면 CSS 링크 1개.
     *
     * @scenario case=standalone_pages_no_inline_style
     *
     * @effects shell_html_has_no_inline_style, core_css_files_served
     *
     * @param  string  $view  뷰 이름
     * @param  array<string, mixed>  $data  뷰 데이터
     * @return void 반환값 없음
     */
    #[DataProvider('standaloneViewProvider')]
    public function test_독립_화면에_인라인_스타일이_없다(string $view, array $data): void
    {
        $html = view($view, $data)->render();

        $this->assertNoInlineStyle($html, $view);
        $this->assertSame(1, substr_count($html, 'data-g7-core-css="standalone"'), "[{$view}] 독립 화면 CSS 링크가 정확히 1개여야 한다");
        $this->assertMatchesRegularExpression('#assets/core/css/g7-standalone\.css\?v=\d+#', $html);
    }

    /**
     * 코어 정적 CSS 두 개가 있고, 뷰가 쓰는 클래스의 규칙을 담고 있다.
     *
     * @scenario case=core_css_contract
     *
     * @effects core_css_files_served
     *
     * @return void 반환값 없음
     */
    public function test_코어_정적_css_두_개가_규칙을_담는다(): void
    {
        $core = public_path('assets/core/css/g7-core.css');
        $standalone = public_path('assets/core/css/g7-standalone.css');
        $this->assertFileExists($core);
        $this->assertFileExists($standalone);

        $standaloneCss = file_get_contents($standalone);
        foreach (['.error-container', '.error-code', '.error-btn', '.error-message--mono', '.maintenance-card', '.credential-reason', '@keyframes spin'] as $needle) {
            $this->assertStringContainsString($needle, $standaloneCss, "g7-standalone.css 에 {$needle} 규칙이 없다");
        }

        // 셸에 실리는 파일에는 전역 선택자가 없어야 한다(템플릿 여백 붕괴 방지)
        $coreCss = preg_replace('#/\*.*?\*/#s', '', file_get_contents($core));
        $this->assertSame(0, preg_match('/(^|[},])\s*(\*|html|body)\s*[{,]/m', $coreCss), 'g7-core.css 에 전역 선택자가 있다');
        $this->assertStringNotContainsString('@layer', $coreCss);
    }
}
