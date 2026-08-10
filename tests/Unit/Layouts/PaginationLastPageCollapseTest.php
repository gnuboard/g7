<?php

namespace Tests\Unit\Layouts;

use Tests\TestCase;

/**
 * 상한 목록의 `last_page: null` 을 화면이 접지 않는지 전 영역 회귀 가드 (#519).
 *
 * 총 건수를 상한까지만 센 목록은 마지막 페이지를 계산할 수 없어 `last_page: null` 을 내보낸다.
 * 화면이 그 값을 1 로 채우면 두 가지가 일어난다.
 *
 *   - `?? 1` / `|| 1` → "1페이지뿐" 이라고 잘못 말한다
 *   - `if: last_page > 1` → 페이저가 통째로 사라져 **뒤쪽 페이지로 갈 방법 자체가 없어진다**
 *
 * 둘 다 예외도 404 도 내지 않는다. 화면은 정상으로 보이고 기능만 없다.
 *
 * 템플릿별 가드는 각 템플릿 스위트에 이미 있지만 자기 `layouts/` 디렉토리만 훑는다.
 * 모듈·플러그인이 소유한 관리자 레이아웃은 어느 스위트에도 잡히지 않아 33 지점이 남아
 * 있었고, 그중 주문·신고·페이지 관리자 목록은 저장소가 실제로 상한을 적용한 목록이었다.
 * 이 테스트는 레이아웃을 소유한 **모든** 디렉토리를 훑어 그 사각을 없앤다.
 *
 * 개별 파일을 열거하지 않는 이유는 다음에 추가되는 레이아웃이 또 빠지기 때문이다.
 */
class PaginationLastPageCollapseTest extends TestCase
{
    /**
     * `last_page` 를 1 로 채우는 형태
     */
    private const COLLAPSE_PATTERN = '/last_page\s*(?:\?\?|\|\|)\s*1(?![0-9])/';

    /**
     * 레이아웃을 소유하는 디렉토리 전부
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function layoutRoots(): array
    {
        return [
            base_path('resources/layouts'),
            base_path('templates/_bundled'),
            base_path('modules/_bundled'),
            base_path('plugins/_bundled'),
        ];
    }

    /**
     * 레이아웃 JSON 파일을 모읍니다.
     *
     * `_bundled` 밑에서는 `layouts/` 경로에 든 것만 본다 (컴포넌트 매니페스트·언어 파일 제외).
     *
     * @return array<int, string> 파일 경로 목록
     */
    private function collectLayoutFiles(): array
    {
        $files = [];

        foreach ($this->layoutRoots() as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                if (! str_contains($path, '/layouts/')) {
                    continue;
                }

                if (str_contains($path, '/node_modules/') || str_contains($path, '/dist/')) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 한 파일의 위반 줄을 찾습니다.
     *
     * @param  string  $path  레이아웃 경로
     * @return array<int, string> "경로:줄 내용" 목록
     */
    private function violationsIn(string $path): array
    {
        $violations = [];
        $lines = explode("\n", (string) file_get_contents($path));
        $relative = str_replace(str_replace('\\', '/', base_path()).'/', '', $path);

        foreach ($lines as $index => $line) {
            if (str_contains($line, 'audit:allow layout-last-page-null-collapse')) {
                continue;
            }

            if (preg_match(self::COLLAPSE_PATTERN, $line) === 1) {
                $violations[] = $relative.':'.($index + 1).'  '.trim($line);

                continue;
            }

            // 페이저 노출 조건이 last_page 하나에만 의존하는 형태
            if (str_contains($line, '"if"')
                && str_contains($line, 'last_page')
                && ! str_contains($line, 'has_more_pages')
                && ! str_contains($line, 'current_page')) {
                $violations[] = $relative.':'.($index + 1).'  '.trim($line);
            }
        }

        return $violations;
    }

    /**
     * 스캔 모집단이 비어 있지 않아야 한다 — 0 건을 훑고 통과하면 이 가드는 아무것도 지키지 않는다.
     */
    public function test_레이아웃_스캔_모집단이_비어있지_않다(): void
    {
        $files = $this->collectLayoutFiles();

        $this->assertGreaterThan(
            300,
            count($files),
            '레이아웃 스캔 대상이 비정상적으로 적다. 경로 규칙이 바뀌었는지 확인해야 한다.'
        );
    }

    /**
     * 어떤 레이아웃도 `last_page` 를 1 로 접지 않는다.
     */
    public function test_어떤_레이아웃도_last_page를_1로_접지_않는다(): void
    {
        $violations = [];

        foreach ($this->collectLayoutFiles() as $path) {
            $violations = array_merge($violations, $this->violationsIn($path));
        }

        $this->assertSame(
            [],
            $violations,
            "상한 목록의 last_page(null) 를 1 로 접는 레이아웃이 있다:\n".implode("\n", $violations)
        );
    }

    /**
     * 합성 표본이 실제로 red 가 되는지 — 판정기가 살아 있음을 확인한다.
     */
    public function test_판정기가_합성_위반을_실제로_잡는다(): void
    {
        $sample = base_path('storage/framework/testing/pagination_collapse_sample.json');
        @mkdir(dirname($sample), 0o775, true);

        file_put_contents($sample, implode("\n", [
            '{',
            '  "props": {',
            '    "totalPages": "{{items?.data?.pagination?.last_page ?? 1}}"',
            '  },',
            '  "if": "{{items?.data?.pagination?.last_page > 1}}"',
            '}',
        ]));

        try {
            $this->assertCount(2, $this->violationsIn($sample));
        } finally {
            @unlink($sample);
        }
    }
}
