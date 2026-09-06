<?php

namespace Tests\Unit\Support;

use App\Services\ExtensionStaticCacheService;
use App\Support\CustomAssets;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 사용자 추가 에셋(`custom/`) 해석기 테스트
 *
 * 운영자가 자기 CSS·JS 를 덧붙일 자리를 각 확장이 제공한다. 이 해석기는 여러 출처를
 * 합쳐 **출처에 의존하지 않는 서술자** 목록을 만든다 — 소비자(뷰 컴포저·프론트 로더)는
 * 파일에서 왔는지 선언에서 왔는지 보지 않는다.
 *
 * DB 를 쓰지 않는다 — 파일 시스템 해석만 검증한다.
 */
class CustomAssetsTest extends TestCase
{
    /** 테스트용 가짜 플러그인 식별자 */
    private const FAKE_PLUGIN = 'g7test-customassets';

    /**
     * 테스트용 확장 디렉토리 경로를 돌려줍니다.
     *
     * @return string 절대 경로
     */
    private function pluginDir(): string
    {
        return base_path('plugins/'.self::FAKE_PLUGIN);
    }

    protected function setUp(): void
    {
        parent::setUp();

        CustomAssets::flushCache();
        File::ensureDirectoryExists($this->pluginDir().'/custom');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pluginDir());
        CustomAssets::flushCache();

        parent::tearDown();
    }

    /**
     * `custom/` 에 파일을 씁니다.
     *
     * @param  string  $relative  상대 경로
     * @param  string  $contents  내용
     * @return void
     */
    private function writeCustom(string $relative, string $contents = '/* x */'): void
    {
        $path = $this->pluginDir().'/custom/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
        CustomAssets::flushCache();
    }

    #[Test]
    public function custom_디렉토리가_없으면_빈_목록이다(): void
    {
        File::deleteDirectory($this->pluginDir());
        CustomAssets::flushCache();

        $this->assertSame([], CustomAssets::forExtension('plugins', self::FAKE_PLUGIN));
    }

    /**
     * @scenario custom_source=convention_scan, custom_asset=js
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_asset_loaded_after_extension_bundles
     */
    #[Test]
    public function 규약_스캔은_파일명_오름차순으로_싣고_css_를_js_보다_먼저_둔다(): void
    {
        $this->writeCustom('20-second.css');
        $this->writeCustom('10-first.css');
        $this->writeCustom('script.js', '// x');

        $assets = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN);

        $this->assertCount(3, $assets);
        $this->assertStringContainsString('10-first.css', $assets[0]['url']);
        $this->assertStringContainsString('20-second.css', $assets[1]['url']);
        $this->assertSame('script', $assets[2]['type']);
    }

    #[Test]
    public function 서술자는_출처에_의존하지_않는_형태다(): void
    {
        $this->writeCustom('custom.css');

        $asset = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN)[0];

        $this->assertArrayHasKey('id', $asset);
        $this->assertArrayHasKey('type', $asset);
        $this->assertArrayHasKey('url', $asset);
        $this->assertArrayHasKey('version', $asset);
        $this->assertSame('file', $asset['source']);
        $this->assertSame('style', $asset['type']);
    }

    /**
     * URL 은 same-origin 이고, 서술자는 파일 서명을 들고 다닌다.
     *
     * URL 의 캐시 무효화 축은 **확장 캐시 버전**이다(세 타입 공통). 파일 서명(mtime)은 URL 에
     * 실리지 않고 서술자 `version` 으로만 남아 **변경 감지 서명**의 재료가 된다 — 뷰 컴포저가
     * 그 서명 변화를 보고 캐시 버전을 올리므로, 결과적으로 파일을 고치면 URL 도 바뀐다.
     *
     * 서명을 URL 에 실으면 정적 게시 경로가 영영 선택되지 않는다: 정적 경로는 언제나 현재
     * 게시 버전이라 `AssetUrl` 의 버전 일치 게이트에 걸린다.
     *
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_asset_url_busts_on_file_change, runtime_asset_served_same_origin
     */
    #[Test]
    public function url_은_same_origin_이고_서술자가_파일_서명을_들고_다닌다(): void
    {
        $this->writeCustom('custom.css');

        $first = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN)[0];

        $this->assertStringStartsWith('/api/plugins/assets/', $first['url']);
        $this->assertNotNull($first['version']);

        // 파일을 고치면 서술자 서명이 달라진다 (감지 축)
        touch($this->pluginDir().'/custom/custom.css', time() + 60);
        CustomAssets::flushCache();

        $second = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN)[0];

        $this->assertNotSame($first['version'], $second['version']);

        // URL 축은 확장 캐시 버전이다 — 버전이 오르면 URL 도 바뀐다
        $this->assertStringContainsString(
            'v='.ExtensionStaticCacheService::getExtensionCacheVersion(),
            $second['url'],
            'custom URL 이 확장 캐시 버전 축을 쓰지 않습니다 — 정적 게시 경로가 선택되지 않습니다.'
        );
    }

    /**
     * @scenario custom_source=assets_json, custom_asset=js
     * @scenario custom_source=assets_json, custom_asset=css
     *
     * @effects custom_assets_json_declaration_overrides_convention_scan
     */
    #[Test]
    public function 선언_파일이_있으면_그것이_규약_스캔보다_우선한다(): void
    {
        $this->writeCustom('ignored.css');
        $this->writeCustom('declared.css');
        $this->writeCustom(CustomAssets::DECLARATION_FILE, json_encode([
            'assets' => [
                ['type' => 'style', 'file' => 'declared.css'],
            ],
        ]));

        $assets = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN);

        $this->assertCount(1, $assets);
        $this->assertStringContainsString('declared.css', $assets[0]['url']);
    }

    /**
     * @scenario custom_source=assets_json_broken, custom_asset=css
     * @scenario custom_source=assets_json_broken
     *
     * @effects custom_broken_declaration_yields_empty_list_without_scan_fallback
     */
    #[Test]
    public function 선언이_깨졌으면_규약_스캔으로_되돌아가지_않는다(): void
    {
        // 되돌아가면 운영자가 의도적으로 뺀 파일이 되살아난다
        $this->writeCustom('should-not-load.css');
        $this->writeCustom(CustomAssets::DECLARATION_FILE, '{ 깨진 JSON');

        $this->assertSame([], CustomAssets::forExtension('plugins', self::FAKE_PLUGIN));
    }

    /**
     * @scenario custom_source=assets_json, custom_asset=external_url
     *
     * @effects custom_external_url_allowed_only_from_declaration
     */
    #[Test]
    public function 외부_url_은_선언에서만_그리고_사유가_있을_때만_허용된다(): void
    {
        $this->writeCustom(CustomAssets::DECLARATION_FILE, json_encode([
            'assets' => [
                ['type' => 'style', 'url' => 'https://fonts.example.com/a.css', 'reason' => '본문 웹폰트'],
                ['type' => 'style', 'url' => 'https://fonts.example.com/b.css'],
                ['type' => 'style', 'url' => 'http://insecure.example.com/c.css', 'reason' => '평문'],
            ],
        ]));

        $assets = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN);

        $this->assertCount(1, $assets);
        $this->assertSame('https://fonts.example.com/a.css', $assets[0]['url']);
        $this->assertSame('url', $assets[0]['source']);
    }

    /**
     * @scenario custom_source=assets_json, custom_asset=static_file
     *
     * @effects custom_path_traversal_blocked, custom_disallowed_extension_blocked
     */
    #[Test]
    public function 경로_이탈과_미허용_확장자는_차단된다(): void
    {
        $this->writeCustom('safe.css');
        $this->writeCustom(CustomAssets::DECLARATION_FILE, json_encode([
            'assets' => [
                ['type' => 'style', 'file' => '../../../.env'],
                ['type' => 'style', 'file' => 'safe.css'],
                ['type' => 'script', 'file' => 'evil.php'],
                ['type' => 'style', 'file' => 'missing.css'],
            ],
        ]));

        $assets = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN);

        $this->assertCount(1, $assets);
        $this->assertStringContainsString('safe.css', $assets[0]['url']);
    }

    #[Test]
    public function 하위_디렉토리_파일은_규약_스캔이_싣지_않는다(): void
    {
        // 폰트·이미지는 CSS 가 상대 경로로 참조하는 대상이지 그 자체로 로드할 것이 아니다
        $this->writeCustom('fonts/inner.css');

        $this->assertSame([], CustomAssets::forExtension('plugins', self::FAKE_PLUGIN));
    }

    #[Test]
    public function 확장_타입이_유효하지_않으면_빈_목록이다(): void
    {
        $this->assertSame([], CustomAssets::forExtension('unknown', self::FAKE_PLUGIN));
        $this->assertNull(CustomAssets::directory('unknown', self::FAKE_PLUGIN));
    }

    #[Test]
    public function 식별자에_경로_이탈이_섞이면_거부한다(): void
    {
        $this->assertNull(CustomAssets::directory('plugins', '../../etc'));
    }
}
