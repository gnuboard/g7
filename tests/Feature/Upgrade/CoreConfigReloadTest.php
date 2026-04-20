<?php

namespace Tests\Feature\Upgrade;

use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Test-2: `CoreUpdateService::reloadCoreConfigAndResync()` 가
 *
 *  1. config/core.php 를 디스크에서 다시 require 하고
 *  2. config repository 의 'core' 네임스페이스를 갱신하며
 *  3. syncCoreRolesAndPermissions / syncCoreMenus 를 재호출하여 DB 에 반영
 *
 * 하는지 실증한다. 경로 B (spawn) 실패 시 in-process fallback 으로 사용되는 핵심 로직.
 *
 * 파일 IO 경로는 config_path('core.php') 를 일시적으로 백업/복원하는 방식으로 검증한다.
 * tearDown 에서 원본을 반드시 복원하므로 테스트 실패 시에도 데이터 손상은 없다.
 */
class CoreConfigReloadTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        // 테스트 실패 시에도 원본 복원 보장
        if ($this->backupCreated && File::exists($this->backupPath)) {
            File::copy($this->backupPath, $this->coreConfigPath);
            File::delete($this->backupPath);
            // 메모리 config 도 원본으로 재주입
            config(['core' => require $this->coreConfigPath]);
        }
        parent::tearDown();
    }

    public function test_reloadCoreConfigAndResync_runs_without_error_on_current_config(): void
    {
        $service = app(CoreUpdateService::class);

        // 현재 config/core.php 기반으로 재동기화 수행 — 예외 없이 완료해야 한다
        $service->reloadCoreConfigAndResync();

        // 코어 최상위 권한(module) 이 DB 에 존재해야 한다
        $coreIdentifier = config('core.permissions.module.identifier', 'core');
        $this->assertDatabaseHas('permissions', ['identifier' => $coreIdentifier]);
    }

    public function test_reloadCoreConfigAndResync_picks_up_disk_changes(): void
    {
        if (! File::exists($this->coreConfigPath)) {
            $this->markTestSkipped('config/core.php 미존재');
        }

        // 1. 원본 백업
        File::copy($this->coreConfigPath, $this->backupPath);
        $this->backupCreated = true;

        // 2. 디스크의 config/core.php 에 새 권한 1건 추가 (category/permission 둘 다 신규)
        $original = require $this->coreConfigPath;
        $modified = $original;
        $modified['permissions']['categories'][] = [
            'identifier' => 'core.test_reload_disk',
            'name' => ['ko' => '테스트 재로드', 'en' => 'Test Reload'],
            'description' => ['ko' => 'Test reload disk sync', 'en' => 'Test reload disk sync'],
            'order' => 9999,
            'permissions' => [
                [
                    'identifier' => 'core.test_reload_disk.read',
                    'type' => 'admin',
                    'name' => ['ko' => '테스트 재로드 조회', 'en' => 'View Test Reload'],
                    'description' => ['ko' => 'Can view', 'en' => 'Can view'],
                    'order' => 1,
                ],
            ],
        ];
        File::put($this->coreConfigPath, '<?php return '.var_export($modified, true).';');

        // 3. 메모리 config 는 아직 구 값 (Laravel 부팅 시점 기준)
        $memoryNames = collect(config('core.permissions.categories', []))
            ->pluck('identifier')
            ->all();
        $this->assertNotContains(
            'core.test_reload_disk',
            $memoryNames,
            '메모리 config 에는 아직 신규 권한이 없어야 한다 (디스크만 변경됨)'
        );

        // 4. reloadCoreConfigAndResync 호출 → 디스크 재읽기 + sync
        $service = app(CoreUpdateService::class);
        $service->reloadCoreConfigAndResync();

        // 5. DB 에 신규 권한이 반영되어야 한다
        $this->assertDatabaseHas('permissions', [
            'identifier' => 'core.test_reload_disk.read',
        ]);
    }

    public function test_reloadCoreConfigAndResync_skips_gracefully_if_file_missing(): void
    {
        if (! File::exists($this->coreConfigPath)) {
            $this->markTestSkipped('config/core.php 미존재');
        }

        // 원본 백업 후 일시 삭제
        File::copy($this->coreConfigPath, $this->backupPath);
        $this->backupCreated = true;
        File::delete($this->coreConfigPath);

        try {
            $service = app(CoreUpdateService::class);
            $service->reloadCoreConfigAndResync();
            $this->assertTrue(true, 'config 파일 미존재 시 예외 없이 정상 반환해야 한다');
        } finally {
            // 즉시 복원 (tearDown 이전에도)
            File::copy($this->backupPath, $this->coreConfigPath);
        }
    }
}
