<?php

namespace Tests\Feature\RuntimeRole;

use App\Enums\DeferredCleanupKind;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\GenericEntitySyncHelper;
use App\Extension\ModuleManager;
use App\Models\ExtensionDeferredCleanup;
use App\Models\Menu;
use App\Models\Module;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 미뤄 둔 선언 정리 대장 — DB 에서 읽은 대상은 정해 둔 것만 실행하고, 소유는 정해 둔 인자 자리에서 읽는다.
 *
 * @scenario-manifest tests/scenarios/deferred-cleanup-allowlist.yaml
 */
class DeferredCleanupAllowlistTest extends TestCase
{
    use DatabaseTransactions;

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
     * 확장이 선언하지 않은 시더·알 수 없는 범위의 행 — 실행하지 않고 실패로 남긴다.
     *
     * 대장의 시더 클래스는 DB 에서 읽는 값이다. 변조된 행이 코어 전체 시더 같은 임의 시더를 돌리지 못해야 한다.
     *
     * @scenario case=unlisted_target
     *
     * @effects undeclared_seeder_is_not_run, unlisted_target_row_fails_and_stays
     *
     * @return void 반환값 없음
     */
    public function test_unlisted_target_row_is_not_run(): void
    {
        $manager = app(ModuleManager::class);
        $manager->loadModules();
        $board = $manager->getModule('sirsoft-board');
        $this->assertNotNull($board, 'sirsoft-board 활성 폴더가 로드되어 있어야 한다');
        Module::query()->where('identifier', 'sirsoft-board')->delete();
        Module::factory()->create(['identifier' => 'sirsoft-board', 'vendor' => 'sirsoft', 'version' => $board->getVersion(), 'status' => 'active']);

        $rows = [
            // 게시판이 선언하지 않은 시더 — 코어 전체 시더
            ExtensionDeferredCleanup::query()->create(['scope' => 'module', 'identifier' => 'sirsoft-board', 'kind' => DeferredCleanupKind::Seeder, 'target' => DatabaseSeeder::class]),
            // 없는 클래스
            ExtensionDeferredCleanup::query()->create(['scope' => 'module', 'identifier' => 'sirsoft-board', 'kind' => DeferredCleanupKind::Seeder, 'target' => 'Acme\\Probe\\WipeSeeder']),
            // 알 수 없는 범위
            ExtensionDeferredCleanup::query()->create(['scope' => 'template', 'identifier' => 'acme-probe', 'kind' => DeferredCleanupKind::Declarations, 'target' => '']),
        ];

        $this->role('live');
        $exit = Artisan::call('extension:apply-deferred-cleanup', ['--json' => true]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exit, '실행할 수 없는 행이 있으면 실패로 끝난다');
        $items = array_column($result['items'], null, 'id');
        foreach ($rows as $row) {
            $this->assertSame('failed', $items[$row->id]['status']);
            $this->assertStringContainsString(
                (string) __('exceptions.deferred_cleanup_target_not_allowed', ['target' => '']),
                $items[$row->id]['error'],
            );
            $this->assertNotNull(ExtensionDeferredCleanup::query()->find($row->id), '실행하지 않은 행은 대장에 남는다');
        }
    }

    /**
     * stage 의 대장 기록 — 소유는 메서드마다 정해 둔 인자에서 읽고, 읽을 수 없으면 모듈로 짐작하지 않고 core 로 둔다.
     * 같은 소유의 정리는 여러 번 불려도 한 줄이다.
     *
     * @scenario case=owner_resolution
     *
     * @effects owner_is_read_from_declared_arguments
     *
     * @return void 반환값 없음
     */
    public function test_owner_is_read_from_declared_arguments(): void
    {
        $this->role('stage');

        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Plugin, 'acme-allow', []);
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Plugin, 'acme-allow', ['acme-allow-kept']);
        // 설치되지 않은 식별자 — 식별자 모양이어도 모듈로 짐작하지 않는다
        app(GenericEntitySyncHelper::class)->cleanupStale(Menu::class, ['created_by' => null], 'slug', [], ownerIdentifier: 'acme-unknown');
        // 범위 조건에 종류·식별자가 있으면 그 값
        app(GenericEntitySyncHelper::class)->cleanupStale(Menu::class, ['extension_type' => ExtensionOwnerType::Module, 'extension_identifier' => 'acme-scoped'], 'slug', []);

        $menuRows = ExtensionDeferredCleanup::query()->where('scope', 'plugin')->where('identifier', 'acme-allow')->get();
        $this->assertCount(1, $menuRows, '같은 소유의 정리는 한 줄');
        $this->assertSame(DeferredCleanupKind::Declarations, $menuRows[0]->kind);

        $this->assertTrue(ExtensionDeferredCleanup::query()->where('scope', 'core')->where('identifier', '')->exists(), '읽을 수 없는 소유는 core');
        $this->assertFalse(ExtensionDeferredCleanup::query()->where('identifier', 'acme-unknown')->exists(), '식별자 모양으로 모듈을 짐작하지 않는다');
        $this->assertTrue(ExtensionDeferredCleanup::query()->where('scope', 'module')->where('identifier', 'acme-scoped')->exists());
    }
}
