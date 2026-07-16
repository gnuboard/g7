<?php

namespace Tests\Unit\Extension;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 소스맵 산출물 재유입 회귀 가드
 *
 * 소스맵(.map)에는 원본 TS/TSX 전문(sourcesContent)이 담긴다. 저장소에 유입되면
 * 그대로 공개 배포판(release)에 실려 코드가 노출된다.
 *
 * 방어선은 두 겹이다:
 *   1) 빌드 단계 — `--production` 이 G7_BUILD_SOURCEMAP=0 을 주입해 생성 자체를 차단
 *   2) 저장소 단계 — .gitignore 의 전역 `*.map` 규칙으로 유입 차단
 *
 * 이 테스트는 (2)가 실효적인지, 그리고 추적 중인 산출 JS 에 맵 참조 주석이
 * 남아 있지 않은지를 고정한다. 새 확장이 추가될 때 조용히 누락되는 것을 막는다.
 */
class NoSourcemapArtifactsTest extends TestCase
{
    /**
     * git 추적 파일 중 .map 이 하나도 없는지 테스트
     */
    public function test_no_sourcemap_files_are_tracked_by_git(): void
    {
        $tracked = $this->git(['ls-files', '*.map']);

        $this->assertSame(
            [],
            $tracked,
            "소스맵이 git 에 추적되고 있습니다. 원본 코드가 배포판에 노출됩니다:\n  ".
            implode("\n  ", $tracked)
        );
    }

    /**
     * .gitignore 가 소스맵을 실제로 무시하는지 테스트
     *
     * 규칙이 존재해도 다른 규칙에 의해 무력화될 수 있으므로 git 에 직접 판정시킨다.
     */
    public function test_gitignore_actually_ignores_sourcemap_files(): void
    {
        $probe = 'public/build/core/__sourcemap_guard_probe__.js.map';

        $result = $this->git(['check-ignore', $probe]);

        $this->assertContains(
            $probe,
            $result,
            '.gitignore 가 .map 을 무시하지 않습니다. 빌드 산출 소스맵이 저장소에 유입될 수 있습니다.'
        );
    }

    /**
     * 추적 중인 빌드 산출 JS 에 sourceMappingURL 주석이 없는지 테스트
     *
     * 주석만 남고 .map 이 없으면 브라우저가 404 를 일으키고, 주석과 .map 이 함께
     * 있으면 원본이 노출된다. 배포 산출물에는 둘 다 없어야 한다.
     *
     * 판정 대상은 워킹트리(= 지금 배포하면 나갈 상태)다. HEAD(직전 커밋)를 보면
     * 워킹트리에서 이미 고친 내용을 반영하지 못한다.
     */
    public function test_tracked_build_output_has_no_source_mapping_url(): void
    {
        $offenders = $this->git([
            'grep', '--files-with-matches', 'sourceMappingURL', '--',
            'public/build/core/*.js',
            'modules/_bundled/*/dist/js/*.js',
            'plugins/_bundled/*/dist/js/*.js',
            'templates/_bundled/*/dist/js/*.js',
        ]);

        $this->assertSame(
            [],
            $offenders,
            "배포 산출 JS 에 소스맵 참조 주석이 남아 있습니다(--production 재빌드 필요):\n  ".
            implode("\n  ", $offenders)
        );
    }

    /**
     * 활성(서빙) 확장 디렉토리에 소스맵 산출물이 남아 있지 않은지 테스트
     *
     * 위 세 테스트는 모두 git 추적 파일만 본다. 그러나 웹서버가 실제로 서빙하는 것은
     * 활성 디렉토리(`templates/{id}`, `modules/{id}`, `plugins/{id}`)이고, 이들은
     * .gitignore 대상이라 추적 기반 검사에 전혀 걸리지 않는다.
     *
     * 실제로 `_bundled` 를 --production 으로 재빌드한 뒤에도 `{type}:update` 를 돌리지
     * 않은 확장은 활성 디렉토리에 예전 .map 과 sourceMappingURL 주석을 그대로 안고 있었다
     * (브라우저 실측으로 발견). 서빙 계층이 .map 을 거부하므로 원본 유출로 이어지지는
     * 않지만, 배포본 위생상 남아 있어서는 안 된다.
     *
     * `_bundled` 원본이 없는 활성 디렉토리는 배포본에 실리지 않는 로컬 잔재이므로 제외한다.
     */
    public function test_active_extension_dirs_have_no_sourcemap_artifacts(): void
    {
        $offenders = [];

        foreach (['templates', 'modules', 'plugins'] as $type) {
            foreach (glob(base_path("{$type}/*/dist/js/*.map")) ?: [] as $path) {
                $identifier = basename(dirname($path, 3));

                if ($this->isReservedExtensionDir($identifier) || ! $this->isBundledExtension($type, $identifier)) {
                    continue;
                }

                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '활성(서빙) 확장 디렉토리에 소스맵이 남아 있습니다. 해당 확장을 --production 으로 '.
            "재빌드한 뒤 `{type}:update {id} --force` 를 실행하세요:\n  ".
            implode("\n  ", $offenders)
        );
    }

    /**
     * `_bundled` / `_pending` 등 활성 확장이 아닌 예약 디렉토리인지 판정합니다.
     *
     * @param  string  $identifier  디렉토리명
     * @return bool 예약 디렉토리 여부
     */
    private function isReservedExtensionDir(string $identifier): bool
    {
        return str_starts_with($identifier, '_');
    }

    /**
     * 해당 확장이 배포본에 실리는 번들 확장인지 판정합니다.
     *
     * 판정 기준은 `_bundled` 원본의 존재다. DB 레코드로 판정하면 안 된다 — 테스트 DB 에는
     * 확장 레코드가 없어(0건) 모든 파일이 건너뛰어지고 테스트가 영구 green 이 된다
     * (실제로 이 함정에 빠졌다: .map 을 심어도 통과했다).
     *
     * `_bundled` 에 없는 활성 디렉토리는 과거 설치의 로컬 잔재이며 gitignore 대상이라
     * 공개 배포본에 포함되지 않으므로 판정에서 제외한다.
     *
     * @param  string  $type  확장 타입 (templates|modules|plugins)
     * @param  string  $identifier  확장 식별자
     * @return bool 번들 확장 여부
     */
    private function isBundledExtension(string $type, string $identifier): bool
    {
        return is_dir(base_path("{$type}/_bundled/{$identifier}"));
    }

    /**
     * git 명령을 실행하고 출력 라인 배열을 반환합니다.
     *
     * @param  array<int, string>  $args  git 인자
     * @return array<int, string> 출력 라인 (빈 줄 제외)
     */
    private function git(array $args): array
    {
        $process = new Process(array_merge(['git'], $args), base_path());
        $process->run();

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];

        return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
    }
}
