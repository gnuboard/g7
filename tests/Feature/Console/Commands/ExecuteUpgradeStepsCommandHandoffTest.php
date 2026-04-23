<?php

namespace Tests\Feature\Console\Commands;

use App\Exceptions\UpgradeHandoffException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `core:execute-upgrade-steps` 커맨드의 핸드오프 신호 출력 계약 테스트
 *
 * 업그레이드 스텝이 `UpgradeHandoffException` 을 던지면 본 커맨드는:
 *  1. stdout 에 `[HANDOFF] <json>` 라인 출력 (부모 spawnUpgradeStepsProcess 가 파싱)
 *  2. exit code `UpgradeHandoffException::EXIT_CODE` (=75) 반환
 *
 * 본 계약은 spawn 부모가 자식의 handoff 신호를 감지하는 유일한 경로이므로
 * stdout 포맷과 exit code 쌍이 동시에 고정되어야 한다.
 *
 * 각 테스트는 고유 버전(0.0.1 / 0.0.2 / ...) 및 고유 클래스명을 사용하여
 * PHP 클래스 캐싱으로 인한 테스트 간 간섭을 차단한다 (`require_once` 의존).
 */
class ExecuteUpgradeStepsCommandHandoffTest extends TestCase
{
    /**
     * 이번 테스트 메서드가 생성한 임시 Upgrade 파일 경로 추적.
     */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }
        $this->createdPaths = [];

        parent::tearDown();
    }

    public function test_handoff_exception_produces_handoff_stdout_and_exit_75(): void
    {
        $version = '0.1.1';
        $this->writeHandoffStep(
            version: $version,
            suffix: 'case1',
            afterVersion: '0.1.0',
            reason: '테스트 핸드오프 사유',
            resumeCommand: null,
        );

        ob_start();
        $exitCode = Artisan::call('core:execute-upgrade-steps', [
            '--from' => '0.1.0',
            '--to' => $version,
            '--force' => true,
        ]);
        ob_end_clean();

        $output = Artisan::output();

        $this->assertSame(UpgradeHandoffException::EXIT_CODE, $exitCode);
        $this->assertSame(75, UpgradeHandoffException::EXIT_CODE);
        $this->assertStringContainsString('[HANDOFF] ', $output);
    }

    public function test_handoff_stdout_contains_valid_json_payload(): void
    {
        $version = '0.1.2';
        $this->writeHandoffStep(
            version: $version,
            suffix: 'case2',
            afterVersion: '0.1.0',
            reason: '테스트 핸드오프 사유',
            resumeCommand: 'php artisan custom:resume',
        );

        ob_start();
        Artisan::call('core:execute-upgrade-steps', [
            '--from' => '0.1.0',
            '--to' => $version,
            '--force' => true,
        ]);
        ob_end_clean();

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/^\[HANDOFF\] (\{.*\})\r?$/m', $output);

        preg_match('/^\[HANDOFF\] (\{.*\})\r?$/m', $output, $m);
        $payload = json_decode($m[1], true);

        $this->assertIsArray($payload);
        $this->assertSame('0.1.0', $payload['afterVersion']);
        $this->assertSame('테스트 핸드오프 사유', $payload['reason']);
        $this->assertSame('php artisan custom:resume', $payload['resumeCommand']);
    }

    public function test_handoff_payload_preserves_null_resume_command(): void
    {
        $version = '0.1.3';
        $this->writeHandoffStep(
            version: $version,
            suffix: 'case3',
            afterVersion: '0.1.0',
            reason: '자동 생성 안내',
            resumeCommand: null,
        );

        ob_start();
        Artisan::call('core:execute-upgrade-steps', [
            '--from' => '0.1.0',
            '--to' => $version,
            '--force' => true,
        ]);
        ob_end_clean();

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/^\[HANDOFF\] (\{.*\})\r?$/m', $output);

        preg_match('/^\[HANDOFF\] (\{.*\})\r?$/m', $output, $m);
        $payload = json_decode($m[1], true);

        $this->assertArrayHasKey('resumeCommand', $payload);
        $this->assertNull($payload['resumeCommand']);
    }

    public function test_generic_exception_does_not_produce_handoff_signal(): void
    {
        $version = '0.1.4';
        $path = base_path("upgrades/Upgrade_0_1_4_test_cmd_case4.php");

        $code = <<<'PHP'
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class Upgrade_0_1_4_test_cmd_case4 implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        throw new \RuntimeException('일반 실패');
    }
}
PHP;

        File::put($path, $code);
        $this->createdPaths[] = $path;

        ob_start();
        $exitCode = Artisan::call('core:execute-upgrade-steps', [
            '--from' => '0.1.0',
            '--to' => $version,
            '--force' => true,
        ]);
        ob_end_clean();

        $output = Artisan::output();

        $this->assertNotSame(UpgradeHandoffException::EXIT_CODE, $exitCode);
        $this->assertStringNotContainsString('[HANDOFF] ', $output);
    }

    /**
     * 테스트 전용 Upgrade 파일을 upgrades/ 에 작성. 각 테스트가 고유 버전+suffix 를
     * 사용하여 클래스명 충돌·캐싱 문제를 회피.
     */
    private function writeHandoffStep(
        string $version,
        string $suffix,
        string $afterVersion,
        string $reason,
        ?string $resumeCommand
    ): void {
        $versionSnake = str_replace('.', '_', $version);
        $className = "Upgrade_{$versionSnake}_test_cmd_{$suffix}";
        $fileName = "Upgrade_{$versionSnake}_test_cmd_{$suffix}.php";
        $path = base_path('upgrades/'.$fileName);

        $resumeArg = $resumeCommand === null ? 'null' : var_export($resumeCommand, true);
        $afterArg = var_export($afterVersion, true);
        $reasonArg = var_export($reason, true);

        $code = <<<PHP
<?php

namespace App\\Upgrades;

use App\\Contracts\\Extension\\UpgradeStepInterface;
use App\\Exceptions\\UpgradeHandoffException;
use App\\Extension\\UpgradeContext;

class {$className} implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void
    {
        throw new UpgradeHandoffException(
            afterVersion: {$afterArg},
            reason: {$reasonArg},
            resumeCommand: {$resumeArg},
        );
    }
}
PHP;

        File::put($path, $code);
        $this->createdPaths[] = $path;
    }
}
