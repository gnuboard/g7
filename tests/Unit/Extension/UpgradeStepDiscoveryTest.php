<?php

namespace Tests\Unit\Extension;

use App\Exceptions\CoreUpdateOperationException;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\AbstractUpgradeStep;
use App\Extension\Exceptions\UpgradeStepClassMissingException;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\UnusableUpgradeStep;
use App\Extension\UpgradeContext;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Zztest\DataStep\Upgrade_1_0_2;

/**
 * 업그레이드 단계·데이터 마이그레이션 파일은 있는데 클래스를 쓸 수 없으면 업데이트가 실패한다 (7.1.3).
 *
 * 예전에는 네임스페이스 오타·오토로드 누락으로 `class_exists` 가 거짓이면 그 단계를 조용히 건너뛰고
 * 업데이트가 성공했다 — 데이터는 옛 형태인데 버전만 올라갔다. 모듈·플러그인 단계, 데이터 마이그레이션, 코어 단계
 * 네 곳이 같은 규칙(UpgradeStepClassMissingException)을 쓴다. 이번 업데이트 범위 밖(이미 지난 판·아직 아닌 판)의
 * 깨진 단계 파일은 업데이트를 막지 않는다 — 확장 수집은 자리 표시(UnusableUpgradeStep)를 두고 범위 안일 때만 실패한다.
 *
 * @scenario-manifest tests/scenarios/upgrade-step-discovery.yaml
 */
class UpgradeStepDiscoveryTest extends TestCase
{
    private string $tempDir;

    /** @var array<int, string> 시험이 만든 코어 upgrades/ 파일 */
    private array $coreFiles = [];

    /**
     * 임시 디렉터리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('app/temp/upgrade-discovery-'.uniqid());
        File::ensureDirectoryExists($this->tempDir);
    }

    /**
     * 임시 디렉터리와 시험이 만든 코어 단계 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        foreach ($this->coreFiles as $file) {
            File::delete($file);
        }
        parent::tearDown();
    }

    /**
     * 모듈 단계 파일의 클래스 네임스페이스가 틀리면, 그 단계가 이번 범위 안일 때 업데이트가 예외로 실패한다.
     *
     * @scenario owner=module, defect=class_missing
     *
     * @effects missing_upgrade_class_fails_update
     *
     * @return void 반환값 없음
     */
    public function test_module_step_with_wrong_namespace_fails_update_in_range(): void
    {
        $module = $this->moduleAt($this->extensionWithStep('zztest-upgmod', 'Modules\\Wrong\\Upgrades', 'Upgrade_1_0_1', true));

        $this->assertInstanceOf(UnusableUpgradeStep::class, $this->discover($module)['1.0.1']);

        try {
            $this->runModuleSteps($module, '1.0.0', '1.0.1');
            $this->fail('범위 안 단계의 클래스 누락이 예외로 올라와야 한다');
        } catch (UpgradeStepClassMissingException $e) {
            $this->assertSame('Upgrade_1_0_1.php', $e->stepFile);
            $this->assertSame('Modules\\Zztest\\Upgmod\\Upgrades\\Upgrade_1_0_1', $e->class);
            $this->assertNull($e->interface);
        }
    }

    /**
     * 모듈 단계 클래스가 UpgradeStepInterface 를 구현하지 않으면, 범위 안일 때 업데이트가 예외로 실패한다.
     *
     * @scenario owner=module, defect=interface_missing
     *
     * @effects non_step_class_fails_update
     *
     * @return void 반환값 없음
     */
    public function test_module_step_without_interface_fails_update_in_range(): void
    {
        $module = $this->moduleAt($this->extensionWithStep('zztest-upgmodb', 'Modules\\Zztest\\Upgmodb\\Upgrades', 'Upgrade_1_0_1', false));

        $this->expectException(UpgradeStepClassMissingException::class);
        $this->expectExceptionMessage('UpgradeStepInterface');

        $this->runModuleSteps($module, '1.0.0', '1.0.1');
    }

    /**
     * 이번 범위 밖(이미 지난 판·아직 아닌 판)의 깨진 단계 파일은 업데이트를 막지 않는다 — 코어 단계와 같은 규칙.
     *
     * @scenario owner=module, defect=out_of_range
     *
     * @effects out_of_range_broken_step_does_not_block_update
     *
     * @return void 반환값 없음
     */
    public function test_module_broken_step_outside_range_does_not_block_update(): void
    {
        $module = $this->moduleAt($this->extensionWithStep('zztest-upgmodd', 'Modules\\Wrong\\Upgrades', 'Upgrade_1_5_3', true));

        // 지난 판(1.5.3 에서 출발)·아직 아닌 판(1.5.3 미만이 목표) 모두 예외 없이 끝난다
        $this->runModuleSteps($module, '1.5.3', '1.6.0');
        $this->runModuleSteps($module, '1.0.8', '1.0.9');

        // 같은 파일이 범위 안이 되면 실패한다
        $this->expectException(UpgradeStepClassMissingException::class);
        $this->runModuleSteps($module, '1.5.2', '1.5.3');
    }

    /**
     * 범위 안에 깨진 단계가 있으면 앞 판의 정상 단계도 돌리지 않고 실패한다 — 일부만 적용된 채 멈추지 않게.
     *
     * @scenario owner=module, defect=class_missing
     *
     * @effects missing_upgrade_class_fails_update
     *
     * @return void 반환값 없음
     */
    public function test_module_broken_step_in_range_fails_before_any_step_runs(): void
    {
        $dir = $this->extensionWithStep('zztest-upgmode', 'Modules\\Wrong\\Upgrades', 'Upgrade_1_0_2', true);
        $marker = $this->tempDir.'/zztest-upgmode-ran.txt';
        File::put($dir.'/upgrades/Upgrade_1_0_1.php', "<?php\nnamespace Modules\\Zztest\\Upgmode\\Upgrades;\nclass Upgrade_1_0_1 implements \\App\\Contracts\\Extension\\UpgradeStepInterface { public function run(\\App\\Extension\\UpgradeContext \$context): void { file_put_contents('".str_replace('\\', '/', $marker)."', 'ran'); } }\n");
        $module = $this->moduleAt($dir);

        try {
            $this->runModuleSteps($module, '1.0.0', '1.0.2');
            $this->fail('범위 안 단계의 클래스 누락이 예외로 올라와야 한다');
        } catch (UpgradeStepClassMissingException $e) {
            $this->assertSame('Upgrade_1_0_2.php', $e->stepFile);
        }

        $this->assertFileDoesNotExist($marker, '깨진 단계보다 앞 판의 정상 단계가 먼저 돌면 안 된다');
    }

    /**
     * 정상 단계는 그대로 수집된다.
     *
     * @scenario owner=module, defect=none
     *
     * @effects valid_step_still_collected
     *
     * @return void 반환값 없음
     */
    public function test_module_valid_step_is_collected(): void
    {
        $module = $this->moduleAt($this->extensionWithStep('zztest-upgmodc', 'Modules\\Zztest\\Upgmodc\\Upgrades', 'Upgrade_1_0_1', true));

        $steps = $this->discover($module);

        $this->assertSame(['1.0.1'], array_keys($steps));
    }

    /**
     * 플러그인 단계도 같은 규칙으로 — 범위 안이면 업데이트가 실패한다.
     *
     * @scenario owner=plugin, defect=class_missing
     *
     * @effects missing_upgrade_class_fails_update
     *
     * @return void 반환값 없음
     */
    public function test_plugin_step_with_wrong_namespace_fails_update_in_range(): void
    {
        $dir = $this->extensionWithStep('zztest-upgplug', 'Plugins\\Wrong\\Upgrades', 'Upgrade_1_0_1', true);
        $plugin = new class extends AbstractPlugin {};
        $property = new \ReflectionProperty(AbstractPlugin::class, 'pluginPath');
        $property->setValue($plugin, $dir);

        $this->expectException(UpgradeStepClassMissingException::class);

        (fn () => $this->runUpgradeSteps($plugin, '1.0.0', '1.0.1'))->call(app(PluginManager::class));
    }

    /**
     * 데이터 마이그레이션 파일의 클래스를 찾을 수 없으면 단계 실행이 실패한다.
     *
     * @scenario owner=data_migration, defect=class_missing
     *
     * @effects missing_upgrade_class_fails_update
     *
     * @return void 반환값 없음
     */
    public function test_data_migration_with_wrong_namespace_fails(): void
    {
        $stepDir = $this->tempDir.'/datastep';
        File::ensureDirectoryExists($stepDir.'/data/1.0.2/migrations');
        File::put($stepDir.'/Upgrade_1_0_2.php', "<?php\nnamespace Zztest\\DataStep;\nclass Upgrade_1_0_2 extends \\App\\Extension\\AbstractUpgradeStep {}\n");
        File::put($stepDir.'/data/1.0.2/migrations/01_Probe.php', "<?php\nnamespace Wrong\\Place;\nclass Probe {}\n");
        require_once $stepDir.'/Upgrade_1_0_2.php';

        /** @var AbstractUpgradeStep $step */
        $step = new Upgrade_1_0_2;
        $context = (new UpgradeContext('1.0.1', '1.0.2'))->withCurrentStep('1.0.2');

        $this->expectException(UpgradeStepClassMissingException::class);

        (fn () => $this->dataMigrations($context))->call($step);
    }

    /**
     * 코어 upgrades/ 의 범위 안 단계 파일 클래스가 없으면 코어 업데이트가 실패한다.
     *
     * @scenario owner=core, defect=class_missing
     *
     * @effects missing_upgrade_class_fails_update
     *
     * @return void 반환값 없음
     */
    public function test_core_step_with_wrong_namespace_fails_update(): void
    {
        $file = base_path('upgrades/Upgrade_99_0_1.php');
        $this->coreFiles[] = $file;
        File::put($file, "<?php\nnamespace App\\Wrong;\nclass Upgrade_99_0_1 {}\n");
        // 메모리 버전(<99.0.1) stale 가드가 abort 로 먼저 끊지 않도록 폴백 모드로 둔다 — 수집 단계만 본다
        config(['app.update.spawn_failure_mode' => 'fallback']);

        try {
            app(CoreUpdateService::class)->runUpgradeSteps('99.0.0', '99.0.1');
            $this->fail('클래스 누락이 코어 업데이트 실패로 올라와야 한다');
        } catch (CoreUpdateOperationException $e) {
            $this->assertSame('extensions.errors.upgrade_step_class_missing', $e->errorKey);
            $this->assertStringContainsString('Upgrade_99_0_1', $e->getMessage());
        }
    }

    /**
     * 임시 확장 디렉터리에 업그레이드 단계 파일 하나를 만듭니다.
     *
     * @param  string  $identifier  확장 디렉터리명
     * @param  string  $namespace  파일에 쓸 네임스페이스
     * @param  string  $class  클래스명
     * @param  bool  $implements  UpgradeStepInterface 구현 여부
     * @return string 확장 디렉터리 경로
     */
    private function extensionWithStep(string $identifier, string $namespace, string $class, bool $implements): string
    {
        $dir = $this->tempDir.'/'.$identifier;
        File::ensureDirectoryExists($dir.'/upgrades');
        $implementsClause = $implements ? ' implements \\App\\Contracts\\Extension\\UpgradeStepInterface' : '';
        $body = $implements ? 'public function run(\\App\\Extension\\UpgradeContext $context): void {}' : '';
        File::put($dir."/upgrades/{$class}.php", "<?php\nnamespace {$namespace};\nclass {$class}{$implementsClause} { {$body} }\n");

        return $dir;
    }

    /**
     * 모듈 경로를 지정한 시험용 모듈 인스턴스를 만듭니다.
     *
     * @param  string  $dir  모듈 디렉터리 경로
     * @return AbstractModule 모듈 인스턴스
     */
    private function moduleAt(string $dir): AbstractModule
    {
        $module = new class extends AbstractModule {};
        $property = new \ReflectionProperty(AbstractModule::class, 'modulePath');
        $property->setValue($module, $dir);

        return $module;
    }

    /**
     * 모듈의 업그레이드 단계 수집을 호출합니다.
     *
     * @param  AbstractModule  $module  모듈 인스턴스
     * @return array<string, mixed> 버전 => 단계
     */
    private function discover(AbstractModule $module): array
    {
        return (fn () => $this->discoverUpgradeSteps())->call($module);
    }

    /**
     * 모듈 매니저의 업그레이드 단계 실행(버전 범위 거르기 포함)을 호출합니다.
     *
     * @param  AbstractModule  $module  모듈 인스턴스
     * @param  string  $from  시작 판
     * @param  string  $to  목표 판
     * @return void 반환값 없음
     */
    private function runModuleSteps(AbstractModule $module, string $from, string $to): void
    {
        (fn () => $this->runUpgradeSteps($module, $from, $to))->call(app(ModuleManager::class));
    }
}
