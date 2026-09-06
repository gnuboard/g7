<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 빌드 산출물 정리 Trait
 *
 * 확장이 구동에 필요한 제3자 자산을 `dist/vendor/` 에 동봉하면서, vite 의
 * `emptyOutDir: true` 를 그대로 둘 수 없게 됐다 — 매 빌드마다 동봉 자산이 삭제된다.
 * 각 확장의 vite config 를 `emptyOutDir: false` 로 바꾸는 대신, **정리 책임을 빌드
 * 커맨드로 옮긴다.** 확장마다 정리 규칙을 복제하면 한 곳만 빠져도 그 확장에서만
 * 해시 청크가 누적되고, 그것은 배포본이 커진 뒤에야 드러난다.
 *
 * 정리 범위는 종전 `emptyOutDir: true` 와 같다 — `dist/` 전체를 비우되 **보존 대상만
 * 남긴다.** "빌드가 만드는 것만 골라 지운다" 로 좁히면 소스에서 사라진 파일의 산출물이
 * `dist/` 에 stale 로 남는다.
 */
trait PrunesBuildOutput
{
    /**
     * 빌드 산출물 디렉토리에서 보존 대상을 제외한 전부를 삭제합니다.
     *
     * 감시(watch) 모드에서는 호출하지 않는다 — 개발 중 재빌드마다 지우면 브라우저가
     * 참조 중인 파일이 사라진다.
     *
     * **활성 디렉토리는 정리하지 않는다.** prune 은 빌드 *전에* 실행되므로, 웹이 서빙
     * 중인 `dist/` 를 비우면 prune~빌드 완료 구간 전체가 서빙 공백이 된다(빌드가 실패하면
     * 빈 채로 남는다). 확장 개발은 `_bundled` 에서 수행하고 활성 반영은 `{type}:update`
     * 가 담당하므로, 활성 경로 빌드는 예외적 경로다 — 그 경우 stale 산출물이 누적되는
     * 것을 감수하고 서빙 연속성을 택한다. 외부 확장처럼 `_bundled` 가 없어 활성 빌드가
     * 유일한 경로인 경우에도 사이트가 끊기지 않는다.
     *
     * @param  string  $buildPath  확장 루트 경로 (`dist/` 의 부모)
     * @param  array<int, string>  $preserve  삭제하지 않을 최상위 항목명
     * @return array<int, string> 삭제한 최상위 항목명 목록
     */
    private function pruneBuildOutput(string $buildPath, array $preserve = ['vendor']): array
    {
        $distPath = rtrim($buildPath, '/\\').DIRECTORY_SEPARATOR.'dist';

        if (! File::isDirectory($distPath)) {
            return [];
        }

        if (! $this->isBundledSourcePath($buildPath)) {
            $this->warn(
                '   ⚠️  활성 디렉토리 빌드 — 이전 산출물을 정리하지 않습니다 '
                .'(정리하면 빌드 완료까지 서빙이 끊깁니다). stale 산출물이 누적될 수 있으니 '
                .'개발은 _bundled 에서 하고 활성 반영은 update 커맨드로 하세요.'
            );

            return [];
        }

        $removed = [];

        foreach (new \FilesystemIterator($distPath, \FilesystemIterator::SKIP_DOTS) as $item) {
            $name = $item->getBasename();

            if (in_array($name, $preserve, true)) {
                continue;
            }

            $deleted = $item->isDir()
                ? File::deleteDirectory($item->getPathname())
                : File::delete($item->getPathname());

            if ($deleted) {
                $removed[] = $name;

                continue;
            }

            // 삭제 실패는 빌드를 막을 이유가 못 된다 — vite 가 같은 경로를 덮어쓴다.
            // 다만 조용히 넘기면 stale 산출물이 남은 것을 알 수 없으므로 기록한다.
            Log::warning('빌드 산출물 정리 실패 (다음 빌드에서 재시도)', [
                'path' => $item->getPathname(),
            ]);
        }

        return $removed;
    }

    /**
     * 빌드 경로가 소스 디렉토리(`_bundled` / `_pending`)인지 판정합니다.
     *
     * 이 둘은 웹이 서빙하지 않는 소스 보관소라 비워도 서빙 공백이 없다. 그 밖의 경로는
     * 활성 디렉토리(= 서빙 중)로 본다 — 판정을 뒤집어 두면(활성 목록을 열거하면) 새로운
     * 배치가 생길 때마다 조용히 활성 경로가 정리 대상이 된다.
     *
     * @param  string  $buildPath  확장 루트 경로
     * @return bool 소스 디렉토리 여부
     */
    private function isBundledSourcePath(string $buildPath): bool
    {
        $normalized = str_replace('\\', '/', $buildPath);

        return str_contains($normalized, '/_bundled/') || str_contains($normalized, '/_pending/');
    }
}
