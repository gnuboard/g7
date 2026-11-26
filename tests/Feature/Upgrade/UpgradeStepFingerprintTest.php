<?php

namespace Tests\Feature\Upgrade;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\UpgradeStepRunStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Upgrade\UpgradeStepRunLedger;
use App\Models\UpgradeStepRun;
use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 업그레이드 스텝 지문 — 검수 서버의 같은 판 `--force` 재배포가 같은 내용의 스텝을 공유 DB 에서 두 번 돌리지 않는다.
 *
 * 검수 서버는 운영 서버와 같은 DB 를 쓴다. 검수에서 결함을 고쳐 같은 판 번호로 다시 배포(`*:update --force`)하면
 * 대장(`upgrade_step_runs`)이 `--force` 를 「대장 무시」로 읽어 이미 끝난 스텝이 공유 DB 에서 다시 돌았다.
 * 반대로 「늘 건너뜀」으로 고치면 1차 배포 때 결함이 있던 스텝을 같은 판 번호로 고쳐도 다시 돌 길이 없다.
 *
 * 대장에 스텝 내용의 지문(sha256 — 스텝 클래스 파일 + 그 스텝의 `data/<판>/` 파일)을 적는다. 검수 서버의 `--force` 는
 * 이미 끝났고 지문이 같으면 건너뛰고, 지문이 다르면 다시 돌고 지문을 갱신한다. 운영 서버의 `--force` 는 종전대로 다시 돈다.
 * 지문이 없는 옛 행은 「모름」으로 보고 다시 돌며, 클로저 스텝은 지문이 없다.
 *
 * @scenario-manifest tests/scenarios/upgrade-step-fingerprint.yaml
 */
class UpgradeStepFingerprintTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 만든 파일 */
    private array $createdFiles = [];

    /** @var array<int, string> 만든 디렉터리 */
    private array $createdDirs = [];

    /**
     * 대장을 켭니다(단위 테스트에서는 기본으로 꺼져 있다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        UpgradeStepRunLedger::enableForTests();
        $GLOBALS['g7_fingerprint_probe_runs'] = [];
    }

    /**
     * 만든 스텝 파일을 지우고 대장·역할을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            File::delete($path);
        }
        foreach ($this->createdDirs as $dir) {
            File::deleteDirectory($dir);
        }
        UpgradeStepRunLedger::enableForTests(false);
        unset($GLOBALS['g7_fingerprint_probe_runs']);

        parent::tearDown();
    }

    /**
     * 검수 서버의 같은 판 `--force` — 이미 끝났고 내용이 같은 코어 스텝은 다시 돌지 않고 「건너뜀」으로 통지된다.
     *
     * @scenario case=core__stage_force_same_content
     *
     * @effects step_fingerprint_is_recorded, stage_force_skips_done_step_with_same_fingerprint
     *
     * @return void 반환값 없음
     */
    public function test_stage_force_skips_done_core_step_with_same_content(): void
    {
        $this->role('stage');
        $this->writeCoreCountingStep('fp_same');
        $service = app(CoreUpdateService::class);

        $service->runUpgradeSteps('0.0.6', '0.0.7', freshProcess: true);
        $this->assertSame(1, $this->runs('fp_same'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $this->coreRow('0.0.7-test.fp.same')->fingerprint, '실행한 스텝은 내용 지문(sha256)을 대장에 남긴다');

        $skipped = [];
        $service->runUpgradeSteps('0.0.7-test.fp.same', '0.0.7-test.fp.same', null, true, freshProcess: true, onSkipped: function (string $version) use (&$skipped): void {
            $skipped[] = $version;
        });

        $this->assertSame(1, $this->runs('fp_same'), '검수 서버의 같은 판 재배포는 같은 내용의 스텝을 공유 DB 에서 다시 돌리지 않는다');
        $this->assertSame(['0.0.7-test.fp.same'], $skipped, '건너뛴 스텝은 「건너뜀」으로 따로 통지된다');
        $this->assertSame(UpgradeStepRunStatus::Done, $this->coreRow('0.0.7-test.fp.same')->status);
    }

    /**
     * 검수 서버의 같은 판 `--force` — 1차 배포 뒤 스텝 파일을 고쳤으면 다시 돌고 지문을 갱신한다.
     *
     * @scenario case=core__stage_force_changed_step
     *
     * @effects step_fingerprint_is_recorded, stage_force_reruns_step_when_fingerprint_changes
     *
     * @return void 반환값 없음
     */
    public function test_stage_force_reruns_core_step_after_step_file_changed(): void
    {
        $this->role('stage');
        $path = $this->writeCoreCountingStep('fp_changed');
        $service = app(CoreUpdateService::class);

        $service->runUpgradeSteps('0.0.6', '0.0.7', freshProcess: true);
        $before = $this->coreRow('0.0.7-test.fp.changed')->fingerprint;
        $this->assertNotNull($before, '실행한 스텝은 내용 지문을 대장에 남긴다');

        // 검수에서 결함을 찾아 같은 판 번호로 스텝을 고쳐 다시 배포했다
        File::append($path, "\n// 검수 뒤 고친 판\n");

        $service->runUpgradeSteps('0.0.7-test.fp.changed', '0.0.7-test.fp.changed', null, true, freshProcess: true);

        $this->assertSame(2, $this->runs('fp_changed'), '고친 스텝은 같은 판 번호여도 검수 서버의 --force 에서 다시 돈다');
        $after = $this->coreRow('0.0.7-test.fp.changed')->fingerprint;
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $after);
        $this->assertNotSame($before, $after, '다시 돈 뒤 대장의 지문은 고친 내용으로 바뀐다');
    }

    /**
     * 운영 서버의 `--force` 는 내용이 같아도 종전대로 다시 돈다 — 운영자의 명시 재실행.
     *
     * @scenario case=core__live_force_same_content
     *
     * @effects step_fingerprint_is_recorded, live_force_reruns_done_step
     *
     * @return void 반환값 없음
     */
    public function test_live_force_reruns_done_core_step_even_with_same_content(): void
    {
        $this->role('live');
        $this->writeCoreCountingStep('fp_live');
        $service = app(CoreUpdateService::class);

        $service->runUpgradeSteps('0.0.6', '0.0.7', freshProcess: true);
        $service->runUpgradeSteps('0.0.7-test.fp.live', '0.0.7-test.fp.live', null, true, freshProcess: true);

        $this->assertSame(2, $this->runs('fp_live'), '운영 서버의 --force 는 지문과 무관하게 다시 돈다');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $this->coreRow('0.0.7-test.fp.live')->fingerprint, '운영 서버의 실행도 지문을 남긴다');
    }

    /**
     * 운영 서버 배포의 코어 스텝 실행은 `--force` 없이 `--yes` 로 확인만 넘긴다 — 검수 서버가 공유 DB 에서 이미 끝낸 스텝은
     * 대장을 보고 건너뛴다(`--force` 는 운영 서버에서 대장을 무시하고 다시 돈다).
     *
     * @scenario case=core__live_yes_without_force
     *
     * @effects live_yes_skips_done_step
     *
     * @return void 반환값 없음
     */
    public function test_live_execute_steps_with_yes_skips_step_done_on_stage(): void
    {
        $this->role('live');
        $this->writeCoreCountingStep('fp_yes');
        // 검수 서버가 공유 DB 에서 먼저 끝냈다
        app(CoreUpdateService::class)->runUpgradeSteps('0.0.6', '0.0.7', freshProcess: true);
        $this->assertSame(1, $this->runs('fp_yes'));

        $exit = Artisan::call('core:execute-upgrade-steps', [
            '--from' => '0.0.6',
            '--to' => '0.0.7-test.fp.yes',
            '--yes' => true,
            '--steps-only' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exit, trim(Artisan::output()));
        $this->assertSame(1, $this->runs('fp_yes'), '--yes 는 확인만 넘기고 대장을 무시하지 않는다');
    }

    /**
     * 지문이 없는 옛 행(지문 도입 전에 끝난 스텝)은 「모름」 — 검수 서버의 `--force` 가 다시 돌고 지문을 채운다.
     *
     * @scenario case=core__stage_force_legacy_row
     *
     * @effects legacy_row_without_fingerprint_reruns_and_fills
     *
     * @return void 반환값 없음
     */
    public function test_stage_force_reruns_legacy_row_without_fingerprint(): void
    {
        $this->role('stage');
        $this->writeCoreCountingStep('fp_legacy');
        UpgradeStepRun::query()->create([
            'scope' => 'core',
            'identifier' => '',
            'version' => '0.0.7-test.fp.legacy',
            'step' => 'App\\Upgrades\\Upgrade_0_0_7_test_fp_legacy',
            'status' => UpgradeStepRunStatus::Done->value,
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        app(CoreUpdateService::class)->runUpgradeSteps('0.0.7-test.fp.legacy', '0.0.7-test.fp.legacy', null, true, freshProcess: true);

        $this->assertSame(1, $this->runs('fp_legacy'), '지문 없는 옛 행은 같은 내용인지 알 수 없어 다시 돈다');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $this->coreRow('0.0.7-test.fp.legacy')->fingerprint, '다시 돈 뒤 지문을 채운다');
    }

    // 아래 시험이 함께 도는 스텝 소유 — docblock 은 @scenario 를 1건만 인식하므로 나머지는 라인 주석으로 적는다.
    // @scenario case=plugin__stage_force_data_migration

    /**
     * 확장 스텝(모듈·플러그인) — 지문은 스텝 클래스 파일과 그 스텝이 실행하는 `data/<판>/migrations/*.php` 를 함께 잰다.
     * 같은 내용이면 검수 서버의 `--force` 가 건너뛰고, 데이터 마이그레이션 파일만 고쳐도 다시 돈다.
     *
     * @scenario case=module__stage_force_data_migration
     *
     * @effects stage_force_skips_done_step_with_same_fingerprint, data_migration_change_changes_fingerprint, stage_force_reruns_step_when_fingerprint_changes
     *
     * @return void 반환값 없음
     */
    public function test_stage_force_extension_step_follows_data_migration_fingerprint(): void
    {
        $this->role('stage');

        foreach (['module', 'plugin'] as $scope) {
            $tag = "fp_{$scope}";
            [$step, $migrationFile] = $this->writeDataMigrationStep($tag);
            $identifier = "acme-fp-{$scope}";
            [$manager, $owner] = $this->extensionWithSteps($scope, $identifier, ['1.2.0' => $step]);
            $method = new \ReflectionMethod($manager, 'runUpgradeSteps');

            $method->invoke($manager, $owner, '1.1.0', '1.2.0', false, null);
            $this->assertSame(1, $this->runs($tag), "{$scope}: 첫 실행");
            $before = $this->extensionRow($scope, $identifier, '1.2.0')->fingerprint;
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $before, "{$scope}: 클래스 스텝은 지문을 남긴다");

            $method->invoke($manager, $owner, '1.2.0', '1.2.0', true, null);
            $this->assertSame(1, $this->runs($tag), "{$scope}: 같은 내용의 같은 판 재배포는 다시 돌지 않는다");

            // 스텝 클래스 파일은 그대로 두고 그 스텝이 실행하는 데이터 마이그레이션만 고쳤다
            File::append($migrationFile, "\n// 검수 뒤 고친 판\n");

            $method->invoke($manager, $owner, '1.2.0', '1.2.0', true, null);
            $this->assertSame(2, $this->runs($tag), "{$scope}: 데이터 마이그레이션을 고치면 같은 판 번호여도 다시 돈다");
            $this->assertNotSame($before, $this->extensionRow($scope, $identifier, '1.2.0')->fingerprint, "{$scope}: 지문을 고친 내용으로 갱신한다");
        }
    }

    /**
     * 클로저 스텝(`callable@판`)은 지문이 없다 — 검수 서버의 `--force` 도 종전대로 다시 돈다.
     *
     * @scenario case=module__stage_force_closure
     *
     * @effects closure_step_has_no_fingerprint
     *
     * @return void 반환값 없음
     */
    public function test_closure_step_has_no_fingerprint_and_reruns_on_stage_force(): void
    {
        $this->role('stage');
        $runs = 0;
        [$manager, $module] = $this->extensionWithSteps('module', 'acme-fp-closure', ['1.1.0' => function () use (&$runs): void {
            $runs++;
        }]);
        $method = new \ReflectionMethod($manager, 'runUpgradeSteps');

        $method->invoke($manager, $module, '1.0.0', '1.1.0', false, null);
        $method->invoke($manager, $module, '1.1.0', '1.1.0', true, null);

        $this->assertSame(2, $runs, '내용을 잴 수 없는 클로저 스텝은 --force 에서 다시 돈다');
        $row = $this->extensionRow('module', 'acme-fp-closure', '1.1.0');
        $this->assertSame('callable@1.1.0', $row->step);
        $this->assertNull($row->fingerprint, '클로저 스텝은 지문을 남기지 않는다');
    }

    /**
     * 역할을 정합니다.
     *
     * @param  string  $role  실행 역할 (live|stage)
     * @return void 반환값 없음
     */
    private function role(string $role): void
    {
        config(['core.runtime_role' => $role]);
    }

    /**
     * 스텝이 돈 횟수.
     *
     * @param  string  $tag  스텝 구분
     * @return int 횟수
     */
    private function runs(string $tag): int
    {
        return (int) ($GLOBALS['g7_fingerprint_probe_runs'][$tag] ?? 0);
    }

    /**
     * 코어 스텝의 대장 행.
     *
     * @param  string  $version  스텝 판
     * @return UpgradeStepRun 대장 행
     */
    private function coreRow(string $version): UpgradeStepRun
    {
        return UpgradeStepRun::query()->where('scope', 'core')->where('identifier', '')->where('version', $version)->firstOrFail();
    }

    /**
     * 확장 스텝의 대장 행.
     *
     * @param  string  $scope  범위 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @param  string  $version  스텝 판
     * @return UpgradeStepRun 대장 행
     */
    private function extensionRow(string $scope, string $identifier, string $version): UpgradeStepRun
    {
        return UpgradeStepRun::query()->where('scope', $scope)->where('identifier', $identifier)->where('version', $version)->firstOrFail();
    }

    /**
     * 실행 횟수를 세는 코어 스텝 파일(`upgrades/Upgrade_0_0_7_test_{suffix}.php`)을 만듭니다.
     *
     * @param  string  $suffix  클래스 이름 접미 (판 접미 `-test.{suffix 의 _ 를 . 로}`)
     * @return string 만든 파일 경로
     */
    private function writeCoreCountingStep(string $suffix): string
    {
        $className = "Upgrade_0_0_7_test_{$suffix}";
        $path = base_path("upgrades/{$className}.php");
        File::put($path, <<<PHP
<?php

namespace App\\Upgrades;

use App\\Contracts\\Extension\\UpgradeStepInterface;
use App\\Extension\\UpgradeContext;

class {$className} implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void
    {
        \$GLOBALS['g7_fingerprint_probe_runs']['{$suffix}'] = (\$GLOBALS['g7_fingerprint_probe_runs']['{$suffix}'] ?? 0) + 1;
    }
}
PHP);
        $this->createdFiles[] = $path;

        return $path;
    }

    /**
     * `AbstractUpgradeStep` 을 상속한 빈 껍데기 스텝과, 그 스텝이 실행하는 `data/1.2.0/migrations/01_*.php` 를 임시 폴더에 만듭니다.
     *
     * 실행 횟수는 데이터 마이그레이션이 센다 — 스텝 클래스 파일은 손대지 않고 데이터 파일만 고쳐 지문 범위를 잰다.
     *
     * @param  string  $tag  스텝 구분
     * @return array{0: object, 1: string} [스텝 인스턴스, 데이터 마이그레이션 파일 경로]
     */
    private function writeDataMigrationStep(string $tag): array
    {
        $unique = $tag.'_'.uniqid();
        $root = storage_path('framework/testing/g7test-fingerprint-'.$unique);
        $this->createdDirs[] = $root;
        File::ensureDirectoryExists($root.'/upgrades/data/1.2.0/migrations');

        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $unique)));
        $stepClass = "Upgrade_1_2_0_{$studly}";
        File::put("{$root}/upgrades/{$stepClass}.php", <<<PHP
<?php

namespace Tests\\Fixtures\\UpgradeFingerprint;

use App\\Extension\\AbstractUpgradeStep;

class {$stepClass} extends AbstractUpgradeStep
{
}
PHP);

        $migrationClass = "FingerprintProbe{$studly}";
        $migrationFile = "{$root}/upgrades/data/1.2.0/migrations/01_{$migrationClass}.php";
        File::put($migrationFile, <<<PHP
<?php

namespace App\\Upgrades\\Data\\V1_2_0\\Migrations;

use App\\Extension\\Upgrade\\DataMigration;
use App\\Extension\\UpgradeContext;

final class {$migrationClass} implements DataMigration
{
    public function name(): string
    {
        return '{$migrationClass}';
    }

    public function run(UpgradeContext \$context): void
    {
        \$GLOBALS['g7_fingerprint_probe_runs']['{$tag}'] = (\$GLOBALS['g7_fingerprint_probe_runs']['{$tag}'] ?? 0) + 1;
    }
}
PHP);

        require_once "{$root}/upgrades/{$stepClass}.php";
        $fqcn = "Tests\\Fixtures\\UpgradeFingerprint\\{$stepClass}";

        return [new $fqcn, $migrationFile];
    }

    /**
     * 스텝 목록을 돌려주는 확장(목)과 그 Manager 를 만듭니다.
     *
     * @param  string  $scope  범위 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @param  array<string, mixed>  $steps  판 => 스텝
     * @return array{0: ModuleManager|PluginManager, 1: ModuleInterface|PluginInterface} [Manager, 확장]
     */
    private function extensionWithSteps(string $scope, string $identifier, array $steps): array
    {
        $owner = Mockery::mock($scope === 'module' ? ModuleInterface::class : PluginInterface::class);
        $owner->shouldReceive('getIdentifier')->andReturn($identifier);
        $owner->shouldReceive('getRequiredCoreVersion')->andReturn(null);
        $owner->shouldReceive('getVersion')->andReturn(array_key_last($steps));
        $owner->shouldReceive('upgrades')->andReturn($steps);

        return [app($scope === 'module' ? ModuleManager::class : PluginManager::class), $owner];
    }
}
