<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Core\CoreUpdateCommand;
use App\Exceptions\UpgradeHandoffException;
use App\Services\CoreUpdateService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `spawn_failure_mode` + `[STEPS_EXECUTED]` 신호 + stale 메모리 가드 회귀 테스트 (§2/§2.1/§6).
 *
 * 회귀 시나리오 (gnuboard/g7#28):
 *   - spawn 자식 실패 → in-process fallback 진입 → 부모 메모리 stale → upgrade step
 *     안에서 신규 메서드 호출 fatal (예: `Call to undefined method ensureWritableDirectories()`)
 *
 * 본 테스트는 `spawnUpgradeStepsProcess` 의 mode 분기 + `failSpawnWithMode` + 자식의
 * `[STEPS_EXECUTED]` 발행 + `runUpgradeSteps` 의 stale 메모리 가드를 단위 수준에서 검증한다.
 * 실제 `core:update` 통합 흐름은 운영자의 Linux 서버 수동 검증 (계획서 §"통합 시나리오") 으로 보완.
 */
class CoreUpdateCommandSpawnFailureTest extends TestCase
{
    // in-process fallback 은 실제 업그레이드 스텝을 실행하고, 그 스텝이 코어
    // 역할·권한·메뉴를 동기화한다(`fresh config 기반 코어 데이터 재동기화`).
    // 트랜잭션 격리가 없으면 이 시드 데이터가 testing DB 에 영구 커밋되어,
    // 이후 모든 테스트 클래스의 `Role::create(identifier: 'admin')` 이
    // UniqueConstraintViolation 으로 깨진다(단독 실행에서는 재현되지 않음).
    use RefreshDatabase;

    private string $failingStepPath;

    private string $silentStepPath;

    private ?string $originalEnvVersion = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->failingStepPath = base_path('upgrades/Upgrade_0_0_1_test_spawn_failure_fail.php');
        $this->silentStepPath = base_path('upgrades/Upgrade_0_0_1_test_spawn_failure_silent.php');

        // stale 가드는 spawn 자식이 받는 env APP_VERSION 을 먼저 읽는다 (config 캐시가 있으면
        // config('app.version') 은 캐시에 박힌 구버전이라 신뢰할 수 없다 — 7.0.9→7.0.10 실사례).
        // 각 테스트가 부모/자식 시나리오에 맞춰 env 를 직접 세우도록 원값을 보관하고 비운다.
        $this->originalEnvVersion = $_ENV['APP_VERSION'] ?? null;
        $this->setEnvVersion(null);
    }

    protected function tearDown(): void
    {
        foreach ([$this->failingStepPath, $this->silentStepPath] as $p) {
            if (File::exists($p)) {
                File::delete($p);
            }
        }

        $this->setEnvVersion($this->originalEnvVersion);

        parent::tearDown();
    }

    /**
     * 프로세스 env 의 APP_VERSION 을 세우거나(문자열) 비운다(null).
     *
     * @param  string|null  $version  세울 버전. null 이면 세 채널($_ENV/$_SERVER/putenv) 모두 제거
     */
    private function setEnvVersion(?string $version): void
    {
        if ($version === null) {
            unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
            putenv('APP_VERSION');

            return;
        }

        $_ENV['APP_VERSION'] = $version;
        $_SERVER['APP_VERSION'] = $version;
        putenv('APP_VERSION='.$version);
    }

    #[Test]
    public function fail_spawn_with_mode_abort_모드는_upgrade_handoff_exception_을_throw_한다(): void
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
    public function fail_spawn_with_mode_fallback_모드는_false_를_반환한다(): void
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
    public function spawn_자식_범위내_스텝_파일이_없어_0건이면_정상_통과한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        // upgrade 파일 없이 호출 → 자식은 [STEPS_EXECUTED] executed=0, discovered=0 발행 + exit=0
        // "범위 내 스텝 파일 자체가 없음" 은 정상 상황 (예: 스텝 불필요 릴리즈).
        // discovered=0 이면 fail-fast 를 발동하지 않고 정상 통과해야 한다 (케이스 B).
        config(['app.update.spawn_failure_mode' => 'abort']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        $logs = [];
        $log = function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        };

        // 실 스텝 파일이 존재하지 않는 버전 범위 (9.9.8 → 9.9.9)
        $result = $method->invoke($command, '9.9.8', '9.9.9', true, $log);

        $this->assertTrue($result, '범위 내 스텝 파일이 없어 0건이면 정상 통과(true)여야 한다');
        $allLogs = implode("\n", $logs);
        $this->assertStringContainsString('실행할 스텝 없음', $allLogs, '스텝 부재 정상 통과 로그');
    }

    #[Test]
    public function spawn_자식_범위내_스텝이_있는데_0건_실행이면_abort_throw_한다(): void
    {
        // 범위 내에 스텝 파일이 실제로 존재(discovered>0)하는데 executed=0 인 경우 —
        // 이전 버전 자식이 신규 스텝을 놓치고 silent skip 한 gnuboard/g7#28 케이스 (케이스 A).
        // 이 경우에만 fail-fast 를 발동해야 한다.
        //
        // 재현: 스텝 파일은 존재하나 자식의 runUpgradeSteps 가 executed 를 0 으로 보고하도록,
        // discovered 는 세되 onStep 은 호출하지 않는 상황을 자식 프로세스 안에서 만들 수 없으므로,
        // 부모 가드 로직(handleSpawnExit)을 직접 호출해 discovered>0 && executed=0 분기를 검증한다.
        config(['app.update.spawn_failure_mode' => 'abort']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'handleSpawnExit');
        $method->setAccessible(true);

        try {
            // exitCode=0, handoffPayload=null, stepsExecuted=0, stepsDiscovered=5
            $method->invoke($command, 0, null, 0, 5, '9.9.8', '9.9.9', fn () => null);
            $this->fail('discovered>0 && executed=0 이면 mode=abort 에서 throw 되어야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertStringContainsString('step 0건 실행', $e->reason);
            $this->assertStringContainsString('발견 5건', $e->reason);
            $this->assertSame('9.9.8', $e->afterVersion);
        }
    }

    #[Test]
    public function handle_spawn_exit_executed_0_discovered_0_이면_from_lt_to_여도_정상_통과한다(): void
    {
        // 케이스 B — 범위 내 스텝 파일 부재. from<to 여도 실패 아님 (스텝 불필요 릴리즈).
        // proc_open 불필요한 순수 판정 로직 단위 검증.
        config(['app.update.spawn_failure_mode' => 'abort']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'handleSpawnExit');
        $method->setAccessible(true);

        $logs = [];
        $log = function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        };

        // exitCode=0, handoffPayload=null, stepsExecuted=0, stepsDiscovered=0
        $result = $method->invoke($command, 0, null, 0, 0, '7.0.0', '7.0.1', $log);

        $this->assertTrue($result, 'discovered=0 이면 abort 모드에서도 정상 통과(true)');
        $this->assertStringContainsString('실행할 스텝 없음', implode("\n", $logs));
    }

    #[Test]
    public function handle_spawn_exit_구버전_자식_discovered_null_은_레거시_fail_fast_판정을_유지한다(): void
    {
        // discovered 필드를 모르는 구버전 자식 — executed=0 + discovered=null + from<to.
        // 신버전 부모는 이 케이스를 기존(레거시) fail-fast 로 판정해야 한다 (안전 우선).
        config(['app.update.spawn_failure_mode' => 'abort']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'handleSpawnExit');
        $method->setAccessible(true);

        try {
            // stepsExecuted=0, stepsDiscovered=null (구버전 자식)
            $method->invoke($command, 0, null, 0, null, '7.0.0', '7.0.1', fn () => null);
            $this->fail('discovered=null + executed=0 + from<to 는 레거시 fail-fast 여야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertStringContainsString('step 0건 실행', $e->reason);
            $this->assertStringNotContainsString('발견', $e->reason, 'discovered 미상이므로 발견 건수 표기 없음');
        }
    }

    #[Test]
    public function spawn_자식_범위내_스텝_파일이_없어_0건이면_fallback_모드도_정상_통과한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'fallback']);

        $command = $this->makeCommandWithDummyIo();
        $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
        $method->setAccessible(true);

        $result = $method->invoke($command, '9.9.8', '9.9.9', true, fn () => null);

        $this->assertTrue($result, '스텝 파일 부재(discovered=0)는 fallback 모드에서도 정상 통과(true)');
    }

    #[Test]
    public function spawn_자식_정상_종료_시_step_s_execute_d_파싱_후_true_반환한다(): void
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
    public function run_upgrade_steps_stale_메모리_감지_시_abort_throw_한다(): void
    {
        // 부모 in-process fallback 시나리오: .env 의 APP_VERSION 은 아직 fromVersion (Step 11 전).
        $this->setEnvVersion('7.0.0-beta.3');
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
    public function run_upgrade_steps_stale_메모리_감지_시_fallback_은_경고_후_진행한다(): void
    {
        $this->setEnvVersion('7.0.0-beta.3');
        config(['app.version' => '7.0.0-beta.3']);
        config(['app.update.spawn_failure_mode' => 'fallback']);

        $service = app(CoreUpdateService::class);

        // step 파일 없으면 stale 가드 통과 후 silent return — 예외 미발생 확인
        $service->runUpgradeSteps('7.0.0-beta.3', '7.0.0-beta.5');

        // 예외 없이 도달하면 성공 — fallback 모드는 throw 하지 않고 step 실행으로 진입
        $this->assertTrue(true);
    }

    #[Test]
    public function run_upgrade_steps_memory_가_target_과_동일하면_가드_미발동(): void
    {
        $this->setEnvVersion('7.0.0-beta.5');
        config(['app.version' => '7.0.0-beta.5']);
        config(['app.update.spawn_failure_mode' => 'abort']);

        $service = app(CoreUpdateService::class);

        // spawn 자식 시나리오: 메모리 = target → 가드 무관 → step 파일 없으면 silent return
        $service->runUpgradeSteps('7.0.0-beta.3', '7.0.0-beta.5');

        $this->assertTrue(true);
    }

    /**
     * 7.0.9 → 7.0.10 실사례 (2026-09-06): 7.0.9 설치본에는 `bootstrap/cache/config.php` 가 있고
     * 부모는 spawn 전에 그 캐시를 비우지 않는다. 자식은 env `APP_VERSION=toVersion` 을 받지만
     * 캐시로 부팅하므로 `config('app.version')` 은 캐시에 박힌 fromVersion 이다. 가드가 config 만
     * 읽으면 정상 spawn 자식을 stale 부모로 오판해 abort 한다 — 스텝 0건 릴리즈에서도 중단된다.
     */
    #[Test]
    public function run_upgrade_steps_spawn_자식은_config_캐시가_stale_해도_env_버전으로_가드를_통과한다(): void
    {
        // 자식이 받는 env 는 toVersion, 캐시로 부팅한 config 는 fromVersion.
        $this->setEnvVersion('7.0.10');
        config(['app.version' => '7.0.9']);
        config(['app.update.spawn_failure_mode' => 'abort']);

        $service = app(CoreUpdateService::class);

        $discovered = null;
        $service->runUpgradeSteps('7.0.9', '7.0.10', null, false, function (int $count) use (&$discovered): void {
            $discovered = $count;
        });

        // 가드를 지나 스텝 발견 단계까지 도달했다 (throw 시 여기 미도달).
        $this->assertNotNull($discovered, 'env APP_VERSION=toVersion 인 spawn 자식은 stale 가드를 통과해야 한다');
    }

    /**
     * 부모는 spawn 직전에 config 캐시를 비운다 — 자식이 이전 버전 캐시로 부팅하면 env `APP_VERSION`
     * 오버라이드와 신버전 `config/app.php` 의 update 목록(쓰기 권한 디렉토리 등)이 모두 무시된다.
     * 캐시 파일 위치는 `APP_CONFIG_CACHE` 로 격리한다 (자식도 같은 env 를 물려받는다). 부모가 지우지
     * 않으면 자식은 이 불완전한 캐시로 부팅해 비정상 종료한다.
     *
     * @effects spawnUpgradeStepsProcess_clears_config_cache_before_proc_open
     */
    #[Test]
    public function spawn_전에_config_캐시_파일을_지워_자식이_디스크_config_로_부팅하게_한다(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 미지원 환경');
        }

        config(['app.update.spawn_failure_mode' => 'abort']);

        // 상대 경로로 지정한다 — Application::normalizeCachePath 는 `/`·`\` 로 시작하지 않는 값을
        // basePath 기준 상대 경로로 해석하므로 Windows 절대 경로(`C:\…`)는 어긋난다. 자식도 같은
        // basePath(cwd) 에서 부팅하므로 같은 파일을 가리킨다.
        $relativeCachePath = 'storage/framework/testing/stale-config-cache-'.uniqid().'.php';
        $cachePath = base_path($relativeCachePath);
        File::ensureDirectoryExists(dirname($cachePath));
        File::put($cachePath, "<?php return ['app' => ['version' => '0.0.0']];\n");

        $originalCacheEnv = $_ENV['APP_CONFIG_CACHE'] ?? null;
        $_ENV['APP_CONFIG_CACHE'] = $relativeCachePath;
        $_SERVER['APP_CONFIG_CACHE'] = $relativeCachePath;
        putenv('APP_CONFIG_CACHE='.$relativeCachePath);

        try {
            $this->assertSame($cachePath, $this->app->getCachedConfigPath(), '전제: APP_CONFIG_CACHE 가 캐시 경로를 정한다');
            $this->assertFileExists($cachePath, '전제: 부모 시점에 config 캐시 파일이 존재한다');

            $command = $this->makeCommandWithDummyIo();
            $method = new \ReflectionMethod(CoreUpdateCommand::class, 'spawnUpgradeStepsProcess');
            $method->setAccessible(true);

            $result = $method->invoke($command, '9.9.8', '9.9.9', true, fn () => null);

            $this->assertFileDoesNotExist($cachePath, 'spawn 전에 config 캐시를 비워야 자식이 디스크 config + env 로 부팅한다');
            $this->assertTrue($result, '캐시가 비워졌으면 자식은 정상 부팅해 스텝 0건 통과');
        } finally {
            if ($originalCacheEnv === null) {
                unset($_ENV['APP_CONFIG_CACHE'], $_SERVER['APP_CONFIG_CACHE']);
                putenv('APP_CONFIG_CACHE');
            } else {
                $_ENV['APP_CONFIG_CACHE'] = $originalCacheEnv;
                $_SERVER['APP_CONFIG_CACHE'] = $originalCacheEnv;
                putenv('APP_CONFIG_CACHE='.$originalCacheEnv);
            }
            if (File::exists($cachePath)) {
                File::delete($cachePath);
            }
        }
    }

    /**
     * env 가 비어 있으면(운영자 단독 실행 등) 종전처럼 config('app.version') 으로 판정한다.
     */
    #[Test]
    public function run_upgrade_steps_env_부재_시_config_버전으로_stale_을_판정한다(): void
    {
        $this->setEnvVersion(null);
        config(['app.version' => '7.0.9']);
        config(['app.update.spawn_failure_mode' => 'abort']);

        $this->expectException(UpgradeHandoffException::class);

        app(CoreUpdateService::class)->runUpgradeSteps('7.0.9', '7.0.10');
    }

    /**
     * CoreUpdateCommand 를 OutputStyle 주입 없이 리플렉션 호출 가능한 형태로 준비.
     */
    private function makeCommandWithDummyIo(): CoreUpdateCommand
    {
        $command = app(CoreUpdateCommand::class);

        $input = new ArrayInput([]);
        $output = new BufferedOutput;
        $style = new OutputStyle($input, $output);

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
