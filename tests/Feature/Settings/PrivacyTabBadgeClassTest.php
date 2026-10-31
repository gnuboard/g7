<?php

namespace Tests\Feature\Settings;

use Tests\TestCase;

/**
 * 관리자 템플릿 레이아웃이 **존재하지 않는 `badge-*` 변종**을 쓰지 않는지 봉인합니다.
 *
 * 개인정보 탭의 dry-run 뱃지가 `badge-warning` 으로 적혀 있었는데 이 템플릿의 CSS 에는 그
 * 클래스가 없다. Tailwind 는 없는 유틸을 만들어 주지 않고 브라우저도 모르는 클래스를
 * 조용히 무시하므로, 뱃지는 **오류도 경고도 없이 무색으로** 렌더됐다 — 되돌릴 수 없는 파기를
 * 켜는 자리에서 「지금 대상이 몇 명인가」를 강조하려고 넣은 색이 사라진 것이다.
 *
 * 그래서 모집단을 양쪽에서 파생한다 — 레이아웃이 **쓰는** 토큰은 소스 글롭에서, 템플릿이
 * **가진** 토큰은 빌드 CSS 에서 뽑아 차집합을 본다. 어느 쪽도 손으로 적지 않는다.
 */
class PrivacyTabBadgeClassTest extends TestCase
{
    /** 이 템플릿의 빌드 CSS — 화면이 실제로 받는 클래스의 정본. */
    private const BUILT_CSS = 'templates/_bundled/sirsoft-admin_basic/dist/css/components.css';

    /** 레이아웃 소스 글롭. */
    private const LAYOUT_GLOB = 'templates/_bundled/sirsoft-admin_basic/layouts/**/*.g7.html';

    /**
     * 레이아웃이 쓰는 `badge-*` 토큰 최소 개수.
     *
     * 글롭이나 정규식이 좁아져 0 건이 되어도 차집합은 비어 green 이 된다 — 모집단이 비면
     * red 가 되도록 하한을 둔다. 늘어나는 것은 정상이므로 상한은 두지 않는다.
     */
    private const TOKEN_MINIMUM = 4;

    /**
     * 레이아웃이 쓰는 모든 `badge-*` 가 빌드 CSS 에 실재하는지 확인합니다.
     *
     * @scenario surface=admin_basic_layouts
     *
     * @effects privacy_dry_run_badges_use_classes_that_exist_in_the_built_css
     *
     * @return void 반환값 없음
     */
    public function test_layout_badge_variants_exist_in_the_built_css(): void
    {
        $cssPath = base_path(self::BUILT_CSS);

        $this->assertFileExists($cssPath, '빌드 CSS 가 없습니다 — 경로가 바뀌었다면 이 단언도 따라가야 합니다.');

        $css = (string) file_get_contents($cssPath);
        $used = $this->usedBadgeTokens();

        $this->assertGreaterThanOrEqual(
            self::TOKEN_MINIMUM,
            count($used),
            'badge-* 토큰 모집단이 줄었습니다 (기대 최소 '.self::TOKEN_MINIMUM
            .', 실측 '.count($used).') — 글롭이나 정규식이 좁아졌습니다.',
        );

        $missing = array_values(array_filter(
            $used,
            static fn (string $token): bool => ! str_contains($css, '.'.$token),
        ));

        $this->assertSame(
            [],
            $missing,
            '레이아웃이 빌드 CSS 에 없는 badge 변종을 씁니다 — 브라우저가 조용히 무시해 무색으로 렌더됩니다: '
            .implode(', ', $missing),
        );
    }

    /**
     * 레이아웃 소스에서 `badge-*` 토큰을 파생합니다.
     *
     * 앞에 하이픈·글자가 붙은 형태(`module-badge-grid`)는 다른 클래스 계열이므로 부정
     * 후방탐색으로 걸러낸다 — 처음 작성한 정규식이 그 부분 문자열을 잡아 없는 토큰으로
     * 오판했다.
     *
     * @return array<int, string> 중복 제거된 토큰 목록
     */
    private function usedBadgeTokens(): array
    {
        $tokens = [];

        foreach (glob(base_path(self::LAYOUT_GLOB), GLOB_BRACE) ?: [] as $file) {
            if (preg_match_all('/(?<![-a-z])badge-[a-z]+/', (string) file_get_contents($file), $m) === false) {
                continue;
            }

            foreach ($m[0] as $token) {
                $tokens[$token] = true;
            }
        }

        // `layouts/**` 글롭이 PHP 에서 재귀하지 않는 플랫폼이 있어 하위 디렉터리를 직접 훑는다.
        foreach ($this->layoutFiles() as $file) {
            if (preg_match_all('/(?<![-a-z])badge-[a-z]+/', (string) file_get_contents($file), $m)) {
                foreach ($m[0] as $token) {
                    $tokens[$token] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    /**
     * 관리자 템플릿의 레이아웃 소스 파일을 재귀로 모읍니다.
     *
     * @return array<int, string> `.g7.html` 절대 경로 목록
     */
    private function layoutFiles(): array
    {
        $root = base_path('templates/_bundled/sirsoft-admin_basic/layouts');

        if (! is_dir($root)) {
            return [];
        }

        $found = [];
        $walker = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($walker as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.g7.html')) {
                $found[] = $entry->getPathname();
            }
        }

        return $found;
    }
}
