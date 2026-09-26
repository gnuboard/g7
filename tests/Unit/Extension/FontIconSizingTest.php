<?php

namespace Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 글꼴 아이콘 크기 계약 테스트
 *
 * 번들 확장의 `Icon` 은 Font Awesome 글꼴 아이콘이라 글리프 크기가 글자 크기를 따른다.
 * 너비·높이(`w-12 h-12`)만 주면 칸만 커지고 글리프는 기본 글자 크기(16px)로 칸 위쪽에
 * 작게 놓인다 — 방문자 통계 빈 화면 아이콘이 원 안에서 작고 위로 치우친 원인이었다.
 * 기본 글자 크기보다 큰 칸(24px 이상)을 주는 아이콘은 글자 크기도 함께 줘야 한다.
 *
 * 소유 시나리오: `font-icon-sizing`
 *
 * @effects large_font_icon_declares_font_size
 */
class FontIconSizingTest extends TestCase
{
    /** @var int 기본 글자 크기와 같은 칸(w-4 = 16px)은 그대로 둔다 — 이 값 이상이면 글자 크기 필수 */
    private const LARGE_BOX_STEP = 6;

    /**
     * 번들 확장 소스의 `Icon` 요소마다 [파일:줄, className, 나머지 속성] 을 모읍니다.
     *
     * @return array<int, array{0: string, 1: string, 2: string}> 아이콘 요소 목록
     */
    private function iconElements(): array
    {
        $roots = [];
        foreach (['templates/_bundled/*/src', 'modules/_bundled/*/resources', 'plugins/_bundled/*/resources'] as $pattern) {
            $roots = array_merge($roots, glob(base_path($pattern), GLOB_ONLYDIR) ?: []);
        }

        $elements = [];
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (! str_ends_with($path, '.tsx') || preg_match('#/(node_modules|dist|__tests__)/#', $path)) {
                    continue;
                }

                $source = (string) file_get_contents($path);
                preg_match_all('/<Icon\b([^>]*?)\/?>/s', $source, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as [$attributes, $offset]) {
                    if (preg_match('/className=(?:"([^"]*)"|\{`([^`]*)`\}|\'([^\']*)\')/', $attributes, $class) !== 1) {
                        continue;
                    }

                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $elements[] = [substr($path, strlen(str_replace('\\', '/', base_path())) + 1).':'.$line, $class[1] ?: ($class[2] ?? '') ?: ($class[3] ?? ''), $attributes];
                }
            }
        }

        return $elements;
    }

    /**
     * 24px 이상 칸을 주는 글꼴 아이콘은 글자 크기(`text-*` 또는 `size`)도 준다.
     *
     * @scenario box=large
     *
     * @effects large_font_icon_declares_font_size
     *
     * @return void 없음
     */
    #[Test]
    public function large_font_icons_declare_a_font_size(): void
    {
        $elements = $this->iconElements();
        $this->assertGreaterThan(50, count($elements), '아이콘 모집단이 비었다 — 스캔 경로·정규식이 소스를 못 읽는다');

        $large = [];
        $violations = [];
        foreach ($elements as [$where, $className, $attributes]) {
            if (preg_match('/\b[wh]-(\d+)\b/', $className, $box) !== 1 || (int) $box[1] < self::LARGE_BOX_STEP) {
                continue;
            }

            $large[] = $where;
            $hasFontSize = preg_match('/\btext-(xs|sm|base|lg|\d?xl|\[)/', $className) === 1
                || preg_match('/\bfa-(\dx|lg|xl|2xl)\b/', $className) === 1
                || preg_match('/\bsize=/', $attributes) === 1;

            if (! $hasFontSize) {
                $violations[] = $where.'  "'.$className.'"';
            }
        }

        $this->assertNotEmpty($large, '24px 이상 아이콘 모집단이 비었다 — 판정이 아무것도 보지 않는다');
        $this->assertSame([], $violations, "글꼴 아이콘은 너비·높이만으로 커지지 않는다 — 글자 크기를 함께 준다:\n".implode("\n", $violations));
    }
}
