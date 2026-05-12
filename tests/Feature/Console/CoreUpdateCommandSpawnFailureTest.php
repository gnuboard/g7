<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Exceptions\UpgradeHandoffException;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `spawn_failure_mode` + `[STEPS_EXECUTED]` 신호 + stale 메모리 가드 회귀 테스트 (§2/§2.1/§6).
 *
 * 회귀 시나리오 (이슈 #28):
 *   - spawn 자식 실패 → in-process fallback 진입 → 부모 메모리 stale → upgrade step
 *     안에서 신규 메서드 호출 fatal (예: `Call to undefined method ensureWritableDirectories()`)
 *
 * 본 테스트는 `spawnUpgradeStepsProcess` 의 mode 분기 + `failSpawnWithMode` + 자식의
 * `[STEPS_EXECUTED]` 발행 + `runUpgradeSteps` 의 stale 메모리 가드를 단위 수준에서 검증한다.
 * 실제 `core:update` 통합 흐름은 운영자의 Linux 서버 수동 검증 (계획서 §"통합 시나리오") 으로 보완.
 */
class CoreUpdateCommandSpawnFailureTest extends TestCase
{
    private string $failingStepPath;

    private string $silentStepPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->failingStepPath = base_path('upgrades/Upgrade_0_0_1_test_spawn_failure_fail.php');
        $this->silentStepPath = base_path('upgrades/Upgrade_0_0_1_test_spawn_failure_silent.php');
    }

    protected function tearDown(): void
    {
        foreach ([$this->failingStepPath, $this->silentStepPath] as $p) {
            if (File::exists($p)) {
                File::delete($p);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function failSpawnWithMode_abort_모드는_UpgradeHandoffException_을_throw_한다(): void
    {
        config(['app.update.spawn_failure_mode' => 'abort']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'failSpawnWithMode');
        $method->setAccessible(true);

        try {
            $method->invoke($command, '테스트 사유', fn () => null, '7.0.0-beta.3', '7.0.0-beta.5');
            $this->fail('mode=abort 일 때 UpgradeHandoffException 이 throw 되어야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertSame('7.0.0-beta.3', $e->afterVersion);
            $this->assertStringContainsString('테스트 사유', $e->reason);
            $this->assertStringContainsString('fail-fast', $e->reason);
            $this->assertNotNull($e->resumeCommand);
            $this->assertStringContainsString('core:execute-upgrade-steps', $e->resumeCommand);
            $this->assertStringContainsString('--from=7.0.0-beta.3', $e->resumeCommand);
            $this->assertStringContainsString('--to=7.0.0-beta.5', $e->resumeCommand);
        }
    }

    #[Test]
    public function failSpawnWithMode_fallback_모드는_false_를_반환한다(): void
    {
        config(['app.update.spawn_failure_mode' => 'fallback']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'failSpawnWithMode');
        $method->setAccessible(true);

        $logs = [];
        $result = $method->invoke(
            $command,
            '테스트 사유',
            function (string $msg) use (&$logs): void {
                $logs[] = $msg;
            },
            '7.0.0-beta.3',
            '7.0.0-beta.5',
        );

        $this->assertFalse($result, 'fallback 모드는 false 반환 (in-process fallback 진입)');
        $this->assertNotEmpty($logs, '로그 기록 필요');
        $allLogs = implode("\n", $logs);
        $this->assertStringContainsString('테스트 사유', $allLogs);
        $this->assertStringContainsString('stale 메모리 위험', $allLogs);
    }

    #[Test]
    public function spawn_자식_silent_skip_시_abort_모드는_throw_한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        // upgrade 파일 없이 호출 → 자식은 [STEPS_EXECUTED] count=0 발행 + exit=0
        // from < to 인 상태에서 0건 실행은 비정상 → mode=abort 시 throw
        config(['app.update.spawn_failure_mode' => 'abort']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        try {
            $method->invoke($command, '9.9.8', '9.9.9', true, fn () => null);
            $this->fail('step 0건 실행 시 mode=abort 에서 throw 되어야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertStringContainsString('step 0건 실행', $e->reason);
            $this->assertSame('9.9.8', $e->afterVersion);
        }
    }

    #[Test]
    public function spawn_자식_silent_skip_시_fallback_모드는_false_반환한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'fallback']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        $result = $method->invoke($command, '9.9.8', '9.9.9', true, fn () => null);

        $this->assertFalse($result, 'mode=fallback 에서 step 0건 실행은 false 반환');
    }

    #[Test]
    public function spawn_자식_정상_종료_시_STEPS_EXECUTED_파싱_후_true_반환한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'abort']);

        // 실제 step 1건이 실행되는 시나리오 — fromVersion == toVersion + --force 로
        // 동일 버전 step 만 실행되게 함. but no test step exists → 0건. 회피하려면
        // 실제 step 파일을 생성:
        File::put($this->silentStepPath, <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_spawn_failure_silent implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        // no-op step — count 가 1 이 되도록만 보장
    }
}
PHP);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        $logs = [];
        $log = function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        };

        $result = $method->invoke($command, '0.0.0', '0.0.1', true, $log);

        $this->assertTrue($result, '정상 step 실행 시 spawn 성공');

        $allLogs = implode("\n", $logs);
        $this->assertStringContainsString('실행된 step 수: 1', $allLogs, 'STEPS_EXECUTED 파싱 결과 로그');
        $this->assertStringContainsString('steps=1', $allLogs, '최종 spawn 완료 로그에 step 수 포함');
    }

    #[Test]
    public function spawn_자식_비정상_종료_시_abort_모드는_throw_한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'abort']);

        File::put($this->failingStepPath, <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_spawn_failure_fail implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        throw new \RuntimeException('자식 비정상 종료 테스트');
    }
}
PHP);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        try {
            $method->invoke($command, '0.0.0', '0.0.1', true, fn () => null);
            $this->fail('비정상 종료 시 mode=abort 에서 throw 되어야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertStringContainsString('spawn 비정상 종료', $e->reason);
            $this->assertSame('0.0.0', $e->afterVersion);
        }
    }

    #[Test]
    public function spawn_자식_비정상_종료_시_fallback_모드는_false_반환한다(): void
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

class Upgrade_0_0_1_test_spawn_failure_fail implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        throw new \RuntimeException('자식 비정상 종료 테스트');
    }
}
PHP);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        $result = $method->invoke($command, '0.0.0', '0.0.1', true, fn () => null);

        $this->assertFalse($result, 'fallback 모드는 false 반환');
    }

    #[Test]
    public function runUpgradeSteps_stale_메모리_감지_시_abort_throw_한다(): void
    {
        config(['app.version' => '7.0.0-beta.3']);
        config(['app.update.spawn_failure_mode' => 'abort']);

        $service = app(CoreUpdateService::class);

        try {
            $service->runUpgradeSteps('7.0.0-beta.3', '7.0.0-beta.5');
            $this->fail('stale 메모리 감지 시 UpgradeHandoffException 이 throw 되어야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertSame('7.0.0-beta.3', $e->afterVersion);
            $this->assertStringContainsString('stale', $e->reason);
            $this->assertStringContainsString('memory=7.0.0-beta.3', $e->reason);
            $this->assertStringContainsString('target=7.0.0-beta.5', $e->reason);
        }
    }

    #[Test]
    public function runUpgradeSteps_stale_메모리_감지_시_fallback_은_경고_후_진행한다(): void
    {
        config(['app.version' => '7.0.0-beta.3']);
        config(['app.update.spawn_failure_mode' => 'fallback']);

        $service = app(CoreUpdateService::class);

        // step 파일 없으면 stale 가드 통과 후 silent return — 예외 미발생 확인
        $service->runUpgradeSteps('7.0.0-beta.3', '7.0.0-beta.5');

        // 예외 없이 도달하면 성공 — fallback 모드는 throw 하지 않고 step 실행으로 진입
        $this->assertTrue(true);
    }

    #[Test]
    public function runUpgradeSteps_memory_가_target_과_동일하면_가드_미발동(): void
    {
        config(['app.version' => '7.0.0-beta.5']);
        config(['app.update.spawn_failure_mode' => 'abort']);

        $service = app(CoreUpdateService::class);

        // spawn 자식 시나리오: 메모리 = target → 가드 무관 → step 파일 없으면 silent return
        $service->runUpgradeSteps('7.0.0-beta.3', '7.0.0-beta.5');

        $this->assertTrue(true);
    }

    /**
     * CoreUpdateCommand 를 OutputStyle 주입 없이 리플렉션 호출 가능한 형태로 준비.
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

        if ($reflection->hasProperty('input')) {
            $inputProp = $reflection->getProperty('input');
            $inputProp->setAccessible(true);
            $inputProp->setValue($command, $input);
        }

        return $command;
    }
}
