<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Support\Facades\File;

/**
 * 전량 왕복 하네스(S9 DoD) — 번들 확장·템플릿의 모든 레이아웃 `.json` 이 왕복함을 기계 확인한다.
 *
 * 계획서 DoD "왕복 불일치 0" 의 회귀 봉인. `layout:decompile --all --roundtrip` 과 같은 검사를
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
}
