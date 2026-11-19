<?php

namespace Tests\Feature\Upgrades;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\AbstractUpgradeStep;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Upgrade\DataSnapshot;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * AbstractUpgradeStep 인프라가 *확장 위치* 에서도 코어와 동일하게 동작하는지 통합 검증.
 *
 * 본 테스트는 확장 측 dogfood step 이 아직 존재하지 않는 상황에서 회귀 안전망 역할:
 *   - dataDir() 가 확장 디렉토리로 자동 분기되는가 (ReflectionClass 기반)
 *   - Applier 가 확장의 delta JSON 을 읽어 호출되는가
 *   - Migration 이 확장 디렉토리의 migrations/ 에서 glob+require_once 로 로드되는가
 *   - postRun() 호출 순서가 보존되는가 (Applier → Migration → postRun)
 *   - manifest 부재 시 graceful (no fatal)
 *   - manifest 의 Applier 파일 미존재 시 명확한 RuntimeException
 *   - 같은 버전 namespace 의 다른 파일이 두 번 require_once 될 때의 동작 (known limitation)
 */
class ExtensionAbstractUpgradeStepFullFlowTest extends TestCase
{
    private array $tempDirs = [];

    /**
     * 스텝 호출 순서를 기록할 전역 배열을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__ext_full_flow_calls'] = [];
    }

    /**
     * 만든 확장 디렉토리와 전역 기록을 지우고 Mockery 를 닫은 뒤 부모 정리를 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }
        unset($GLOBALS['__ext_full_flow_calls']);
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ── 모듈 측 전체 흐름 ────────────────────────────────────────────────

    /**
     * 모듈 스텝이 Applier, 파일명 순서의 Migration, postRun 순으로 실행된다.
     *
     * @scenario extension_type=bundled_module, data_dir_state=present_full
     *
     * @effects extension_full_flow_preserves_applier_migration_post_run_order
     *
     * @return void 반환값 없음
     */
    public function test_module_full_flow_runs_applier_then_migration_then_post_run(): void
    {
        $version = '0.0.21-test.mod.flow';
        $extDir = $this->makeExtensionDir('module-flow');
        $stepClass = $this->writeStepFile($extDir, $version, postRunCall: 'PostRunM1');
        $this->writeManifest($extDir, $version, [
            ['kind' => 'permissions', 'file' => 'permissions.delta.json'],
        ]);
        $this->writeDelta($extDir, $version, 'permissions.delta.json', $this->permissionsDelta('PermApplierM1'));
        $this->writeApplier($extDir, $version, 'PermissionsApplier', 'PermApplierM1');
        $this->writeMigration($extDir, $version, '01_FirstStep.php', 'FirstStep', 'MigrationM1A');
        $this->writeMigration($extDir, $version, '02_SecondStep.php', 'SecondStep', 'MigrationM1B');

        $stepInstance = new $stepClass;
        $module = $this->mockModule(
            identifier: 'vendor-module-flow',
            workingVersion: $version,
            g7Version: '>=7.0.0-beta.5',
            upgrades: [$version => $stepInstance],
        );

        $this->invokeRunUpgradeSteps(
            manager: app(ModuleManager::class),
            extension: $module,
            from: '0.0.20',
            to: $version,
        );

        $this->assertSame(
            ['PermApplierM1', 'MigrationM1A', 'MigrationM1B', 'PostRunM1'],
            $GLOBALS['__ext_full_flow_calls'],
            'Applier 먼저, 다음 Migration 정렬 순서, 마지막 postRun',
        );
    }

    /**
     * 모듈 스텝의 dataDir() 가 스텝 파일 옆 data/{버전}/ 를 가리킨다.
     *
     * @scenario extension_type=bundled_module, data_dir_state=present_full
     *
     * @effects data_dir_resolves_to_extension_directory_via_reflection
     *
     * @return void 반환값 없음
     */
    public function test_module_data_dir_resolves_under_extension_path(): void
    {
        $version = '0.0.22-test.mod.datadir';
        $extDir = $this->makeExtensionDir('module-datadir');
        $stepClass = $this->writeStepFile($extDir, $version);
        $this->writeManifest($extDir, $version, [
            ['kind' => 'permissions', 'file' => 'permissions.delta.json'],
        ]);
        $this->writeDelta($extDir, $version, 'permissions.delta.json', $this->permissionsDelta('DataDirApplier'));
        $this->writeApplier($extDir, $version, 'PermissionsApplier', 'DataDirApplier');

        $stepInstance = new $stepClass;

        // dataDir() 는 protected — Reflection 으로 호출
        $reflection = new \ReflectionClass($stepInstance);
        $dataDirMethod = $reflection->getMethod('dataDir');
        $dataDirMethod->setAccessible(true);

        $context = new UpgradeContext(
            fromVersion: '0.0.21',
            toVersion: $version,
            currentStep: $version,
        );

        $resolvedPath = $this->normalizePath($dataDirMethod->invoke($stepInstance, $context));
        $expected = $this->normalizePath($extDir.'/data/'.$version);

        $this->assertStringStartsWith(
            $this->normalizePath($extDir),
            $resolvedPath,
            'dataDir() 는 확장 디렉토리 안에서 해석되어야 함 (ReflectionClass 기반 자동 분기)',
        );
        $this->assertSame(
            $expected,
            $resolvedPath,
            'dataDir() 는 확장 step 파일 옆 data/{ver}/ 를 가리켜야 함',
        );
    }

    /**
     * manifest.json 이 없으면 Applier 와 Migration 없이 postRun 만 실행되고 실패하지 않는다.
     *
     * @scenario extension_type=bundled_module, data_dir_state=absent
     *
     * @effects manifest_json_absent_is_graceful_silent
     *
     * @return void 반환값 없음
     */
    public function test_module_manifest_absent_does_not_fail(): void
    {
        // manifest.json 부재 → DataSnapshot::empty() 반환 → Applier 0개, fatal 없음
        $version = '0.0.23-test.mod.empty';
        $extDir = $this->makeExtensionDir('module-empty');
        $stepClass = $this->writeStepFile($extDir, $version, postRunCall: 'PostRunEmpty');
        // 의도적으로 data/{ver}/ 디렉토리 미생성

        $stepInstance = new $stepClass;
        $module = $this->mockModule(
            identifier: 'vendor-module-empty',
            workingVersion: $version,
            g7Version: '>=7.0.0-beta.5',
            upgrades: [$version => $stepInstance],
        );

        $this->invokeRunUpgradeSteps(
            manager: app(ModuleManager::class),
            extension: $module,
            from: '0.0.22',
            to: $version,
        );

        $this->assertSame(
            ['PostRunEmpty'],
            $GLOBALS['__ext_full_flow_calls'],
            'manifest 부재 시 Applier/Migration 0건, postRun 만 실행',
        );
    }

    /**
     * manifest 가 가리키는 Applier 파일이 없으면 「Applier file not found」 RuntimeException 을 던진다.
     *
     * @scenario extension_type=bundled_module, applier_namespace=missing_class
     *
     * @effects manifest_applier_file_missing_throws_explicit_runtime_exception
     *
     * @return void 반환값 없음
     */
    public function test_module_missing_applier_file_throws_runtime_exception(): void
    {
        $version = '0.0.24-test.mod.missing';
        $extDir = $this->makeExtensionDir('module-missing');
        $stepClass = $this->writeStepFile($extDir, $version);
        $this->writeManifest($extDir, $version, [
            ['kind' => 'permissions', 'file' => 'permissions.delta.json'],
        ]);
        $this->writeDelta($extDir, $version, 'permissions.delta.json', $this->permissionsDelta('NotUsed'));
        // 의도적으로 appliers/PermissionsApplier.php 생성 안 함

        $stepInstance = new $stepClass;
        $module = $this->mockModule(
            identifier: 'vendor-module-missing',
            workingVersion: $version,
            g7Version: '>=7.0.0-beta.5',
            upgrades: [$version => $stepInstance],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Applier file not found/');

        $this->invokeRunUpgradeSteps(
            manager: app(ModuleManager::class),
            extension: $module,
            from: '0.0.23',
            to: $version,
        );
    }

    // ── 플러그인 측 전체 흐름 (모듈과 1:1 대응) ─────────────────────────

    /**
     * 플러그인 스텝이 Applier, Migration, postRun 순으로 실행된다.
     *
     * @scenario extension_type=bundled_plugin, data_dir_state=present_full
     *
     * @effects extension_full_flow_preserves_applier_migration_post_run_order
     *
     * @return void 반환값 없음
     */
    public function test_plugin_full_flow_runs_applier_then_migration_then_post_run(): void
    {
        $version = '0.0.31-test.plg.flow';
        $extDir = $this->makeExtensionDir('plugin-flow', 'plugin');
        $stepClass = $this->writeStepFile($extDir, $version, postRunCall: 'PostRunP1');
        $this->writeManifest($extDir, $version, [
            ['kind' => 'permissions', 'file' => 'permissions.delta.json'],
        ]);
        $this->writeDelta($extDir, $version, 'permissions.delta.json', $this->permissionsDelta('PermApplierP1'));
        $this->writeApplier($extDir, $version, 'PermissionsApplier', 'PermApplierP1');
        $this->writeMigration($extDir, $version, '01_FirstStep.php', 'FirstStep', 'MigrationP1A');

        $stepInstance = new $stepClass;
        $plugin = $this->mockPlugin(
            identifier: 'vendor-plugin-flow',
            workingVersion: $version,
            g7Version: '>=7.0.0-beta.5',
            upgrades: [$version => $stepInstance],
        );

        $this->invokeRunUpgradeSteps(
            manager: app(PluginManager::class),
            extension: $plugin,
            from: '0.0.30',
            to: $version,
        );

        $this->assertSame(
            ['PermApplierP1', 'MigrationP1A', 'PostRunP1'],
            $GLOBALS['__ext_full_flow_calls'],
        );
    }

    /**
     * 플러그인 스텝의 dataDir() 가 스텝 파일 옆 data/{버전}/ 를 가리킨다.
     *
     * @scenario extension_type=bundled_plugin, data_dir_state=absent
     *
     * @effects data_dir_resolves_to_extension_directory_via_reflection
     *
     * @return void 반환값 없음
     */
    public function test_plugin_data_dir_resolves_under_extension_path(): void
    {
        $version = '0.0.32-test.plg.datadir';
        $extDir = $this->makeExtensionDir('plugin-datadir', 'plugin');
        $stepClass = $this->writeStepFile($extDir, $version);

        $stepInstance = new $stepClass;
        $reflection = new \ReflectionClass($stepInstance);
        $dataDirMethod = $reflection->getMethod('dataDir');
        $dataDirMethod->setAccessible(true);

        $context = new UpgradeContext(
            fromVersion: '0.0.31',
            toVersion: $version,
            currentStep: $version,
        );

        $resolvedPath = $this->normalizePath($dataDirMethod->invoke($stepInstance, $context));
        $expected = $this->normalizePath($extDir.'/data/'.$version);

        $this->assertSame($expected, $resolvedPath);
    }

    /**
     * Windows / POSIX 경로 구분자 차이 흡수.
     *
     * @param  string  $path  원본 경로
     * @return string 슬래시로 통일한 경로
     */
    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    // ── namespace 격리 회귀 가드 (KNOWN LIMITATION 해결 검증) ────────────

    /**
     * 두 다른 번들 모듈이 *같은 step 버전 + 같은 Migration 클래스명* 을 가져도
     * 각자 격리된 namespace 로 인해 PHP compile-time fatal 없이 양쪽 모두 실행됨을 검증.
     *
     * 격리 메커니즘:
     *   - 모듈 A: `modules/_bundled/test-ext-collision-a-xxx/upgrades/data/{ver}/migrations/01_Shared.php`
     *     → namespace `App\Upgrades\Data\Ext\Modules\TestExtCollisionA{xxx}\V{ver}\Migrations\Shared`
     *   - 모듈 B: `modules/_bundled/test-ext-collision-b-yyy/upgrades/data/{ver}/migrations/01_Shared.php`
     *     → namespace `App\Upgrades\Data\Ext\Modules\TestExtCollisionB{yyy}\V{ver}\Migrations\Shared`
     *
     * `require_once` 가 두 파일을 모두 로드하고, 각자 별개 FQCN 으로 선언되어 충돌 없음.
     *
     * 본 테스트는 KNOWN LIMITATION 의 해결 후 회귀 안전망 — 향후 namespace 패턴이 다시
     * 변경되어 두 확장이 같은 FQCN 을 갖게 되면 본 테스트가 fatal 로 실패한다.
     *
     * @scenario extension_type=bundled_module, cross_extension_namespace_collision=two_extensions_same_step_version_class
     *
     * @effects two_modules_same_step_version_same_migration_class_both_run_own_code
     *
     * @return void 반환값 없음
     */
    public function test_two_extensions_with_same_step_version_isolated_by_namespace(): void
    {
        $sharedVersion = '0.0.42-test.shared.ver';

        // 모듈 A
        $extDirA = $this->makeExtensionDir('collision-a');
        $stepClassA = $this->writeStepFile($extDirA, $sharedVersion);
        $this->writeMigration($extDirA, $sharedVersion, '01_Shared.php', 'Shared', 'CollisionA');

        // 모듈 B (다른 디렉토리, 다른 모듈 식별자, 같은 클래스명 + 같은 버전)
        $extDirB = $this->makeExtensionDir('collision-b');
        $stepClassB = $this->writeStepFile($extDirB, $sharedVersion);
        $this->writeMigration($extDirB, $sharedVersion, '01_Shared.php', 'Shared', 'CollisionB');

        $stepA = new $stepClassA;
        $stepB = new $stepClassB;

        $moduleA = $this->mockModule(
            identifier: 'vendor-collision-a',
            workingVersion: $sharedVersion,
            g7Version: '>=7.0.0-beta.5',
            upgrades: [$sharedVersion => $stepA],
        );
        $moduleB = $this->mockModule(
            identifier: 'vendor-collision-b',
            workingVersion: $sharedVersion,
            g7Version: '>=7.0.0-beta.5',
            upgrades: [$sharedVersion => $stepB],
        );

        $manager = app(ModuleManager::class);

        $this->invokeRunUpgradeSteps(
            manager: $manager,
            extension: $moduleA,
            from: '0.0.41',
            to: $sharedVersion,
        );
        $this->invokeRunUpgradeSteps(
            manager: $manager,
            extension: $moduleB,
            from: '0.0.41',
            to: $sharedVersion,
        );

        // 두 확장 모두 자기 코드의 라벨로 호출되어야 함 — namespace 격리 성립
        $this->assertSame(
            ['CollisionA', 'CollisionB'],
            $GLOBALS['__ext_full_flow_calls'],
            '두 확장이 같은 step 버전 + 같은 클래스명을 가져도 namespace 격리로 각자 실행됨',
        );
    }

    /**
     * 같은 스텝 버전이라도 모듈, 플러그인, 코어가 서로 다른 namespace 를 받고 확장 식별자가 그 안에 들어간다.
     *
     * @scenario cross_extension_namespace_collision=two_extensions_same_step_version_class
     *
     * @effects same_step_version_different_extensions_get_distinct_namespaces
     *
     * @return void 반환값 없음
     */
    public function test_versionedNamespace_uses_distinct_namespaces_for_different_extensions_same_version(): void
    {
        // namespace 격리의 *근원* 정적 검증 — DataSnapshot 단위에서 검증.
        $context = new UpgradeContext(
            fromVersion: '0.0.0',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        );

        $nsModuleA = DataSnapshot::versionedNamespace(
            $context,
            '/var/www/modules/_bundled/vendor-foo/upgrades/data/1.0.0',
        );
        $nsModuleB = DataSnapshot::versionedNamespace(
            $context,
            '/var/www/modules/_bundled/vendor-bar/upgrades/data/1.0.0',
        );
        $nsPlugin = DataSnapshot::versionedNamespace(
            $context,
            '/var/www/plugins/_bundled/vendor-baz/upgrades/data/1.0.0',
        );
        $nsCore = DataSnapshot::versionedNamespace(
            $context,
            '/var/www/upgrades/data/1.0.0',
        );

        $this->assertNotSame($nsModuleA, $nsModuleB);
        $this->assertNotSame($nsModuleA, $nsPlugin);
        $this->assertNotSame($nsModuleA, $nsCore);
        $this->assertNotSame($nsModuleB, $nsCore);
        $this->assertNotSame($nsPlugin, $nsCore);

        // 확장 식별자 segment 가 실제로 포함되어 있는지 확인
        $this->assertStringContainsString('VendorFoo', $nsModuleA);
        $this->assertStringContainsString('VendorBar', $nsModuleB);
        $this->assertStringContainsString('VendorBaz', $nsPlugin);

        // 코어는 확장 segment 없음
        $this->assertStringNotContainsString('Ext\\Modules', $nsCore);
        $this->assertStringNotContainsString('Ext\\Plugins', $nsCore);
    }

    // ── 헬퍼: 디렉토리/파일 fixture ─────────────────────────────────────

    /**
     * 확장 디렉토리 fixture 작성. 경로에 `modules/_bundled/{id}/upgrades/` 또는
     * `plugins/_bundled/{id}/upgrades/` 마커가 포함되어야 `DataSnapshot::versionedNamespace`
     * 가 확장 namespace 분기로 진입한다.
     *
     * type 이 'module' 이면 modules/_bundled, 'plugin' 이면 plugins/_bundled.
     *
     * @param  string  $label  디렉토리 이름에 넣을 표식
     * @param  string  $type  확장 종류('module' 또는 'plugin')
     * @return string 만든 upgrades 디렉토리의 절대경로
     */
    private function makeExtensionDir(string $label, string $type = 'module'): string
    {
        $extType = $type === 'plugin' ? 'plugins' : 'modules';
        $identifier = 'test-ext-'.$label.'-'.bin2hex(random_bytes(4));
        // storage/.../testing/ 안의 합성 경로 — 마커 substring 만 매칭하면 namespace 분기 작동.
        $base = storage_path('framework/testing/'.$extType.'/_bundled/'.$identifier.'/upgrades');
        File::ensureDirectoryExists($base);
        // 정리 대상은 {id} 디렉토리 (storage/framework/testing/{type}/_bundled/{id})
        $this->tempDirs[] = dirname($base);

        return $base;
    }

    /**
     * 확장의 step 파일을 작성 후 require_once + FQCN 반환.
     *
     * step 클래스명은 fixture 별 고유 — PHP 클래스 재선언 회피.
     *
     * @param  string  $extDir  확장의 upgrades 디렉토리
     * @param  string  $version  스텝 버전
     * @param  string|null  $postRunCall  postRun 이 호출 기록에 남길 표식(없으면 남기지 않음)
     * @return string 만든 스텝 클래스의 FQCN
     */
    private function writeStepFile(string $extDir, string $version, ?string $postRunCall = null): string
    {
        $unique = 'ExtStep_'.bin2hex(random_bytes(8));
        $stepFile = $extDir.'/'.$unique.'.php';

        $postRunBody = $postRunCall !== null
            ? "\$GLOBALS['__ext_full_flow_calls'][] = '{$postRunCall}';"
            : '';

        $contents = <<<PHP
<?php
namespace App\\Upgrades\\Data\\TestFixtures;

use App\\Extension\\AbstractUpgradeStep;
use App\\Extension\\UpgradeContext;

class {$unique} extends AbstractUpgradeStep
{
    protected function postRun(UpgradeContext \$context): void
    {
        {$postRunBody}
    }
}
PHP;
        File::put($stepFile, $contents);
        require_once $stepFile;

        return 'App\\Upgrades\\Data\\TestFixtures\\'.$unique;
    }

    /**
     * data/{버전}/manifest.json 을 쓴다.
     *
     * @param  string  $extDir  확장의 upgrades 디렉토리
     * @param  string  $version  스텝 버전
     * @param  array<int, array<string, string>>  $entries  manifest 의 files 항목
     * @return void 반환값 없음
     */
    private function writeManifest(string $extDir, string $version, array $entries): void
    {
        $dir = $extDir.'/data/'.$version;
        File::ensureDirectoryExists($dir);
        File::put(
            $dir.'/manifest.json',
            json_encode(
                ['version' => $version, 'files' => $entries],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    /**
     * data/{버전}/ 아래에 delta JSON 파일을 쓴다.
     *
     * @param  string  $extDir  확장의 upgrades 디렉토리
     * @param  string  $version  스텝 버전
     * @param  string  $file  delta 파일 이름
     * @param  array<string, mixed>  $delta  delta 내용
     * @return void 반환값 없음
     */
    private function writeDelta(string $extDir, string $version, string $file, array $delta): void
    {
        $dir = $extDir.'/data/'.$version;
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$file, json_encode($delta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 호출되면 표식을 기록하는 Applier 클래스 파일을 data/{버전}/appliers/ 에 쓴다.
     *
     * @param  string  $extDir  확장의 upgrades 디렉토리
     * @param  string  $version  스텝 버전
     * @param  string  $className  Applier 클래스 이름
     * @param  string  $callLabel  호출 기록에 남길 표식
     * @return void 반환값 없음
     */
    private function writeApplier(string $extDir, string $version, string $className, string $callLabel): void
    {
        $dir = $extDir.'/data/'.$version.'/appliers';
        File::ensureDirectoryExists($dir);
        $namespace = $this->namespaceForDataFile($dir, $version);
        $contents = <<<PHP
<?php
namespace {$namespace}\\Appliers;

use App\\Extension\\Upgrade\\SnapshotApplier;
use App\\Extension\\UpgradeContext;

class {$className} implements SnapshotApplier
{
    public function __construct(private readonly string \$jsonPath) {}

    public function apply(UpgradeContext \$context): void
    {
        \$GLOBALS['__ext_full_flow_calls'][] = '{$callLabel}';
    }
}
PHP;
        File::put($dir.'/'.$className.'.php', $contents);
    }

    /**
     * 호출되면 표식을 기록하는 Migration 클래스 파일을 data/{버전}/migrations/ 에 쓴다.
     *
     * @param  string  $extDir  확장의 upgrades 디렉토리
     * @param  string  $version  스텝 버전
     * @param  string  $fileName  마이그레이션 파일 이름(정렬용 접두 포함)
     * @param  string  $className  Migration 클래스 이름
     * @param  string  $callLabel  호출 기록에 남길 표식
     * @return void 반환값 없음
     */
    private function writeMigration(
        string $extDir,
        string $version,
        string $fileName,
        string $className,
        string $callLabel,
    ): void {
        $dir = $extDir.'/data/'.$version.'/migrations';
        File::ensureDirectoryExists($dir);
        $namespace = $this->namespaceForDataFile($dir, $version);
        $contents = <<<PHP
<?php
namespace {$namespace}\\Migrations;

use App\\Extension\\Upgrade\\DataMigration;
use App\\Extension\\UpgradeContext;

class {$className} implements DataMigration
{
    public function name(): string { return '{$className}'; }

    public function run(UpgradeContext \$context): void
    {
        \$GLOBALS['__ext_full_flow_calls'][] = '{$callLabel}';
    }
}
PHP;
        File::put($dir.'/'.$fileName, $contents);
    }

    /**
     * fixture 의 namespace prefix 계산.
     *
     * DataSnapshot::versionedNamespace 의 PHP 측 SSoT 를 그대로 호출하여 동기 보장.
     *
     * @param  string  $dir  data 하위 디렉토리 경로
     * @param  string  $version  스텝 버전
     * @return string 그 디렉토리의 namespace 접두
     */
    private function namespaceForDataFile(string $dir, string $version): string
    {
        $context = new UpgradeContext(
            fromVersion: '0.0.0',
            toVersion: $version,
            currentStep: $version,
        );

        return DataSnapshot::versionedNamespace($context, $dir);
    }

    /**
     * 항목이 없는 권한 delta 를 돌려준다.
     *
     * @param  string  $unusedLabel  쓰지 않는 표식(호출부 가독용)
     * @return array<string, array<int, mixed>> added, removed, renamed 가 빈 delta
     */
    private function permissionsDelta(string $unusedLabel): array
    {
        return [
            'added' => [],
            'removed' => [],
            'renamed' => [],
        ];
    }

    // ── 헬퍼: Manager 진입점 ─────────────────────────────────────────────

    /**
     * 식별자, 버전, 요구 코어 버전, 스텝 목록만 돌려주는 모듈 대역을 만든다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  string  $workingVersion  확장의 현재 버전
     * @param  string|null  $g7Version  요구 코어 버전 제약(없으면 null)
     * @param  array<string, mixed>  $upgrades  버전별 업그레이드 스텝
     * @return ModuleInterface 모듈 대역
     */
    private function mockModule(
        string $identifier,
        string $workingVersion,
        ?string $g7Version,
        array $upgrades,
    ): ModuleInterface {
        $module = Mockery::mock(ModuleInterface::class);
        $module->shouldReceive('getIdentifier')->andReturn($identifier);
        $module->shouldReceive('getVersion')->andReturn($workingVersion);
        $module->shouldReceive('getRequiredCoreVersion')->andReturn($g7Version);
        $module->shouldReceive('upgrades')->andReturn($upgrades);

        return $module;
    }

    /**
     * 식별자, 버전, 요구 코어 버전, 스텝 목록만 돌려주는 플러그인 대역을 만든다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  string  $workingVersion  확장의 현재 버전
     * @param  string|null  $g7Version  요구 코어 버전 제약(없으면 null)
     * @param  array<string, mixed>  $upgrades  버전별 업그레이드 스텝
     * @return PluginInterface 플러그인 대역
     */
    private function mockPlugin(
        string $identifier,
        string $workingVersion,
        ?string $g7Version,
        array $upgrades,
    ): PluginInterface {
        $plugin = Mockery::mock(PluginInterface::class);
        $plugin->shouldReceive('getIdentifier')->andReturn($identifier);
        $plugin->shouldReceive('getVersion')->andReturn($workingVersion);
        $plugin->shouldReceive('getRequiredCoreVersion')->andReturn($g7Version);
        $plugin->shouldReceive('upgrades')->andReturn($upgrades);

        return $plugin;
    }

    /**
     * 매니저의 비공개 runUpgradeSteps 를 리플렉션으로 호출한다.
     *
     * @param  ModuleManager|PluginManager  $manager  대상 매니저
     * @param  ModuleInterface|PluginInterface  $extension  스텝을 가진 확장
     * @param  string  $from  시작 버전
     * @param  string  $to  목표 버전
     * @param  \Closure|null  $onStep  스텝마다 불리는 콜백(없으면 null)
     * @return void 반환값 없음
     */
    private function invokeRunUpgradeSteps(
        $manager,
        $extension,
        string $from,
        string $to,
        ?\Closure $onStep = null,
    ): void {
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('runUpgradeSteps');
        $method->setAccessible(true);
        $method->invoke($manager, $extension, $from, $to, false, $onStep);
    }
}
