<?php

namespace Tests\Unit\Support;

use App\Support\ExtensionIcon\ExtensionIconValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 아이콘 검증기 단위 테스트
 *
 * 규정 원문은 docs/extension/extension-icons.md — 정사각·투명·100KB 이하·
 * 스크립트 없는 SVG. 여기서는 그 규정을 파일 바이트로 판정하는 축만 잠근다.
 *
 * 소유 시나리오: `extension-icons` (축 매핑은 각 테스트 메서드에 있다)
 *
 * @effects icon_resolves_manifest_over_convention, invalid_icon_is_not_served_and_row_falls_back
 */
class ExtensionIconValidatorTest extends TestCase
{
    /** @var list<string> 정리 대상 임시 파일 */
    private array $tempFiles = [];

    /**
     * 임시로 만든 아이콘 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * 정사각 viewBox 를 가진 안전한 SVG 는 통과합니다.
     *
     * @scenario declaration=convention_svg, validity=valid
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function valid_square_svg_passes(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>t</title><rect width="64" height="64" fill="#123456"/></svg>',
            'svg'
        );

        $this->assertTrue($result['valid'], 'errors: '.implode(',', $result['errors']));
        $this->assertSame('svg', $result['format']);
        $this->assertSame([], $result['errors']);
    }

    /**
     * script 요소가 든 SVG 는 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_with_script_element_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><script>alert(1)</script></svg>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_script_element', $result['errors']);
    }

    /**
     * on 으로 시작하는 이벤트 속성이 든 SVG 는 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_with_event_handler_attribute_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" onload="alert(1)"/></svg>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_event_handler_attribute', $result['errors']);
    }

    /**
     * 외부 파일을 참조하는 href 가 있으면 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_with_external_href_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 64 64"><image xlink:href="http://example.com/x.png"/></svg>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_external_reference', $result['errors']);
    }

    /**
     * 문서 안 조각(#) 참조는 그라디언트 용도라 허용됩니다.
     *
     * @scenario declaration=convention_svg, validity=valid
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_with_internal_fragment_href_is_allowed(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<defs><linearGradient id="grad"><stop offset="0" stop-color="#fff"/></linearGradient></defs>'
            .'<rect width="64" height="64" fill="url(#grad)"/><use href="#grad"/></svg>',
            'svg'
        );

        $this->assertTrue($result['valid'], 'errors: '.implode(',', $result['errors']));
    }

    /**
     * foreignObject 가 든 SVG 는 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_with_foreign_object_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><foreignObject width="10" height="10"/></svg>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_foreign_object', $result['errors']);
    }

    /**
     * DOCTYPE·ENTITY 선언이 있으면 파싱 전에 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_with_doctype_or_entity_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"/>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_doctype_or_entity', $result['errors']);
    }

    /**
     * 외부 스타일시트 선언이 있으면 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_with_stylesheet_processing_instruction_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<?xml-stylesheet href="x.css"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"/>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_stylesheet_pi', $result['errors']);
    }

    /**
     * 스타일이 외부 자원을 @import 하면 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_style_with_external_import_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><style>@import url("http://x/y.css");</style></svg>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_style_external_reference', $result['errors']);
    }

    /**
     * viewBox 가 정사각이 아니면 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=not_square
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_with_non_square_viewbox_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 48"/>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('not_square', $result['errors']);
    }

    /**
     * viewBox 가 없으면 width·height 로 정사각을 판정하고, 둘 다 없으면 거부합니다.
     *
     * @scenario declaration=convention_svg, validity=not_square
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_without_viewbox_falls_back_to_width_height(): void
    {
        $square = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"/>',
            'svg'
        );
        $this->assertTrue($square['valid'], 'errors: '.implode(',', $square['errors']));

        $oblong = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64px" height="48px"/>',
            'svg'
        );
        $this->assertFalse($oblong['valid']);
        $this->assertContains('not_square', $oblong['errors']);

        $neither = ExtensionIconValidator::validateBytes(
            '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'svg'
        );
        $this->assertFalse($neither['valid']);
        $this->assertContains('not_square', $neither['errors']);
    }

    /**
     * XML 로 파싱되지 않는 SVG 는 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function svg_that_is_not_well_formed_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes('<svg><rect></svg>', 'svg');

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_not_well_formed', $result['errors']);
    }

    /**
     * 최상위 요소가 svg 가 아니면 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function non_svg_root_element_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes(
            '<html xmlns="http://www.w3.org/1999/xhtml"><body/></html>',
            'svg'
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('svg_root_not_svg', $result['errors']);
    }

    /**
     * 100KB 를 넘는 파일은 이후 검사 없이 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=too_large
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function oversized_file_is_rejected(): void
    {
        $padding = str_repeat('a', ExtensionIconValidator::MAX_BYTES);
        $bytes = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>'.$padding.'</title></svg>';

        $result = ExtensionIconValidator::validateBytes($bytes, 'svg');

        $this->assertFalse($result['valid']);
        $this->assertContains('too_large', $result['errors']);
    }

    /**
     * 0바이트 파일은 거부됩니다.
     *
     * @scenario declaration=convention_svg, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function empty_file_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes('', 'svg');

        $this->assertFalse($result['valid']);
        $this->assertContains('empty_file', $result['errors']);
    }

    /**
     * svg·png·webp 밖의 확장자는 거부됩니다.
     *
     * @scenario declaration=manifest_path, validity=path_escape
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unsupported_extension_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateBytes('GIF89a', 'gif');

        $this->assertFalse($result['valid']);
        $this->assertContains('unsupported_extension', $result['errors']);
    }

    /**
     * 실재하지 않는 경로는 거부됩니다.
     *
     * @scenario declaration=none, validity=valid
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function missing_file_is_rejected(): void
    {
        $result = ExtensionIconValidator::validateFile(sys_get_temp_dir().'/g7-icon-does-not-exist.svg');

        $this->assertFalse($result['valid']);
        $this->assertContains('file_missing', $result['errors']);
    }

    /**
     * 128px 정사각 PNG 는 통과하고 치수를 돌려줍니다.
     *
     * @scenario declaration=convention_png, validity=valid
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function square_png_at_minimum_edge_passes(): void
    {
        $this->requireGd();

        $path = $this->writePng(128, 128);
        $result = ExtensionIconValidator::validateFile($path);

        $this->assertTrue($result['valid'], 'errors: '.implode(',', $result['errors']));
        $this->assertSame('png', $result['format']);
        $this->assertSame(128.0, $result['width']);
    }

    /**
     * 한 변이 128px 미만인 PNG 는 거부됩니다.
     *
     * @scenario declaration=convention_png, validity=not_square
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function png_below_minimum_edge_is_rejected(): void
    {
        $this->requireGd();

        $result = ExtensionIconValidator::validateFile($this->writePng(64, 64));

        $this->assertFalse($result['valid']);
        $this->assertContains('raster_too_small', $result['errors']);
    }

    /**
     * 가로와 세로가 다른 PNG 는 거부됩니다.
     *
     * @scenario declaration=convention_png, validity=not_square
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function non_square_png_is_rejected(): void
    {
        $this->requireGd();

        $result = ExtensionIconValidator::validateFile($this->writePng(256, 128));

        $this->assertFalse($result['valid']);
        $this->assertContains('not_square', $result['errors']);
    }

    /**
     * 내용과 확장자가 어긋나면 거부됩니다.
     *
     * @scenario declaration=convention_webp, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function png_bytes_served_under_webp_extension_are_rejected(): void
    {
        $this->requireGd();

        $pngPath = $this->writePng(128, 128);
        $webpPath = $this->tempPath('webp');
        copy($pngPath, $webpPath);

        $result = ExtensionIconValidator::validateFile($webpPath);

        $this->assertFalse($result['valid']);
        $this->assertContains('raster_type_mismatch', $result['errors']);
    }

    /**
     * 그림으로 읽히지 않는 래스터는 거부됩니다.
     *
     * @scenario declaration=convention_png, validity=invalid_svg
     *
     * @effects invalid_icon_is_not_served_and_row_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unreadable_raster_is_rejected(): void
    {
        $path = $this->tempPath('png');
        file_put_contents($path, 'not an image at all');

        $result = ExtensionIconValidator::validateFile($path);

        $this->assertFalse($result['valid']);
        $this->assertContains('raster_unreadable', $result['errors']);
    }

    /**
     * 매니페스트 icon 경로와 기대 위반 코드의 짝을 돌려줍니다.
     *
     * 절대경로·URL·상위 탈출·역슬래시·NUL·허용 밖 확장자를 각각 한 줄씩 덮고,
     * 통과해야 하는 상대경로 세 가지를 대조군으로 둡니다.
     *
     * @return array<string, array{0: string, 1: ?string}> 경로 → [입력 경로, 기대 위반 코드(없으면 null)]
     */
    public static function manifestPathProvider(): array
    {
        return [
            'absolute unix' => ['/etc/passwd.svg', 'absolute_path'],
            'absolute windows' => ['C:/x/icon.svg', 'absolute_path'],
            'traversal' => ['../../secret.svg', 'traversal'],
            'backslash' => ['assets\\icon.svg', 'backslash'],
            'null byte' => ["assets/icon.svg\0.png", 'null_byte'],
            'unsupported extension' => ['assets/icon.jpg', 'unsupported_extension'],
            'empty' => ['', 'empty'],
            'blank' => ['   ', 'empty'],
            'url' => ['https://example.com/icon.svg', 'absolute_path'],
            'valid nested' => ['assets/brand/mark.svg', null],
            'valid convention' => ['assets/icon.png', null],
            'valid webp' => ['assets/icon.webp', null],
        ];
    }

    /**
     * 매니페스트 icon 경로의 위반 종류를 코드로 분류합니다.
     *
     * @scenario declaration=manifest_path, validity=path_escape
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @param  string  $path  파일 경로
     * @param  ?string  $expected  기대하는 위반 코드 (위반이 없으면 null)
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('manifestPathProvider')]
    public function manifest_path_violations_are_classified(string $path, ?string $expected): void
    {
        $this->assertSame($expected, ExtensionIconValidator::manifestPathViolation($path));
    }

    /**
     * 루트 밖 탈출과 부재 파일은 null 이고, 루트 안 실재 파일만 절대경로를 돌려줍니다.
     *
     * @scenario declaration=manifest_path, validity=path_escape
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function resolve_inside_root_rejects_escape_and_missing(): void
    {
        $root = sys_get_temp_dir().'/g7-icon-root-'.bin2hex(random_bytes(4));
        @mkdir($root.'/assets', 0o755, true);
        $icon = $root.'/assets/icon.svg';
        file_put_contents($icon, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"/>');
        $this->tempFiles[] = $icon;

        $resolved = ExtensionIconValidator::resolveInsideRoot($root, 'assets/icon.svg');
        $this->assertNotNull($resolved);
        $this->assertSame(realpath($icon), $resolved);

        $this->assertNull(ExtensionIconValidator::resolveInsideRoot($root, '../escape.svg'));
        $this->assertNull(ExtensionIconValidator::resolveInsideRoot($root, 'assets/missing.svg'));

        @rmdir($root.'/assets');
        @rmdir($root);
    }

    /**
     * 루트 안 심볼릭 링크가 밖을 가리키면 null 입니다.
     *
     * 경로 문자열만 보면 `assets/icon.svg` 는 루트 안이다 — 링크를 따라가야 밖이 드러난다.
     * `realpath()` 가 링크를 풀어 준 뒤 접두사를 대조하므로 막히지만, 그 순서가 뒤집히면
     * 확장 하나가 서버의 아무 파일이나 아이콘으로 내보낼 수 있다.
     *
     * 심볼릭 링크 생성은 OS 술어라 만들지 못하는 환경(권한 없는 Windows 등)에서는 건너뛴다.
     *
     * @scenario declaration=manifest_path, validity=path_escape
     *
     * @effects icon_resolves_manifest_over_convention
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function resolve_inside_root_rejects_symlink_pointing_outside(): void
    {
        $base = sys_get_temp_dir().'/g7-icon-symlink-'.bin2hex(random_bytes(4));
        $root = $base.'/extension';
        @mkdir($root.'/assets', 0o755, true);

        // 루트 **밖** 의 진짜 파일 — 링크의 표적이다.
        $outside = $base.'/secret.svg';
        file_put_contents($outside, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"/>');

        $link = $root.'/assets/icon.svg';
        if (! @symlink($outside, $link) || ! is_link($link)) {
            @unlink($outside);
            @rmdir($root.'/assets');
            @rmdir($root);
            @rmdir($base);
            $this->markTestSkipped('이 환경에서 심볼릭 링크를 만들 수 없습니다(OS 권한).');
        }

        $resolved = ExtensionIconValidator::resolveInsideRoot($root, 'assets/icon.svg');

        // 대조군: 링크가 실제로 밖의 파일을 가리키고 있었다(가드가 아니라 픽스처가 이유로 null 인 경우 배제).
        $this->assertSame(realpath($outside), realpath($link), '픽스처 링크가 루트 밖을 가리키지 않습니다.');
        $this->assertNull($resolved, '루트 밖을 가리키는 심볼릭 링크를 통과시켰습니다.');

        @unlink($link);
        @unlink($outside);
        @rmdir($root.'/assets');
        @rmdir($root);
        @rmdir($base);
    }

    /**
     * GD 확장이 없으면 래스터 축 테스트를 건너뜁니다.
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
     * 정사각 또는 지정 크기의 PNG 픽스처를 만듭니다.
     *
     * @param  int  $width  가로 픽셀
     * @param  int  $height  세로 픽셀
     * @return string 문자열 결과
     */
    private function writePng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $path = $this->tempPath('png');
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * 정리 목록에 등록된 임시 파일 경로를 만듭니다.
     *
     * @param  string  $extension  확장자 (점 없이)
     * @return string 문자열 결과
     */
    private function tempPath(string $extension): string
    {
        $path = sys_get_temp_dir().'/g7-icon-'.bin2hex(random_bytes(6)).'.'.$extension;
        $this->tempFiles[] = $path;

        return $path;
    }
}
