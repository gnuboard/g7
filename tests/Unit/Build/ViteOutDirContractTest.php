<?php

namespace Tests\Unit\Build;

use Tests\TestCase;

/**
 * 빌드 산출물 디렉토리 계약 테스트 (#122 B1).
 *
 * vite 의 `build.emptyOutDir` 기본값은 `true` 다 — 산출물 디렉토리를 통째로 비운다.
 * 그런데 그 디렉토리에는 vite 가 만들지 않는 **서빙 자산**이 함께 산다:
 * `public/build/core/` 3번들(폴백 없는 동기 classic 스크립트), `public/build/ext/{v}/`
 * 게시본(이미 배달된 HTML 의 immutable URL), 확장 `dist/vendor/`(동봉 제3자 자산).
 * 소실은 예외도 서버 로그도 남기지 않고 브라우저 404 로만 나타난다.
 *
 * 픽스처 기반 정적 검사가 판정식을 잠그고, 이 테스트는 **저장소의 실제 상태**를
 * 계약으로 고정한다 — git 추적 대상 vite config 전부가 `emptyOutDir` 을 명시 선언하고
 * 그 값이 `false` 여야 한다.
 */
class ViteOutDirContractTest extends TestCase
{
    /**
     * 모든 git 추적 vite config 가 `emptyOutDir: false` 를 명시 선언한다.
     *
     * @effects vite_configs_declare_empty_outdir_false
     */
    public function test_every_tracked_vite_config_declares_empty_outdir_false(): void
    {
        $configs = $this->trackedViteConfigs();

        $this->assertNotEmpty($configs, 'vite config 를 하나도 찾지 못했다 — 이 계약 검사가 공허하다');
        $this->assertContains(
            'vite.config.js',
            $configs,
            '루트 vite config 가 목록에 없다 — 열거가 저장소 실제 상태를 반영하지 못한다'
        );

        foreach ($configs as $relative) {
            $source = $this->stripComments((string) file_get_contents(base_path($relative)));

            $this->assertMatchesRegularExpression(
                '/\bemptyOutDir\s*:/',
                $source,
                "{$relative} 이 emptyOutDir 을 선언하지 않았다 — 기본값 true 로 산출물 디렉토리를 통째로 비운다"
            );

            preg_match_all('/\bemptyOutDir\s*:\s*([A-Za-z0-9_.]+)/', $source, $matches);

            foreach ($matches[1] as $value) {
                $this->assertSame(
                    'false',
                    $value,
                    "{$relative} 의 emptyOutDir 이 false 가 아니다 — 함께 사는 서빙 자산(코어 3번들 · 게시본 · 동봉 vendor)이 빌드마다 소실된다"
                );
            }
        }
    }

    /**
     * git 추적 대상 vite config 의 저장소 상대 경로 목록을 반환합니다.
     *
     * git 을 쓸 수 없는 환경에서는 추적 대상이 실제로 놓이는 위치(루트 · `_bundled`)를
     * 직접 훑는다 — 활성 확장 디렉토리 사본(git 미추적)은 계약 대상이 아니다.
     *
     * @return array<int, string> 상대 경로 (구분자 `/`)
     */
    private function trackedViteConfigs(): array
    {
        $output = [];
        $status = 1;

        @exec('git -C '.escapeshellarg(base_path()).' ls-files -- '.escapeshellarg('*vite.config*'), $output, $status);

        if ($status === 0 && $output !== []) {
            $tracked = array_values(array_filter(
                array_map(fn ($line) => trim(str_replace('\\', '/', (string) $line)), $output),
                fn ($line) => (bool) preg_match('#(^|/)vite\.config[^/]*\.(js|ts)$#', $line)
            ));

            if ($tracked !== []) {
                sort($tracked);

                return $tracked;
            }
        }

        $patterns = [
            base_path('vite.config*.js'),
            base_path('vite.config*.ts'),
            base_path('modules/_bundled/*/vite.config*.js'),
            base_path('modules/_bundled/*/vite.config*.ts'),
            base_path('plugins/_bundled/*/vite.config*.js'),
            base_path('plugins/_bundled/*/vite.config*.ts'),
            base_path('templates/_bundled/*/vite.config*.js'),
            base_path('templates/_bundled/*/vite.config*.ts'),
        ];

        $found = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                $found[] = ltrim(str_replace('\\', '/', substr($path, strlen(base_path()))), '/');
            }
        }

        sort($found);

        return $found;
    }

    /**
     * 주석을 제거합니다 — 문서 주석 안의 예시 표기가 선언으로 오인되지 않게 한다.
     *
     * @param  string  $source  원본 소스
     * @return string 주석이 제거된 소스
     */
    private function stripComments(string $source): string
    {
        $withoutBlocks = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

        return preg_replace('#//[^\n]*#', '', $withoutBlocks) ?? $withoutBlocks;
    }
}
