<?php

namespace Tests\Feature\View;

use Tests\TestCase;

/**
 * 서버가 렌더하는 blade 화면의 링크·자산 주소는 **마운트 접두사를 갖는다**.
 *
 * 사이트는 문서 루트가 아니라 하위 폴더에 설치될 수 있다(`https://host/g7/`). 그 배치에서
 * 날 `href="/install"` 은 사이트 밖(`https://host/install`)을 가리키고, 증상은 오류가 아니라
 * **버튼이 조용히 무반응**인 것이다 — 문서 루트에 다른 사이트가 있으면 그쪽 응답을 받는다.
 *
 * 이 계약이 왜 필요한가: 23차가 `dev-dashboard.blade.php` 의 그 링크 하나를
 * `AssetUrl::sitePath()` 로 고쳤는데, **되돌려도 아무 검사가 울지 않았다** — 221개 감사 룰
 * 전수를 그 파일에 돌려도 위반 0 이었다(24차 G3 라운드 R24-C M10 실측). 고친 자리가
 * 무게이트로 남으면 다음 편집이 그대로 되돌린다.
 *
 * 판정할 수 없는 형태(변수·표현식)는 대상이 아니다 — 이 검사는 **리터럴**만 본다.
 * 의도적으로 루트 절대 경로여야 하는 자리는 그 줄 바로 위에 `{{-- base-path:allow <사유> --}}`
 * 를 남긴다.
 */
class BladeRootRelativeLinkContractTest extends TestCase
{
    /**
     * 검사 대상 속성 — 브라우저가 그대로 요청하거나 이동하는 자리.
     */
    private const ATTRIBUTES = ['href', 'src', 'action'];

    /**
     * 루트 절대 경로여도 무방한 접두사.
     *
     * `//` 는 프로토콜 상대라 이 축이 아니고(외부 origin), 프래그먼트·데이터 URI 는 요청이
     * 아니다. `/build/` 는 Vite 산출물로 `@vite` 지시자가 접두사를 붙인다.
     */
    private const ALLOWED_PREFIXES = ['//', '/build/'];

    /**
     * 모든 blade 뷰의 링크 속성에 허용 접두사 밖의 루트 절대 경로(`/...`)가 하드코딩되지 않았는지 전수 확인한다.
     *
     * @scenario base_path=subpath, fetch_origin=core_asset, navigation_direction=request
     *
     * @effects blade_views_do_not_emit_root_relative_links
     *
     * @return void 반환값 없음
     */
    public function test_blade_views_do_not_hardcode_root_relative_urls(): void
    {
        $files = $this->bladeFiles();

        $this->assertNotEmpty(
            $files,
            'blade 모집단이 비었다 — 비면 아래 단언이 한 번도 돌지 않는다'
        );

        $attributes = implode('|', self::ATTRIBUTES);
        $offenders = [];

        foreach ($files as $file) {
            $lines = preg_split('~\r\n|\r|\n~', (string) file_get_contents($file)) ?: [];

            foreach ($lines as $index => $line) {
                if (! preg_match_all('/\b(?:'.$attributes.')\s*=\s*"(\/[^"{}]*)"/', $line, $matches)) {
                    continue;
                }

                // 바로 윗줄의 면제 표식은 의도 선언이다.
                $previous = $lines[$index - 1] ?? '';
                if (str_contains($previous, 'base-path:allow')) {
                    continue;
                }

                foreach ($matches[1] as $url) {
                    foreach (self::ALLOWED_PREFIXES as $allowed) {
                        if (str_starts_with($url, $allowed)) {
                            continue 2;
                        }
                    }

                    $offenders[] = sprintf(
                        '%s:%d — "%s" 가 루트 상대다. `{{ \App\Support\AssetUrl::sitePath(\'%s\') }}` 를 쓰거나, '
                            .'의도된 것이면 윗줄에 `{{-- base-path:allow <사유> --}}` 를 남길 것',
                        str_replace(base_path().DIRECTORY_SEPARATOR, '', $file),
                        $index + 1,
                        $url,
                        $url,
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "하위 폴더 배치에서 사이트 밖을 가리키는 링크가 있다:\n".implode("\n", $offenders)
        );
    }

    /**
     * 검사 대상 blade 파일을 **도출**한다 (손으로 적은 목록이 아니다).
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function bladeFiles(): array
    {
        $roots = [
            base_path('resources/views'),
        ];

        $files = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
