<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Exceptions\UpgradeHandoffException;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CoreUpdateCommand 의 핸드오프 통합 계약 테스트 (spawn 파싱 경로)
 *
 * `handle()` 전체 체인(11 Step) 을 통과시키는 통합 테스트는 GitHub 연동·migration·
 * vendor 설치 등 사이드이펙트가 크고 mock 면적이 방대하다. 따라서 본 테스트는
 * handle() 의 **catch (UpgradeHandoffException) 분기로 들어가는 입구**에 해당하는
 * `spawnUpgradeStepsProcess` 의 파싱 계약을 검증한다. 이 입구가 보장되면:
 *
 *  - spawn 자식의 [HANDOFF] + exit 75 → 부모가 UpgradeHandoffException 재구성 → throw
 *  - 재구성된 예외는 handle() 의 try 전체를 덮는 catch 블록이 포착하여 cleanup 분기로 진입
 *
 * handle() catch 블록 내부 cleanup 시퀀스 (updateVersionInEnv(toVersion),
 * clearAllCaches, restoreOwnership, cleanupPending, disableMaintenanceMode,
 * 사용자 안내) 는 본 테스트 범위에서 제외되며, 운영자 Linux 서버 수동 검증으로 보완한다.
 *
 * 본 테스트는 실제 proc_open 으로 자식 PHP 프로세스를 띄우므로 proc_open 미지원
 * 환경에서는 skip.
 */
class CoreUpdateCommandHandoffTest extends TestCase
{
    private string $handoffStepPath;

    private string $failingStepPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handoffStepPath = base_path('upgrades/Upgrade_0_0_1_test_integration_handoff.php');
        $this->failingStepPath = base_path('upgrades/Upgrade_0_0_1_test_integration_fail.php');
    }

    protected function tearDown(): void
    {
        foreach ([$this->handoffStepPath, $this->failingStepPath] as $p) {
            if (File::exists($p)) {
                File::delete($p);
            }
        }

        parent::tearDown();
    }

    /**
     * 자식 프로세스가 UpgradeHandoffException 을 던지면 부모의
     * spawnUpgradeStepsProcess 가 [HANDOFF] stdout + exit 75 를 파싱해
     * UpgradeHandoffException 을 재구성하여 상위로 throw 한다.
     */
    public function test_spawn_rethrows_handoff_exception_from_child(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        File::put($this->handoffStepPath, <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Exceptions\UpgradeHandoffException;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_integration_handoff implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        throw new UpgradeHandoffException(
            afterVersion: '0.0.0',
            reason: '자식 프로세스 핸드오프',
            resumeCommand: 'php artisan custom:resume',
        );
    }
}
PHP);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        $logs = [];
        $logCollector = function (string $message) use (&$logs) {
            $logs[] = $message;
        };

        try {
            $method->invoke($command, '0.0.0', '0.0.1', true, $logCollector);
            $this->fail('UpgradeHandoffException 이 상위로 전파되어야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertSame('0.0.0', $e->afterVersion);
            $this->assertSame('자식 프로세스 핸드오프', $e->reason);
            $this->assertSame('php artisan custom:resume', $e->resumeCommand);
        }

        // 부모 로그에 핸드오프 수신 흔적이 있어야 한다
        $handoffLog = implode("\n", $logs);
        $this->assertStringContainsString('핸드오프', $handoffLog);
    }

    /**
     * 자식 프로세스가 정상 완료(exit 0)되면 spawn 은 true 를 반환한다.
     * Handoff 파싱 로직이 정상 경로를 손상시키지 않는지 회귀 가드.
     *
     * 본 테스트는 실 step 1건이 실행되는 시나리오 — `spawn_failure_mode` 의 silent skip 가드
     * (step 0건 차단) 을 통과하기 위해 동일 버전 + --force 로 무해한 step 1건만 실행시킨다.
     */
    public function test_spawn_returns_true_on_normal_exit(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        File::put($this->handoffStepPath, <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_integration_handoff implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        // no-op — step 1건 실행되도록만 보장
    }
}
PHP);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        $result = $method->invoke($command, '0.0.0', '0.0.1', true, fn () => null);

        $this->assertTrue($result, '정상 종료 시 true 반환해야 한다');
    }

    /**
     * 자식 프로세스가 일반 실패(exit != 0, != 75)면 spawn 은 false 를 반환하여
     * 상위 호출자가 in-process fallback 경로로 전환할 수 있게 한다.
     *
     * 본 회귀 가드는 `spawn_failure_mode=fallback` 의 호환 모드 동작을 검증한다.
     * 기본 abort 모드 동작은 `CoreUpdateCommandSpawnFailureTest` 가 별도로 검증한다.
     */
    public function test_spawn_returns_false_on_generic_failure(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'fallback']);

        File::put($this->failingStepPath, <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_integration_fail implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        throw new \RuntimeException('자식 일반 실패');
    }
}
PHP);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        $result = $method->invoke($command, '0.0.0', '0.0.1', true, fn () => null);

        $this->assertFalse($result, '일반 실패 시 false 반환 (in-process fallback 유도)');
    }

    /**
     * resumeCommand 자동 생성 계약:
     * 자식이 resumeCommand=null 로 핸드오프 신호를 보낸 경우, 재구성된
     * UpgradeHandoffException::resumeCommand 는 null 이어야 한다 (부모 CoreUpdateCommand
     * 의 catch 블록이 sprintf 로 자동 생성하는 기본값 트리거 조건).
     */
    public function test_spawn_preserves_null_resume_command_for_auto_generation(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        File::put($this->handoffStepPath, <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Exceptions\UpgradeHandoffException;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_integration_handoff implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        throw new UpgradeHandoffException(
            afterVersion: '0.0.0',
            reason: '자동 생성 필요',
        );
    }
}
PHP);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        try {
            $method->invoke($command, '0.0.0', '0.0.1', true, fn () => null);
            $this->fail('UpgradeHandoffException 이 전파되어야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertNull(
                $e->resumeCommand,
                'resumeCommand 는 null 로 유지되어야 CoreUpdateCommand 가 자동 생성한다'
            );
        }
    }

    /**
     * isValidHandoffPayload — 빈 afterVersion 거부.
     *
     * 회귀 시나리오: 자식 stdout 의 [HANDOFF] 라인이 `afterVersion=""` 으로 도착하면
     * Step 11 fromVersion 해석이 혼란스러워진다 (CoreUpdateCommand catch 분기의 sprintf
     * 자동 생성에서도 비정상 명령어 출력). 검증 거부 후 정상 출력으로 fall through.
     */
    public function test_isValidHandoffPayload_rejects_empty_after_version(): void
    {
        $command = app(CoreUpdateCommand::class);
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'isValidHandoffPayload');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($command, [
            'afterVersion' => '',
            'reason' => '정상 사유',
            'resumeCommand' => null,
        ]));

        $this->assertFalse($method->invoke($command, [
            'afterVersion' => 'not-a-version',
            'reason' => '정상 사유',
            'resumeCommand' => null,
        ]));

        $this->assertTrue($method->invoke($command, [
            'afterVersion' => '7.0.0-beta.4',
            'reason' => '정상 사유',
            'resumeCommand' => null,
        ]));
    }

    /**
     * isValidHandoffPayload — reason 과도 길이 거부.
     *
     * 회귀 시나리오: 자식이 비정상적으로 긴 reason 문자열을 보내면 부모 로그 / 운영자
     * 안내 출력이 폭증한다. 500자 이상은 비정상으로 간주하고 거부.
     */
    public function test_isValidHandoffPayload_rejects_oversized_reason(): void
    {
        $command = app(CoreUpdateCommand::class);
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'isValidHandoffPayload');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($command, [
            'afterVersion' => '7.0.0-beta.4',
            'reason' => str_repeat('a', 500),
            'resumeCommand' => null,
        ]));

        $this->assertTrue($method->invoke($command, [
            'afterVersion' => '7.0.0-beta.4',
            'reason' => str_repeat('a', 499),
            'resumeCommand' => null,
        ]));
    }

    /**
     * isValidHandoffPayload — resumeCommand shell metacharacter / 과도 길이 거부.
     *
     * 회귀 시나리오: 자식이 손상되거나 악의적으로 조작된 resumeCommand 를 보내면
     * 부모가 운영자 안내에 shell injection 문자열을 출력. 정상 명령(`php artisan ...`)
     * 에 등장하지 않는 metacharacter 가 포함되면 거부.
     */
    public function test_isValidHandoffPayload_rejects_shell_metacharacters_in_resume_command(): void
    {
        $command = app(CoreUpdateCommand::class);
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'isValidHandoffPayload');
        $method->setAccessible(true);

        foreach (['; rm -rf /', '`whoami`', '$(id)', 'php a && php b', 'php a | tee x', 'php a > x'] as $bad) {
            $this->assertFalse(
                $method->invoke($command, [
                    'afterVersion' => '7.0.0-beta.4',
                    'reason' => '정상 사유',
                    'resumeCommand' => $bad,
                ]),
                "shell metacharacter 가 포함된 resumeCommand 거부: {$bad}",
            );
        }

        $this->assertFalse($method->invoke($command, [
            'afterVersion' => '7.0.0-beta.4',
            'reason' => '정상 사유',
            'resumeCommand' => str_repeat('a', 1000),
        ]));

        $this->assertTrue($method->invoke($command, [
            'afterVersion' => '7.0.0-beta.4',
            'reason' => '정상 사유',
            'resumeCommand' => 'php artisan core:update --force --from=7.0.0-beta.4',
        ]));

        $this->assertTrue($method->invoke($command, [
            'afterVersion' => '7.0.0-beta.4',
            'reason' => '정상 사유',
            'resumeCommand' => null,
        ]));
    }

    /**
     * CoreUpdateCommand 를 OutputStyle 주입 없이 리플렉션 호출 가능한 형태로 준비.
     * spawnUpgradeStepsProcess 내부에서 $this->line / $this->info 를 호출하므로
     * OutputStyle 이 필요. Artisan 의 기본 Buffered Output 로 대체.
     */
    private function makeCommandWithDummyIo(): CoreUpdateCommand
    {
        $command = app(CoreUpdateCommand::class);

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $output = new \Symfony\Component\Console\Output\BufferedOutput;
        $style = new \Illuminate\Console\OutputStyle($input, $output);

        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('output');
        $property->setAccessible(true);
        $property->setValue($command, $style);

        // laravel-zero 등 컨테이너 의존 호출 대비 input 도 설정
        if ($reflection->hasProperty('input')) {
            $inputProp = $reflection->getProperty('input');
            $inputProp->setAccessible(true);
            $inputProp->setValue($command, $input);
        }

        return $command;
    }
}
