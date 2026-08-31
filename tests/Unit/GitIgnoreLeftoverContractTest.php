<?php

namespace Tests\Unit;

use App\Services\CoreUpdateService;
use App\Services\StorageLeftoverPruneService;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 쓰기 프로브와 상태 잠금 파일이 `.gitignore` 에 등재된다 (CLAUDE.md 「코드가 남기는 파일에는 정리 주체가 있다」).
 *
 * 모집단은 **이 테스트가 재는 것만**이다 — 쓰기 프로브(`.installer-write-probe-*`)가 남을 수
 * 있는 경로 전수(프루너의 프로브 루트 목록 + `storage/` 두 단계)와 상태 파일의 잠금 파일
 * (`installer-state.json.lock`, 15차 G3 W1-4). 「코드가 남기는 파일 전수」가 아니다 — 나머지
 * 잔여물(상태·런타임·파손 사본·프로브 sentinel 등)의 등재는 이 파일이 보지 않는다.
 *
 * 쓰기 프로브는 인스톨러가 점검하는 11개 디렉토리 어디에나 남을 수 있는데 `storage/app`·
 * `bootstrap/cache` 두 곳만 각자의 무시 규칙 덕에 가려졌고 `storage/`·`modules/`·`plugins/`·
 * `templates/`·`lang-packs/` 직하는 `git add -A` 한 번으로 저장소에 들어갔다 (14차 G3 W2-7).
 * 모집단은 **생산자 상수**(프루너의 프로브 루트 목록)에서 도출한다 — 손으로 적은 목록은
 * 디렉토리가 하나 늘 때 같이 늘지 않는다.
 *
 * 판정은 `git check-ignore` 가 SSoT 다. `git` 을 띄울 수 없는 환경에서는 건너뛰지 않고
 * `.gitignore` 를 직접 읽어 같은 이름을 대조한다 — 건너뛰면 그 환경에서 이 계약이 통째로
 * 재지지 않는다. 그 대체 경로는 부정 패턴(`!`)을 모르므로, 그 줄을 만나면 **명시적으로
 * 실패**한다 — 무시하면 재등재된 경로를 「무시됨」으로 읽는다(fail-open).
 *
 * @scenario protection=htaccess_ok
 */
class GitIgnoreLeftoverContractTest extends TestCase
{
    /**
     * 생산자 상수에서 도출한 「남을 수 있는 경로」 전수.
     *
     * @return list<string> 저장소 상대 경로
     */
    private function leftoverCandidates(): array
    {
        $reflection = new \ReflectionClass(StorageLeftoverPruneService::class);
        $prefix = (string) $reflection->getConstant('INSTALLER_WRITE_PROBE_PREFIX');
        $roots = (array) $reflection->getConstant('INSTALLER_WRITE_PROBE_ROOTS');

        $this->assertNotEmpty($roots, '프로브 루트 목록이 비었다 — 모집단이 비면 이 검사는 아무것도 재지 않는다');

        $candidates = [];
        foreach ($roots as $root) {
            $candidates[] = $root.'/'.$prefix.'0123456789abcdef';
        }
        // `storage` 는 두 단계 아래까지 점검한다.
        $candidates[] = 'storage/framework/'.$prefix.'0123456789abcdef';
        $candidates[] = 'storage/framework/cache/'.$prefix.'0123456789abcdef';

        // 상태 파일의 잠금 파일 — 이름은 프루너 상수(= 생산자 상수, StorageLeftoverPruneServiceTest 가 대조)에서 도출한다.
        $stateFile = (string) $reflection->getConstant('INSTALLER_STATE_FILE');
        $lockSuffix = (string) $reflection->getConstant('INSTALLER_STATE_LOCK_SUFFIX');
        $this->assertNotSame('', $lockSuffix, '잠금 파일 접미사 상수가 비었다 — 그 항목이 모집단에서 빠진다');
        $candidates[] = 'storage/'.$stateFile.$lockSuffix;

        // 코어 업데이트가 `deploy/**` 조각 옆에 두는 사이드카(`.dist` · 충돌 변형 `.dist.new`).
        // 둘 다 `git add -A` 로 새는 부류다 — 한쪽만 막으면 다른 쪽이 샌다 (18차 C-a). 이름은
        // 생산자 상수(`CoreUpdateService`)에서 도출한다.
        $coreUpdate = new \ReflectionClass(CoreUpdateService::class);
        foreach (['DEPLOY_FRAGMENT_DIST_SUFFIX', 'DEPLOY_FRAGMENT_DIST_NEW_SUFFIX'] as $suffixConst) {
            $suffix = (string) $coreUpdate->getConstant($suffixConst);
            $this->assertNotSame('', $suffix, $suffixConst.' 상수가 비었다 — 그 사이드카가 모집단에서 빠진다');
            $candidates[] = 'deploy/nginx/g7-layout2.conf'.$suffix;
        }

        return $candidates;
    }

    /**
     * @effects every_leftover_producer_prefix_is_registered_in_gitignore
     */
    public function test_every_write_probe_location_is_git_ignored(): void
    {
        $root = dirname(__DIR__, 2);
        $candidates = $this->leftoverCandidates();

        $unignored = $this->canRunGit($root)
            ? $this->notIgnoredByGit($root, $candidates)
            : $this->notIgnoredByParsing($root, $candidates);

        $this->assertSame([], $unignored, '다음 잔여물 경로가 .gitignore 에 없다 — git add -A 한 번으로 저장소에 들어간다');
    }

    /**
     * @param  string  $root  저장소 루트
     * @return bool `git check-ignore` 를 띄울 수 있는가
     */
    private function canRunGit(string $root): bool
    {
        if (! function_exists('proc_open') || ! is_dir($root.'/.git')) {
            return false;
        }

        $process = @proc_open('git --version', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (! is_resource($process)) {
            return false;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    /**
     * @param  string  $root  저장소 루트
     * @param  list<string>  $candidates  대조 경로
     * @return list<string> 무시되지 않는 경로
     */
    private function notIgnoredByGit(string $root, array $candidates): array
    {
        $unignored = [];

        foreach ($candidates as $candidate) {
            $process = proc_open(
                'git check-ignore -q -- '.escapeshellarg($candidate),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root
            );
            $this->assertIsResource($process, 'git check-ignore 를 띄우지 못했다');
            fclose($pipes[1]);
            fclose($pipes[2]);

            // 0 = 무시됨, 1 = 무시되지 않음, 128 = 오류
            $code = proc_close($process);
            $this->assertContains($code, [0, 1], 'git check-ignore 가 오류로 끝났다: '.$candidate);

            if ($code === 1) {
                $unignored[] = $candidate;
            }
        }

        return $unignored;
    }

    /**
     * `git` 없이 `.gitignore` 를 직접 대조한다 — 접두사 패턴(`<name>-*`)의 전역·경로 지정 두 형태만 본다.
     *
     * 부정 패턴(`!`)은 **모른다.** 그 줄이 있으면 무시하지 않고 실패한다 — 무시하면 재등재된
     * 경로를 「무시됨」으로 읽어 이 계약이 fail-open 이 된다 (15차 G3 W1-12).
     *
     * @param  string  $root  저장소 루트
     * @param  list<string>  $candidates  대조 경로
     * @return list<string> 무시되지 않는 경로
     */
    private function notIgnoredByParsing(string $root, array $candidates): array
    {
        $lines = array_map('trim', preg_split('/\r\n|\r|\n/', (string) file_get_contents($root.'/.gitignore')) ?: []);

        $negations = array_values(array_filter($lines, static fn (string $l): bool => $l !== '' && $l[0] === '!'));
        if ($negations !== []) {
            $this->fail('대체 파싱 경로는 부정 패턴(!)을 모른다 — git 을 띄울 수 없는 이 환경에서는 판정할 수 없다: '.implode(', ', $negations));
        }

        $patterns = array_values(array_filter($lines, static fn (string $l): bool => $l !== '' && $l[0] !== '#'));

        $unignored = [];
        foreach ($candidates as $candidate) {
            $base = basename($candidate);
            $matched = false;

            foreach ($patterns as $pattern) {
                $pattern = rtrim($pattern, '/');
                if (! str_contains($pattern, '/')) {
                    $matched = fnmatch($pattern, $base);
                } else {
                    $matched = fnmatch(ltrim($pattern, '/'), $candidate) || fnmatch(ltrim($pattern, '/').'/*', $candidate);
                }
                if ($matched) {
                    break;
                }
            }

            if (! $matched) {
                $unignored[] = $candidate;
            }
        }

        return $unignored;
    }
}
