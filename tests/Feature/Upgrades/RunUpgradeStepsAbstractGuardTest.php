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

    private ?string $originalEnvVersion = null;

    /**
     * 메모리 버전(env APP_VERSION)을 비워 두고 원래 값을 기억합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // stale 메모리 가드는 env APP_VERSION 을 먼저 읽는다 (spawn 자식 계약). 테스트 프로세스의
        // .env.testing 값이 target 판정에 끼어들지 않도록 비우고, 각 테스트가 직접 세운다.
        $this->originalEnvVersion = $_ENV['APP_VERSION'] ?? null;
        $this->setMemoryVersion(null);
    }

    /**
     * 스텁 스텝 파일을 지우고 메모리 버전을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->stubStepFiles as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }
        $this->setMemoryVersion($this->originalEnvVersion);
        parent::tearDown();
    }

    /**
     * 가드가 읽는 "메모리 버전" 을 env 와 config 양쪽에 세운다 (null 이면 env 만 비운다).
     *
     * @param  string|null  $version  세울 버전
     * @return void 반환값 없음
     */
    private function setMemoryVersion(?string $version): void
    {
        if ($version === null) {
            unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
            putenv('APP_VERSION');

            return;
        }

        $_ENV['APP_VERSION'] = $version;
        $_SERVER['APP_VERSION'] = $version;
        putenv('APP_VERSION='.$version);
        config(['app.version' => $version]);
    }

    /**
     * beta.5 이후 판의 코어 스텝이 AbstractUpgradeStep 을 상속하지 않으면 러너가 즉시 막습니다.
     *
     * @scenario extension_type=core, step_version=post_beta_5, step_class_inheritance=implements_only
     *
     * @effects core_beta_5_or_later_step_without_abstract_inheritance_throws
     *
     * @return void 반환값 없음
     */
    public function test_throws_when_beta5_step_does_not_extend_abstract(): void
    {
        // stale 메모리 가드 우회를 위해 메모리 버전을 target 이상으로
        $this->setMemoryVersion('9.9.9');

        // 9.9.9-test.guard.a 는 7.0.0-beta.5 보다 명확히 큼 (version_compare 의 pre-release
        // 해석에 영향 받지 않음). stub 은 AbstractUpgradeStep 미상속 → fatal 가드 발동.
        // 구간을 9.9.8 부터 잡는 이유: 실제 스텝(upgrades/Upgrade_7_*)이 구간에 들어오면 저장소 루트를
        // 대상으로 실행된다 — 7.2.0 스텝 13 이 설치 완료 사이트로 보고 루트 g7-setup.php 를 지웠다.
        $version = '9_9_9_test_guard_a';
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
        $service->runUpgradeSteps('9.9.8', '9.9.9-test.guard.a');
    }

    /**
     * beta.5 이후 판의 코어 스텝이 AbstractUpgradeStep 을 상속하면 그대로 실행됩니다 — 구간 안의 스텁만 돈다.
     *
     * @scenario extension_type=core, step_version=post_beta_5, step_class_inheritance=extends_abstract
     *
     * @effects core_beta_5_or_later_step_with_inheritance_runs
     *
     * @return void 반환값 없음
     */
    public function test_passes_when_beta5_step_extends_abstract(): void
    {
        $this->setMemoryVersion('9.9.9');

        $version = '9_9_9_test_guard_b';
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
            '9.9.8',
            '9.9.9-test.guard.b',
            function (string $v) use (&$executed): void {
                $executed[] = $v;
            },
        );

        // 스텁만 실행돼야 한다 — 실제 스텝이 섞이면 저장소 루트의 파일을 지우거나 놓는다.
        $this->assertSame(['9.9.9-test.guard.b'], $executed, '실제 업그레이드 스텝이 시험 구간에 들어왔다');
    }

    /**
     * beta.4 이하 판의 옛 스텝은 상속하지 않아도 가드를 지나갑니다(호환).
     *
     * @scenario extension_type=core, step_version=pre_beta_5_legacy, step_class_inheritance=implements_only
     *
     * @effects core_legacy_beta_4_or_earlier_step_passes_without_inheritance
     *
     * @return void 반환값 없음
     */
    public function test_legacy_pre_beta5_step_bypasses_guard(): void
    {
        // legacy beta.4 이하 step 은 AbstractUpgradeStep 미상속이어도 통과 (호환)
        $this->setMemoryVersion('7.0.0-beta.5');

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
