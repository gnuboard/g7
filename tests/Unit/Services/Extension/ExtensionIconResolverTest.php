<?php

namespace Tests\Unit\Services\Extension;

use App\Services\Extension\ExtensionIconResolver;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

/**
 * 확장 아이콘 해석기 테스트 [case:extension-icon-bundled-fallback]
 *
 * 자산 서빙 lane 이 활성 디렉터리만 보는 것과 달리, 해석기는 활성 → _pending →
 * _bundled 를 순서대로 본다. 미설치 번들 확장의 아이콘이 목록에 뜨는 축이 여기다.
 *
 * 소유 시나리오: `extension-icons` (축 매핑은 각 테스트 메서드에 있다)
 *
 * @effects icon_resolves_manifest_over_convention, uninstalled_bundled_icon_resolves_from_bundled_dir, invalid_icon_is_not_served_and_row_falls_back
 */
class ExtensionIconResolverTest extends TestCase
{
    use ProtectsExtensionDirectories;

    /** @var string 정사각 유효 SVG */
    private const VALID_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>t</title><rect width="64" height="64" fill="#336699"/></svg>';

    /** @var string 이벤트 핸들러가 박힌 규격 위반 SVG */
    private const UNSAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" onload="alert(1)"/></svg>';

    /** @var list<string> 정리 대상 픽스처 디렉터리 */
    private array $fixtureDirs = [];

    private ExtensionIconResolver $resolver;

    /**
     * 확장 디렉터리를 보호하고 해석기를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExtensionProtection();
        $this->resolver = new ExtensionIconResolver;
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
     * _bundled 의 관례 아이콘이 해석됩니다.
     *
     * @scenario extension_type=module, location=bundled, declaration=convention_svg
     *
     * @effects uninstalled_bundled_icon_resolves_from_bundled_dir
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function convention_svg_in_bundled_directory_resolves(): void
    {
        $this->makeExtension('modules/_bundled/test-icon-mod', 'module.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $resolved = $this->resolver->resolve('module', 'test-icon-mod');

        $this->assertNotNull($resolved);
        $this->assertSame('image/svg+xml', $resolved['mime']);
        $this->assertSame('convention', $resolved['source']);
        $this->assertSame('bundled', $resolved['location']);
    }

    /**
     * 활성 디렉터리가 _pending·_bundled 보다 우선합니다.
     *
     * @scenario extension_type=module, location=active, declaration=convention_svg
     *
     * @effects uninstalled_bundled_icon_resolves_from_bundled_dir
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function active_directory_wins_over_pending_and_bundled(): void
    {
        $this->makeExtension('modules/test-icon-order', 'module.json', [], ['assets/icon.svg' => self::VALID_SVG]);
        $this->makeExtension('modules/_pending/test-icon-order', 'module.json', [], ['assets/icon.svg' => self::VALID_SVG]);
        $this->makeExtension('modules/_bundled/test-icon-order', 'module.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $resolved = $this->resolver->resolve('module', 'test-icon-order');

        $this->assertNotNull($resolved);
        $this->assertSame('active', $resolved['location']);
    }

    /**
     * 활성본이 없으면 _pending 이 _bundled 보다 우선합니다.
     *
     * @scenario extension_type=plugin, location=pending, declaration=convention_svg
     *
     * @effects uninstalled_bundled_icon_resolves_from_bundled_dir
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function pending_wins_over_bundled_when_active_is_absent(): void
    {
        $this->makeExtension('plugins/_pending/test-icon-pending', 'plugin.json', [], ['assets/icon.svg' => self::VALID_SVG]);
        $this->makeExtension('plugins/_bundled/test-icon-pending', 'plugin.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $resolved = $this->resolver->resolve('plugin', 'test-icon-pending');

        $this->assertNotNull($resolved);
        $this->assertSame('pending', $resolved['location']);
    }

    /**
     * 매니페스트 icon 선언이 파일 관례를 덮습니다.
     *
     * @scenario extension_type=plugin, location=bundled, declaration=manifest_path
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function manifest_icon_overrides_convention(): void
    {
        $this->makeExtension(
            'plugins/_bundled/test-icon-manifest',
            'plugin.json',
            ['icon' => 'assets/brand/mark.svg'],
            ['assets/icon.svg' => self::VALID_SVG, 'assets/brand/mark.svg' => self::VALID_SVG]
        );

        $resolved = $this->resolver->resolve('plugin', 'test-icon-manifest');

        $this->assertNotNull($resolved);
        $this->assertSame('manifest', $resolved['source']);
        $this->assertStringContainsString('mark.svg', $resolved['path']);
    }

    /**
     * icon 경로가 규칙 위반이면 경고를 남기고 관례로 폴백합니다.
     *
     * @scenario extension_type=plugin, location=bundled, declaration=manifest_path, validity=path_escape
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function manifest_path_violation_falls_back_to_convention_with_warning(): void
    {
        $root = $this->makeExtension(
            'plugins/_bundled/test-icon-escape',
            'plugin.json',
            ['icon' => '../../../etc/passwd.svg'],
            ['assets/icon.svg' => self::VALID_SVG]
        );

        $inspection = $this->resolver->inspectRoot($root, '../../../etc/passwd.svg');

        $this->assertTrue($inspection['present']);
        $this->assertSame('convention', $inspection['source']);
        $this->assertContains('manifest_icon_traversal', $inspection['warnings']);

        $resolved = $this->resolver->resolve('plugin', 'test-icon-escape');
        $this->assertNotNull($resolved);
        $this->assertSame('convention', $resolved['source']);
    }

    /**
     * icon 이 가리키는 파일이 없으면 경고 후 관례로 폴백합니다.
     *
     * @scenario extension_type=plugin, location=bundled, declaration=manifest_path
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function manifest_icon_that_does_not_exist_warns_and_falls_back(): void
    {
        $root = $this->makeExtension(
            'plugins/_bundled/test-icon-missing',
            'plugin.json',
            ['icon' => 'assets/nope.svg'],
            ['assets/icon.svg' => self::VALID_SVG]
        );

        $inspection = $this->resolver->inspectRoot($root, 'assets/nope.svg');

        $this->assertSame('convention', $inspection['source']);
        $this->assertContains('manifest_icon_missing', $inspection['warnings']);
    }

    /**
     * 관례 후보는 svg → png → webp 순으로 첫 존재 파일을 씁니다.
     *
     * @scenario extension_type=module, location=bundled, declaration=convention_png
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function convention_order_prefers_svg_then_png_then_webp(): void
    {
        $this->requireGd();

        $root = $this->makeExtension('modules/_bundled/test-icon-order2', 'module.json', [], []);
        $this->writePng($root.'/assets/icon.png', 128);
        File::put($root.'/assets/icon.webp', 'x');

        $inspection = $this->resolver->inspectRoot($root, null);
        $this->assertSame('assets/icon.png', $inspection['relative']);

        File::put($root.'/assets/icon.svg', self::VALID_SVG);
        $freshResolver = new ExtensionIconResolver;
        $this->assertSame('assets/icon.svg', $freshResolver->inspectRoot($root, null)['relative']);
    }

    /**
     * 관례 후보가 존재하되 규격 위반이면 다음 후보로 넘어가지 않고 해석이 실패합니다.
     *
     * @scenario extension_type=module, location=bundled, declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function invalid_convention_icon_does_not_fall_through_to_next_candidate(): void
    {
        $this->requireGd();

        $root = $this->makeExtension('modules/_bundled/test-icon-invalid', 'module.json', [], [
            'assets/icon.svg' => self::UNSAFE_SVG,
        ]);
        $this->writePng($root.'/assets/icon.png', 128);

        $inspection = $this->resolver->inspectRoot($root, null);

        $this->assertTrue($inspection['present']);
        $this->assertFalse($inspection['valid']);
        $this->assertSame('assets/icon.svg', $inspection['relative']);
        $this->assertContains('svg_event_handler_attribute', $inspection['warnings']);

        $this->assertNull($this->resolver->resolve('module', 'test-icon-invalid'));
    }

    /**
     * 행에 얹을 icon_url 에 버전 쿼리가 붙고 출처가 함께 옵니다.
     *
     * @scenario extension_type=module, location=bundled, surface=list
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function describe_returns_url_with_version_and_source(): void
    {
        $this->makeExtension('modules/_bundled/test-icon-desc', 'module.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $described = $this->resolver->describe('module', 'test-icon-desc', '1.2.3');

        $this->assertSame('/api/extensions/module/test-icon-desc/icon?v=1.2.3', $described['icon_url']);
        $this->assertSame('convention', $described['icon_source']);
    }

    /**
     * 아이콘이 없는 확장은 icon_url 이 null 이고 출처가 none 입니다.
     *
     * @scenario extension_type=module, location=bundled, declaration=none, surface=list
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function describe_returns_none_for_extension_without_icon(): void
    {
        $this->makeExtension('modules/_bundled/test-icon-none', 'module.json', [], []);

        $described = $this->resolver->describe('module', 'test-icon-none', '1.0.0');

        $this->assertNull($described['icon_url']);
        $this->assertSame('none', $described['icon_source']);
    }

    /**
     * 버전이 없으면 캐시 무효화 쿼리를 붙이지 않습니다.
     *
     * @scenario extension_type=plugin, surface=list
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function url_omits_version_query_when_version_is_absent(): void
    {
        $this->assertSame(
            '/api/extensions/plugin/sirsoft-gdpr/icon',
            $this->resolver->url('plugin', 'sirsoft-gdpr')
        );
    }

    /**
     * 같은 요청 안에서는 메모가 답하고, 새 인스턴스는 디스크를 다시 봅니다.
     *
     * @scenario extension_type=module, location=bundled, surface=list
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function repeated_resolution_is_memoized_within_the_request(): void
    {
        $root = $this->makeExtension('modules/_bundled/test-icon-memo', 'module.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $first = $this->resolver->resolve('module', 'test-icon-memo');
        $this->assertNotNull($first);

        // 파일을 지워도 같은 요청 안에서는 메모가 답한다.
        File::delete($root.'/assets/icon.svg');
        $this->assertNotNull($this->resolver->resolve('module', 'test-icon-memo'));

        // 새 요청(새 인스턴스)은 다시 디스크를 본다.
        $this->assertNull((new ExtensionIconResolver)->resolve('module', 'test-icon-memo'));
    }

    /**
     * 미설치 언어팩은 _bundled 에서 해석됩니다.
     *
     * @scenario extension_type=language_pack, location=bundled, declaration=convention_svg
     *
     * @effects language_pack_icon_source_derivation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function language_pack_resolves_from_bundled_when_not_installed(): void
    {
        $this->makeExtension('lang-packs/_bundled/g7-test-icon-ja', 'language-pack.json', [], ['assets/icon.svg' => self::VALID_SVG]);

        $resolved = $this->resolver->resolve('language-pack', 'g7-test-icon-ja');

        $this->assertNotNull($resolved);
        $this->assertSame('bundled', $resolved['location']);
    }

    /**
     * 지원하지 않는 유형은 null 입니다.
     *
     * @scenario extension_type=module, declaration=none
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unknown_type_resolves_to_null(): void
    {
        $this->assertNull($this->resolver->resolve('widget', 'test-icon-mod'));
    }

    /**
     * 미리보기용 검사 결과의 경고가 코드·문장 쌍으로 옵니다.
     *
     * @scenario extension_type=module, location=bundled, surface=manifest_preview, validity=invalid_svg
     *
     * @effects preview_reports_icon_warnings_without_blocking_install
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function inspect_root_for_api_translates_warning_codes(): void
    {
        $root = $this->makeExtension('modules/_bundled/test-icon-api', 'module.json', [], [
            'assets/icon.svg' => self::UNSAFE_SVG,
        ]);

        $inspection = $this->resolver->inspectRootForApi($root, null);

        $this->assertTrue($inspection['present']);
        $this->assertFalse($inspection['valid']);
        $this->assertNotSame([], $inspection['warnings']);
        $this->assertSame('svg_event_handler_attribute', $inspection['warnings'][0]['code']);
        $this->assertNotSame(
            'extensions.icon.warnings.svg_event_handler_attribute',
            $inspection['warnings'][0]['message'],
            '경고 코드에 대응하는 번역 문장이 없습니다.'
        );
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

    /**
     * GD 확장이 없으면 테스트를 건너뜁니다.
     *
     * @return void 반환값 없음
     */
    private function requireGd(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD 확장이 없어 래스터 축을 건너뜁니다.');
        }
    }

    /**
     * 정사각 PNG 를 만듭니다.
     *
     * @param  string  $path  저장 경로
     * @param  int  $edge  한 변 픽셀
     * @return void 반환값 없음
     */
    private function writePng(string $path, int $edge): void
    {
        File::ensureDirectoryExists(dirname($path));
        $image = imagecreatetruecolor($edge, $edge);
        imagepng($image, $path);
        imagedestroy($image);
    }
}
