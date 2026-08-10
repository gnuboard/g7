<?php

namespace Tests\Unit\Seo;

use Tests\TestCase;

/**
 * 봇 대상 레이아웃이 화면에 쓰는 데이터소스를 빠짐없이 선언했는지 감시합니다.
 *
 * 배경:
 * 봇 렌더링은 `meta.seo.data_sources` 에 적힌 id 만 미리 조회한다. 화면이 쓰는
 * 데이터소스가 목록에서 빠지면 그 값은 늘 비어 있고, 그 값을 조건으로 삼는 블록이
 * 통째로 사라진다. 실제로 `users/posts` 레이아웃이 목록 전체를 `userPosts` 로
 * 감싸 두고 그 id 를 선언하지 않아, 봇에게는 머리말과 꼬리말만 있는 빈 화면이
 * 나갔다.
 *
 * 봇에 노출할 필요가 없어 일부러 뺀 데이터소스는 아래 EXEMPT 목록에 사유와 함께
 * 등록한다. 목록에 없는 미선언 사용처가 나타나면 실패한다.
 */
class SeoLayoutDataSourceDeclarationTest extends TestCase
{
    /**
     * 검사 대상 템플릿의 레이아웃 디렉토리 (프로젝트 루트 기준 상대 경로).
     */
    private const LAYOUT_ROOT = 'templates/_bundled/sirsoft-basic/layouts';

    /**
     * 의도적으로 봇 조회에서 제외한 데이터소스 — `레이아웃::데이터소스 id => 사유`.
     */
    private const EXEMPT = [
        'shop/index::recentProducts' => '브라우저에 저장된 열람 이력이 필요해 봇에게는 늘 빈 값',
        'shop/show::productDownloadableCoupons' => '로그인 사용자 전용 쿠폰이라 봇에게 의미 없음',
    ];

    /**
     * 봇 대상 레이아웃은 화면이 참조하는 데이터소스를 모두 선언해야 합니다.
     *
     * @effects every_used_data_source_is_declared
     */
    public function test_seo_layouts_declare_every_data_source_they_use(): void
    {
        $root = base_path(self::LAYOUT_ROOT);
        $this->assertDirectoryExists($root);

        $violations = [];
        $checked = 0;

        foreach ($this->layoutFiles($root) as $file) {
            $layout = json_decode((string) file_get_contents($file), true);
            if (! is_array($layout)) {
                continue;
            }

            $seo = $layout['meta']['seo'] ?? null;
            if (! is_array($seo) || ($seo['enabled'] ?? false) !== true) {
                continue;
            }

            $checked++;
            $layoutName = $this->layoutName($root, $file);
            $declared = $seo['data_sources'] ?? [];
            $body = $this->layoutBodyText($root, $file);

            foreach ($layout['data_sources'] ?? [] as $dataSource) {
                $id = $dataSource['id'] ?? null;
                if (! is_string($id) || $id === '' || in_array($id, $declared, true)) {
                    continue;
                }

                if (isset(self::EXEMPT[$layoutName.'::'.$id])) {
                    continue;
                }

                if ($this->isReferenced($id, $body)) {
                    $violations[] = "{$layoutName}: '{$id}' 를 화면에서 참조하지만 meta.seo.data_sources 에 없음";
                }
            }
        }

        $this->assertGreaterThan(0, $checked, '봇 대상 레이아웃을 하나도 찾지 못했습니다 — 스캔 경로를 확인하세요.');
        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * 면제 목록에 죽은 항목이 남아 있지 않은지 확인합니다.
     *
     * @effects intentional_exclusions_are_documented
     */
    public function test_exempt_entries_still_exist(): void
    {
        $root = base_path(self::LAYOUT_ROOT);

        foreach (array_keys(self::EXEMPT) as $entry) {
            [$layoutName, $id] = explode('::', $entry, 2);
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $layoutName).'.json';

            $this->assertFileExists($path, "면제 항목의 레이아웃이 없습니다: {$entry}");

            $layout = json_decode((string) file_get_contents($path), true);
            $ids = array_column($layout['data_sources'] ?? [], 'id');

            $this->assertContains($id, $ids, "면제 항목의 데이터소스가 없습니다: {$entry}");
        }
    }

    /**
     * 레이아웃 JSON 파일 목록을 반환합니다 (admin 하위 제외 — 봇 대상 아님).
     *
     * @param  string  $root  레이아웃 루트 절대 경로
     * @return array<int, string> 파일 절대 경로 목록
     */
    private function layoutFiles(string $root): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains(str_replace('\\', '/', $path), '/admin/')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * 레이아웃 파일 경로를 레이아웃명으로 변환합니다.
     *
     * @param  string  $root  레이아웃 루트 절대 경로
     * @param  string  $file  레이아웃 파일 절대 경로
     * @return string 레이아웃명 (예: users/posts)
     */
    private function layoutName(string $root, string $file): string
    {
        $relative = ltrim(str_replace('\\', '/', substr($file, strlen($root))), '/');

        return preg_replace('/\.json$/', '', $relative) ?? $relative;
    }

    /**
     * 레이아웃 본문 + 참조 partial 본문을 하나의 문자열로 모읍니다.
     *
     * 화면 구성이 partial 로 쪼개져 있어 자기 파일만 봐서는 참조 여부를 알 수 없습니다.
     *
     * @param  string  $root  레이아웃 루트 절대 경로
     * @param  string  $file  레이아웃 파일 절대 경로
     * @param  array<string, bool>  $visited  순환 방지용 방문 기록
     * @return string 합쳐진 JSON 문자열
     */
    private function layoutBodyText(string $root, string $file, array &$visited = []): string
    {
        $normalized = str_replace('\\', '/', $file);
        if (isset($visited[$normalized]) || ! is_file($file)) {
            return '';
        }
        $visited[$normalized] = true;

        $raw = (string) file_get_contents($file);
        $text = $raw;

        preg_match_all('/"partial"\s*:\s*"([^"]+)"/', $raw, $matches);
        foreach ($matches[1] ?? [] as $partial) {
            $text .= $this->layoutBodyText($root, $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $partial), $visited);
        }

        return $text;
    }

    /**
     * 데이터소스 id 가 표현식/조건에서 참조되는지 판정합니다.
     *
     * `data_sources` 선언부의 `"id": "..."` 는 참조가 아니므로 제외하고, 표현식 안에서
     * 식별자로 등장하는 경우만 참조로 봅니다.
     *
     * @param  string  $id  데이터소스 id
     * @param  string  $body  레이아웃 + partial 본문
     * @return bool 참조하면 true
     */
    private function isReferenced(string $id, string $body): bool
    {
        $withoutDeclarations = preg_replace('/"id"\s*:\s*"'.preg_quote($id, '/').'"/', '', $body) ?? $body;

        return preg_match('/[{(\s|!?,]'.preg_quote($id, '/').'[?.\s)]/', $withoutDeclarations) === 1;
    }
}
