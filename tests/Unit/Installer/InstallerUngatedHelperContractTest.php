<?php

namespace Tests\Unit\Installer;

use App\Execution\ExecutionMode;
use App\Support\ExecutionEnvironment;
use App\Support\PhpBinaryResolver;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * G2 가 신설했으나 어느 테스트도 부르지 않던 인스톨러 헬퍼들의 계약.
 *
 * "테스트 참조 0" 은 §16.11.2 #6 이 회귀 게이트를 세운 근거로 쓴 바로 그 지표다 — 그 배선이
 * 지워져도 전 스위트가 초록이라는 뜻이고, 실제로 그중 둘(`recordInstallationRunException` ·
 * `installerTaskKeyDisplayName`)에서 6차 라운드가 결함을 다시 찾았다. 여기서는 나머지
 * 헬퍼들의 계약을 고정한다 — 각각이 **무엇을 약속하는지**만 잡고, 호출 흐름은 이미
 * InstallerStepwiseRunnerTest 가 덮는다.
 *
 * 커버 축: execution_mode_choice auto·subprocess·inprocess·invalid / runner_strategy subprocess·inprocess·override / task_outcome best_effort_failed·done
 *
 * 효과 요약(마커 아님 — 평문): 실행 모드 어휘는 코어 열거형에서 오고, best-effort 실패는 한 번만 기록되며, 태스크 표시 이름은 운영자용이고, 런타임 파일 쓰기는 OPcache 를 무효화한다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerUngatedHelperContractTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 프로세스마다 한 번 임시 BASE_PATH 와 저장소 디렉토리를 만들고 인스톨러 런타임, 실행기, 태스크 러너와 한국어 번역을 적재한다.
     *
     * BASE_PATH 가 이미 시스템 임시 디렉토리 밖으로 정의돼 있으면 적재하지 않고 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    private static function bootInstallerRuntime(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 상태 파일 파괴 방지를 위해 skip.';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-ungated-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/run-task.php';
        }

        $root = dirname(__DIR__, 3);
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/includes/execution-mode.php';
        require_once $root.'/public/install/includes/artisan-executor.php';
        require_once $root.'/public/install/includes/task-runner.php';

        $GLOBALS['translations'] = loadTranslations('ko');
    }

    /**
     * 런타임을 적재하고 상태 파일, 설치 로그, 런타임 파일을 지운 뒤 진행 기록을 버퍼 송신기로 받는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::bootInstallerRuntime();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach ([self::$base.'/storage/installer-state.json', self::$base.'/storage/logs/installation.log', INSTALLER_RUNTIME_PATH] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        setProgressEmitter(new \BufferEmitter);
    }

    /**
     * 실행 모드 어휘는 코어 열거형에서 나온다 — 인스톨러가 자기 목록을 따로 들지 않는다
     * (§16.9.1 #3). 인스톨러가 목록을 복제하면 코어에 모드가 늘어도 화면·검증이 따라가지 않는다.
     *
     * @scenario execution_mode_choice=auto, execution_mode_choice=subprocess, execution_mode_choice=inprocess
     *
     * @effects installer_execution_mode_vocabulary_comes_from_core_enum
     *
     * @return void 반환값 없음
     */
    public function test_execution_mode_vocabulary_comes_from_the_core_enum(): void
    {
        $modes = installerExecutionModes();

        $this->assertSame(ExecutionMode::values(), $modes, '인스톨러 어휘 = 코어 열거형 값');
        $this->assertContains('auto', $modes);
        $this->assertContains('subprocess', $modes);
        $this->assertContains('inprocess', $modes);
    }

    /**
     * 판정 SSoT 로드는 멱등이고, 로드 후에는 코어 클래스를 쓸 수 있어야 한다.
     *
     * @scenario mode_touchpoint=execution_mode_read
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_execution_environment_loader_is_idempotent(): void
    {
        // 오토로더를 우회해 판정한다 (11차 #D7). PHPUnit 프로세스에는 Composer 오토로더가
        // 있어 `class_exists()` 는 이 함수가 아무 일도 하지 않아도 참이다 — 본문을 통째로
        // 비워도 초록이었다. 실 인스톨러에는 오토로더가 없고, 이 함수가 존재하는 이유가
        // 정확히 그것이다.
        installerEnsureExecutionEnvironmentLoaded();
        installerEnsureExecutionEnvironmentLoaded();

        foreach ([
            ExecutionEnvironment::class,
            PhpBinaryResolver::class,
            ExecutionMode::class,
        ] as $class) {
            $this->assertTrue(
                class_exists($class, false),
                $class.' 가 로드되지 않았다 — 로더가 실제로 require 하지 않으면 오토로더 없는 인스톨러에서 fatal 이 된다'
            );
        }
    }

    /**
     * 감지는 판정 결과와 근거를 함께 돌려준다 — 화면(W1·Step 3)이 사유를 그린다.
     *
     * @scenario execution_mode_choice=auto, php_binary_source=detected_autodetect
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_detect_execution_mode_reports_mode_and_basis(): void
    {
        $report = installerDetectExecutionMode('php');

        $this->assertContains($report['mode'], ['subprocess', 'inprocess'], '판정은 두 모드 중 하나로 확정된다');
        // 키 존재만 보면 값을 늘 null·빈 배열로 채워도 초록이다 (11차 #D15).
        $this->assertIsArray($report['blocked_functions']);
        $this->assertIsArray($report['php_probe']);
        $this->assertArrayHasKey('sapi', $report['php_probe'], 'probe 는 SAPI 를 실어 올린다 (§16.11.1 #5)');
        $this->assertArrayHasKey('status', $report['php_probe']);
        $this->assertContains(
            $report['php_probe']['status'],
            ['valid', 'invalid', 'unavailable'],
            'probe 상태는 닫힌 어휘다 — 값이 비면 화면이 근거를 그릴 수 없다'
        );

        // 이 프로세스에서 실제로 도달 가능한 값 — 차단 함수가 없으면 probe 가 수행된다.
        if ($report['blocked_functions'] === []) {
            $this->assertNotSame('unavailable', $report['php_probe']['status'], '차단 함수가 없는데 probe 를 건너뛰면 판정 근거가 사라진다');
        }
    }

    /**
     * 실행기가 고르는 모드는 상태의 확정값(R1)을 따르고, 없으면 subprocess 다 (기본값 불변).
     *
     * @scenario execution_mode_resolved=inprocess, execution_mode_resolved=absent
     *
     * @effects installer_execution_mode_resolved_state_wins_over_redetection
     *
     * @return void 반환값 없음
     */
    public function test_artisan_execution_mode_follows_the_resolved_state_and_defaults_to_subprocess(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        saveInstallationState($state);

        $this->assertSame('subprocess', installerArtisanExecutionMode(), '확정값이 없으면 subprocess (기본값 불변)');

        $state['execution_mode_resolved'] = 'inprocess';
        saveInstallationState($state);

        $this->assertSame('inprocess', installerArtisanExecutionMode());
    }

    /**
     * best-effort 실패는 한 번만 기록된다 — 중복 누적되면 pending 필터가 같은 태스크를
     * 반복해 무한 루프가 된다 (§16.8.1 #1 이 닫은 결함).
     *
     * @scenario task_outcome=best_effort_failed, task=config_cache
     *
     * @effects installer_best_effort_failure_is_recorded_once
     *
     * @return void 반환값 없음
     */
    public function test_best_effort_failure_is_recorded_once(): void
    {
        $state = DEFAULT_INSTALLATION_STATE;
        $state['installation_status'] = 'running';
        saveInstallationState($state);

        $this->assertTrue(markTaskBestEffortFailed('config_cache'));
        markTaskBestEffortFailed('config_cache');

        $this->assertSame(['config_cache'], getInstallationState()['best_effort_failed_tasks'] ?? []);
    }

    /**
     * 태스크 표시 이름은 운영자용이다 (§12.0 W3) — 대상이 있으면 괄호로 덧붙인다.
     *
     * @scenario task=db_migrate, task=module
     *
     * @effects installer_task_display_name_is_operator_facing
     *
     * @return void 반환값 없음
     */
    public function test_task_display_name_is_operator_facing(): void
    {
        $bare = installerTaskDisplayName(['id' => 'db_migrate', 'target' => null]);
        $withTarget = installerTaskDisplayName(['id' => 'module_install', 'target' => 'sirsoft-board']);

        $this->assertNotSame('db_migrate', $bare, '번역된 이름이어야 한다');
        $this->assertStringContainsString('(sirsoft-board)', $withTarget);
        $this->assertStringNotContainsString('task_', $withTarget, '번역 키 원문이 새면 안 된다');
    }

    /**
     * runtime.php 는 요청마다 다시 읽히는 include 라, 쓴 뒤 OPcache 를 무효화하지 않으면
     * 다음 요청이 이전 내용을 본다 (stepwise 토큰이 stale 이 되어 403 — P0 실측 결함).
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_runtime_opcache_is_invalidated_on_write
     *
     * @return void 반환값 없음
     */
    public function test_runtime_write_invalidates_opcache_and_round_trips(): void
    {
        writeInstallerRuntime(['installer' => ['stepwise_token' => 'tok-1']]);
        $this->assertSame('tok-1', readInstallerRuntime()['installer']['stepwise_token'] ?? null);

        // 19차 G#1 — 종전에는 여기서 검사가 **스스로** `installerInvalidateRuntimeOpcache()` 를
        // 부른 뒤 읽었다. 그러면 생산 배선(`installer-runtime.php` 의 두 호출)을 지워도 초록이라
        // 계약을 전혀 보지 않는다. 게다가 PHPUnit CLI 는 opcache 가 꺼져 있어 round-trip 은
        // 무효화와 무관하게 늘 통과한다 — 그 자리가 P0 에서 실측으로 한 번 터진 결함
        // (stepwise 토큰 stale → 403)의 **유일한** 게이트였다.
        //
        // 라운드트립은 그대로 두되(회귀 감시로서의 값은 있다) 대신 호출을 제거하고,
        // 배선 자체는 아래 검사가 소유한다.
        writeInstallerRuntime(['installer' => ['stepwise_token' => 'tok-2']]);

        $this->assertSame('tok-2', readInstallerRuntime()['installer']['stepwise_token'] ?? null, '갱신된 내용이 즉시 읽혀야 한다');
    }

    /**
     * 19차 G#1 — 쓰기·읽기 **양쪽**이 OPcache 를 무효화한다.
     *
     * `runtime.php` 는 PHP 파일이라 OPcache 가 캐시한다. 무효화가 빠지면
     * `opcache.revalidate_freq`(기본 2초) 창 안의 다음 요청이 **이전 내용**을 include 한다 —
     * 요청 간격이 0초인 단계별 실행에서 갱신 직후의 토큰이 stale 이 되어 403 이 된다.
     * P0 환경에서 실측으로 확인된 결함이고, 그 배선을 보는 검사가 저장소에 0건이었다.
     *
     * CLI 에서는 opcache 가 꺼져 있어 행위로 재현할 수 없으므로 **배선**을 고정한다.
     * 함수 본문은 중괄호로 잘라 본다 — 파일 전체를 보면 한쪽만 남아도 초록이 된다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_runtime_opcache_is_invalidated_on_write
     *
     * @return void 반환값 없음
     */
    public function test_runtime_helpers_wire_the_opcache_invalidation(): void
    {
        $source = $this->stripComments(
            (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/installer-runtime.php')
        );

        foreach (['writeInstallerRuntime', 'readInstallerRuntime'] as $fn) {
            $body = $this->functionBody($source, $fn);

            $this->assertNotSame('', $body, $fn.'() 를 찾지 못했다');
            $this->assertStringContainsString(
                'installerInvalidateRuntimeOpcache(',
                $body,
                $fn.'() 가 OPcache 를 무효화하지 않으면 다음 요청이 이전 내용을 본다 (stepwise 토큰 stale → 403)',
            );
        }
    }

    /**
     * 마무리는 completed 이벤트를 정확히 한 번 낸다 — 화면이 완료로 전환되는 유일한 신호다.
     *
     * @scenario task_outcome=done, resume_point=all_completed
     *
     * @effects installer_runtime_helpers_behave_as_contracted
     *
     * @return void 반환값 없음
     */
    public function test_finalize_run_emits_exactly_one_completed_event(): void
    {
        finalizeInstallationRun();

        $events = array_column(getProgressEmitter()->drain(), 'event');

        $this->assertSame(1, count(array_filter($events, fn ($e) => $e === 'completed')));
    }

    /**
     * subprocess 실행기는 exec 이 가용한 환경에서 **실제로 스폰 경로를 탄다**.
     *
     * 짝이 되는 분기(exec 이 막힌 환경의 조기 반환)는 이 계층에서 재현할 수 없다 —
     * `disable_functions` 는 PHP_INI_SYSTEM 이라 런타임에 끌 수 없다(§8 자동화 한계).
     * 그래서 그 분기는 P0 무CLI 프로필의 실측이 판정한다.
     *
     * 여기서 고정하는 것은 그 반대 방향이다: exec 이 있는데도 조기 반환 형태를 돌려주면
     * 판정이 뒤집힌 것이다. 배열 키만 확인하면 두 분기가 구분되지 않아 어느 쪽으로
     * 뒤집혀도 초록이 된다(§16.11.3 #21 이 형제 테스트에 적용한 기준).
     *
     * @scenario runner_strategy=subprocess
     *
     * @effects installer_runner_dispatches_by_resolved_mode
     *
     * @return void 반환값 없음
     */
    public function test_subprocess_runner_takes_the_spawn_path_when_exec_is_available(): void
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $this->markTestSkipped('exec 이 막힌 환경 — 이 방향은 P0 실측이 판정한다');
        }

        $result = installerRunArtisanSubprocess('list', []);

        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $result['mode']);
        $this->assertIsArray($result['output']);

        $output = trim(implode("\n", $result['output']));
        $this->assertStringNotContainsString(
            'error_subprocess_unavailable',
            $output,
            'exec 이 가용한데 조기 반환 형태가 나왔다 — 가용성 판정이 뒤집혔다'
        );
        $this->assertStringNotContainsString(
            lang('error_subprocess_unavailable'),
            $output,
            'exec 이 가용한데 조기 반환 형태가 나왔다 — 가용성 판정이 뒤집혔다'
        );
    }

    /**
     * in-process 실행기는 vendor 가 없으면 부팅을 시도하지 않고 실패 사유를 돌려준다.
     * (테스트 BASE_PATH 에는 vendor 가 없다 — 그 경로가 바로 이 계약이다.)
     *
     * @scenario runner_strategy=inprocess_no_vendor
     *
     * @effects installer_inprocess_fails_clearly_without_vendor
     *
     * @return void 반환값 없음
     */
    public function test_inprocess_runner_fails_cleanly_without_vendor(): void
    {
        $result = installerRunArtisanInProcess('list', []);

        $this->assertNotSame(0, $result['exit_code'], 'vendor 부재에서는 성공으로 위장하지 않는다');
        $this->assertNotEmpty($result['output'], '사유가 비어 있으면 운영자가 조치할 수 없다');
    }
}
