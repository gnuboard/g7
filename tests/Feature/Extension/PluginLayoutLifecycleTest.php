<?php

namespace Tests\Feature\Extension;

use App\Enums\LayoutSourceType;
use App\Extension\PluginManager;
use App\Extension\UpgradeContext;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Upgrades\Data\V7_1_0\Migrations\PurgeOrphanPluginLayouts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 플러그인 레이아웃의 생명주기 (#712 W7 · F7).
 *
 * 플러그인 비활성화·재활성화·삭제가 레이아웃 정리에 모듈용 저장소 메서드(`source_type = module` 조건)를 써서, 플러그인
 * 레이아웃은 비활성화 뒤에도 살아 있고 삭제 뒤에도 행이 남았다(7.0.11 부터의 결함 — 실측: 삭제된 hello 플러그인의
 * `plugin_settings` 행 잔존). 같은 식별자의 모듈 레이아웃은 건드리지 않아야 한다.
 */
class PluginLayoutLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private const PLUGIN = 'probevendor-lifecycle_plugin';

    private PluginManager $manager;

    private Template $template;

    /**
     * 플러그인 레이아웃 1행과 같은 식별자의 모듈 레이아웃 1행(대조군)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(PluginManager::class);
        $this->template = Template::factory()->create(['identifier' => 'probe-lifecycle-'.uniqid(), 'type' => 'admin']);

        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => self::PLUGIN.'.plugin_settings',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => self::PLUGIN,
        ]);
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => self::PLUGIN.'.module_twin',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => self::PLUGIN,
        ]);
    }

    /**
     * 비활성화는 플러그인 레이아웃을 soft delete 하고, 재활성화는 되살린다 — 같은 식별자의 모듈 행은 그대로.
     *
     * @scenario case=plugin_deactivate_restore
     *
     * @effects plugin_layouts_soft_deleted_on_deactivate_and_restored_on_reactivate
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_soft_deletes_and_reactivate_restores(): void
    {
        $this->assertSame(1, $this->invokeManager('softDeletePluginLayouts'));
        $this->assertSame(['live' => 0, 'trashed' => 1], $this->counts(LayoutSourceType::Plugin));
        $this->assertSame(['live' => 1, 'trashed' => 0], $this->counts(LayoutSourceType::Module), '같은 식별자의 모듈 레이아웃은 건드리지 않는다');

        $this->invokeManager('restorePluginLayouts');
        $this->assertSame(['live' => 1, 'trashed' => 0], $this->counts(LayoutSourceType::Plugin));
    }

    /**
     * 삭제는 플러그인 레이아웃을 영구 삭제하고(soft delete 포함), 개수는 실제 행 수와 같다.
     *
     * @scenario case=plugin_uninstall_count
     *
     * @effects plugin_layouts_force_deleted_on_uninstall_and_counted_correctly
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_force_deletes_and_count_matches(): void
    {
        $this->assertSame(1, $this->manager->getPluginLayoutsCount(self::PLUGIN), '삭제 안내의 레이아웃 수는 실제 행 수와 같아야 한다');

        $this->invokeManager('softDeletePluginLayouts');
        $this->assertSame(1, $this->manager->getPluginLayoutsCount(self::PLUGIN), 'soft delete 된 행도 센다');

        $this->assertSame(1, $this->invokeManager('deletePluginLayouts'));
        $this->assertSame(0, TemplateLayout::withTrashed()->where('source_type', LayoutSourceType::Plugin)->where('source_identifier', self::PLUGIN)->count());
        $this->assertSame(['live' => 1, 'trashed' => 0], $this->counts(LayoutSourceType::Module));
    }

    /**
     * 7.1.0 데이터 마이그레이션은 설치 기록이 없는 플러그인의 레이아웃 행만 지운다 — 설치된 플러그인과 같은 식별자의 모듈 행은 그대로.
     *
     * @scenario case=upgrade_purge_orphan_plugin_layouts
     *
     * @effects orphan_plugin_layouts_purged_by_upgrade_step_only_for_uninstalled_plugins
     *
     * @return void 반환값 없음
     */
    public function test_upgrade_step_purges_layouts_of_uninstalled_plugins_only(): void
    {
        require_once base_path('upgrades/data/7.1.0/migrations/06_PurgeOrphanPluginLayouts.php');

        $installed = DB::table('plugins')->value('identifier');
        $kept = null;

        if (is_string($installed)) {
            $kept = TemplateLayout::factory()->create([
                'template_id' => $this->template->id,
                'name' => $installed.'.probe_settings',
                'source_type' => LayoutSourceType::Plugin,
                'source_identifier' => $installed,
            ]);
        }

        // 고아 행에 판본 이력도 하나 둔다 — cascade 에 기대지 않고 함께 지워져야 한다.
        $orphan = TemplateLayout::query()->where('source_type', LayoutSourceType::Plugin)->where('source_identifier', self::PLUGIN)->firstOrFail();
        DB::table('template_layout_versions')->insert(['layout_id' => $orphan->id, 'version' => 1, 'content' => '{}', 'created_at' => now()]);

        $migration = new PurgeOrphanPluginLayouts;
        $migration->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));

        $this->assertSame(0, TemplateLayout::withTrashed()->where('source_type', LayoutSourceType::Plugin)->where('source_identifier', self::PLUGIN)->count());
        $this->assertSame(0, DB::table('template_layout_versions')->where('layout_id', $orphan->id)->count());
        $this->assertSame(['live' => 1, 'trashed' => 0], $this->counts(LayoutSourceType::Module), '같은 식별자의 모듈 레이아웃은 대상이 아니다');

        if ($kept !== null) {
            $this->assertTrue(TemplateLayout::query()->whereKey($kept->id)->exists(), '설치된 플러그인의 레이아웃은 지우지 않는다');
        }

        // 두 번째 실행은 아무것도 바꾸지 않는다.
        $before = TemplateLayout::withTrashed()->count();
        $migration->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
        $this->assertSame($before, TemplateLayout::withTrashed()->count());
    }

    /**
     * PluginManager 의 보호 메서드를 부릅니다.
     *
     * @param  string  $method  메서드 이름
     * @return mixed 반환값
     */
    private function invokeManager(string $method): mixed
    {
        $reflection = new \ReflectionMethod($this->manager, $method);

        return $reflection->invoke($this->manager, self::PLUGIN);
    }

    /**
     * 출처 타입별 live·trashed 행 수를 셉니다.
     *
     * @param  LayoutSourceType  $type  출처 타입
     * @return array{live: int, trashed: int} 행 수
     */
    private function counts(LayoutSourceType $type): array
    {
        $query = fn () => TemplateLayout::withTrashed()->where('template_id', $this->template->id)->where('source_type', $type)->where('source_identifier', self::PLUGIN);

        return ['live' => $query()->whereNull('deleted_at')->count(), 'trashed' => $query()->whereNotNull('deleted_at')->count()];
    }
}
