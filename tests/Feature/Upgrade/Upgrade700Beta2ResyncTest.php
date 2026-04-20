<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Upgrades\Upgrade_7_0_0_beta_2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Test-3: 경로 C — `Upgrade_7_0_0_beta_2::resyncCorePermissionsAndMenus()` 가
 *
 *  1. config/core.php 를 디스크에서 다시 require 하고
 *  2. config repository 의 'core' 네임스페이스를 갱신하며
 *  3. `CoreUpdateService::syncCoreRolesAndPermissions` / `syncCoreMenus` 를 재호출
 *
 * 하는지 실증한다. beta.1 → beta.2 특수 경로(Path C)의 단발성 로컬 로직 검증.
 *
 * 본 메서드는 beta.1 메모리에서 실행되는 전제로 작성되었으므로 **기존 클래스(CoreUpdateService)
 * 의 기존 메서드만** 호출한다. 새로 도입된 `reloadCoreConfigAndResync` 호출 금지 (beta.1 메모리에는
 * 존재하지 않음 — Fatal 유발). 본 테스트는 이 계약을 검증하기보다 "동일한 효과를 로컬 로직으로
 * 달성하는지" 를 확인한다.
 */
class Upgrade700Beta2ResyncTest extends TestCase
{
    use RefreshDatabase;

    private string $coreConfigPath;

    private string $backupPath;

    private bool $backupCreated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreConfigPath = config_path('core.php');
        $this->backupPath = $this->coreConfigPath.'.test-backup-'.uniqid();

        // upgrade 파일은 composer PSR-4 autoload 대상이 아니며 runUpgradeSteps()가
        // 실행 시점에 require_once 로 수동 로드한다. 테스트에서도 동일하게 수동 로드.
        require_once base_path('upgrades/Upgrade_7_0_0_beta_2.php');
    }

    protected function tearDown(): void
    {
        if ($this->backupCreated && File::exists($this->backupPath)) {
            File::copy($this->backupPath, $this->coreConfigPath);
            File::delete($this->backupPath);
            config(['core' => require $this->coreConfigPath]);
        }
        parent::tearDown();
    }

    /**
     * private resyncCorePermissionsAndMenus 를 Reflection 으로 호출합니다.
     */
    private function invokeResync(Upgrade_7_0_0_beta_2 $upgrade, UpgradeContext $context): void
    {
        $ref = new ReflectionMethod($upgrade, 'resyncCorePermissionsAndMenus');
        $ref->setAccessible(true);
        $ref->invoke($upgrade, $context);
    }

    public function test_resyncCorePermissionsAndMenus_reads_disk_and_upserts_new_permissions(): void
    {
        if (! File::exists($this->coreConfigPath)) {
            $this->markTestSkipped('config/core.php 미존재');
        }

        File::copy($this->coreConfigPath, $this->backupPath);
        $this->backupCreated = true;

        // 디스크에 신규 카테고리 + 권한 추가 (beta.2 신규 권한 시뮬레이션)
        $original = require $this->coreConfigPath;
        $modified = $original;
        $modified['permissions']['categories'][] = [
            'identifier' => 'core.test_beta2_path_c',
            'name' => ['ko' => '경로 C 테스트', 'en' => 'Path C Test'],
            'description' => ['ko' => 'Path C test category', 'en' => 'Path C test category'],
            'order' => 9998,
            'permissions' => [
                [
                    'identifier' => 'core.test_beta2_path_c.read',
                    'type' => 'admin',
                    'name' => ['ko' => '경로 C 조회', 'en' => 'View Path C'],
                    'description' => ['ko' => 'Can view', 'en' => 'Can view'],
                    'order' => 1,
                ],
            ],
        ];
        File::put($this->coreConfigPath, '<?php return '.var_export($modified, true).';');

        // 메모리 config 에는 아직 신규 권한 없음
        $memoryNames = collect(config('core.permissions.categories', []))
            ->pluck('identifier')
            ->all();
        $this->assertNotContains('core.test_beta2_path_c', $memoryNames);

        // Reflection 으로 private resyncCorePermissionsAndMenus 호출
        $upgrade = new Upgrade_7_0_0_beta_2;
        $context = new UpgradeContext('7.0.0-beta.1', '7.0.0-beta.2', '7.0.0-beta.2');
        $this->invokeResync($upgrade, $context);

        // 디스크 재로드가 성공하여 신규 권한이 DB 에 upsert 되어야 한다
        $this->assertDatabaseHas('permissions', [
            'identifier' => 'core.test_beta2_path_c.read',
        ]);
    }

    public function test_resyncCorePermissionsAndMenus_skips_gracefully_if_config_missing(): void
    {
        if (! File::exists($this->coreConfigPath)) {
            $this->markTestSkipped('config/core.php 미존재');
        }

        File::copy($this->coreConfigPath, $this->backupPath);
        $this->backupCreated = true;
        File::delete($this->coreConfigPath);

        try {
            $upgrade = new Upgrade_7_0_0_beta_2;
            $context = new UpgradeContext('7.0.0-beta.1', '7.0.0-beta.2', '7.0.0-beta.2');
            $this->invokeResync($upgrade, $context);
            $this->assertTrue(true, 'config 파일 미존재 시 예외 없이 로그 경고 후 정상 반환');
        } finally {
            File::copy($this->backupPath, $this->coreConfigPath);
        }
    }
}
