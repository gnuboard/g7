<?php

namespace Tests\Feature\Template;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Support\AssetUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 확장 자산 CSS 서빙의 **상대 참조 치환** 계약.
 *
 * 배경:
 *   `general.asset_url_mode` 가 `extensionless` 면 CSS 주소가
 *   `/api/templates/assets/{id}?file=vendor%2F…%2Fa.css` 형태다. 브라우저는 CSS 안의 상대
 *   `url()` 을 스타일시트 URL 의 **디렉토리** 기준으로 푸는데, 이 형태에서는 디렉토리가
 *   `/api/templates/assets/` 라서 `./woff2/f.woff2` 가 존재하지 않는 주소가 된다.
 *
 *   증상은 404 하나뿐이라 서버 로그에 흔적이 없다 — 글꼴은 기본 서체로 대체되고 아이콘은
 *   빈칸이 되며, 운영자에게는 원인을 특정할 단서가 없다. 그래서 행위(치환된 주소가 실제로
 *   그 파일을 돌려주는가)까지 왕복으로 단언한다.
 */
class TemplateAssetCssUrlRewriteTest extends TestCase
{
    use RefreshDatabase;

    private string $identifier;

    private string $distPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identifier = 'test-css-rewrite-'.uniqid();

        Template::factory()->create([
            'identifier' => $this->identifier,
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->distPath = base_path("templates/{$this->identifier}/dist");

        // 실제 디렉토리 구조를 만든다 — 상대 해석은 경로 계층이 있어야 의미가 있다.
        mkdir($this->distPath.'/vendor/pkg/1.0/css', 0755, true);
        mkdir($this->distPath.'/vendor/pkg/1.0/woff2', 0755, true);
        mkdir($this->distPath.'/vendor/pkg/flags', 0755, true);

        file_put_contents(
            $this->distPath.'/vendor/pkg/1.0/css/style.css',
            "@font-face{src:url('../woff2/f.woff2')}\n"
            ."a{background:url(../../flags/kr.svg)}\n"
            ."b{background:url('https://cdn.example.com/x.png')}\n"
            ."c{background:url('/build/ext/1/y.png')}\n"
        );

        file_put_contents($this->distPath.'/vendor/pkg/1.0/woff2/f.woff2', 'FONTBYTES');
        file_put_contents($this->distPath.'/vendor/pkg/flags/kr.svg', '<svg/>');
        file_put_contents($this->distPath.'/vendor/pkg/1.0/css/app.js', "var a='./not-a-css-ref';");
    }

    protected function tearDown(): void
    {
        AssetUrl::forceMode(null);

        if (isset($this->identifier) && is_dir(base_path("templates/{$this->identifier}"))) {
            $this->deleteDirectory(base_path("templates/{$this->identifier}"));
        }

        parent::tearDown();
    }

    /**
     * 확장자 없는 모드 — 상대 참조가 쿼리 형태 절대 URL 로 치환되고, 그 URL 이 실제로 파일을 준다.
     *
     * 이 모드가 결함이 실제로 나타나던 조합이다.
     */
    public function test_extensionless_mode_rewrites_relative_urls_and_they_resolve(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $css = $this->get('/api/templates/assets/'.$this->identifier.'?file='.rawurlencode('vendor/pkg/1.0/css/style.css'))
            ->assertOk()
            ->getContent();

        $fontUrl = '/api/templates/assets/'.$this->identifier.'?file='.rawurlencode('vendor/pkg/1.0/woff2/f.woff2');
        $flagUrl = '/api/templates/assets/'.$this->identifier.'?file='.rawurlencode('vendor/pkg/flags/kr.svg');

        $this->assertStringContainsString($fontUrl, $css, '하위 디렉토리 참조가 치환되지 않았습니다.');
        $this->assertStringContainsString($flagUrl, $css, '상위 디렉토리 참조가 치환되지 않았습니다.');

        // 결함의 지문 — 치환 전에는 이 주소가 나갔고 404 였다.
        $this->assertStringNotContainsString("url('../woff2/f.woff2')", $css);

        // 왕복 — 치환된 주소가 실제로 그 파일을 돌려줘야 한다. 문자열만 맞고 서빙이 404 면
        // 화면 증상은 고쳐지지 않는다.
        $this->assertSame('FONTBYTES', $this->get($fontUrl)->assertOk()->streamedContent());
    }

    /**
     * 확장자 모드 — 같은 참조가 경로 형태 절대 URL 로 치환되고, 그 URL 도 파일을 준다.
     *
     * 이 모드는 상대 해석만으로도 정상이었지만, 두 모드가 서로 다른 코드로 갈라지지 않도록
     * 같은 경로를 태우고 결과를 함께 고정한다.
     */
    public function test_extension_mode_rewrites_to_path_form_and_they_resolve(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);

        $css = $this->get('/api/templates/assets/'.$this->identifier.'/vendor/pkg/1.0/css/style.css')
            ->assertOk()
            ->getContent();

        $fontUrl = '/api/templates/assets/'.$this->identifier.'/vendor/pkg/1.0/woff2/f.woff2';

        $this->assertStringContainsString($fontUrl, $css);
        $this->assertSame('FONTBYTES', $this->get($fontUrl)->assertOk()->streamedContent());
    }

    /**
     * 절대·스킴 참조는 두 모드 모두에서 원문 그대로 남는다.
     */
    public function test_absolute_references_are_never_rewritten(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $css = $this->get('/api/templates/assets/'.$this->identifier.'?file='.rawurlencode('vendor/pkg/1.0/css/style.css'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://cdn.example.com/x.png', $css);
        $this->assertStringContainsString('/build/ext/1/y.png', $css);
    }

    /**
     * CSS 가 아닌 자산은 손대지 않는다.
     *
     * 치환은 CSS 문법 안에서만 의미가 있다 — JS·이미지 본문에 같은 규칙을 적용하면 내용이
     * 훼손된다.
     */
    public function test_non_css_assets_are_served_byte_identical(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);

        $body = $this->get('/api/templates/assets/'.$this->identifier.'?file='.rawurlencode('vendor/pkg/1.0/css/app.js'))
            ->assertOk()
            ->streamedContent();

        $this->assertSame("var a='./not-a-css-ref';", $body);
    }

    /**
     * ETag 는 내보내는 본문 기준이라 모드가 바뀌면 함께 바뀐다.
     *
     * 파일 stat 기준으로 잡으면 모드가 바뀌어 본문이 달라져도 같은 ETag 가 나와, 브라우저가
     * 옛 본문(어긋난 주소)을 계속 쓴다. 그 회귀는 캐시가 비워지기 전까지 드러나지 않는다.
     */
    public function test_etag_tracks_the_rewritten_body_not_the_file_stat(): void
    {
        AssetUrl::forceMode(AssetUrl::MODE_EXTENSIONLESS);
        $extensionless = $this->get('/api/templates/assets/'.$this->identifier.'?file='.rawurlencode('vendor/pkg/1.0/css/style.css'))
            ->assertOk()
            ->headers->get('ETag');

        AssetUrl::forceMode(AssetUrl::MODE_EXTENSION);
        $extension = $this->get('/api/templates/assets/'.$this->identifier.'/vendor/pkg/1.0/css/style.css')
            ->assertOk()
            ->headers->get('ETag');

        $this->assertNotNull($extensionless);
        $this->assertNotNull($extension);
        $this->assertNotSame($extension, $extensionless, '모드가 달라 본문이 다른데 ETag 가 같습니다.');

        // 같은 모드의 재요청은 304 로 응답해야 한다 (캐싱 계약 유지).
        $this->get(
            '/api/templates/assets/'.$this->identifier.'/vendor/pkg/1.0/css/style.css',
            ['If-None-Match' => $extension]
        )->assertStatus(304);
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
