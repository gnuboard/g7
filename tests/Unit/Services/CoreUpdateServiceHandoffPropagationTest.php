<?php

namespace Tests\Unit\Services;

use App\Exceptions\UpgradeHandoffException;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * CoreUpdateService::runUpgradeSteps 의 예외 전파 계약 테스트
 *
 * 본 메서드는 어떤 예외도 catch 하지 않고 상위로 그대로 전파한다. 특히:
 *  - 일반 예외(\Throwable): 상위 CoreUpdateCommand 의 catch(\Throwable) 경로에서
 *    백업 복원 + 실패 리포트 로직이 트리거된다.
 *  - UpgradeHandoffException: 상위 CoreUpdateCommand 의 catch(UpgradeHandoffException)
 *    경로에서 maintenance 해제 + .env 고정 + 사용자 안내 로직이 트리거된다.
 *
 * 만약 runUpgradeSteps 가 실수로 예외를 삼키면 핸드오프 인프라 전체가 무력화되므로
 * 본 계약을 회귀 테스트로 고정.
 */
class CoreUpdateServiceHandoffPropagationTest extends TestCase
{
    private string $upgradesPath;

    private string $handoffStepPath;

    private string $failingStepPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->upgradesPath = base_path('upgrades');
        $this->handoffStepPath = $this->upgradesPath.'/Upgrade_0_0_1_test_handoff.php';
        $this->failingStepPath = $this->upgradesPath.'/Upgrade_0_0_1_test_failing.php';
    }

    protected function tearDown(): void
    {
        foreach ([$this->handoffStepPath, $this->failingStepPath] as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    /**
     * UpgradeHandoffException 은 runUpgradeSteps 를 뚫고 상위로 그대로 전파되어야 한다.
     */
    public function test_handoff_exception_propagates_up(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Exceptions\UpgradeHandoffException;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_handoff implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        throw new UpgradeHandoffException(
            afterVersion: '0.0.0',
            reason: '테스트 핸드오프',
        );
    }
}
PHP;

        File::put($this->handoffStepPath, $code);

        $service = new CoreUpdateService;

        try {
            $service->runUpgradeSteps('0.0.0', '0.0.1', null, true);
            $this->fail('UpgradeHandoffException 이 상위로 전파되어야 한다');
        } catch (UpgradeHandoffException $e) {
            $this->assertSame('0.0.0', $e->afterVersion);
            $this->assertSame('테스트 핸드오프', $e->reason);
        }
    }

    /**
     * 일반 RuntimeException 도 상위로 그대로 전파되어야 한다 (catch 안 함).
     */
    public function test_generic_exception_propagates_up(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class Upgrade_0_0_1_test_failing implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        throw new \RuntimeException('테스트 일반 실패');
    }
}
PHP;

        File::put($this->failingStepPath, $code);

        $service = new CoreUpdateService;

        try {
            $service->runUpgradeSteps('0.0.0', '0.0.1', null, true);
            $this->fail('RuntimeException 이 상위로 전파되어야 한다');
        } catch (RuntimeException $e) {
            // UpgradeHandoffException 도 RuntimeException 상속이므로 구체 타입 확인
            $this->assertNotInstanceOf(UpgradeHandoffException::class, $e);
            $this->assertSame('테스트 일반 실패', $e->getMessage());
        }
    }

    /**
     * upgrades 디렉토리에 매칭 스텝이 없으면 조용히 반환 (예외 없음).
     * 경계 조건 검증.
     */
    public function test_no_matching_steps_returns_silently(): void
    {
        $service = new CoreUpdateService;

        // 매우 작은 from~to 범위 — 어떤 실제 Upgrade_*.php 도 매칭되지 않음
        $service->runUpgradeSteps('0.0.0', '0.0.0', null, false);

        $this->assertTrue(true, '예외 없이 종료되어야 한다');
    }
}
