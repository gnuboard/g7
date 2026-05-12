<?php

namespace Tests\Unit\Extension\Upgrade;

use App\Extension\Upgrade\DataSnapshot;
use App\Extension\Upgrade\SnapshotApplier;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * DataSnapshot 의 manifest 로딩 / Applier 동적 로드 / versionedNamespace 검증.
 *
 * 본 테스트는 실제 DB 를 건드리지 않고 fixture 디렉토리만 사용.
 */
class DataSnapshotTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = storage_path('framework/testing/datasnapshot-'.uniqid());
        File::ensureDirectoryExists($this->fixtureDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->fixtureDir)) {
            File::deleteDirectory($this->fixtureDir);
        }
        parent::tearDown();
    }

    public function test_empty_returns_no_appliers(): void
    {
        $snapshot = DataSnapshot::empty();
        $this->assertSame([], $snapshot->appliers);
    }

    public function test_fromManifest_returns_empty_when_manifest_absent(): void
    {
        $context = $this->makeContext('9.9.9-test.empty');
        $snapshot = DataSnapshot::fromManifest($this->fixtureDir, $context);
        $this->assertSame([], $snapshot->appliers);
    }

    public function test_versionedNamespace_converts_version_to_namespace(): void
    {
        $context = $this->makeContext('7.0.0-beta.5');
        $this->assertSame(
            'App\\Upgrades\\Data\\V7_0_0_beta_5',
            DataSnapshot::versionedNamespace($context),
        );

        $context2 = $this->makeContext('1.2.3');
        $this->assertSame(
            'App\\Upgrades\\Data\\V1_2_3',
            DataSnapshot::versionedNamespace($context2),
        );
    }

    public function test_versionedNamespace_uses_core_pattern_when_source_path_is_core(): void
    {
        $context = $this->makeContext('7.0.0-beta.5');
        $this->assertSame(
            'App\\Upgrades\\Data\\V7_0_0_beta_5',
            DataSnapshot::versionedNamespace(
                $context,
                '/var/www/upgrades/data/7.0.0-beta.5',
            ),
        );
    }

    public function test_versionedNamespace_inserts_module_segment_when_source_path_is_bundled_module(): void
    {
        $context = $this->makeContext('1.2.0');
        $this->assertSame(
            'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_2_0',
            DataSnapshot::versionedNamespace(
                $context,
                '/var/www/modules/_bundled/sirsoft-ecommerce/upgrades/data/1.2.0',
            ),
        );
    }

    public function test_versionedNamespace_inserts_plugin_segment_when_source_path_is_bundled_plugin(): void
    {
        $context = $this->makeContext('2.0.0');
        $this->assertSame(
            'App\\Upgrades\\Data\\Ext\\Plugins\\SirsoftPayment\\V2_0_0',
            DataSnapshot::versionedNamespace(
                $context,
                '/var/www/plugins/_bundled/sirsoft-payment/upgrades/data/2.0.0',
            ),
        );
    }

    public function test_versionedNamespace_uses_ext_segment_for_external_extension_paths(): void
    {
        $context = $this->makeContext('0.3.1');
        // 외부 확장 (modules/{not _bundled}) 도 동일 격리 패턴
        $this->assertSame(
            'App\\Upgrades\\Data\\Ext\\Modules\\VendorExternalMod\\V0_3_1',
            DataSnapshot::versionedNamespace(
                $context,
                '/var/www/modules/vendor-external-mod/upgrades/data/0.3.1',
            ),
        );
    }

    public function test_versionedNamespace_different_extensions_same_step_version_produce_different_namespaces(): void
    {
        // 본 PR 이 해결한 namespace 충돌 회귀 — 같은 step 버전 + 다른 확장 → 다른 namespace.
        $context = $this->makeContext('1.0.0');
        $nsA = DataSnapshot::versionedNamespace(
            $context,
            '/var/www/modules/_bundled/vendor-foo/upgrades/data/1.0.0',
        );
        $nsB = DataSnapshot::versionedNamespace(
            $context,
            '/var/www/modules/_bundled/vendor-bar/upgrades/data/1.0.0',
        );
        $nsCore = DataSnapshot::versionedNamespace(
            $context,
            '/var/www/upgrades/data/1.0.0',
        );

        $this->assertNotSame($nsA, $nsB, '두 다른 확장은 같은 step 버전이라도 다른 namespace 가 되어야 함');
        $this->assertNotSame($nsA, $nsCore);
        $this->assertNotSame($nsB, $nsCore);
    }

    public function test_fromManifest_loads_applier_with_versioned_namespace(): void
    {
        $version = '0.0.1-snapshot.test';
        $context = $this->makeContext($version);

        // fixture: manifest + delta JSON + Applier (버전 namespace)
        File::put($this->fixtureDir.'/manifest.json', json_encode([
            'version' => $version,
            'files' => [
                ['kind' => 'sample', 'file' => 'sample.delta.json'],
            ],
        ]));
        File::put($this->fixtureDir.'/sample.delta.json', json_encode(['added' => []]));

        File::ensureDirectoryExists($this->fixtureDir.'/appliers');
        $token = 'V'.str_replace(['.', '-'], '_', $version);
        File::put($this->fixtureDir.'/appliers/SampleApplier.php', <<<PHP
<?php
namespace App\Upgrades\Data\\{$token}\Appliers;

use App\Extension\Upgrade\SnapshotApplier;
use App\Extension\UpgradeContext;

class SampleApplier implements SnapshotApplier
{
    public function __construct(public readonly string \$jsonPath) {}

    public function apply(UpgradeContext \$context): void
    {
        // no-op
    }
}
PHP);

        $snapshot = DataSnapshot::fromManifest($this->fixtureDir, $context);

        $this->assertCount(1, $snapshot->appliers);
        $this->assertInstanceOf(SnapshotApplier::class, $snapshot->appliers[0]);
        $this->assertSame(
            $this->fixtureDir.'/sample.delta.json',
            $snapshot->appliers[0]->jsonPath,
        );
    }

    public function test_fromManifest_throws_when_applier_file_missing(): void
    {
        $context = $this->makeContext('0.0.2-missing.applier');

        File::put($this->fixtureDir.'/manifest.json', json_encode([
            'version' => '0.0.2-missing.applier',
            'files' => [
                ['kind' => 'permissions', 'file' => 'permissions.delta.json'],
            ],
        ]));
        File::put($this->fixtureDir.'/permissions.delta.json', json_encode(['added' => []]));

        // appliers/ 디렉토리에 PermissionsApplier 파일 미작성

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Applier file not found/');

        DataSnapshot::fromManifest($this->fixtureDir, $context);
    }

    public function test_fromManifest_throws_when_namespace_mismatch(): void
    {
        $version = '0.0.3-wrong.ns';
        $context = $this->makeContext($version);

        File::put($this->fixtureDir.'/manifest.json', json_encode([
            'version' => $version,
            'files' => [
                ['kind' => 'foo', 'file' => 'foo.delta.json'],
            ],
        ]));
        File::put($this->fixtureDir.'/foo.delta.json', json_encode(['added' => []]));

        File::ensureDirectoryExists($this->fixtureDir.'/appliers');
        // 잘못된 namespace 로 클래스 선언 (V0_0_3_wrong_ns 가 아닌 임의 namespace)
        File::put($this->fixtureDir.'/appliers/FooApplier.php', <<<'PHP'
<?php
namespace App\Upgrades\Data\WrongNamespace\Appliers;

use App\Extension\Upgrade\SnapshotApplier;
use App\Extension\UpgradeContext;

class FooApplier implements SnapshotApplier
{
    public function __construct(public readonly string $jsonPath) {}
    public function apply(UpgradeContext $context): void {}
}
PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Applier class not declared in expected namespace/');

        DataSnapshot::fromManifest($this->fixtureDir, $context);
    }

    private function makeContext(string $version): UpgradeContext
    {
        return new UpgradeContext(
            fromVersion: '0.0.0',
            toVersion: $version,
            currentStep: $version,
        );
    }
}
