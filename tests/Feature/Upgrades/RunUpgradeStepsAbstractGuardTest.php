<?php

namespace Tests\Feature\Upgrades;

use App\Exceptions\CoreUpdateOperationException;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * runUpgradeSteps 의 신규 fatal 가드 검증.
 *
 * 7.0.0-beta.5 부터 step 인스턴스가 AbstractUpgradeStep 미상속이면 즉시 throw.
 * legacy step (beta.4 이하) 은 가드 우회.
 *
 * 회귀 시나리오:
 *   1. AbstractUpgradeStep 미상속 step 을 beta.5 버전으로 머지 → runUpgradeSteps 가 즉시 차단
 *   2. legacy 버전 (alpha/beta.2~4) 은 미상속이어도 통과 (호환 보장)
 *   3. beta.5+ step 이 AbstractUpgradeStep 상속하면 정상 실행
 */
class RunUpgradeStepsAbstractGuardTest extends TestCase
{
    private array $stubStepFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->stubStepFiles as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }
        parent::tearDown();
    }

    public function test_throws_when_beta5_step_does_not_extend_abstract(): void
    {
        // stale 메모리 가드 우회를 위해 메모리 버전을 target 이상으로
        config(['app.version' => '7.9.9']);

        // 7.9.9-test.guard.a 는 7.0.0-beta.5 보다 명확히 큼 (version_compare 의 pre-release
        // 해석에 영향 받지 않음). stub 은 AbstractUpgradeStep 미상속 → fatal 가드 발동.
        $version = '7_9_9_test_guard_a';
        $className = 'Upgrade_'.$version;
        $path = base_path('upgrades/'.$className.'.php');
        $this->stubStepFiles[] = $path;
        File::put($path, <<<PHP
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class {$className} implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void {}
}
PHP);

        $this->expectException(CoreUpdateOperationException::class);
        $this->expectExceptionMessageMatches('/must extend App\\\\Extension\\\\AbstractUpgradeStep/');

        $service = app(CoreUpdateService::class);
        $service->runUpgradeSteps('7.0.0-beta.4', '7.9.9-test.guard.a');
    }

    public function test_passes_when_beta5_step_extends_abstract(): void
    {
        config(['app.version' => '7.9.9']);

        $version = '7_9_9_test_guard_b';
        $className = 'Upgrade_'.$version;
        $path = base_path('upgrades/'.$className.'.php');
        $this->stubStepFiles[] = $path;
        File::put($path, <<<PHP
<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

class {$className} extends AbstractUpgradeStep
{
}
PHP);

        $executed = [];
        $service = app(CoreUpdateService::class);
        $service->runUpgradeSteps(
            '7.0.0-beta.4',
            '7.9.9-test.guard.b',
            function (string $v) use (&$executed): void {
                $executed[] = $v;
            },
        );

        $this->assertContains('7.9.9-test.guard.b', $executed);
    }

    public function test_legacy_pre_beta5_step_bypasses_guard(): void
    {
        // legacy beta.4 이하 step 은 AbstractUpgradeStep 미상속이어도 통과 (호환)
        config(['app.version' => '7.0.0-beta.5']);

        $version = '7_0_0_beta_4_test_guard_legacy';
        $className = 'Upgrade_'.$version;
        $path = base_path('upgrades/'.$className.'.php');
        $this->stubStepFiles[] = $path;
        File::put($path, <<<PHP
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class {$className} implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void {}
}
PHP);

        $executed = [];
        $service = app(CoreUpdateService::class);
        $service->runUpgradeSteps(
            '7.0.0-beta.3',
            '7.0.0-beta.4.test.guard.legacy',
            function (string $v) use (&$executed): void {
                $executed[] = $v;
            },
        );

        $this->assertContains('7.0.0-beta.4.test.guard.legacy', $executed);
    }
}
