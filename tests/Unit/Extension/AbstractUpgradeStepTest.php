<?php

namespace Tests\Unit\Extension;

use App\Extension\AbstractUpgradeStep;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AbstractUpgradeStep 의 데이터 스냅샷 위임 흐름 검증.
 *
 * 본 테스트는 fixture 디렉토리에 manifest/appliers/migrations 를 임시 작성하여
 * 실제 DB 변경 없이 위임 순서 / Migration 파일명 prefix 제거 / dataDir 계산을 검증한다.
 */
class AbstractUpgradeStepTest extends TestCase
{
    private string $tempStepDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempStepDir = storage_path('framework/testing/abstract-upgrade-'.uniqid());
        File::ensureDirectoryExists($this->tempStepDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempStepDir)) {
            File::deleteDirectory($this->tempStepDir);
        }
        parent::tearDown();
    }

    public function test_run_executes_migrations_in_filename_sorted_order(): void
    {
        $version = '0.0.10-test.order';
        $token = 'V'.str_replace(['.', '-'], '_', $version);
        $dataDir = $this->tempStepDir.'/data/'.$version;
        File::ensureDirectoryExists($dataDir.'/migrations');

        // 일부러 알파벳 역순으로 prefix 부여하여 파일명 정렬이 실제 실행 순서를 결정함을 검증
        $this->writeMigrationFixture($dataDir.'/migrations/02_SecondStep.php', $token, 'SecondStep');
        $this->writeMigrationFixture($dataDir.'/migrations/01_FirstStep.php', $token, 'FirstStep');
        $this->writeMigrationFixture($dataDir.'/migrations/03_ThirdStep.php', $token, 'ThirdStep');

        $step = $this->makeAnonymousStep($this->tempStepDir);
        $context = new UpgradeContext(
            fromVersion: '0.0.9',
            toVersion: $version,
            currentStep: $version,
        );

        $captured = $this->captureMigrationCalls();
        $step->run($context);

        $this->assertSame(
            ['FirstStep', 'SecondStep', 'ThirdStep'],
            $captured(),
            'migrations 는 파일명 정렬 순서로 실행되어야 한다 (01 → 02 → 03)',
        );
    }

    public function test_dataMigrations_strips_numeric_prefix_when_resolving_class_name(): void
    {
        $version = '0.0.11-test.prefix';
        $token = 'V'.str_replace(['.', '-'], '_', $version);
        $dataDir = $this->tempStepDir.'/data/'.$version;
        File::ensureDirectoryExists($dataDir.'/migrations');

        // 파일명: 01_PrefixStripped.php, 클래스명: PrefixStripped (prefix 없음)
        $this->writeMigrationFixture($dataDir.'/migrations/01_PrefixStripped.php', $token, 'PrefixStripped');

        $step = $this->makeAnonymousStep($this->tempStepDir);
        $context = new UpgradeContext(
            fromVersion: '0.0.10',
            toVersion: $version,
            currentStep: $version,
        );

        $captured = $this->captureMigrationCalls();
        $step->run($context);

        $this->assertSame(['PrefixStripped'], $captured());
    }

    public function test_run_skips_when_data_dir_absent(): void
    {
        $version = '0.0.12-test.empty';
        $step = $this->makeAnonymousStep($this->tempStepDir);
        $context = new UpgradeContext(
            fromVersion: '0.0.11',
            toVersion: $version,
            currentStep: $version,
        );

        // data/{version}/ 디렉토리 부재 — 예외 없이 silent return 되어야 한다
        $step->run($context);
        $this->assertTrue(true);
    }

    /**
     * @param  string  $token  버전 namespace token (예: V0_0_10_test_order)
     */
    private function writeMigrationFixture(string $filePath, string $token, string $className): void
    {
        $contents = <<<PHP
<?php
namespace App\Upgrades\Data\\{$token}\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

class {$className} implements DataMigration
{
    public function name(): string { return '{$className}'; }

    public function run(UpgradeContext \$context): void
    {
        \$GLOBALS['__abstract_upgrade_step_test_calls'][] = '{$className}';
    }
}
PHP;
        File::put($filePath, $contents);
    }

    private function makeAnonymousStep(string $stepDir): AbstractUpgradeStep
    {
        // 임시 step 파일을 실제 디렉토리에 작성하여 ReflectionClass::getFileName() 이
        // 본 디렉토리를 가리키도록 한다. 클래스명은 테스트 케이스별 고유 — 재선언 회피.
        $unique = 'AnonymousStep_'.bin2hex(random_bytes(8));
        $stepFile = $stepDir.'/'.$unique.'.php';
        $contents = <<<PHP
<?php
namespace App\Upgrades\Data\TestFixtures;

use App\Extension\AbstractUpgradeStep;

class {$unique} extends AbstractUpgradeStep
{
}
PHP;
        File::put($stepFile, $contents);
        require_once $stepFile;

        $fqcn = 'App\\Upgrades\\Data\\TestFixtures\\'.$unique;

        return new $fqcn;
    }

    private function captureMigrationCalls(): \Closure
    {
        $GLOBALS['__abstract_upgrade_step_test_calls'] = [];

        return fn () => $GLOBALS['__abstract_upgrade_step_test_calls'] ?? [];
    }
}
