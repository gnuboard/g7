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
}
