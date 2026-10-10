<?php

namespace Tests\Feature\Extension;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * 확장 아이콘 공개 서빙 라우트 테스트 [case:extension-icon-bundled-fallback]
 *
 * 관리자 화면이 `<img src>` 로 부르므로 토큰 없이 200 이어야 한다. 미설치 번들
 * 확장도 대상이라 DB 행이 없어도 서빙된다.
 *
 * 소유 시나리오: `extension-icons` (축 매핑은 각 테스트 메서드에 있다)
 *
 * @effects public_icon_route_needs_no_auth, uninstalled_bundled_icon_resolves_from_bundled_dir, invalid_icon_is_not_served_and_row_falls_back
 */
class ExtensionIconServingTest extends TestCase
{
    use ProtectsExtensionDirectories;
    use RefreshDatabase;

    /** @var string 정사각 유효 SVG */
    private const VALID_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>t</title><rect width="64" height="64" fill="#336699"/></svg>';

    /** @var list<string> 정리 대상 픽스처 디렉터리 */
    private array $fixtureDirs = [];

    /**
     * 확장 디렉터리를 보호합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExtensionProtection();
    }

    /**
     * 픽스처 디렉터리를 지우고 확장 디렉터리 보호를 해제합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }
        $this->fixtureDirs = [];

        $this->tearDownExtensionProtection();

        parent::tearDown();
    }

    /**
     * 미설치 번들 모듈의 아이콘이 토큰 없이 200 으로 서빙되고 보안 헤더가 붙습니다.
     *
     * @scenario extension_type=module, location=bundled, surface=public_route
     *
     * @effects public_icon_route_needs_no_auth
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function uninstalled_bundled_module_icon_is_served_without_authentication(): void
    {
        $this->makeExtension('modules/_bundled/test-serve-mod', 'module.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $response = $this->get('/api/extensions/module/test-serve-mod/icon');

        $response->assertOk();
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(
            "default-src 'none'; style-src 'unsafe-inline'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    /**
     * 같은 ETag 로 다시 요청하면 304 입니다.
     *
     * @scenario extension_type=module, location=bundled, surface=public_route
     *
     * @effects public_icon_route_needs_no_auth
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function matching_etag_returns_not_modified(): void
    {
        $this->makeExtension('modules/_bundled/test-serve-etag', 'module.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $first = $this->get('/api/extensions/module/test-serve-etag/icon');
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/api/extensions/module/test-serve-etag/icon')
            ->assertStatus(304);
    }

    /**
     * _pending 플러그인의 아이콘도 서빙됩니다.
     *
     * @scenario extension_type=plugin, location=pending, surface=public_route
     *
     * @effects uninstalled_bundled_icon_resolves_from_bundled_dir
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function pending_plugin_icon_is_served(): void
    {
        $this->makeExtension('plugins/_pending/test-serve-plg', 'plugin.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $this->get('/api/extensions/plugin/test-serve-plg/icon')->assertOk();
    }

    /**
     * 번들 언어팩의 매니페스트 선언 아이콘이 서빙됩니다.
     *
     * @scenario extension_type=language_pack, location=bundled, declaration=manifest_path, surface=public_route
     *
     * @effects language_pack_icon_source_derivation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function bundled_language_pack_manifest_icon_is_served(): void
    {
        $this->makeExtension(
            'lang-packs/_bundled/g7-test-serve-ja',
            'language-pack.json',
            ['icon' => 'assets/brand/mark.svg'],
            ['assets/brand/mark.svg' => self::VALID_SVG]
        );

        $this->get('/api/extensions/language-pack/g7-test-serve-ja/icon')->assertOk();
    }

    /**
     * 아이콘이 없는 확장은 404 입니다.
     *
     * @scenario extension_type=module, location=bundled, declaration=none, surface=public_route
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function extension_without_icon_returns_not_found(): void
    {
        $this->makeExtension('modules/_bundled/test-serve-noicon', 'module.json', [], []);

        $this->get('/api/extensions/module/test-serve-noicon/icon')->assertNotFound();
    }

    /**
     * 규격 위반 아이콘은 서빙되지 않습니다.
     *
     * @scenario extension_type=module, location=bundled, validity=invalid_svg, surface=public_route
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function invalid_icon_is_not_served(): void
    {
        $this->makeExtension('modules/_bundled/test-serve-bad', 'module.json', [], [
            'assets/icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><script>x</script></svg>',
        ]);

        $this->get('/api/extensions/module/test-serve-bad/icon')->assertNotFound();
    }

    /**
     * 허용 유형 밖의 값은 라우트에 매칭되지 않습니다.
     *
     * @scenario extension_type=module, surface=public_route
     *
     * @effects public_icon_route_needs_no_auth
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unknown_type_does_not_match_the_route(): void
    {
        $this->get('/api/extensions/widget/test-serve-mod/icon')->assertNotFound();
    }

    /**
     * 인코딩된 슬래시는 라우트 매칭 단계에서 걸립니다.
     *
     * @scenario extension_type=module, surface=public_route, validity=path_escape
     *
     * @effects public_icon_route_needs_no_auth
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function path_separator_in_identifier_never_reaches_the_handler(): void
    {
        // 인코딩된 슬래시는 라우터가 세그먼트로 인정하지 않아 매칭 자체가 실패한다 —
        // 검증기(422)에 닿기 전에 404 다. 경로 탈출이 막히는 첫 관문.
        $this->getJson('/api/extensions/module/..%2F..%2Fetc/icon')->assertNotFound();
    }

    /**
     * 점만 든 식별자는 FormRequest 가 422 로 막습니다.
     *
     * @scenario extension_type=module, surface=public_route, validity=path_escape
     *
     * @effects public_icon_route_needs_no_auth
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function traversal_shaped_identifier_is_rejected_by_validation(): void
    {
        // 슬래시 없이 점만 든 식별자는 라우트에 매칭되므로 FormRequest 가 막는다.
        $this->getJson('/api/extensions/module/..../icon')->assertStatus(422);
    }

    /**
     * 대문자가 든 언어팩 식별자는 422 입니다.
     *
     * @scenario extension_type=language_pack, surface=public_route, validity=path_escape
     *
     * @effects public_icon_route_needs_no_auth
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function uppercase_language_pack_identifier_is_rejected(): void
    {
        $this->getJson('/api/extensions/language-pack/G7-Core-JA/icon')->assertStatus(422);
    }

    /**
     * 픽스처 확장을 만듭니다.
     *
     * @param  string  $relativeRoot  base_path 기준 확장 루트
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  array<string, mixed>  $manifestExtra  매니페스트에 덧붙일 키
     * @param  array<string, string>  $files  루트 기준 상대경로 → 내용
     * @return string 생성된 루트 절대 경로
     */
    private function makeExtension(string $relativeRoot, string $manifestFile, array $manifestExtra, array $files): string
    {
        $root = base_path($relativeRoot);
        File::ensureDirectoryExists($root);
        $this->fixtureDirs[] = $root;

        File::put($root.'/'.$manifestFile, json_encode(array_merge([
            'identifier' => basename($relativeRoot),
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '아이콘 픽스처', 'en' => 'Icon fixture'],
        ], $manifestExtra), JSON_UNESCAPED_UNICODE));

        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }

        return $root;
    }
}
