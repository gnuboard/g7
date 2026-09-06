<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * 출하 산출물에 실린 HTML 정화 라이브러리 버전 계약 (#126).
 *
 * 이 저장소는 확장의 `dist/` 를 Git 으로 추적해 그대로 배포한다. 그래서 브라우저가 받는
 * 정화기의 버전은 잠금파일이 아니라 **커밋된 산출물**이 정한다. 잠금을 올려도 재빌드를
 * 빠뜨리면 취약한 정화기가 계속 나가고, 그 상태는 예외도 로그도 남기지 않는다 — 취약한
 * 정화기가 정상 동작하는 것이 유일한 증상이다.
 *
 * 모집단은 저장소 스캔으로 도출한다(확장명 하드코딩 금지). 새 템플릿·모듈·플러그인이
 * 추가되면 그 산출물도 자동으로 이 계약에 편입된다.
 *
 * `public/build/ext/` 는 Git 추적 대상이 아닌 게시본이므로 보지 않는다. 그 축은 게시·정리
 * 커맨드가 담당한다.
 *
 * DB 를 쓰지 않으므로 `RefreshDatabase` 를 붙이지 않는다.
 */
class ShippedSanitizerVersionTest extends TestCase
{
    /**
     * 자사 산출물이 실어야 하는 DOMPurify 최소 버전.
     *
     * 공개 권고의 영향 범위는 `3.4.12 이하` 이므로 안전 하한 자체는 3.4.13 이다. 여기서는
     * 그보다 한 칸 위인 **실제 배포판 3.4.14** 를 요구한다 — 자사 번들은 잠금이 정하므로
     * 배포된 판을 그대로 쓰면 되고, 하한에 딱 붙여 두면 다음 권고가 나올 때 이 값이
     * 조용히 미달이 된다.
     */
    private const MINIMUM_SAFE_DOMPURIFY = '3.4.14';

    /**
     * 제3자 동봉 자산의 내장 정화기 하한.
     *
     * 동봉 자산은 그 라이브러리가 미리 빌드해 배포한 번들이라 우리가 정화기 버전을 고를 수
     * 없다. 상향할 수 있는 최신본을 담되, 그보다 내려가는 것(구 버전 재유입 · 라이브러리
     * 다운그레이드)은 회귀이므로 막는다. 상한이 아니라 바닥이다.
     *
     * 항목 추가 시 사유를 함께 적는다 — 근거 없는 예외는 이 계약을 무의미하게 만든다.
     *
     * @var array<string, array{floor: string, reason: string}>
     */
    private const VENDORED_FLOORS = [
        'monaco-editor' => [
            'floor' => '3.4.8',
            'reason' => 'monaco 가 자기 번들에 DOMPurify 를 인라인한다. 배포된 최신본(0.56.0)이 담은 것이 3.4.8 이라 그 위로 올릴 방법이 없다. 상류가 새 정화기를 담은 판을 내면 floor 를 함께 올린다.',
        ],
    ];

    /**
     * 번들에서 DOMPurify 버전을 짚어내는 패턴.
     *
     * `.version="…"` 만 보면 같은 번들에 실린 다른 라이브러리(이미지 압축기 등)의 버전까지
     * 걸린다. DOMPurify 는 버전 직후에 `removed` 배열을 초기화하므로 그 인접 쌍을 앵커로
     * 삼는다. 상류가 이 형태를 바꾸면 수집 결과가 비어 검사 자체가 실패한다 — 조용히
     * 무력화되지 않는다.
     */
    private const DOMPURIFY_VERSION_PATTERN = '/\.version\s*=\s*"(\d+\.\d+\.\d+)"\s*[,;]\s*[\w$.]+\.removed\s*=\s*\[\s*\]/';

    /** 스캔에서 제외할 경로 조각 */
    private const EXCLUDED_SEGMENTS = [
        'node_modules',
        '_pending',
    ];

    /**
     * 커밋된 산출물의 DOMPurify 버전이 모두 안전 범위인지 확인합니다.
     *
     * @return void
     */
    public function test_shipped_bundles_carry_a_safe_dompurify(): void
    {
        $findings = $this->scanShippedDompurifyVersions();

        $this->assertNotSame(
            [],
            $findings,
            '커밋된 산출물에서 DOMPurify 버전 문자열을 하나도 찾지 못했다. '
            .'스캔 경로나 버전 표기 형태가 바뀌었을 수 있다 — 검사가 조용히 무력화된 상태다.'
        );

        $violations = [];

        foreach ($findings as $finding) {
            $vendorLibrary = $finding['vendor_library'];

            if ($vendorLibrary === null) {
                if (version_compare($finding['version'], self::MINIMUM_SAFE_DOMPURIFY, '<')) {
                    $violations[] = sprintf(
                        '%s → DOMPurify %s (최소 %s). 잠금 갱신 후 재빌드가 누락됐을 수 있다.',
                        $finding['path'],
                        $finding['version'],
                        self::MINIMUM_SAFE_DOMPURIFY
                    );
                }

                continue;
            }

            if (! array_key_exists($vendorLibrary, self::VENDORED_FLOORS)) {
                $violations[] = sprintf(
                    '%s → 동봉 자산 "%s" 에 기록된 하한이 없다. VENDORED_FLOORS 에 사유와 함께 등록하라.',
                    $finding['path'],
                    $vendorLibrary
                );

                continue;
            }

            $floor = self::VENDORED_FLOORS[$vendorLibrary]['floor'];

            if (version_compare($finding['version'], $floor, '<')) {
                $violations[] = sprintf(
                    '%s → 동봉 자산 "%s" 의 DOMPurify %s 는 기록된 하한 %s 보다 낮다 (구 버전 재유입).',
                    $finding['path'],
                    $vendorLibrary,
                    $finding['version'],
                    $floor
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "출하 산출물의 정화기 버전 위반:\n- ".implode("\n- ", $violations)
        );
    }

    /**
     * 기록된 동봉 하한 예외에 사유가 붙어 있는지 확인합니다.
     *
     * 하한이 안전 버전에 도달하면 그 예외 항목은 지운다. 근거 없이 남은 예외는 이 계약을
     * 무의미하게 만든다.
     *
     * @return void
     */
    public function test_vendored_floor_exceptions_are_documented(): void
    {
        foreach (self::VENDORED_FLOORS as $library => $entry) {
            $this->assertNotSame(
                '',
                trim($entry['reason']),
                sprintf('동봉 자산 "%s" 의 하한 예외에 사유가 없다.', $library)
            );
        }
    }

    /**
     * 커밋된 산출물에서 DOMPurify 버전 문자열을 수집합니다.
     *
     * @return array<int, array{path: string, version: string, vendor_library: string|null}>
     */
    private function scanShippedDompurifyVersions(): array
    {
        $findings = [];

        foreach ($this->shippedDistDirectories() as $distDirectory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($distDirectory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'js') {
                    continue;
                }

                $path = $file->getPathname();

                if ($this->isExcluded($path)) {
                    continue;
                }

                $contents = @file_get_contents($path);

                if ($contents === false) {
                    continue;
                }

                if (! preg_match_all(self::DOMPURIFY_VERSION_PATTERN, $contents, $matches)) {
                    continue;
                }

                foreach (array_unique($matches[1]) as $version) {
                    $findings[] = [
                        'path' => $this->relativePath($path),
                        'version' => $version,
                        'vendor_library' => $this->vendorLibraryOf($path),
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * 스캔 대상 `dist` 디렉토리를 저장소 구조에서 도출합니다.
     *
     * @return array<int, string>
     */
    private function shippedDistDirectories(): array
    {
        $roots = [
            base_path('public/build/core'),
        ];

        foreach (['templates', 'modules', 'plugins'] as $kind) {
            foreach ((array) glob(base_path($kind.'/*/dist'), GLOB_ONLYDIR) as $dir) {
                $roots[] = $dir;
            }

            foreach ((array) glob(base_path($kind.'/_bundled/*/dist'), GLOB_ONLYDIR) as $dir) {
                $roots[] = $dir;
            }
        }

        return array_values(array_filter($roots, static fn (string $dir): bool => is_dir($dir)));
    }

    /**
     * 경로가 동봉 제3자 자산이면 그 라이브러리 이름을 돌려줍니다.
     *
     * @param  string  $path  파일 절대 경로
     * @return string|null 동봉 자산이 아니면 null
     */
    private function vendorLibraryOf(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);

        if (! preg_match('#/dist/vendor/([^/]+)/#', $normalized, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * 스캔에서 제외할 경로인지 판정합니다.
     *
     * @param  string  $path  파일 절대 경로
     * @return bool
     */
    private function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_SEGMENTS as $segment) {
            if (str_contains($path, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 보고용 저장소 상대 경로로 바꿉니다.
     *
     * @param  string  $path  파일 절대 경로
     * @return string
     */
    private function relativePath(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_replace('\\', '/', str_starts_with($path, $base) ? substr($path, strlen($base)) : $path);
    }
}
