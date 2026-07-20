<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Support\Facades\File;

/**
 * 전량 왕복 하네스(S9 DoD) — 번들 확장·템플릿의 모든 레이아웃 `.json` 이 왕복함을 기계 확인한다.
 *
 * 계획서 DoD "왕복 불일치 0" 의 회귀 봉인. `layout:compile --all --roundtrip` 과 같은 검사를
 * PHPUnit 으로 상시 돌려, 역변환기·직렬화기·컴파일러 어느 층의 회귀도 이 한 테스트가 잡는다.
 *
 * 표기 탈출구(`<json>`)로도 왕복하지 못하는 건이 하나라도 있으면 실패한다 — deep-equal 이 유일한 게이트다.
 */
class LayoutMarkupRoundTripCorpusTest extends LayoutMarkupTestCase
{
    /**
     * @scenario compile_unit=decompile,source_health=valid
     *
     * @effects every_bundled_layout_round_trips_through_decompile_and_compile
     */
    public function test_every_bundled_layout_round_trips(): void
    {
        $resolver = new MarkupCompileContextResolver;
        $harness = new LayoutMarkupRoundTrip;
        $files = $this->scanLayoutFiles();

        $this->assertNotEmpty($files, '번들 레이아웃을 하나도 찾지 못했습니다.');

        $failures = [];

        foreach ($files as $file) {
            $registry = $resolver->registryFor($file);

            if ($registry === null) {
                continue;
            }

            $json = json_decode(File::get($file), true);

            if (! is_array($json)) {
                continue;
            }

            $result = $harness->verify($json, $registry, $file);

            if (! $result['ok']) {
                $failures[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).($result['error'] !== null ? " ({$result['error']})" : '');
            }
        }

        $this->assertSame([], $failures, '왕복 불일치 레이아웃:'.PHP_EOL.implode(PHP_EOL, array_slice($failures, 0, 20)));
    }

    /**
     * 이관 완료된 `.g7.html` 의 **명시 목록**이다 (계획서 §201·§207 "확장 목록형").
     *
     * 디렉토리 스캔만으로 게이트를 걸면 이관 파일이 사라져도 green 이 된다 — 회귀 감지력이 없다.
     * 그래서 W1 산출 16건(15 clean + S7 데모)을 목록으로 못박고, 스캔 결과가 이 목록과 정확히
     * 일치하는지까지 단언한다. 이후 웨이브는 이 목록을 확장한다.
     *
     * @var array<int, string>
     */
    private const MIGRATED_MARKUP_FILES = [
        // S7 데모 (W1 이전 완료본)
        'modules/_bundled/gnuboard7-hello_module/resources/layouts/admin/admin_markup_demo.g7.html',
        // W1 — hello admin 템플릿 (7)
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/admin_dashboard.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/401.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/403.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/404.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/500.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/503.g7.html',
        'templates/_bundled/gnuboard7-hello_admin_template/layouts/errors/maintenance.g7.html',
        // W1 — hello user 템플릿 (8)
        'templates/_bundled/gnuboard7-hello_user_template/layouts/_user_base.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/home.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/401.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/403.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/404.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/500.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/503.g7.html',
        'templates/_bundled/gnuboard7-hello_user_template/layouts/errors/maintenance.g7.html',
    ];

    /**
     * 이관된 `.g7.html` 이 원본과 100% 동등하고 escape-0 임을 봉인합니다 (S10 W1 DoD).
     *
     * 각 `.g7.html` 을 설치 시점과 같은 파사드로 컴파일해 짝 `.json` 과 정규화 후 deep-equal 을 보고,
     * 저작 파일에 `<json>` 탈출구가 하나도 없음을 확인한다. 기계 이관이 원본 기능을 100% 보존한다는
     * (D38) 계약의 회귀 게이트다. 짝 `.json` 부재·레지스트리 미해석은 **skip 이 아니라 실패**다 —
     * 조용히 건너뛰면 이관이 깨져도 green 이 된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects every_migrated_markup_file_matches_its_json_and_is_escape_free
     */
    public function test_every_migrated_markup_file_matches_its_json_and_is_escape_free(): void
    {
        $resolver = new MarkupCompileContextResolver;

        // 스캔 결과가 명시 목록과 정확히 일치해야 한다 (누락도 잉여도 회귀).
        $scanned = array_map(
            fn (string $path): string => str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)),
            $this->scanMarkupFiles(),
        );
        sort($scanned);

        $expected = self::MIGRATED_MARKUP_FILES;
        sort($expected);

        $this->assertSame($expected, $scanned, '이관된 .g7.html 집합이 명시 목록과 다릅니다 (누락 또는 미등록 신규 이관).');

        $failures = [];

        foreach (self::MIGRATED_MARKUP_FILES as $relative) {
            $markupFile = base_path($relative);

            if (! File::exists($markupFile)) {
                $failures[] = "{$relative} — 이관 저작 파일이 없습니다";

                continue;
            }

            $jsonFile = preg_replace('/\.g7\.html$/i', '.json', $markupFile);
            $source = File::get($markupFile);

            if (str_contains($source, '<json')) {
                $failures[] = "{$relative} — <json> 탈출구가 남아 있음 (escape≠0)";

                continue;
            }

            if (! File::exists($jsonFile)) {
                $failures[] = "{$relative} — 짝 원본 .json 이 없습니다 (병존 규약 위반)";

                continue;
            }

            $registry = $resolver->registryFor($markupFile);

            if ($registry === null) {
                $failures[] = "{$relative} — 대상 템플릿 레지스트리를 해석하지 못했습니다";

                continue;
            }

            $original = json_decode(File::get($jsonFile), true);

            try {
                $compiled = (new LayoutMarkupCompiler($registry))->compile(
                    $source,
                    $markupFile,
                    $resolver->contextFor($markupFile),
                )->json;
            } catch (\Throwable $e) {
                $failures[] = "{$relative} — 컴파일 오류: {$e->getMessage()}";

                continue;
            }

            $a = LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize($original)));
            $b = LayoutMarkupRoundTrip::encode(LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize($compiled)));

            if ($a !== $b) {
                $failures[] = "{$relative} — 컴파일 산출이 원본 .json 과 다름";
            }
        }

        $this->assertSame([], $failures, '이관 동등성·escape-0 위반:'.PHP_EOL.implode(PHP_EOL, array_slice($failures, 0, 20)));
    }

    /**
     * 번들 확장·템플릿에서 모든 레이아웃 `.json` 을 스캔합니다.
     *
     * @return array<int, string> `.json` 절대 경로 목록
     */
    private function scanLayoutFiles(): array
    {
        $globs = [
            base_path('templates/_bundled/*/layouts'),
            base_path('modules/_bundled/*/resources/layouts'),
            base_path('plugins/_bundled/*/resources/layouts'),
        ];

        $files = [];

        foreach ($globs as $glob) {
            foreach (glob($glob, GLOB_ONLYDIR) ?: [] as $root) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                );

                foreach ($iterator as $entry) {
                    if ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.json')) {
                        $files[] = $entry->getPathname();
                    }
                }
            }
        }

        return $files;
    }

    /**
     * 번들 확장·템플릿에서 모든 이관 저작 파일 `.g7.html` 을 스캔합니다.
     *
     * @return array<int, string> `.g7.html` 절대 경로 목록
     */
    private function scanMarkupFiles(): array
    {
        $globs = [
            base_path('templates/_bundled/*/layouts'),
            base_path('modules/_bundled/*/resources/layouts'),
            base_path('plugins/_bundled/*/resources/layouts'),
        ];

        $files = [];

        foreach ($globs as $glob) {
            foreach (glob($glob, GLOB_ONLYDIR) ?: [] as $root) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                );

                foreach ($iterator as $entry) {
                    if ($entry->isFile() && str_ends_with(strtolower($entry->getFilename()), '.g7.html')) {
                        $files[] = $entry->getPathname();
                    }
                }
            }
        }

        return $files;
    }
}
