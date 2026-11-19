<?php

namespace Tests\Unit;

use App\Services\CoreUpdateService;
use App\Services\StorageLeftoverPruneService;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 쓰기 프로브와 상태 잠금 파일이 `.gitignore` 에 등재된다 (저장소 규약 「코드가 남기는 파일에는 정리 주체가 있다」).
 *
 * 모집단은 **이 테스트가 재는 것만**이다 — 쓰기 프로브(`.installer-write-probe-*`)가 남을 수
 * 있는 경로 전수(프루너의 프로브 루트 목록 + `storage/` 두 단계)와 상태 파일의 잠금 파일
 * (`installer-state.json.lock`), 코어 업데이트의 `deploy/**` 사이드카,
 * 그리고 압축 풀기 부트스트랩(`g7-setup.php`)이 사이트 루트에 남기는 파일.
 * 「코드가 남기는 파일 전수」가 아니다 — 나머지 잔여물(파손 사본·프로브 sentinel 등)의
 * 등재는 이 파일이 보지 않는다.
 *
 * 쓰기 프로브는 인스톨러가 점검하는 11개 디렉토리 어디에나 남을 수 있는데 `storage/app`·
 * `bootstrap/cache` 두 곳만 각자의 무시 규칙 덕에 가려졌고 `storage/`·`modules/`·`plugins/`·
 * `templates/`·`lang-packs/` 직하는 `git add -A` 한 번으로 저장소에 들어갔다.
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

        foreach ($this->bootstrapLeftovers() as $name) {
            $candidates[] = $name;
        }

        return $candidates;
    }

    /**
     * 압축 풀기 부트스트랩이 사이트 루트에 남기는 파일 이름.
     *
     * 확인 번호 파일은 **해제를 여는 자격증명**이다. 해제가 끝나면 부트스트랩이 스스로
     * 지우지만 중간에 그만두면 남고, 문서 루트를 프로젝트 루트로 두는 배치에서는 그 자리가
     * 곧 저장소라 `git add -A` 한 번으로 자격증명이 이력에 들어간다.
     *
     * 모집단은 부트스트랩이 「자기가 관리하는 이름」으로 열거한 목록(`g7setup_is_self_managed()`)
     * 에서 도출한다 — 손으로 적으면 이름이 하나 늘 때 같이 늘지 않는다. 그 파일은 압축이
     * 풀리기 전에 도는 자기 완결 파일이라 require 할 수 없어 소스에서 읽는다.
     *
     * 배포되는 진입 파일 자신은 뺀다 — 그 파일은 무시되면 **안 되고**, 그 반대 방향은
     * `ZipBootstrapContractTest::test_부트스트랩이_배포_대상에_포함된다` 가 잰다.
     *
     * @return list<string> 저장소 루트 기준 파일 이름
     */
    private function bootstrapLeftovers(): array
    {
        $path = dirname(__DIR__, 2).'/g7-setup.php';
        $this->assertFileExists($path, '압축 풀기 부트스트랩이 저장소 루트에 없다');
        $source = (string) file_get_contents($path);

        $constants = [];
        if (preg_match_all("/^const (G7_SETUP_[A-Z_]+) = '([^']*)';/m", $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $constants[$row[1]] = $row[2];
            }
        }
        $this->assertNotEmpty($constants, '부트스트랩 상수를 읽지 못했다 — 모집단이 비면 이 검사는 아무것도 재지 않는다');

        $start = strpos($source, 'function g7setup_is_self_managed(');
        $this->assertNotFalse($start, '자기 관리 파일 목록을 여는 함수가 없다 — 이름이 바뀌면 이 축이 조용히 빈다');
        $open = strpos($source, '[', $start);
        $close = strpos($source, '], true);', (int) $open);
        $this->assertNotFalse($close, '자기 관리 파일 목록의 끝을 찾지 못했다');
        $body = substr($source, (int) $open, (int) $close - (int) $open);

        $selfName = $constants['G7_SETUP_SELF_NAME'] ?? '';
        $this->assertNotSame('', $selfName, '진입 파일 이름 상수가 비었다');

        $leftovers = [];
        // 각 항목은 `G7_SETUP_X` 또는 `G7_SETUP_X.'접미사'` 형태다. `basename(__FILE__)` 처럼
        // 상수가 아닌 항목은 진입 파일 자신을 가리키므로 여기서 잡히지 않아도 된다.
        if (preg_match_all("/(G7_SETUP_[A-Z_]+)(?:\s*\.\s*'([^']*)')?/", $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $value = $constants[$row[1]] ?? null;
                if ($value === null || $value === $selfName) {
                    continue;
                }
                $leftovers[] = $value.($row[2] ?? '');
            }
        }

        $leftovers = array_values(array_unique($leftovers));
        $this->assertNotEmpty($leftovers, '부트스트랩 잔여물 목록이 비었다 — 파서가 죽으면 이 축이 통째로 사라진다');

        return $leftovers;
    }

    /**
     * 잔여물을 만들 수 있는 모든 쓰기 프로브 경로가 .gitignore 로 무시되는지, git 이 없으면 .gitignore 해석으로 대신 확인한다.
     *
     * @effects every_leftover_producer_prefix_is_registered_in_gitignore
     *
     * @return void 반환값 없음
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
     * 이 환경에서 저장소 루트로 git 을 띄울 수 있는지 판정한다.
     *
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
     * 후보 경로마다 git check-ignore 를 돌려 무시되지 않는 경로만 모은다.
     *
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
     * 경로를 「무시됨」으로 읽어 이 계약이 fail-open 이 된다.
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
