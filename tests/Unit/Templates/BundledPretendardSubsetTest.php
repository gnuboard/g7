<?php

namespace Tests\Unit\Templates;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 번들 템플릿의 Pretendard 동봉본이 동적 서브셋(글자 묶음별 woff2 + unicode-range)인지 잠근다.
 *
 * 한 파일(2MB) 가변 글꼴을 모든 첫 방문이 받던 것을, 화면에 쓰인 글자 묶음만 받도록 바꿨다.
 * CSS 파일 이름(`pretendard-variable.css`)과 글꼴 이름(`Pretendard Variable`)은 그대로라 template.json externals 와
 * 템플릿 `--font-sans` 는 바뀌지 않는다. 옛 단일 파일이 남으면 배포 크기만 늘고 아무도 쓰지 않는다.
 */
class BundledPretendardSubsetTest extends TestCase
{
    /**
     * Pretendard 를 동봉한 번들 템플릿.
     *
     * @return array<string, array{0: string}> 템플릿 식별자
     */
    public static function templates(): array
    {
        return [
            'sirsoft-basic' => ['sirsoft-basic'],
            'sirsoft-admin_basic' => ['sirsoft-admin_basic'],
        ];
    }

    /**
     * 동봉 CSS 는 글자 묶음마다 unicode-range 를 가진 @font-face 90개 이상이고, 참조한 woff2 가 모두 있으며,
     * 옛 단일 가변 글꼴 파일은 없다.
     *
     * @scenario asset_class=vendored, outcome=loaded
     *
     * @effects vendored_font_split_by_unicode_range
     * @effects vendored_asset_declared_path_exists_on_disk
     *
     * @param  string  $template  템플릿 식별자
     * @return void 반환값 없음
     */
    #[DataProvider('templates')]
    public function test_pretendard_is_bundled_as_dynamic_subset(string $template): void
    {
        $dir = base_path("templates/_bundled/{$template}/dist/vendor/pretendard/1.3.9");
        $css = file_get_contents($dir.'/pretendard-variable.css');
        $this->assertIsString($css, "{$template}: pretendard-variable.css 가 없습니다");

        preg_match_all('/@font-face\s*\{([^}]*)\}/', $css, $blocks);
        $this->assertGreaterThanOrEqual(90, count($blocks[1]), "{$template}: @font-face 가 90개 미만입니다");

        foreach ($blocks[1] as $i => $block) {
            $this->assertStringContainsString("font-family: 'Pretendard Variable'", $block, "{$template}: [{$i}] 글꼴 이름이 다릅니다");
            $this->assertMatchesRegularExpression('/unicode-range:\s*U\+/', $block, "{$template}: [{$i}] unicode-range 가 없습니다");
            $this->assertSame(1, preg_match('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', $block, $url), "{$template}: [{$i}] url() 이 없습니다");
            $this->assertStringStartsWith('./woff2-dynamic-subset/', $url[1], "{$template}: [{$i}] 상대 경로가 아닙니다");
            $this->assertFileExists($dir.'/'.substr($url[1], 2), "{$template}: [{$i}] 참조한 woff2 가 없습니다");
        }

        $this->assertDirectoryDoesNotExist($dir.'/woff2', "{$template}: 옛 단일 가변 글꼴 디렉터리가 남아 있습니다");
        $this->assertFileExists($dir.'/OFL.txt', "{$template}: 글꼴 라이선스가 없습니다");
    }
}
