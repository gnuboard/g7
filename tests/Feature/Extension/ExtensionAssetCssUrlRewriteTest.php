<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Plugin;
use App\Support\AssetUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 모듈·플러그인 자산 CSS 의 **상대 참조 치환** 왕복 계약.
 *
 * 템플릿 경로는 TemplateAssetCssUrlRewriteTest 가 잠근다. 셋은 같은 트레이트를 공유하지만,
 * 실제로 그 트레이트를 **거치는지**는 경로마다 따로 배선되어 있다 — 어느 한 컨트롤러가
 * 종전 `fileResponse()` 로 되돌아가도 그 사실은 어디에도 드러나지 않는다. 배선 자체는
 * ExtensionAssetCssRewriteContractTest 가 모집단 도출로 잠그고, 여기서는 그 배선이 실제
 * 왕복까지 성립하는지(치환된 주소가 그 파일을 정말 돌려주는지)를 확인한다.
 *
 * 확장자 없는 모드가 결함이 실제로 나타나던 조합이므로 그 모드를 주 축으로 두고, 확장자
 * 모드도 같은 경로를 태워 두 모드의 결과를 함께 고정한다.
 */
class ExtensionAssetCssUrlRewriteTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> 정리 대상 디렉토리 */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        AssetUrl::forceMode(null);

        foreach ($this->createdPaths as $path) {
            $this->deleteDirectory($path);
        }

        parent::tearDown();
    }

    /**
     * 모듈 — 확장자 없는 모드에서 상대 참조가 치환되고 그 주소가 파일을 돌려준다.
     */
    public function test_module_extensionless_mode_rewrites_and_resolves(): void
    {
        $identifier = $this->makeModule();

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $css = $this->get("/api/modules/assets/{$identifier}?file=".rawurlencode('dist/css/style.css'))
            ->assertOk()
            ->getContent();

        $fontUrl = "/api/modules/assets/{$identifier}?file=".rawurlencode('dist/woff2/f.woff2');

        $this->assertStringContainsString($fontUrl, $css, '모듈 CSS 의 상대 참조가 치환되지 않았습니다.');
        $this->assertStringNotContainsString("url('../woff2/f.woff2')", $css);

        // 왕복 — 문자열만 맞고 서빙이 404 면 화면 증상은 그대로다.
        $this->assertSame('FONTBYTES', $this->get($fontUrl)->assertOk()->streamedContent());
    }

    /**
     * 모듈 — 확장자 모드도 같은 경로를 타고 경로 형태로 치환된다.
     */
    public function test_module_extension_mode_rewrites_and_resolves(): void
    {
        $identifier = $this->makeModule();

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);

        $css = $this->get("/api/modules/assets/{$identifier}/dist/css/style.css")
            ->assertOk()
            ->getContent();

        $fontUrl = "/api/modules/assets/{$identifier}/dist/woff2/f.woff2";

        $this->assertStringContainsString($fontUrl, $css);
        $this->assertSame('FONTBYTES', $this->get($fontUrl)->assertOk()->streamedContent());
    }

    /**
     * 플러그인 — 확장자 없는 모드에서 상대 참조가 치환되고 그 주소가 파일을 돌려준다.
     */
    public function test_plugin_extensionless_mode_rewrites_and_resolves(): void
    {
        $identifier = $this->makePlugin();

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $css = $this->get("/api/plugins/assets/{$identifier}?file=".rawurlencode('dist/css/style.css'))
            ->assertOk()
            ->getContent();

        $fontUrl = "/api/plugins/assets/{$identifier}?file=".rawurlencode('dist/woff2/f.woff2');

        $this->assertStringContainsString($fontUrl, $css, '플러그인 CSS 의 상대 참조가 치환되지 않았습니다.');
        $this->assertStringNotContainsString("url('../woff2/f.woff2')", $css);

        $this->assertSame('FONTBYTES', $this->get($fontUrl)->assertOk()->streamedContent());
    }

    /**
     * 플러그인 — 확장자 모드도 같은 경로를 타고 경로 형태로 치환된다.
     */
    public function test_plugin_extension_mode_rewrites_and_resolves(): void
    {
        $identifier = $this->makePlugin();

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);

        $css = $this->get("/api/plugins/assets/{$identifier}/dist/css/style.css")
            ->assertOk()
            ->getContent();

        $fontUrl = "/api/plugins/assets/{$identifier}/dist/woff2/f.woff2";

        $this->assertStringContainsString($fontUrl, $css);
        $this->assertSame('FONTBYTES', $this->get($fontUrl)->assertOk()->streamedContent());
    }

    /**
     * 절대·루트상대 참조는 두 확장 유형에서도 손대지 않는다.
     */
    public function test_absolute_references_are_never_rewritten_for_extensions(): void
    {
        $identifier = $this->makeModule();

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $css = $this->get("/api/modules/assets/{$identifier}?file=".rawurlencode('dist/css/style.css'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://cdn.example.com/x.png', $css);
        $this->assertStringContainsString('/build/ext/1/y.png', $css);
    }

    /**
     * 활성 모듈과 그 자산 파일을 만듭니다.
     *
     * @return string 모듈 식별자
     */
    private function makeModule(): string
    {
        $identifier = 'test-css-module';

        Module::factory()->create([
            'identifier' => $identifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->writeAssets(base_path("modules/{$identifier}"));

        return $identifier;
    }

    /**
     * 활성 플러그인과 그 자산 파일을 만듭니다.
     *
     * @return string 플러그인 식별자
     */
    private function makePlugin(): string
    {
        $identifier = 'test-css-plugin';

        Plugin::factory()->create([
            'identifier' => $identifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->writeAssets(base_path("plugins/{$identifier}"));

        return $identifier;
    }

    /**
     * 확장 디렉토리에 CSS·서브리소스를 만듭니다.
     *
     * 상대 해석은 경로 계층이 있어야 의미가 있으므로 실제 디렉토리 구조를 만든다.
     *
     * @param  string  $root  확장 루트 절대 경로
     */
    private function writeAssets(string $root): void
    {
        $this->createdPaths[] = $root;

        @mkdir($root.'/dist/css', 0755, true);
        @mkdir($root.'/dist/woff2', 0755, true);

        file_put_contents(
            $root.'/dist/css/style.css',
            "@font-face{src:url('../woff2/f.woff2')}\n"
            ."b{background:url('https://cdn.example.com/x.png')}\n"
            ."c{background:url('/build/ext/1/y.png')}\n"
        );

        file_put_contents($root.'/dist/woff2/f.woff2', 'FONTBYTES');
    }

    /**
     * 디렉토리를 재귀 삭제합니다.
     *
     * @param  string  $dir  대상 디렉토리
     */
    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
