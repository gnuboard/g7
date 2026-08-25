<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 워커 lock 에 선언하는 시간 예산은 **실제로 허용된 값**이어야 한다 (PO 결정 13차 환경#9).
 *
 * `set_time_limit()` 은 요청한 값을 항상 얻어내지 못한다 — `disable_functions` 로 막혀 있거나
 * 호스팅이 ini 를 잠그면 호출은 조용히 무시된다. 그런데 워커 lock 은 **선언한 값**을 그대로
 * 시간 예산으로 기록했다. 그래서 30초에 강제 종료되는 호스팅에서 600초를 선언하면 죽은
 * 워커의 예산이 최대 10분 남아 그동안 모든 진입점이 거짓 busy 를 받는다 — 운영자에게는
 * 화면이 멈춘 채 「다른 창에서 진행 중」만 보인다. 오류도 로그도 남지 않는다.
 *
 * ## 이 조치가 덮지 못하는 축
 *
 * `php-fpm` 의 `request_terminate_timeout` 과 LiteSpeed 의 `lsapi_max_process_time` 은
 * **PHP 에서 읽을 수 없다**. 이 계약은 「`ini_get` 이 말하는 실제 적용치」까지만 좁히며,
 * FPM 이 그보다 먼저 프로세스를 죽이는 경우는 여전히 남는다(계획서 §16.19.4). 완전한
 * 해결로 읽지 말 것 — 그 사실은 `installerEffectiveTimeLimit()` 의 문서 주석이 소유한다.
 *
 * 커버 축: time_limit_applied granted·capped·blocked·unlimited·unreadable / declaration_site stepwise·subprocess·inprocess
 *
 * @effects installer_declared_time_budget_never_exceeds_the_applied_limit, installer_lock_declarations_use_the_applied_time_budget
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerTimeBudgetDeclarationTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/stubs/lang_stub.php';

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-installer-timebudget-'.bin2hex(random_bytes(4)));
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';
    }

    /**
     * `set_time_limit` 이 먹지 않는 상황 — ini 가 말하는 실제 적용치가 요청값보다 작다.
     */
    public function test_declared_budget_never_exceeds_the_limit_the_host_actually_applied(): void
    {
        // 호스팅이 30초로 잠갔다 — 600 을 요청해도 선언은 30 이어야 한다.
        $this->assertSame(30, installerEffectiveTimeLimit(600, '30'));
        $this->assertSame(30, installerEffectiveTimeLimit(600, 30));

        // 요청값이 더 작으면 그대로 — 좁히기만 하고 늘리지 않는다.
        $this->assertSame(60, installerEffectiveTimeLimit(60, '180'));
    }

    /**
     * 0(무제한)·음수는 상한이 없다는 뜻이다 — CLI·일부 SAPI 가 그렇다.
     */
    public function test_unlimited_execution_time_keeps_the_requested_budget(): void
    {
        $this->assertSame(600, installerEffectiveTimeLimit(600, '0'));
        $this->assertSame(600, installerEffectiveTimeLimit(600, 0));
        $this->assertSame(600, installerEffectiveTimeLimit(600, '-1'));
    }

    /**
     * 값을 읽을 수 없으면 좁히지 않는다.
     *
     * 「확인 불가」를 0 초로 접으면 lock 선언이 사라져, 살아 있는 워커가 곧바로 stale 로
     * 탈취되고 같은 태스크가 겹쳐 돈다 — 좁히려다 반대편 결함을 만드는 형태다.
     */
    public function test_unreadable_ini_value_leaves_the_requested_budget_untouched(): void
    {
        $this->assertSame(600, installerEffectiveTimeLimit(600, false));
        $this->assertSame(600, installerEffectiveTimeLimit(600, null));
        $this->assertSame(600, installerEffectiveTimeLimit(600, '   '));
    }

    /**
     * 헬퍼 자신도 실제 적용치를 넘겨 돌려주지 않는다.
     *
     * `set_time_limit` 이 먹은 환경(CLI)에서는 요청값이 그대로 나오고, 막히거나 잠긴
     * 환경에서는 `ini_get` 이 말하는 값 이하가 나온다 — 어느 쪽이든 「선언 ≤ 실제」다.
     */
    public function test_helper_return_value_is_bounded_by_the_ini_value_after_the_call(): void
    {
        $declared = installerSetTimeLimit(600);
        $applied = (int) ini_get('max_execution_time');

        $this->assertGreaterThan(0, $declared, '선언값이 0 이하면 lock 선언이 사라진다');

        if ($applied > 0) {
            $this->assertLessThanOrEqual(
                $applied,
                $declared,
                'lock 에 선언한 시간이 실제 허용된 시간보다 길다 — 죽은 워커의 예산이 그 차이만큼 남는다',
            );
        } else {
            $this->assertSame(600, $declared, '무제한 환경에서는 요청값을 그대로 선언한다');
        }
    }

    /**
     * 선언 지점 셋이 요청 상수가 아니라 **헬퍼가 돌려준 값**을 넘긴다.
     *
     * 헬퍼가 실제 적용치를 돌려주더라도 호출부가 상수를 그대로 넘기면 이 조치는 사문이
     * 된다 — 그 상태에서도 헬퍼 단위 테스트는 전부 초록이다.
     */
    public function test_every_lock_declaration_passes_the_applied_budget_not_the_requested_constant(): void
    {
        $root = dirname(__DIR__, 3).'/public/install';

        $executor = $this->stripComments((string) file_get_contents($root.'/includes/artisan-executor.php'));
        $runTask = $this->stripComments((string) file_get_contents($root.'/api/run-task.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/extendWorkerStaleWindow\(\s*\$workerId\s*,\s*INSTALLER_(?:SUB|IN)PROCESS_TIME_LIMIT\s*\)/',
            $executor,
            "요청 상수를 그대로 lock 에 선언한다 — installerSetTimeLimit() 의 반환값을 넘겨야 한다.\n"
            .'그 값을 얻지 못한 환경에서 죽은 워커의 예산이 최대 10분 남는다.',
        );
        $this->assertMatchesRegularExpression(
            '/\$timeBudget\s*=\s*installerSetTimeLimit\(\s*INSTALLER_SUBPROCESS_TIME_LIMIT\s*\)/',
            $executor,
            '서브프로세스 경로가 적용치를 받아 두지 않는다',
        );
        $this->assertMatchesRegularExpression(
            '/\$timeBudget\s*=\s*installerSetTimeLimit\(\s*INSTALLER_INPROCESS_TIME_LIMIT\s*\)/',
            $executor,
            'in-process 경로가 적용치를 받아 두지 않는다',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/acquireWorkerLock\(\s*STEPWISE_LOCK_STALE_SECONDS\s*\)/',
            $runTask,
            "단계별 실행이 요청 상수를 그대로 lock 에 선언한다 — 적용치를 먼저 확정해 넘겨야 한다.\n"
            .'`set_time_limit(180)` 이 먹지 않는 호스팅에서 30초에 죽은 요청의 점유가 3분 남는다.',
        );
        $this->assertMatchesRegularExpression(
            '/installerSetTimeLimit\(\s*STEPWISE_LOCK_STALE_SECONDS\s*\)[\s\S]{0,600}?acquireWorkerLock\(\s*\$stepwiseTimeBudget\s*\)/',
            $runTask,
            '수명 확정이 lock 획득보다 **뒤**에 있으면 lock 이 확정 전 값을 받는다 — 순서가 계약이다',
        );
    }
}
