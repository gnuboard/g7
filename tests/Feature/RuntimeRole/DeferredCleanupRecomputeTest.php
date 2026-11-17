<?php

namespace Tests\Feature\RuntimeRole;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\DeferredCleanupKind;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\DeclaredExtensionKeys;
use App\Extension\Helpers\DeferredDeclarativeCleanup;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\ModuleManager;
use App\Extension\Support\LifecycleWarnings;
use App\Models\ExtensionDeferredCleanup;
use App\Models\FieldDefinition;
use App\Models\FieldValue;
use App\Models\Menu;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Services\CoreUpdateService;
use App\Services\Extension\ExtensionFileSyncService;
use App\Support\CoreVersionDiskReader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingCarrierSeeder;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Providers\EcommerceServiceProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 운영 서버의 미뤄 둔 정리 실행은 검수 서버가 적어 둔 「남길 목록」이 아니라, 실행하는 순간의 이 서버 파일 선언과 공유 DB 로
 * 지울 대상을 다시 정한다 (#888).
 *
 * 검수 서버에 여러 번 배포하거나, 배포 뒤 운영 서버에서 게시판·주문이 새로 생기면 검수 서버 시점의 목록은 낡는다. 이 서버의
 * 확장 파일 판이 공유 DB 판과 다르면(운영 서버가 아직 새 파일을 받지 않음, 반려된 판) 실행하지 않고 기다린다.
 * 회원 항목 정리·역할 권한 회수도 검수 서버에서는 지우지 않고 미룬다.
 *
 * @scenario-manifest tests/scenarios/stage-extension-update.yaml
 */
class DeferredCleanupRecomputeTest extends TestCase
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
     * 이 서버에 로드된 모듈을 공유 DB 에 설치된 것으로 적습니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string|null  $version  공유 DB 판 (null 이면 이 서버 파일 판과 같게)
     * @return ModuleInterface 로드된 모듈
     */
    private function installedModule(string $identifier, ?string $version = null): ModuleInterface
    {
        $manager = app(ModuleManager::class);
        $manager->loadModules();
        $module = $manager->getModule($identifier);
        $this->assertNotNull($module, "{$identifier} 활성 폴더가 로드되어 있어야 한다");

        Module::query()->where('identifier', $identifier)->delete();
        Module::factory()->create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'version' => $version ?? $module->getVersion(),
            'status' => 'active',
        ]);

        return $module;
    }

    /**
     * ModuleManager 의 비공개 단계를 부릅니다 (파일 무접촉 — 선언 정리 단계만).
     *
     * @param  string  $method  메서드 이름
     * @param  mixed  ...$args  인자
     * @return mixed 반환값
     */
    private function invokeManager(string $method, mixed ...$args): mixed
    {
        $manager = app(ModuleManager::class);

        return (new ReflectionMethod($manager, $method))->invoke($manager, ...$args);
    }

    /**
     * 모듈 소유 권한을 만듭니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @param  string  $identifier  권한 식별자
     * @return Permission 권한
     */
    private function modulePermission(string $moduleIdentifier, string $identifier): Permission
    {
        return Permission::factory()->create([
            'identifier' => $identifier,
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => $moduleIdentifier,
        ]);
    }

    /**
     * 검수 서버 배포 뒤 운영 서버에서 새로 만든 게시판의 권한·메뉴는 운영 서버 정리 실행이 지우지 않는다.
     *
     * 게시판 모듈의 「남길 목록」은 그 순간의 게시판 목록으로 만들어진다 — 검수 서버 시점 목록으로 다시 실행하면 지운다.
     *
     * @scenario case=stage_redeploy
     *
     * @effects live_apply_recomputes_dynamic_keep_list
     *
     * @return void 반환값 없음
     */
    public function test_live_apply_keeps_board_items_created_after_stage_deploy(): void
    {
        $board = $this->installedModule('sirsoft-board');

        $this->role('stage');
        $this->invokeManager('cleanupStaleModuleEntries', $board);
        $this->assertGreaterThan(0, ExtensionDeferredCleanup::query()->where('identifier', 'sirsoft-board')->count(), '검수 서버는 정리를 미룬다');

        // 운영 서버 운영자가 게시판 notice2 를 만든다 — 게시판 생성이 그 게시판의 권한·메뉴를 만든다
        $this->role('live');
        Board::factory()->create(['slug' => 'notice2']);
        $permission = $this->modulePermission('sirsoft-board', 'sirsoft-board.notice2');
        $menu = Menu::factory()->create(['extension_type' => ExtensionOwnerType::Module, 'extension_identifier' => 'sirsoft-board', 'slug' => 'board-notice2']);

        $this->assertSame(0, Artisan::call('extension:apply-deferred-cleanup'));

        $this->assertNotNull(Permission::query()->find($permission->id), '실행 순간의 게시판 목록으로 남길 권한을 다시 정한다');
        $this->assertNotNull(Menu::query()->find($menu->id), '실행 순간의 게시판 목록으로 남길 메뉴를 다시 정한다');
        $this->assertSame(0, ExtensionDeferredCleanup::query()->where('identifier', 'sirsoft-board')->count());
    }

    /**
     * 검수 서버 1차 배포에서 뺐다가 2차 배포에서 되살린 메뉴는 운영 서버 실행이 지우지 않는다.
     *
     * @scenario case=stage_redeploy
     *
     * @effects stage_redeploy_restored_item_survives_live_apply
     *
     * @return void 반환값 없음
     */
    public function test_stage_redeploy_restored_menu_survives_live_apply(): void
    {
        $board = $this->installedModule('sirsoft-board');
        $declared = DeclaredExtensionKeys::menus($board) ?? [];
        $this->assertNotEmpty($declared);
        $restoredSlug = $declared[0];
        $restored = Menu::factory()->create(['extension_type' => ExtensionOwnerType::Module, 'extension_identifier' => 'sirsoft-board', 'slug' => $restoredSlug]);

        $this->role('stage');
        // 1차 배포: restored 를 선언에서 뺐다 / 2차 배포: 되살렸다
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Module, 'sirsoft-board', array_values(array_diff($declared, [$restoredSlug])));
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Module, 'sirsoft-board', $declared);

        $this->role('live');
        $this->assertSame(0, Artisan::call('extension:apply-deferred-cleanup'));

        $this->assertNotNull(Menu::query()->where('slug', $restoredSlug)->first(), '이 서버 선언에 있는 메뉴는 지우지 않는다');
        $this->assertNotNull(Menu::query()->find($restored->id), '되살린 메뉴 행 자체가 남는다(운영자 수정 보존)');
    }

    /**
     * 검수 서버 2차 배포가 새로 추가한 메뉴는 1차 배포가 적은 정리가 지우지 않는다.
     *
     * @scenario case=stage_redeploy
     *
     * @effects stage_redeploy_added_item_survives_live_apply
     *
     * @return void 반환값 없음
     */
    public function test_stage_redeploy_added_menu_survives_live_apply(): void
    {
        $board = $this->installedModule('sirsoft-board');
        $declared = DeclaredExtensionKeys::menus($board) ?? [];
        $this->assertNotEmpty($declared);
        $addedSlug = $declared[0];

        $this->role('stage');
        // 1차 배포: added 가 없던 판
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Module, 'sirsoft-board', array_values(array_diff($declared, [$addedSlug])));
        // 2차 배포: added 를 추가(동기화가 공유 DB 에 곧바로 만든다)
        $added = Menu::factory()->create(['extension_type' => ExtensionOwnerType::Module, 'extension_identifier' => 'sirsoft-board', 'slug' => $addedSlug]);
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Module, 'sirsoft-board', $declared);

        $this->role('live');
        $this->assertSame(0, Artisan::call('extension:apply-deferred-cleanup'));

        $this->assertNotNull(Menu::query()->find($added->id), '마지막 검수 배포가 추가한 메뉴는 지우지 않는다');
    }

    /**
     * 이 서버의 확장 파일 판이 공유 DB 판과 다르면 실행하지 않고 기다린다 — 운영 서버가 아직 새 파일을 받지 않았거나 반려된 판.
     *
     * @scenario case=stage_redeploy
     *
     * @effects live_apply_waits_until_server_files_match_shared_version
     *
     * @return void 반환값 없음
     */
    public function test_live_apply_waits_when_server_files_differ_from_shared_version(): void
    {
        $board = $this->installedModule('sirsoft-board', '0.0.1');
        $stale = $this->modulePermission('sirsoft-board', 'sirsoft-board.zz_gone_by_new_version');

        $this->role('stage');
        $this->invokeManager('cleanupStaleModuleEntries', $board);
        $rows = ExtensionDeferredCleanup::query()->where('identifier', 'sirsoft-board')->count();
        $this->assertGreaterThan(0, $rows);

        $this->role('live');
        $this->assertSame(1, Artisan::call('extension:apply-deferred-cleanup'), '기다리는 정리가 있으면 종료 1');

        $this->assertNotNull(Permission::query()->find($stale->id), '파일 판이 다르면 지우지 않는다');
        $this->assertSame($rows, ExtensionDeferredCleanup::query()->where('identifier', 'sirsoft-board')->count(), '대장 행은 남는다');
    }

    /**
     * 검수 서버 시점에 주문에 쓰이지 않던 기본 택배사를, 그 뒤 운영 서버 주문이 쓰면 운영 서버 실행은 지우지 않는다.
     *
     * @scenario case=stage_redeploy
     *
     * @effects live_apply_rechecks_seed_usage
     *
     * @return void 반환값 없음
     */
    public function test_live_apply_keeps_seed_row_used_after_stage_deploy(): void
    {
        $this->installedModule('sirsoft-ecommerce');
        // 시험 DB 부팅 때는 이커머스가 설치돼 있지 않아 바인딩이 없다 — 시더가 쓰는 서비스를 위해 등록
        $this->app->register(EcommerceServiceProvider::class);
        $legacy = ShippingCarrier::query()->create([
            'code' => 'zz-legacy-carrier',
            'name' => ['ko' => '옛 택배사', 'en' => 'Legacy carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $unused = ShippingCarrier::query()->create([
            'code' => 'zz-unused-carrier',
            'name' => ['ko' => '안 쓰는 옛 택배사', 'en' => 'Unused legacy carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 98,
        ]);

        $this->role('stage');
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => ShippingCarrierSeeder::class, '--force' => true]));
        $this->assertNotNull(ShippingCarrier::query()->find($legacy->id), '검수 서버는 지우지 않는다');
        $this->assertTrue(
            ExtensionDeferredCleanup::query()->where('scope', 'module')->where('identifier', 'sirsoft-ecommerce')
                ->where('kind', DeferredCleanupKind::Seeder->value)->where('target', ShippingCarrierSeeder::class)->exists(),
            '시드 정리는 부른 시더를 적는다 — 운영 서버가 그 시더로 사용 중 판정을 다시 한다',
        );

        // 운영 서버에서 그 택배사로 배송이 접수된다
        $this->role('live');
        OrderShipping::factory()->create(['carrier_id' => $legacy->id]);

        $this->assertSame(0, Artisan::call('extension:apply-deferred-cleanup'));

        $this->assertNotNull(ShippingCarrier::query()->find($legacy->id), '실행 순간에 쓰이는 택배사는 지우지 않는다');
        $this->assertNull(ShippingCarrier::query()->find($unused->id), '실행 순간에도 쓰이지 않는 옛 시드 택배사는 지운다');
    }

    /**
     * 검수 서버에서 선언에서 빠진 회원 항목은 항목도, 회원이 입력한 값도 지우지 않고 미룬다.
     *
     * @scenario case=deferred_cleanup
     *
     * @effects stage_defers_field_definition_removal
     *
     * @return void 반환값 없음
     */
    public function test_stage_field_sync_defers_removal_of_definition_and_values(): void
    {
        $definition = FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'acme_gone', 'type' => 'text', 'source_type' => 'plugin',
            'source_identifier' => 'acme-defer', 'storage' => 'value', 'name' => ['ko' => '빠질 항목'],
        ]);
        $value = FieldValue::create([
            'field_definition_id' => $definition->id, 'owner_type' => 'user', 'owner_id' => 1, 'value_text' => '회원 입력값',
        ]);

        $this->role('stage');
        app(FieldDefinitionSyncHelper::class)->sync('plugin', 'acme-defer', [[
            'field_set' => 'nowhere.set', 'key' => 'lost', 'type' => 'text', 'name' => ['ko' => '건너뛸 선언'],
        ]]);

        $this->assertNotNull(FieldDefinition::query()->find($definition->id), '검수 서버는 회원 항목을 지우지 않는다');
        $this->assertNotNull(FieldValue::query()->find($value->id), '검수 서버는 회원 입력값을 지우지 않는다');
        $this->assertSame(1, ExtensionDeferredCleanup::query()->where('scope', 'plugin')->where('identifier', 'acme-defer')->count(), '운영 서버 실행으로 미룬다');
    }

    /**
     * 검수 서버에서 확장 선언이 역할에서 뺀 권한은 회수하지 않고 미룬다 — 운영 서버 옛 화면의 그 역할 사용자가 기능을 잃지 않게.
     *
     * @scenario case=deferred_cleanup
     *
     * @effects stage_defers_role_permission_revoke
     *
     * @return void 반환값 없음
     */
    public function test_stage_role_assignment_sync_defers_revoke(): void
    {
        $role = Role::factory()->create(['identifier' => 'acme-defer-role', 'is_active' => true]);
        $kept = Permission::factory()->create(['identifier' => 'acme-defer.kept', 'extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => 'acme-defer']);
        $revoked = Permission::factory()->create(['identifier' => 'acme-defer.revoked', 'extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => 'acme-defer']);
        $role->permissions()->attach([$kept->id, $revoked->id]);

        $this->role('stage');
        app(ExtensionRoleSyncHelper::class)->syncAllRoleAssignments(
            ['acme-defer.kept' => ['acme-defer-role']],
            ['acme-defer.kept', 'acme-defer.revoked'],
        );

        $this->assertTrue($role->permissions()->whereKey($revoked->id)->exists(), '검수 서버는 역할 권한을 회수하지 않는다');
        $this->assertSame(1, ExtensionDeferredCleanup::query()->where('scope', 'plugin')->where('identifier', 'acme-defer')->count(), '운영 서버 실행으로 미룬다');
    }

    /**
     * 코어 재동기화를 대역으로 바꿉니다.
     *
     * @param  \Closure(): void  $body  재동기화 대신 실행할 본문
     * @return void 반환값 없음
     */
    private function fakeCoreResync(\Closure $body): void
    {
        $this->app->instance(CoreUpdateService::class, new class($body) extends CoreUpdateService
        {
            /**
             * 대역 본문을 받습니다.
             *
             * @param  \Closure(): void  $body  본문
             */
            public function __construct(private \Closure $body) {}

            /**
             * 코어 선언 재동기화 대신 대역 본문을 실행합니다.
             *
             * @return void 반환값 없음
             */
            public function reloadCoreConfigAndResync(): void
            {
                ($this->body)();
            }
        });
    }

    /**
     * 검수 서버 코어 업데이트(부모 프로세스의 판은 아직 옛 판)에서 적힌 코어 정리는 디스크 코드 판을 기록하고,
     * 운영 서버가 아직 옛 코어면 기다린다 — 옛 코어 선언으로 다시 계산해 새 코어 항목을 지우지 않게.
     *
     * @scenario case=stage_redeploy
     *
     * @effects core_cleanup_waits_until_live_core_is_new
     *
     * @return void 반환값 없음
     */
    public function test_core_cleanup_recorded_during_stage_core_update_waits_for_new_live_core(): void
    {
        $shipped = (string) CoreVersionDiskReader::shippedVersion();
        $this->assertNotSame('', $shipped);

        $this->role('stage');
        config(['app.version' => '0.0.1']);
        DeferredDeclarativeCleanup::deferOwner('core', 'core');
        $row = ExtensionDeferredCleanup::query()->where('scope', 'core')->firstOrFail();
        $this->assertSame($shipped, $row->recorded_version, '부모 프로세스 판이 아니라 디스크의 새 코드 판을 적는다');

        $ran = false;
        $this->fakeCoreResync(function () use (&$ran): void {
            $ran = true;
        });
        $this->role('live');
        config(['app.version' => '0.0.1']);
        $this->assertSame(1, Artisan::call('extension:apply-deferred-cleanup'), '옛 코어 운영 서버는 기다린다');
        $this->assertFalse($ran, '옛 코어 선언으로 재동기화하지 않는다');
        $this->assertNotNull(ExtensionDeferredCleanup::query()->find($row->id));
    }

    /**
     * 코어 재동기화가 경고(단계 실패)를 남기면 실패로 보고 행을 남긴다.
     *
     * @scenario case=deferred_cleanup
     *
     * @effects core_resync_failure_keeps_row
     *
     * @return void 반환값 없음
     */
    public function test_core_resync_warning_keeps_row(): void
    {
        $this->role('stage');
        DeferredDeclarativeCleanup::deferOwner('core', 'core');
        $row = ExtensionDeferredCleanup::query()->where('scope', 'core')->firstOrFail();

        $this->fakeCoreResync(function (): void {
            LifecycleWarnings::current()->add('core_resync', 'permissions', 'probe failure');
        });
        $this->role('live');
        $this->assertSame(1, Artisan::call('extension:apply-deferred-cleanup'));
        $this->assertNotNull(ExtensionDeferredCleanup::query()->find($row->id), '재동기화가 실패하면 행을 지우지 않는다');
    }

    /**
     * 운영 서버 실행 도중 검수 서버가 같은 정리를 다시 적으면, 그 새 기록은 지우지 않는다.
     *
     * @scenario case=deferred_cleanup
     *
     * @effects rerecorded_row_during_apply_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_row_rerecorded_during_apply_is_kept(): void
    {
        $this->role('stage');
        DeferredDeclarativeCleanup::deferOwner('core', 'core');
        $row = ExtensionDeferredCleanup::query()->where('scope', 'core')->firstOrFail();
        DB::table('extension_deferred_cleanups')->where('id', $row->id)->update(['updated_at' => now()->subMinute()]);

        $this->fakeCoreResync(function () use ($row): void {
            // 실행 도중 검수 서버의 재배포가 같은 정리를 다시 적는다
            DB::table('extension_deferred_cleanups')->where('id', $row->id)->update(['updated_at' => now()->addMinute()]);
        });
        $this->role('live');
        Artisan::call('extension:apply-deferred-cleanup');
        $this->assertNotNull(ExtensionDeferredCleanup::query()->find($row->id), '실행 뒤 다시 적힌 정리는 다음 실행으로 남긴다');
    }

    /**
     * 같은 확장의 업데이트·설치가 진행 중(작업 잠금)이면 그 확장 정리를 실행하지 않고 행을 남긴다.
     *
     * @scenario case=deferred_cleanup
     *
     * @effects locked_extension_is_not_applied
     *
     * @return void 반환값 없음
     */
    public function test_extension_under_operation_lock_is_not_applied(): void
    {
        $board = $this->installedModule('sirsoft-board');
        $stale = $this->modulePermission('sirsoft-board', 'sirsoft-board.zz_locked_stale');
        $this->role('stage');
        $this->invokeManager('cleanupStaleModuleEntries', $board);

        $this->role('live');
        // 다른 프로세스(같은 확장 업데이트)가 잡은 잠금 — 같은 프로세스 안의 잠금은 재진입이라 캐시 잠금을 직접 잡는다
        $foreign = Cache::lock(ExtensionOperationLock::key('module', 'sirsoft-board'), 60);
        $this->assertTrue($foreign->get());
        try {
            $this->assertSame(1, Artisan::call('extension:apply-deferred-cleanup'));
        } finally {
            $foreign->release();
        }
        $this->assertNotNull(Permission::query()->find($stale->id), '작업 중인 확장은 정리하지 않는다');
        $this->assertGreaterThan(0, ExtensionDeferredCleanup::query()->where('identifier', 'sirsoft-board')->count());
    }

    /**
     * 알 수 없는 종류 값의 행이 있어도 명령 전체가 죽지 않고 그 행만 실패로 남긴다.
     *
     * @scenario case=deferred_cleanup
     *
     * @effects unknown_kind_row_fails_alone
     *
     * @return void 반환값 없음
     */
    public function test_unknown_kind_row_does_not_abort_command(): void
    {
        $id = DB::table('extension_deferred_cleanups')->insertGetId([
            'scope' => 'module', 'identifier' => 'acme-bogus', 'kind' => 'bogus', 'target' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->role('live');
        $exit = Artisan::call('extension:apply-deferred-cleanup', ['--json' => true]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exit);
        $items = array_column($result['items'] ?? [], null, 'id');
        $this->assertSame('failed', $items[$id]['status'] ?? null, '그 행만 실패로 남긴다');
        $this->assertNotNull(DB::table('extension_deferred_cleanups')->find($id));
    }

    /**
     * 검수 서버는 정리를 적을 때 그 확장 활성 폴더의 내용 지문을 함께 적고, 운영 서버는 판 번호가 같아도 파일 지문이 다르면
     * 기다린다 — 판 번호를 올리지 않은 수정을 옛 파일의 운영 서버가 옛 선언으로 계산해 새 항목을 지우지 않게.
     *
     * @scenario case=stage_redeploy
     *
     * @effects live_apply_waits_when_files_differ_with_same_version
     *
     * @return void 반환값 없음
     */
    public function test_live_apply_waits_when_extension_files_differ_with_same_version(): void
    {
        $board = $this->installedModule('sirsoft-board');
        $stale = $this->modulePermission('sirsoft-board', 'sirsoft-board.zz_fp_stale');

        $this->role('stage');
        $this->invokeManager('cleanupStaleModuleEntries', $board);
        $sync = app(ExtensionFileSyncService::class);
        $expected = $sync->contentHash($sync->activePath('module', 'sirsoft-board'));
        $row = ExtensionDeferredCleanup::query()->where('identifier', 'sirsoft-board')->firstOrFail();
        $this->assertSame($expected, $row->code_fingerprint, '검수 서버 활성 폴더 지문을 적는다');

        // 같은 판이지만 검수 서버가 다른 파일(판 번호를 올리지 않은 수정)로 적었다
        DB::table('extension_deferred_cleanups')->where('id', $row->id)->update(['code_fingerprint' => str_repeat('a', 64)]);

        $this->role('live');
        $this->assertSame(1, Artisan::call('extension:apply-deferred-cleanup'), '파일이 다르면 기다린다');
        $this->assertNotNull(Permission::query()->find($stale->id));
        $this->assertNotNull(ExtensionDeferredCleanup::query()->find($row->id));
    }

    /**
     * 시드 정리 행은 설치 때만 적힌다(업데이트는 시더를 돌리지 않음) — 그 뒤 검수 서버가 같은 확장을 다시 배포하면 지문이
     * 그 확장의 마지막 검수 빌드를 따라야 한다. 설치 때 지문에 머물면 마지막 빌드를 받은 운영 서버에서 영영 기다린다.
     *
     * @scenario case=stage_redeploy
     *
     * @effects seeder_row_follows_last_stage_build
     *
     * @return void 반환값 없음
     */
    public function test_seeder_row_follows_last_stage_build_after_redeploy(): void
    {
        $this->installedModule('sirsoft-ecommerce');
        $this->app->register(EcommerceServiceProvider::class);
        $unused = ShippingCarrier::query()->create([
            'code' => 'zz-unused-carrier-fp',
            'name' => ['ko' => '안 쓰는 옛 택배사', 'en' => 'Unused legacy carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 97,
        ]);

        // 검수 서버 설치 — 시더가 시드 정리를 미루며 그때 빌드 지문을 적는다
        $this->role('stage');
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => ShippingCarrierSeeder::class, '--force' => true]));
        $seederRow = ExtensionDeferredCleanup::query()->where('identifier', 'sirsoft-ecommerce')
            ->where('kind', DeferredCleanupKind::Seeder->value)->firstOrFail();
        DB::table('extension_deferred_cleanups')->where('id', $seederRow->id)->update(['code_fingerprint' => str_repeat('c', 64)]);

        // 같은 확장을 고친 빌드로 다시 배포(업데이트 — 시더는 돌지 않고 선언 정리만 다시 적힌다)
        DeferredDeclarativeCleanup::deferOwner('module', 'sirsoft-ecommerce');
        $sync = app(ExtensionFileSyncService::class);
        $lastBuild = $sync->contentHash($sync->activePath('module', 'sirsoft-ecommerce'));
        $this->assertSame($lastBuild, ExtensionDeferredCleanup::query()->find($seederRow->id)->code_fingerprint,
            '시드 정리 행도 그 확장의 마지막 검수 빌드 지문을 따른다');

        // 이 시험의 대상은 시드 행이다 — 이커머스 전체 선언 재동기화 행은 빼고 운영 서버에서 실행한다
        ExtensionDeferredCleanup::query()->where('identifier', 'sirsoft-ecommerce')
            ->where('kind', DeferredCleanupKind::Declarations->value)->delete();
        $this->role('live');
        $this->assertSame(0, Artisan::call('extension:apply-deferred-cleanup'), '마지막 빌드를 받은 운영 서버는 기다리지 않는다');
        $this->assertNull(ShippingCarrier::query()->find($unused->id));
        $this->assertNull(ExtensionDeferredCleanup::query()->find($seederRow->id));
    }

    /**
     * 코어 정리도 코어 선언 파일(config/core.php) 지문을 적고, 운영 서버 파일 지문이 다르면 기다린다.
     *
     * @scenario case=stage_redeploy
     *
     * @effects core_cleanup_waits_when_declaration_file_differs
     *
     * @return void 반환값 없음
     */
    public function test_core_cleanup_waits_when_core_declaration_file_differs(): void
    {
        $this->role('stage');
        DeferredDeclarativeCleanup::deferOwner('core', 'core');
        $row = ExtensionDeferredCleanup::query()->where('scope', 'core')->firstOrFail();
        $this->assertSame(hash_file('sha256', config_path('core.php')), $row->code_fingerprint);

        DB::table('extension_deferred_cleanups')->where('id', $row->id)->update(['code_fingerprint' => str_repeat('b', 64)]);
        $ran = false;
        $this->fakeCoreResync(function () use (&$ran): void {
            $ran = true;
        });

        $this->role('live');
        $this->assertSame(1, Artisan::call('extension:apply-deferred-cleanup'));
        $this->assertFalse($ran, '코어 선언 파일이 다르면 재동기화하지 않는다');
    }

    /**
     * 판 번호와 무관하게 코어 선언만 다시 맞추는 명령 — 판 번호를 올리지 않은 config/core.php 수정을 DB 에 반영한다.
     * 검수 서버에서도 돌며(추가·갱신), 삭제는 장부로 미뤄진다(재동기화 안의 정리 헬퍼가 판정).
     *
     * @scenario case=deferred_cleanup
     *
     * @effects core_resync_declarations_command_runs_resync
     *
     * @return void 반환값 없음
     */
    public function test_core_resync_declarations_command_runs_resync_on_both_roles(): void
    {
        foreach (['stage', 'live'] as $role) {
            $ran = false;
            $this->fakeCoreResync(function () use (&$ran): void {
                $ran = true;
            });
            $this->role($role);

            $this->assertSame(0, Artisan::call('core:resync-declarations'), "{$role} 에서 실행된다");
            $this->assertTrue($ran, "{$role} 에서 코어 선언을 다시 맞춘다");
        }
    }
}
