<?php

namespace Tests\Unit\Support;

use App\Support\CustomAssets;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `CustomAssets::publishableFiles()` — 게시·변경 감지가 공유하는 열거자 테스트 (#651 F7).
 *
 * 정적 게시는 `custom/**` 를 재귀로 복사하고, 변경 감지는 종전에 최상위 css/js 의 mtime 만
 * 서명했다. 두 범위를 서로 다른 코드가 정의하면 어긋나고, 그 어긋남은 "글꼴을 바꿨는데
 * 반영되지 않는다" 로만 나타난다. 이 열거자 하나가 두 소비자의 집합을 정한다.
 *
 * DB 를 쓰지 않는다 — 파일 시스템 해석만 검증한다.
 */
class CustomAssetsPublishableFilesTest extends TestCase
{
    /** 테스트용 가짜 플러그인 식별자 */
    private const FAKE_PLUGIN = 'g7test-publishable';

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

    private function writeCustom(string $relative, string $contents = '/* x */'): void
    {
        $path = $this->pluginDir().'/custom/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    /**
     * 하위 디렉토리의 글꼴·이미지까지 열거한다 — 게시와 같은 범위.
     *
     * @scenario custom_source=convention_scan, custom_asset=static_file, custom_change_detection=nested_static_file
     *
     * @effects custom_signature_covers_publishable_tree
     */
    #[Test]
    public function 하위_디렉토리의_정적_파일까지_열거한다(): void
    {
        $this->writeCustom('custom.css');
        $this->writeCustom('fonts/x.woff2', 'font');
        $this->writeCustom('img/deep/marker.svg', '<svg/>');

        $relatives = array_column(CustomAssets::publishableFiles('plugins', self::FAKE_PLUGIN), 'relative');

        $this->assertSame(['custom.css', 'fonts/x.woff2', 'img/deep/marker.svg'], $relatives);
    }

    /**
     * 허용 확장자 밖(텍스트·PHP)과 소스맵은 뺀다 — 게시 화이트리스트와 동일.
     *
     * @scenario custom_source=convention_scan, custom_asset=static_file, custom_change_detection=nested_static_file
     *
     * @effects custom_signature_covers_publishable_tree
     */
    #[Test]
    public function 허용_확장자_밖과_소스맵은_열거하지_않는다(): void
    {
        $this->writeCustom('custom.css');
        $this->writeCustom('notes.txt', 'not an asset');
        $this->writeCustom('evil.php', '<?php');
        $this->writeCustom('custom.css.map', '{}');

        $relatives = array_column(CustomAssets::publishableFiles('plugins', self::FAKE_PLUGIN), 'relative');

        $this->assertSame(['custom.css'], $relatives);
    }

    /**
     * 항목마다 절대 경로·수정 시각·크기를 싣는다 — 서명 재료이자 게시 복사 원본이다.
     *
     * @scenario custom_source=convention_scan, custom_asset=css, custom_change_detection=top_level_asset
     *
     * @effects custom_signature_covers_publishable_tree
     */
    #[Test]
    public function 항목은_절대경로_mtime_크기를_갖는다(): void
    {
        $this->writeCustom('custom.css', '/* twelve b */');

        $files = CustomAssets::publishableFiles('plugins', self::FAKE_PLUGIN);

        $this->assertCount(1, $files);
        $this->assertSame('custom.css', $files[0]['relative']);
        $this->assertFileExists($files[0]['absolute']);
        $this->assertSame(14, $files[0]['size']);
        $this->assertGreaterThan(0, $files[0]['mtime']);
    }

    /**
     * 요청 스코프 메모이즈 — 같은 요청에서 두 번 물어도 디스크를 다시 훑지 않고, `flushCache()` 가 비운다.
     *
     * @scenario custom_source=convention_scan, custom_asset=css, custom_change_detection=no_change
     *
     * @effects custom_signature_covers_publishable_tree
     */
    #[Test]
    public function 결과는_요청_스코프로_메모이즈되고_flush_로_비워진다(): void
    {
        $this->writeCustom('custom.css');

        $first = CustomAssets::publishableFiles('plugins', self::FAKE_PLUGIN);

        // 메모가 살아 있는 동안 추가한 파일은 보이지 않는다
        $this->writeCustom('fonts/x.woff2', 'font');
        $this->assertSame($first, CustomAssets::publishableFiles('plugins', self::FAKE_PLUGIN));

        CustomAssets::flushCache();

        $this->assertCount(2, CustomAssets::publishableFiles('plugins', self::FAKE_PLUGIN));
    }

    /**
     * 디렉토리가 없거나 타입이 유효하지 않으면 빈 목록이다.
     *
     * @scenario custom_source=none, custom_asset=css, custom_change_detection=no_change
     *
     * @effects custom_signature_covers_publishable_tree
     */
    #[Test]
    public function 디렉토리_부재와_무효_타입은_빈_목록이다(): void
    {
        File::deleteDirectory($this->pluginDir());

        $this->assertSame([], CustomAssets::publishableFiles('plugins', self::FAKE_PLUGIN));
        $this->assertSame([], CustomAssets::publishableFiles('unknown', self::FAKE_PLUGIN));
        $this->assertSame([], CustomAssets::publishableFiles('plugins', '../escape'));
    }

    /**
     * 로드 목록(`forExtension`)은 그대로 최상위 css/js 만 싣는다 — 열거자 도입이 로드 의미를 바꾸지 않는다.
     *
     * @scenario custom_source=convention_scan, custom_asset=static_file, custom_change_detection=nested_static_file
     *
     * @effects custom_signature_covers_publishable_tree
     */
    #[Test]
    public function 로드_목록은_여전히_최상위_css_js_만_싣는다(): void
    {
        $this->writeCustom('custom.css');
        $this->writeCustom('fonts/inner.css');
        $this->writeCustom('img/marker.svg', '<svg/>');

        $loaded = CustomAssets::forExtension('plugins', self::FAKE_PLUGIN);
        $publishable = CustomAssets::publishableFiles('plugins', self::FAKE_PLUGIN);

        $this->assertCount(1, $loaded);
        $this->assertStringContainsString('custom.css', $loaded[0]['url']);
        $this->assertCount(3, $publishable);
    }
}
