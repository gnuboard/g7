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
 * 모집단은 TSX 의 `Icon` 과 레이아웃(`.g7.html`)의 `<icon>` 둘 다다 — 처음에는 TSX 만 봐서, 관리자 템플릿의
 * 원형 스타일 자산(`danger-confirm-icon`)을 레이아웃이 아이콘 요소에 직접 준 자리(방문자 통계 전체 삭제
 * 확인 창)를 놓쳤다(#682 PO 실측 후속 4). 자산은 클래스 이름만 보여 Tailwind 크기 판정에 걸리지 않으므로,
 * 템플릿 스타일시트에서 「칸·원을 그리되 글자 크기는 없는」 자산을 파생해 아이콘 요소에 직접 쓰지 못하게 한다.
 *
 * 트러블슈팅: [case:components-misc-box-asset-on-icon]
 *
 * 소유 시나리오: `font-icon-sizing`
 *
 * @effects large_font_icon_declares_font_size
 * @effects box_style_asset_wraps_the_icon_not_the_icon_itself
 */
class FontIconSizingTest extends TestCase
{
    /** @var int 기본 글자 크기와 같은 칸(w-4 = 16px)은 그대로 둔다 — 이 값 이상이면 글자 크기 필수 */
    private const LARGE_BOX_STEP = 6;

    /**
     * 번들 확장 소스(TSX·레이아웃)의 아이콘 요소마다 [파일:줄, 클래스, 나머지 속성] 을 모읍니다.
     *
     * @return array<int, array{0: string, 1: string, 2: string}> 아이콘 요소 목록
     */
    private function iconElements(): array
    {
        $roots = [];
        foreach (['templates/_bundled/*/src', 'templates/_bundled/*/layouts', 'modules/_bundled/*/resources', 'plugins/_bundled/*/resources'] as $pattern) {
            $roots = array_merge($roots, glob(base_path($pattern), GLOB_ONLYDIR) ?: []);
        }

        $elements = [];
        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                $tsx = str_ends_with($path, '.tsx');
                if ((! $tsx && ! str_ends_with($path, '.g7.html')) || preg_match('#/(node_modules|dist|__tests__)/#', $path)) {
                    continue;
                }

                // TSX 는 `<Icon className=…>`, 레이아웃은 `<icon class=…>`(`<icon-select>` 같은 다른 부품은 뺀다).
                $source = (string) file_get_contents($path);
                preg_match_all($tsx ? '/<Icon\b([^>]*?)\/?>/s' : '/<icon\b(?!-)([^>]*?)\/?>/s', $source, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as [$attributes, $offset]) {
                    if (preg_match('/\b(?:className|class)=(?:"([^"]*)"|\{`([^`]*)`\}|\'([^\']*)\')/', $attributes, $class) !== 1) {
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

    /**
     * 템플릿 스타일시트에서 「칸(24px 이상) 또는 원·배경을 그리되 글자 크기는 정하지 않는」 자산 클래스를 파생합니다.
     *
     * @return array<string, string> 클래스 이름 → 정의한 템플릿
     */
    private function boxStyleAssets(): array
    {
        $assets = [];
        foreach (glob(base_path('templates/_bundled/*/src/styles/*.css')) ?: [] as $css) {
            $source = (string) file_get_contents($css);
            // 단일 클래스 선택자 블록만 — `.x > i` 같은 자식 규칙은 그 자산이 감싸는 아이콘을 꾸미는 것이다.
            preg_match_all('/\n\s*\.([a-z0-9-]+)\s*\{([^}]*)\}/', $source, $blocks, PREG_SET_ORDER);
            foreach ($blocks as [, $name, $body]) {
                $large = preg_match('/@apply[^;]*\b[wh]-(\d+)\b/', $body, $box) === 1 && (int) $box[1] >= self::LARGE_BOX_STEP;
                $shape = preg_match('/\brounded-full\b|\bbg-/', $body) === 1 && preg_match('/\b[wh]-\d+/', $body) === 1;
                $fontSize = preg_match('/font-size|\btext-(xs|sm|base|lg|\d?xl|\[)/', $body) === 1;
                if (($large || $shape) && ! $fontSize) {
                    $assets[$name] = basename(dirname($css, 3));
                }
            }
        }

        return $assets;
    }

    /**
     * 칸·원을 그리는 스타일 자산은 아이콘을 **감싸는** 요소에 준다 — 아이콘 요소에 직접 주면 글리프가 칸 위쪽에
     * 본문 글자 크기로 붙는다(Font Awesome 의 `display` 가 자산의 가운데 정렬을 이긴다).
     *
     * @scenario box=large
     *
     * @effects box_style_asset_wraps_the_icon_not_the_icon_itself
     *
     * @return void 없음
     */
    #[Test]
    public function box_style_assets_are_not_put_on_the_icon_itself(): void
    {
        $assets = $this->boxStyleAssets();
        $this->assertArrayHasKey('danger-confirm-icon', $assets, '칸·원 자산 모집단을 스타일시트에서 못 읽는다');

        $violations = [];
        foreach ($this->iconElements() as [$where, $className]) {
            foreach (preg_split('/\s+/', trim($className)) ?: [] as $class) {
                if (isset($assets[$class])) {
                    $violations[] = $where.'  .'.$class.' ('.$assets[$class].')';
                }
            }
        }

        $this->assertSame([], $violations, "칸·원 스타일 자산을 아이콘 요소에 직접 줬다 — 감싸는 요소에 주고 아이콘은 그 안에 둔다:\n".implode("\n", $violations));
    }
}
