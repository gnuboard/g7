<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 엔드포인트 진입 가드 전수 검사 (R5).
 *
 * `public/install/api/*.php` 의 모든 진입 파일은 설치 완료 후 차단 가드를 경유해야 한다.
 * 새 엔드포인트(단계별 실행 run-task.php 등)가 가드를 빠뜨리면 설치 후에도 열린 입구가
 * 남는다 — 파일을 열거하지 않고 디렉토리를 스캔하므로 추가되는 엔드포인트도 모집단이다.
 *
 * finalize 전용 가드(`.env` 플래그 단독 판정)는 complete_flag 직후에도 호출되어야 하는
 * 흐름에만 허용된다 — 허용 목록 밖의 파일이 그 가드를 쓰면 차단 강도가 조용히 약해진 것이다.
 *
 * 커버 축: endpoint_guard general·finalize_allowed·finalize_unexpected·missing
 */
class InstallerEndpointGuardCoverageTest extends TestCase
{
    use ScansPhpSourceFunctions;

    /** 엔드포인트가 아닌 라이브러리 (require 되어 함수만 정의) */
    private const LIBRARIES = ['_guard.php', 'rollback-functions.php'];

    /**
     * finalize 전용 가드 허용 목록 — complete_flag 가 g7_installed 락을 만든 직후에도
     * 같은 흐름에서 호출되어야 하는 엔드포인트만.
     */
    private const FINALIZE_GUARD_ALLOWED = [
        'finalize-env.php' => 'complete_flag 직후 .env 머지 (KVE-2026-1056 자가 차단 회귀)',
        'state-management.php' => 'complete_flag 직후 폴링/상태 조회 구간',
        'run-task.php' => 'complete_flag 다음 태스크(config_cache)가 같은 단계별 흐름에서 이어진다',
        // 복구 진입점 — 이 창에서 막히면 운영자가 실패 화면에서 나갈 수 없다 (9차 #M5)
        'install-process.php' => 'complete_flag 이후의 재시작·[다시 시도]·[건너뛰고 계속]',
        'install-worker.php' => 'complete_flag 이후 SSE 재연결',
    ];

    /**
     * 화면 진입(index.php)도 같은 복구 창을 갖는다 — api/ 밖이라 위 모집단에 없다.
     */
    private const FINALIZE_GUARD_ALLOWED_OUTSIDE_API = [
        'index.php' => 'complete_flag 이후 새로고침 — 완료 화면에 도달하려면 열려 있어야 한다',
    ];

    private function apiDir(): string
    {
        return dirname(__DIR__, 3).'/public/install/api';
    }

    /**
     * @return array<int, string> 엔드포인트 파일명 목록
     */
    private function endpoints(): array
    {
        // 재귀 스캔 — 한 겹 `glob('*.php')` 은 `api/` 아래에 디렉토리가 하나 생기는 순간
        // 그 안의 엔드포인트를 통째로 놓친다 (12차 메타 검사 ②).
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->apiDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getBasename();
            }
        }
        sort($files);

        return array_values(array_filter($files, fn (string $f) => ! in_array($f, self::LIBRARIES, true)));
    }

    /**
     * 인스톨러의 `includes/` PHP 파일 전수 (결정 헬퍼 도출 모집단).
     *
     * 한 파일 고정으로 두면 형제 파일에 새 결정 헬퍼가 생겨도 도출이 그것을 모른다 —
     * 그러면 그 헬퍼를 부르는 엔드포인트가 배선 대조에서 통째로 빠진다
     * (10차 G3 라운드 C10-10).
     *
     * @return array<int, string> 절대 경로 목록
     */
    private function installerIncludeFiles(): array
    {
        // 재귀 스캔 — `endpoints()` 와 동형 (G3 회차 13 M-4 ②). 한 겹 `glob('*.php')` 은
        // `includes/` 아래에 디렉토리가 하나 생기는 순간 그 안의 결정 헬퍼를 통째로 놓친다.
        $dir = dirname(__DIR__, 3).'/public/install/includes';

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 판정 SSoT(`includes/execution-mode.php`)가 소유한 **결정** 헬퍼 이름 — 도출한다.
     *
     * 이름이 결정을 담는 것만 고른다: 거부(`*Rejection`/`*Rejected`)·수용(`*Accepted`)·
     * 차단(`*BlockedBy*`)·확정(`installerResolve*`)·요구(`installerRequires*`).
     * 그 밖(`installerHasFunction`·`installerSetTimeLimit` 같은 유틸)은 어느 엔드포인트든
     * 부르므로 배선 축이 아니다 — 넣으면 모든 파일이 걸려 표가 소음으로 부푼다.
     *
     * @return array<int, string>
     */
    private function decisionHelpers(): array
    {
        // 모집단은 **인스톨러 전수**다.
        //
        // 종전에는 `includes/execution-mode.php` 한 파일만 읽었다. 그런데 같은 이름
        // 규칙에 걸리는 결정 헬퍼가 형제 파일에도 있다(`installerRecordBusyRejection`
        // — `includes/installer-state.php`). 그러면 그 헬퍼를 부르는 엔드포인트는
        // 배선 표 대조에서 통째로 빠지고, 새 파일이 생겨도 아무것도 울지 않는다
        // (10차 G3 라운드 C10-10).
        $names = [];

        foreach ($this->installerIncludeFiles() as $file) {
            preg_match_all(
                '/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m',
                $this->sourceWithoutComments($file),
                $matches
            );

            foreach ($matches[1] as $name) {
                $names[] = $name;
            }
        }

        $decision = array_values(array_filter(
            array_unique($names),
            static fn (string $fn): bool => preg_match('/(Rejection|Rejected|Accepted|BlockedBy)/', $fn) === 1
                || preg_match('/^installer(Resolve|Requires)/', $fn) === 1,
        ));

        sort($decision);

        return $decision;
    }

    public function test_population_is_not_empty(): void
    {
        $this->assertGreaterThanOrEqual(10, count($this->endpoints()));
        $this->assertContains('run-task.php', $this->endpoints(), '단계별 실행 엔드포인트가 모집단에 있어야 한다');
    }

    /**
     * @effects installer_every_api_endpoint_passes_completion_guard
     */
    public function test_every_endpoint_loads_guard_and_calls_it(): void
    {
        foreach ($this->endpoints() as $file) {
            // 주석을 지운 뒤에 찾는다 — 호출을 `//` 로 감싸도 문자열은 남으므로 원문 검색만으로는
            // 「가드가 살아 있다」를 증명하지 못한다. 같은 파일의 형제 검사(배선 표)는 이미 그렇게
            // 하고 있었고, 이 두 검사만 원문 정규식이라 한 파일 안에서 규칙이 갈려 있었다 (10차 #G4).
            $src = $this->stripComments((string) file_get_contents($this->apiDir().'/'.$file));

            $this->assertMatchesRegularExpression(
                '/require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]\/_guard\.php[\'"]/',
                $src,
                "{$file} 가 _guard.php 를 로드하지 않는다 (R5)",
            );
            $this->assertMatchesRegularExpression(
                '/installer_guard(?:_finalize)?_or_410\s*\(\s*\)/',
                $src,
                "{$file} 가 진입 가드를 호출하지 않는다 (R5)",
            );
        }
    }

    public function test_finalize_guard_is_used_only_by_allowed_endpoints(): void
    {
        foreach ($this->endpoints() as $file) {
            $src = $this->stripComments((string) file_get_contents($this->apiDir().'/'.$file));
            $usesFinalize = (bool) preg_match('/installer_guard_finalize_or_410\s*\(\s*\)/', $src);

            if ($usesFinalize) {
                $this->assertArrayHasKey(
                    $file,
                    self::FINALIZE_GUARD_ALLOWED,
                    "{$file} 가 finalize 전용(약한) 가드를 쓴다 — 허용 목록에 사유와 함께 등재하거나 일반 가드로 바꿀 것",
                );
            }
        }

        foreach (array_keys(self::FINALIZE_GUARD_ALLOWED) as $file) {
            $this->assertFileExists($this->apiDir().'/'.$file, "허용 목록의 {$file} 이 존재하지 않는다 — 목록을 정리할 것");
        }
    }

    /**
     * complete_flag 이후의 복구 진입점이 그 창에서 열려 있다 (9차 #M5).
     *
     * `complete_flag` 태스크가 `storage/app/g7_installed` 를 먼저 만들고 그 뒤에
     * `config_cache` 와 `.env` 머지(finalize)가 온다. 그 창에서 재시작·SSE 재연결·
     * 새로고침이 엄격 가드에 걸리면, 설치가 끝나지 않은 상태(.env 미머지)인데 운영자는
     * 실패 화면에서 어느 쪽으로도 나갈 수 없다 — 세 진입점 모두 410 이기 때문이다.
     */
    public function test_recovery_entry_points_stay_open_until_env_is_merged(): void
    {
        $targets = array_merge(
            array_map(fn (string $f): string => 'api/'.$f, array_keys(self::FINALIZE_GUARD_ALLOWED)),
            array_map(fn (string $f): string => $f, array_keys(self::FINALIZE_GUARD_ALLOWED_OUTSIDE_API)),
        );

        foreach (['api/install-process.php', 'api/install-worker.php', 'index.php'] as $required) {
            $this->assertContains($required, $targets, '복구 진입점이 허용 목록에서 빠지면 그 창에서 운영자가 갇힌다');
        }

        foreach ($targets as $relative) {
            $path = dirname(__DIR__, 3).'/public/install/'.$relative;
            $this->assertFileExists($path);

            $src = $this->stripComments((string) file_get_contents($path));
            $this->assertMatchesRegularExpression(
                '/installer_guard_finalize_or_410\s*\(\s*\)/',
                $src,
                "{$relative} 이 완화 가드를 쓰지 않는다 — complete_flag 이후 창에서 410 이 된다",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*installer_guard_or_410\s*\(\s*\)\s*;/m',
                $src,
                "{$relative} 이 엄격 가드를 함께 호출한다 — 완화가 무효가 된다",
            );
        }
    }

    /**
     * run-task.php 는 세션이 아니라 토큰으로 호출자를 확인한다 (R8) — 세션 include 금지.
     */
    public function test_run_task_endpoint_uses_token_not_session(): void
    {
        // 주석을 지운 뒤에 본다 — 이 두 검사만 원문 대조라 한 파일 안에서 규칙이 갈려 있었다.
        // 실측(12차 GI-3): 토큰 판정 호출을 주석 처리해도 7개 단언이 전부 초록이었고,
        // 그 상태의 결과는 stepwise 의 run-task·중단·초기화·건너뛰기가 전부 무인증이 되는 것이다.
        $src = $this->stripComments((string) file_get_contents($this->apiDir().'/run-task.php'));

        $this->assertStringNotContainsString("includes/session.php'", $src, 'run-task.php 는 세션에 의존하면 안 된다 (R8)');
        $this->assertStringContainsString('HTTP_X_INSTALLER_TOKEN', $src);
        // 판정 자체는 execution-mode.php 가 소유하고 행위 테스트가 덮는다
        // (InstallerExecutionModeHelperTest::test_stepwise_token_judgment_...).
        // 여기서 보는 축은 「그 판정을 실제로 부르고 있다」 — 호출이 지워지면 화면이
        // 아니라 인증이 사라지므로, 두 축을 각자 고정한다.
        // 11차 #A10 이 「보관본 없음」과 「값 불일치」를 나누면서 호출부가 부르는 이름이
        // installerStepwiseTokenRejection() 으로 바뀌었다 — 그 함수가 내부에서
        // installerStepwiseTokenAccepted() 를 부른다. 축(「판정을 실제로 부른다」)은 그대로다.
        // 배선 축은 문자열 채널까지 벗긴 소스로 잰다 (G3 회차 15) — 위의 문자열 리터럴 단언은 $src 그대로.
        $this->assertStringContainsString(
            'installerStepwiseTokenRejection(',
            $this->sourceWithoutCommentsAndStrings($this->apiDir().'/run-task.php'),
            '토큰 판정을 거치지 않고 진행하면 안 된다'
        );
        $this->assertStringContainsString("['installer']['stepwise_token']", $src, '토큰은 runtime.php 의 installer 섹션에서 읽는다 (R3)');
    }

    /**
     * 같은 토큰을 요구하는 두 번째 엔드포인트도 같은 판정을 부른다.
     *
     * state-management.php?action=skip_task 는 run-task.php 와 같은 stepwise 토큰을 요구한다.
     * 판정을 인라인으로 복제하면 그 사본의 `hash_equals` 가 `==` 로 바뀌어도 어떤 테스트도
     * red 가 되지 않는다 — 위 테스트는 run-task.php 만 보기 때문이다. 판정 SSoT 는 하나다.
     */
    public function test_skip_task_endpoint_uses_the_same_token_judgment(): void
    {
        // 형제 검사와 같은 규칙 — 주석 제거 후 대조 (12차 GI-3)
        $src = $this->stripComments((string) file_get_contents($this->apiDir().'/state-management.php'));

        $this->assertStringContainsString(
            "includes/execution-mode.php'",
            $src,
            'skip_task 가 토큰 판정을 부르려면 그 판정을 소유한 파일을 require 해야 한다'
        );
        $this->assertStringContainsString(
            'installerStepwiseTokenRejection(',
            $this->sourceWithoutCommentsAndStrings($this->apiDir().'/state-management.php'),
            '토큰 판정은 한 지점이 소유한다'
        );
        $this->assertStringNotContainsString(
            'hash_equals(',
            $src,
            '판정을 인라인으로 복제하지 않는다 — 사본은 소스 대조 게이트 밖이라 회귀가 조용히 통과한다'
        );
    }

    /**
     * 판정 함수가 아니라 **그 판정을 부르는 배선**을 고정한다.
     *
     * 이 라운드가 찾은 결함군이 정확히 이것이다 — 순수 함수(진행 모드 가드·워커 id 기억·
     * 중단 전이)는 행위 테스트가 덮는데, 그 함수를 **호출하는 줄**은 아무도 보지 않아
     * 지워도 전 스위트가 초록이었다. 배선이 사라지면 함수는 멀쩡한 채로 기능만 없어진다:
     *  - 진행 모드 가드가 빠지면 stepwise 설치가 SSE 워커로 들어가 한 요청 안에서 전 태스크를
     *    연속 실행한다 (A-2·R1 이 피하려던 바로 그 상태).
     *  - 워커 id 기억이 빠지면 오래 도는 태스크가 lock 창을 늘리지 못해 겹쳐 돈다.
     *  - 중단 전이가 빠지면 죽은 워커의 점유가 남아 [계속 진행]이 거짓 busy 로 막힌다.
     *
     * 새 배선이 생기면 이 표에 한 줄을 더한다 — 표가 비면(모집단 0) 검사가 무의미해지므로
     * 하한도 함께 단언한다.
     */
    public function test_judgment_helpers_are_actually_wired(): void
    {
        $expectations = [
            ['api/run-task.php', 'installerStepwiseEndpointRejection(', 'stepwise 가 아닌 설치를 단계별 입구가 받아 버린다', null],
            ['api/install-worker.php', 'installerSseWorkerRejected(', 'stepwise 로 확정된 설치를 스트리밍 워커가 받아 전 태스크를 한 요청에 돌린다', null],
            ['api/run-task.php', 'installerCurrentWorkerId(', '태스크 본문이 자기 lock 창을 늘리지 못한다', null],
            ['api/install-worker.php', 'installerCurrentWorkerId(', '오래 도는 태스크가 stale 로 판정돼 겹쳐 돈다', null],
            ['api/install-process.php', 'installerCurrentWorkerId(', '오래 도는 태스크가 stale 로 판정돼 겹쳐 돈다', null],
            ['api/state-management.php', 'installerApplyAbortedState(', '중단 후 죽은 워커의 점유가 남아 [계속 진행]이 거짓 busy 로 막힌다', null],
            ['includes/artisan-executor.php', 'extendWorkerStaleWindow(', 'exec() 블로킹 구간에 heartbeat 가 멈춰 살아 있는 요청을 다른 요청이 탈취한다', 'installerRunArtisanSubprocess'],
            ['api/check-configuration.php', 'orderPhpBinaryCandidates(', '감지 결과가 CLI 우선으로 정렬되지 않아 웹 SAPI 바이너리가 입력란에 자동으로 채워진다', 'detectPhpBinaries'],
            ['includes/installer-state.php', 'installerActiveWorkerIsAlive(', '살아 있는 워커의 완료 마커를 지워 방금 만든 테이블을 다시 DROP 한다', 'applyExistingDbActionStateGuard'],
            ['includes/task-runner.php', 'installerTaskIsBestEffort(', '태스크 밖으로 나온 예외가 best_effort 계약을 건너뛰어 완료를 실패로 덮는다', 'recordInstallationRunException'],
            // 요약의 형태·값은 execution-mode.php 가 소유하고 행위 테스트가 덮는다
            // (InstallerStateSchemaTest::stepwise_state_summary_matches_the_polling_field_set).
            // 여기서 보는 축은 「그 요약을 실제로 쓰고 있다」 — 배선이 지워지면 응답 봉투가 빈다.
            ['api/run-task.php', 'installerStepwiseStateSummary(', '단계별 실행 응답의 실패 필드 집합이 폴링 응답과 갈린다', 'stepwiseStateSummary'],
            ['api/state-management.php', 'installerClientFailureFields(', '폴링 응답의 실패 필드 집합이 단계별 실행 응답과 갈린다', 'getState'],
            ['api/run-task.php', 'installerStepwiseFailurePayload(', '500 봉투가 응답 계약을 깨고 예외 원문을 실패 화면 본문으로 보낸다', 'stepwiseFail'],
            ['api/state-management.php', 'installerRequiresStepwiseToken(', 'stepwise 설치의 상태 변경이 무인증이 된다', 'requireStepwiseTokenOrReject'],
            // 상태를 바꾸는 세 액션이 **전부** 같은 게이트를 거쳐야 한다 — 건너뛰기 하나에만
            // 걸어 두면 그보다 파괴적인 중단(진행 중 태스크 롤백)과 초기화(완료 기록 전소거)가
            // 무인증으로 남는다 (10차 #M10).
            ['api/state-management.php', 'requireStepwiseTokenOrReject(', 'stepwise 설치의 [건너뛰고 계속] 이 무인증이 된다', 'skipFailedTask'],
            ['api/state-management.php', 'requireStepwiseTokenOrReject(', 'stepwise 설치의 중단이 무인증이 되어 진행 중 태스크 롤백을 아무나 부를 수 있다', 'abortInstallation'],
            ['api/state-management.php', 'requireStepwiseTokenOrReject(', 'stepwise 설치의 초기화가 무인증이 되어 완료 기록을 아무나 지울 수 있다', 'resetState'],
            // 진행 방식 확정 — inprocess 강제(A-2·R1)와 composer 조합 차단(#M3)의 배선.
            // 이 호출이 사라지면 inprocess 설치가 한 요청에 전 태스크를 연속 실행한다 (10차 #G1).
            ['api/install-process.php', 'installerResolveInstallationMode(', 'inprocess 설치가 단계별 실행으로 강제되지 않아 한 요청에 전 태스크를 돌린다', null],
            // 실행 모드·PHP 경로 확정값을 state 에 박는 **생산** 지점 (10차 #G2·#G3).
            // 소비 축은 테스트가 state 를 직접 seed 해 덮고 있어, 심는 줄은 아무도 보지 않았다.
            ['api/install-process.php', 'installerResolveExecutionMode(', '모드가 진입 1회로 확정되지 않아 요청마다 재판정된다 (R1 파기)', null],
            ['api/install-process.php', 'installerResolvedPhpBinary(', '자동 탐색된 PHP 경로가 실행에 도달하지 못해 전 태스크가 `php` 로 스폰된다 (D35)', null],
            // 11차가 신설한 두 **파괴적** 가드 — 판정 함수는 행위 테스트가 덮는데 그것을 부르는
            // 줄은 이 표에 없어, 대입을 `= null` 로 바꿔도 인스톨러 전 스위트가 초록이었다
            // (12차 GI-2). 그 상태의 결과는 GET·무토큰·설치 진행 중 finalize 가 다시 열려
            // 10차 fail-closed 가 요청 하나로 우회되는 것이다.
            ['api/finalize-env.php', 'installerFinalizeRejection(', '끝나지 않은 설치의 완료 표식·runtime.php 삭제를 아무나 GET 으로 부를 수 있다', null],
            ['api/install-process.php', 'installerStartRejection(', '진행 중 stepwise 설치에 무인증으로 새 토큰을 발급해 10차의 토큰 게이트가 요청 하나로 우회된다', null],
            // 10차 #M3(단계별 + composer 조합 차단)의 **화면 절반**. 서버 절반
            // (installerResolveInstallationMode)만 이 표에 있었고, 화면은 이 표의 모집단이
            // api/·includes/ 뿐이라 아무도 보지 않았다 — 호출을 지우면 고를 수 없는 조합의
            // 라디오가 사유 없이 활성으로 돌아온다 (12차 GI-6).
            ['views/5-installation.php', 'installerStepwiseBlockedByVendorMode(', '한 요청 수명보다 오래 걸리는 조합(단계별 + composer)이 화면에서 다시 선택 가능해진다', null],
            ['views/5-installation.php', 'installerEffectiveExecutionMode(', '화면이 확정 실행 모드를 읽지 않아 stepwise 강제·조합 차단 표시가 통째로 사라진다', null],
            // 같은 결함 형태의 **서버측 사슬** (12차 재귀 조사). 순수 함수 테스트는 4단언이
            // 있는데 그 함수를 진행 방식 확정에 끼워 넣는 줄은 아무도 보지 않았다 —
            // 지우면 단계별 + composer 조합이 서버에서 다시 통과한다.
            ['includes/execution-mode.php', 'installerCoerceInstallationModeForVendor(', '진행 방식 확정이 Vendor 조합 보정을 건너뛰어 stepwise + composer 가 서버에서 통과한다', 'installerResolveInstallationMode'],
            ['includes/execution-mode.php', 'installerStepwiseBlockedByVendorMode(', '조합 보정이 차단 판정을 부르지 않아 보정이 무조건 통과가 된다', 'installerCoerceInstallationModeForVendor'],
            // 아래 두 줄은 하한을 도출로 바꾸면서 드러난 실제 사각이다 — 확장 선택 저장은
            // 상태를 바꾸는 엔드포인트인데 그 두 게이트의 **호출**을 아무도 보지 않았다.
            // 지우면 진행 이력이 있는 설치의 선택 교체와 교차 출처 요청이 다시 통과한다.
            ['api/save-extensions.php', 'installerExtensionSelectionRejection(', '진행 이력이 있는 설치의 확장 선택 교체가 다시 통과해 의존성 검증 없는 편입·진행률 되감김이 생긴다', null],
            ['api/save-extensions.php', 'installerCrossOriginRejection(', '상태를 바꾸는 저장 엔드포인트의 출처 확인이 사라진다', null],
            // 아래는 대조 단위를 (파일, 헬퍼) 쌍으로 내리면서 드러났다. 이 호출은 형제
            // 검사(`InstallerServerJudgmentReachesScreenTest`)가 **손 목록**으로 덮고
            // 있었지만, 도출은 그 사실을 알 수 없다 — 표에 없으면 「배선 표가 이 쌍을
            // 모른다」가 사실이고, 손 목록이 줄어드는 날 그 사각이 조용히 열린다
            // (10차 G3 라운드 C10-9).
            ['api/check-configuration.php', 'installerExecutionModeRejection(', '화면의 「고를 수 있는가」가 서버 저장 게이트와 다른 규칙을 쓰게 되어, 서버가 받아들이는 선택을 화면이 잠근다', null],
            // 아래 아홉은 같은 전환이 드러낸 나머지다. 여섯은 형제 검사
            // (`InstallerEntryGateWiringTest`)의 **손 목록**이 덮고 있었고, 셋은
            // **어느 검사도 보지 않았다** — 그중 `run-task.php` 의 출처 확인은 단계별
            // 실행 엔드포인트라, 지우면 그 통로가 교차 출처로 열린다 (10차 G3 라운드 C10-9).
            ['api/finalize-env.php', 'installerCrossOriginRejection(', '완료 표식 기록·runtime.php 삭제를 다른 사이트가 부를 수 있다', null],
            ['api/install-process.php', 'installerCrossOriginRejection(', '설치 시작 + 단계별 토큰 발급을 다른 사이트가 부를 수 있다', null],
            ['api/install-process.php', 'installerRecordBusyRejection(', '진행 중 판정의 기록이 사라져 동시 시작이 조용히 통과한다 (형제 검사에도 없던 자리)', null],
            ['api/install-worker.php', 'installerCrossOriginRejection(', '크로스오리진 이미지 태그 하나가 실제 설치를 시작한다', null],
            ['api/install-worker.php', 'installerSseWorkerStateRejection(', 'SSE 워커가 상태 게이트 없이 재진입해 같은 태스크가 두 번 돈다', null],
            ['api/run-task.php', 'installerCrossOriginRejection(', '단계별 실행 엔드포인트의 출처 확인이 사라진다 — 형제 검사의 손 목록에 이 파일이 없어 어느 검사도 보지 않았다', null],
            ['api/run-task.php', 'installerStepwiseTokenRejection(', '단계별 실행 토큰 게이트가 사라져 요청 하나로 임의 태스크를 돌릴 수 있다', null],
            ['api/state-management.php', 'installerCrossOriginRejection(', '중단·초기화를 다른 사이트가 부를 수 있다', null],
            ['api/state-management.php', 'installerStepwiseTokenRejection(', '중단·초기화의 토큰 게이트가 사라진다 (형제 검사에도 없던 자리)', null],
        ];

        // 배선 표는 api/·includes/ 뿐 아니라 **views/** 도 모집단으로 삼는다 — 판정이 화면에서
        // 소비되는 절반은 종전에 통째로 사각이었다 (12차 GI-6).
        $this->assertNotEmpty(
            array_filter($expectations, static fn (array $row): bool => str_starts_with($row[0], 'views/')),
            '화면에서 소비되는 판정도 배선이다 — views/ 가 모집단에서 빠지면 그 절반이 사각이 된다'
        );

        // 하한을 리터럴(`>= 26`)로 적으면 그 수가 **자기 자신과 비교된다** — 표에 없는
        // 엔드포인트가 새로 생겨도 숫자가 움직이지 않아 늘어난 사각이 게이트에 보이지 않는다.
        // 실측: 판정 헬퍼를 부르는 엔드포인트를 하나 새로 넣어도 이 클래스 12검사가 전부
        // 초록이었다. 하한과 커버리지를 **실제 파일 집합에서 도출**하고 양방향으로 잰다.
        $wiredFiles = array_values(array_unique(array_column($expectations, 0)));
        $wiredEndpoints = array_values(array_unique(array_map(
            static fn (string $path): string => basename($path),
            array_filter($wiredFiles, static fn (string $path): bool => str_starts_with($path, 'api/')),
        )));

        // (파일, 헬퍼) 쌍 — 대조 단위를 파일에서 쌍으로 내린다 (10차 G3 라운드 C10-9).
        $wiredPairs = [];

        foreach ($expectations as [$relative, $call]) {
            if (! str_starts_with($relative, 'api/')) {
                continue;
            }

            $wiredPairs[] = basename($relative).'::'.rtrim($call, '(');
        }

        $wiredPairs = array_values(array_unique($wiredPairs));

        $endpoints = $this->endpoints();
        $decisionHelpers = $this->decisionHelpers();

        // 모집단 자체를 잰다 — 어느 한쪽이 비면 아래 두 대조가 조용히 통과한다.
        $this->assertNotEmpty($endpoints, '엔드포인트 도출이 비었다 — 아래 대조가 무의미해진다');
        $this->assertNotEmpty($decisionHelpers, '결정 헬퍼 도출이 비었다 — 아래 대조가 무의미해진다');

        // (1) 실제 ⊇ 표 — 표의 api/ 행은 실존하는 (라이브러리가 아닌) 엔드포인트를 가리킨다.
        foreach ($wiredEndpoints as $file) {
            $this->assertContains(
                $file,
                $endpoints,
                "배선 표의 {$file} 이 실제 엔드포인트 모집단에 없다 — 이름이 바뀌었거나 사라진 행이다"
            );
        }

        // (2) 표 ⊇ 실제 — 판정 SSoT 의 **결정** 헬퍼를 부르는 엔드포인트는 전부 표에 있어야
        //     한다. 「이 엔드포인트에는 배선이 없다」를 손으로 선언하지 않고 소스에서 잰다 —
        //     손 선언은 파일이 새로 생겨도 그대로 남아 그 사각을 덮어 준다.
        $mustBeWired = [];

        foreach ($endpoints as $file) {
            $source = $this->stripComments((string) file_get_contents($this->apiDir().'/'.$file));

            $called = array_values(array_filter(
                $decisionHelpers,
                static fn (string $fn): bool => preg_match('/(?<![A-Za-z0-9_$])'.preg_quote($fn, '/').'\s*\(/', $source) === 1,
            ));

            if ($called === []) {
                continue;
            }

            $mustBeWired[] = $file;

            // 대조 단위는 **(파일, 헬퍼) 쌍**이다.
            //
            // 종전에는 「이 **파일**이 표에 있는가」만 물었다. 그래서 한 파일이 결정
            // 헬퍼를 셋 부르는데 표에 한 행만 있어도 통과했고, 나머지 둘은 지워도
            // 아무 검사도 red 가 되지 않았다 — 실물이 그 상태였다
            // (`api/run-task.php` 의 교차 출처 가드) (10차 G3 라운드 C10-9).
            foreach ($called as $helper) {
                $this->assertContains(
                    $file.'::'.$helper,
                    $wiredPairs,
                    "{$file} 이 결정 헬퍼 `{$helper}` 를 부르는데 배선 표에 그 쌍이 없다 "
                    .'— 그 호출을 지워도 아무 검사도 red 가 되지 않는다'
                );
            }
        }

        // (3) 하한도 도출한다 — 배선이 필요한 엔드포인트 수만큼은 표가 덮어야 한다.
        $this->assertGreaterThanOrEqual(
            count($mustBeWired),
            count($wiredEndpoints),
            '배선 표가 덮는 엔드포인트가 배선이 필요한 엔드포인트보다 적다 — 그 차이가 곧 사각이다'
        );

        foreach ($expectations as [$relative, $call, $why, $enclosing]) {
            $path = dirname(__DIR__, 3).'/public/install/'.$relative;
            $this->assertFileExists($path);

            // 주석을 지운 뒤에 찾는다 — 호출을 주석으로 감싸도 문자열은 남기 때문에,
            // 원문 검색만으로는 「배선이 살아 있다」를 증명하지 못한다.
            $source = $this->stripComments((string) file_get_contents($path));

            // 정의(`function X(`)는 호출이 아니다 — 같은 파일이 그 함수를 소유하는 경우
            // 정의 한 줄만 남기고 호출을 지워도 통과해서는 안 된다.
            $callSites = [];
            $cursor = 0;
            while (($offset = strpos($source, $call, $cursor)) !== false) {
                $cursor = $offset + 1;
                $prefix = substr($source, max(0, $offset - 12), min(12, $offset));
                if (preg_match('/function\s+$/', $prefix) === 1) {
                    continue;
                }
                $callSites[] = $offset;
            }

            $this->assertNotEmpty($callSites, "{$relative} 이 {$call} 을 부르지 않는다 (주석·정의 제외) — {$why}");

            if ($enclosing !== null) {
                $enclosingNames = array_map(fn (int $o): string => $this->enclosingFunctionName($source, $o), $callSites);
                $this->assertContains(
                    $enclosing,
                    $enclosingNames,
                    "{$relative} 의 {$call} 이 {$enclosing}() 밖으로 나갔다 (발견: ".implode(', ', $enclosingNames).") — {$why}"
                );
            }
        }
    }

    /**
     * 브라우저가 반복 호출하며 JSON 으로 파싱하는 엔드포인트는 최상위 Throwable 봉투를 갖는다.
     *
     * 이 셋은 헤더를 application/json 으로 확정한 뒤 본문을 만든다 — Error/TypeError 가 새면
     * 비-JSON 본문이 나가 프론트의 res.json() 이 깨지고, 운영자에게는 실제 실패 사유 대신
     * 일반 통신 오류만 보인다. 일회성 probe(check-env·session-probe)와 SSE 주 응답
     * (install-worker)은 이 모집단이 아니다 — 반복 파싱 루프에 실리지 않는다.
     *
     * @effects stepwise_endpoint_wraps_uncaught_errors_in_a_json_envelope
     */
    public function test_browser_polled_json_endpoints_have_a_top_level_throwable_envelope(): void
    {
        foreach (['run-task.php', 'state-management.php', 'install-process.php'] as $file) {
            $src = $this->stripComments((string) file_get_contents($this->apiDir().'/'.$file));

            $this->assertStringContainsString(
                'catch (Throwable',
                $src,
                "{$file} 은 JSON 계약이라 Throwable 을 받아 JSON 으로 응답해야 한다"
            );
        }
    }

    /**
     * run-task.php 의 보호 구간은 상태 읽기보다 **앞에서** 열려야 한다.
     *
     * 손상된 state.json 이나 runtime.php 로 인한 Error 가 나는 곳이 바로 그 구간이다 —
     * lock 획득 이후만 감싸면 정작 위험한 초반 구간이 봉투 밖에 남는다. 응답 헬퍼는
     * try 밖에서 정의돼야 catch 가 응답할 수 있다 (try 안 선언은 그 지점에 닿아야 정의된다).
     */
    public function test_run_task_protected_region_starts_before_state_read(): void
    {
        // 주석을 지워도 줄 수는 보존되므로 **순서** 판정은 그대로 유효하다 —
        // 주석 안의 `try {` 가 보호 구간의 시작 행세를 하지 못하게 한다.
        $src = $this->stripComments((string) file_get_contents($this->apiDir().'/run-task.php'));

        $respondAt = strpos($src, 'function stepwiseRespond(');
        $failAt = strpos($src, 'function stepwiseFail(');
        $tryAt = strpos($src, "\ntry {");
        $stateAt = strpos($src, '$state = getInstallationState();');

        $this->assertNotFalse($failAt, 'stepwiseFail() 이 있어야 catch 가 JSON 으로 응답할 수 있다');
        $this->assertNotFalse($tryAt, '최상위 try 블록이 있어야 한다');
        $this->assertNotFalse($stateAt);

        $this->assertLessThan($tryAt, $respondAt, 'stepwiseRespond 는 try 밖에서 정의돼야 한다');
        $this->assertLessThan($tryAt, $failAt, 'stepwiseFail 은 try 밖에서 정의돼야 한다');
        $this->assertLessThan($stateAt, $tryAt, '보호 구간이 상태 읽기보다 앞에서 열려야 한다');
    }

    /**
     * install-process.php 는 토큰을 runtime.php 에만 싣고 state.json 에는 싣지 않는다 (R3).
     */
    public function test_install_process_keeps_stepwise_token_out_of_state(): void
    {
        $src = $this->stripComments((string) file_get_contents($this->apiDir().'/install-process.php'));

        $this->assertMatchesRegularExpression('/\$runtime\[\'installer\'\]\s*=\s*\[\'stepwise_token\'/', $src);
        $this->assertDoesNotMatchRegularExpression('/\$state\[[^\]]*token[^\]]*\]/i', $src, '토큰이 state 배열에 대입되면 안 된다 (R3)');
    }

    /**
     * 토큰 판정 배선 검사도 주석 채널을 구분한다 (12차 GI-3 부정 픽스처).
     *
     * 위 두 검사(run-task·skip_task)는 종전에 `file_get_contents` **원문**을 봤다 —
     * 형제 2검사만 `stripComments()` 를 쓰고 있어 한 파일 안에서 규칙이 갈려 있었다.
     * 실 소스가 준수 상태라 그 사실은 red 로 드러나지 않는다. 합성 입력으로 고정한다.
     */
    public function test_token_judgment_detection_ignores_commented_out_calls(): void
    {
        $live = "<?php
require_once __DIR__.'/../includes/execution-mode.php';
\$tokenRejection = installerStepwiseTokenRejection(\$presented, \$expected);
";
        $commentedOut = "<?php
require_once __DIR__.'/../includes/execution-mode.php';
// \$tokenRejection = installerStepwiseTokenRejection(\$presented, \$expected);
";

        $this->assertStringContainsString('installerStepwiseTokenRejection(', $this->stripComments($live));
        $this->assertStringContainsString(
            'installerStepwiseTokenRejection(',
            $commentedOut,
            '원문 검색은 주석 처리된 판정 호출을 구분하지 못한다 — 이 사실이 곧 stripComments 가 필요한 이유다'
        );
        $this->assertStringNotContainsString(
            'installerStepwiseTokenRejection(',
            $this->stripComments($commentedOut),
            '주석을 지운 뒤에는 호출이 남지 않아야 한다 — 남으면 토큰 판정을 주석으로 꺼도 이 검사가 초록이다'
        );

        // 인라인 복제 금지 축도 같은 규칙을 받아야 한다 — 주석 안의 hash_equals( 는 복제가 아니다.
        $commentedClone = '<?php
// $ok = hash_equals($presented, $expected);
';
        $this->assertStringNotContainsString('hash_equals(', $this->stripComments($commentedClone));
    }

    /**
     * 판정 정규식의 **부정 분기**를 합성 입력으로 태운다 (9차 #G14).
     *
     * 실 엔드포인트가 전부 준수 상태라 위 검사들은 늘 통과한다 — 정규식이 무엇이든 통과시키게
     * 망가져도 red 가 나지 않는다. 「가드가 없는 엔드포인트」를 실제로 만들어 잡히는지 본다.
     */
    public function test_guard_detection_actually_discriminates(): void
    {
        $loadPattern = '/require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]\/_guard\.php[\'"]/';
        $callPattern = '/installer_guard(?:_finalize)?_or_410\s*\(\s*\)/';

        $compliant = "<?php
require_once __DIR__.'/_guard.php';
installer_guard_or_410();
";
        $finalize = "<?php
require_once __DIR__.'/_guard.php';
installer_guard_finalize_or_410();
";
        $noLoad = '<?php
installer_guard_or_410();
';
        $noCall = "<?php
require_once __DIR__.'/_guard.php';
";
        $lookalike = '<?php
// installer_guard_or_410 은 여기서 부르지 않는다
$x = 1;
';

        $this->assertMatchesRegularExpression($loadPattern, $compliant);
        $this->assertMatchesRegularExpression($callPattern, $compliant);
        $this->assertMatchesRegularExpression($callPattern, $finalize, '완화 가드도 「호출했다」로 인정된다');

        $this->assertDoesNotMatchRegularExpression($loadPattern, $noLoad, '로드 없이 호출만 하는 파일을 통과시키면 R5 가 무의미해진다');
        $this->assertDoesNotMatchRegularExpression($callPattern, $noCall, '로드만 하고 부르지 않으면 가드는 없는 것과 같다');
        $this->assertDoesNotMatchRegularExpression($callPattern, $lookalike, '이름만 언급한 주석은 호출이 아니다');

        // 주석 채널 — 괄호까지 포함해 주석으로 감싼 호출은 원문 정규식을 **통과한다**.
        // 그래서 검사는 반드시 주석을 지운 뒤에 돌아야 한다 (10차 #G4).
        $commentedOut = "<?php
require_once __DIR__.'/_guard.php';
// installer_guard_or_410();
";
        $this->assertMatchesRegularExpression(
            $callPattern,
            $commentedOut,
            '원문 검색은 주석 처리된 호출을 구분하지 못한다 — 이 사실이 곧 stripComments 가 필요한 이유다'
        );
        $this->assertDoesNotMatchRegularExpression(
            $callPattern,
            $this->stripComments($commentedOut),
            '주석을 지운 뒤에는 호출이 남지 않아야 한다 — 남으면 가드를 주석으로 꺼도 전 엔드포인트 검사가 초록이다'
        );
    }
}
