<?php

namespace Tests\Feature\View;

use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [case:network-strict-csp-inline-shell-blank] 관리자·사용자 셸 HTML 에 실행형 인라인 코드가 없다 (#158).
 *
 * 운영자가 웹서버에 `Content-Security-Policy: script-src 'self'`(unsafe-inline 없음)를 걸면
 * 인라인 `<script>` 와 on* 속성은 실행되지 않는다. 종전에는 G7Config 대입·자산 복구·부트스트랩이
 * 인라인이었고 엔진 초기화 신호가 `<script onload>` 에 묶여 있어 전 화면이 백지가 됐다.
 *
 * 서버 값은 비실행 JSON 데이터 블록으로만 넘기고 실행 코드는 정적 셸 JS 두 개가 맡는다.
 * `g7-config` 블록의 키 집합은 종전 `window.G7Config` 와 같아야 한다 — 엔진·확장이 그 키를
 * 직접 읽는 계약이다.
 *
 * @scenario surface=admin_shell, csp=webserver_strict, failure=none
 *
 * @effects shell_html_has_no_inline_executable, shell_config_keys_unchanged
 */
class ShellNoInlineScriptTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 종전 인라인 `window.G7Config = {...}` 의 키 집합 (사용자 셸 기준, staticBase 는 게시 게이트 통과 시에만).
     *
     * 뒤에 더해진 키: `seoTitle`(방문자 탭 제목을 검색엔진 제목과 맞춤), `searchScopes`(검색창 최근 검색어 범위).
     */
    private const USER_CONFIG_KEYS = [
        'settings', 'plugins', 'modules', 'moduleAssets', 'pluginAssets', 'bundleUrls', 'customAssets',
        'customAssetsDisabled', 'activeModules', 'activePlugins', 'trustedScriptHosts', 'stateContracts',
        'appConfig', 'consentInitial', 'coreEditorAsset', 'coreDevToolsAsset', 'cache_version', 'assetUrlMode',
        'searchScopes', 'seoTitle',
    ];

    /**
     * 관리자 셸은 consentInitial·searchScopes 를 싣지 않는다 (종전과 동일). `seoTitle` 은 뒤에 더해졌다.
     */
    private const ADMIN_CONFIG_KEYS = [
        'settings', 'plugins', 'modules', 'moduleAssets', 'pluginAssets', 'bundleUrls', 'customAssets',
        'customAssetsDisabled', 'activeModules', 'activePlugins', 'trustedScriptHosts', 'stateContracts',
        'appConfig', 'coreEditorAsset', 'coreDevToolsAsset', 'cache_version', 'assetUrlMode',
        'seoTitle',
    ];

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
     * 셸 HTML 에 실행형 인라인 `<script>`·on* 속성·`javascript:` URL 이 없는지 검사합니다.
     *
     * @param  string  $html  응답 HTML
     * @param  string  $label  실패 메시지 접두사
     * @return void 반환값 없음
     */
    private function assertNoInlineExecutable(string $html, string $label): void
    {
        $this->assertSame(0, preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html), "[{$label}] on* 인라인 핸들러가 남아 있다");
        $this->assertSame(0, preg_match('/href\s*=\s*["\']\s*javascript:/i', $html), "[{$label}] javascript: URL 이 남아 있다");

        preg_match_all('#<script\b([^>]*)>(.*?)</script>#s', $html, $scripts, PREG_SET_ORDER);
        $this->assertNotEmpty($scripts, "[{$label}] script 태그가 하나도 없다");

        foreach ($scripts as $script) {
            if (preg_match('/type="application\/(json|ld\+json)"/', $script[1])) {
                continue;
            }
            $this->assertSame('', trim($script[2]), "[{$label}] 실행형 인라인 <script> 본문: ".substr(trim($script[2]), 0, 80));
        }
    }

    /**
     * JSON 데이터 블록을 파싱합니다.
     *
     * @param  string  $html  응답 HTML
     * @param  string  $id  블록 id
     * @return array<string, mixed>|null 파싱 결과 (블록이 없으면 null)
     */
    private function dataBlock(string $html, string $id): ?array
    {
        if (! preg_match('#<script type="application/json" id="'.preg_quote($id, '#').'">(.*?)</script>#s', $html, $m)) {
            return null;
        }

        $data = json_decode($m[1], true);
        $this->assertIsArray($data, "#{$id} 블록이 JSON 으로 파싱되지 않는다");

        return $data;
    }

    /**
     * 관리자 셸 HTML 의 실행형 인라인 0 · JSON 블록 파싱 · G7Config 키 집합 유지.
     *
     * @scenario surface=admin_shell, csp=webserver_strict, failure=none
     *
     * @effects shell_html_has_no_inline_executable, shell_config_keys_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_관리자_셸에_실행형_인라인_코드가_없다(): void
    {
        $this->activate('sirsoft-admin_basic');

        $html = $this->get('/admin/login')->assertStatus(200)->getContent();

        $this->assertNoInlineExecutable($html, 'admin');

        $config = $this->dataBlock($html, 'g7-config');
        $this->assertNotNull($config, '#g7-config 블록이 없다');
        $keys = array_values(array_diff(array_keys($config), ['staticBase']));
        $this->assertEqualsCanonicalizing(self::ADMIN_CONFIG_KEYS, $keys, 'G7Config 키 집합이 종전과 다르다');

        $this->assertIsArray($this->dataBlock($html, 'g7-shell-head-data'));
        $boot = $this->dataBlock($html, 'g7-boot-data');
        $this->assertSame('[Admin]', $boot['label']);
        $this->assertSame('admin', $boot['initConfig']['templateType']);
        $this->assertStringContainsString('build/core/template-engine.min.js', $boot['coreEngineSrc']);
    }

    /**
     * 사용자 셸 HTML 의 실행형 인라인 0 · G7Config 키 집합 유지(consentInitial 포함).
     *
     * @scenario surface=user_shell, csp=webserver_strict, failure=none
     *
     * @effects shell_html_has_no_inline_executable, shell_config_keys_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_사용자_셸에_실행형_인라인_코드가_없다(): void
    {
        $this->activate('sirsoft-basic');

        $html = $this->get('/')->assertStatus(200)->getContent();

        $this->assertNoInlineExecutable($html, 'user');

        $config = $this->dataBlock($html, 'g7-config');
        $this->assertNotNull($config, '#g7-config 블록이 없다');
        $keys = array_values(array_diff(array_keys($config), ['staticBase']));
        $this->assertEqualsCanonicalizing(self::USER_CONFIG_KEYS, $keys, 'G7Config 키 집합이 종전과 다르다');

        $boot = $this->dataBlock($html, 'g7-boot-data');
        $this->assertSame('[User]', $boot['label']);
        $this->assertSame('user', $boot['initConfig']['templateType']);
    }

    /**
     * 실행 순서 계약: head 셸 → externals/템플릿 CSS, 데이터 블록 → boot 셸 → 코어 → 컴포넌트.
     *
     * @scenario surface=admin_shell, csp=webserver_strict, failure=none
     *
     * @effects shell_script_order_preserved
     *
     * @return void 반환값 없음
     */
    public function test_셸_스크립트는_기존_인라인_위치를_지킨다(): void
    {
        $this->activate('sirsoft-admin_basic');

        $html = $this->get('/admin/login')->assertStatus(200)->getContent();

        $order = [
            'id="g7-shell-head-data"',
            'g7-shell-head.js',
            'data-g7-external="1"',
            'data-g7-template-css',
            'id="g7-config"',
            'id="g7-boot-data"',
            'g7-shell-boot.js',
            'data-g7-boot-bundle="core"',
            'data-g7-boot-bundle="components"',
        ];

        $previous = -1;
        foreach ($order as $needle) {
            $position = strpos($html, $needle);
            $this->assertNotFalse($position, "[{$needle}] 가 없다");
            $this->assertGreaterThan($previous, $position, "[{$needle}] 순서가 어긋났다");
            $previous = $position;
        }
    }

    /**
     * 셸 JS 를 못 받은 경우의 지연 안내문(JS 없음)이 실린다.
     *
     * @scenario surface=admin_shell, csp=webserver_strict, failure=none
     *
     * @effects shell_stall_notice_rendered
     *
     * @return void 반환값 없음
     */
    public function test_셸_js_미도착_지연_안내문이_실린다(): void
    {
        $this->activate('sirsoft-admin_basic');

        $html = $this->get('/admin/login')->assertStatus(200)->getContent();

        $this->assertStringContainsString('id="g7-shell-stall"', $html);
        $this->assertStringContainsString('html:not(.g7-shell-ok) #g7-shell-stall', $html);
        $this->assertStringContainsString('@keyframes g7ShellStall', $html);
        $this->assertStringContainsString(e(__('errors.bootstrap.reload')), $html);
    }
}
