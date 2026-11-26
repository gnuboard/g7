<?php

namespace Tests\Feature\RuntimeRole;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Models\ExtensionDeferredCleanup;
use App\Models\Menu;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 검수 서버(stage)의 확장 업데이트 — 공유 상태에 `updating` 을 쓰지 않고, 선언형 정리는 대장에 미뤄 운영 서버가 실행한다.
 *
 * @scenario-manifest tests/scenarios/stage-extension-update.yaml
 */
class StageExtensionUpdateTest extends TestCase
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
     * 시험 확장 소유 메뉴를 만듭니다.
     *
     * @return Menu 메뉴
     */
    private function staleMenu(): Menu
    {
        return Menu::factory()->create(['extension_type' => ExtensionOwnerType::Plugin, 'extension_identifier' => 'acme-defer', 'slug' => 'acme-defer-stale']);
    }

    /**
     * stage 의 선언형 정리는 지우지 않고 대장에 한 번만 적는다. live 의 실행 명령이 같은 규칙대로 지우고 대장 행을 지운다.
     *
     * @scenario case=deferred_cleanup
     *
     * @effects stage_cleanup_is_deferred_to_ledger, live_applies_deferred_cleanup
     *
     * @return void 반환값 없음
     */
    public function test_stage_defers_cleanup_and_live_applies_it(): void
    {
        $menu = $this->staleMenu();

        $this->role('stage');
        $this->assertSame(0, app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Plugin, 'acme-defer', []));
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Plugin, 'acme-defer', []);
        $this->assertNotNull(Menu::query()->find($menu->id), 'stage 는 지우지 않는다');
        $this->assertSame(1, ExtensionDeferredCleanup::query()->where('identifier', 'acme-defer')->count(), '같은 정리는 한 번만 적는다');

        $this->assertSame(1, Artisan::call('extension:apply-deferred-cleanup'), 'stage 에서는 실행하지 않는다');

        $this->role('live');
        $this->assertSame(0, Artisan::call('extension:apply-deferred-cleanup', ['--dry-run' => true]));
        $this->assertNotNull(Menu::query()->find($menu->id), 'dry-run 은 무변경');

        $this->assertSame(0, Artisan::call('extension:apply-deferred-cleanup'));
        $this->assertNull(Menu::query()->find($menu->id), 'live 실행이 지운다');
        $this->assertSame(0, ExtensionDeferredCleanup::query()->where('identifier', 'acme-defer')->count());
    }

    /**
     * 운영 서버의 실행은 지우기 전에 무엇을 몇 건 정리하는지 먼저 알린다 — 「정리」라는 이름이 메뉴·권한 등을 지운다는 것을 가렸다.
     * `--json` 출력에는 섞지 않는다(스크립트가 파싱한다).
     *
     * @scenario case=deferred_cleanup
     *
     * @effects apply_deferred_cleanup_announces_items_before_deleting
     *
     * @return void 반환값 없음
     */
    public function test_apply_announces_items_before_deleting(): void
    {
        $this->staleMenu();
        $this->role('stage');
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Plugin, 'acme-defer', []);
        $count = ExtensionDeferredCleanup::query()->count();

        $this->role('live');
        $this->assertSame(0, Artisan::call('extension:apply-deferred-cleanup'));
        $output = Artisan::output();

        $notice = __('extensions.commands.apply_deferred_cleanup.about_to_apply', ['count' => $count]);
        $this->assertNotFalse(strpos($output, $notice), '지우기 전에 정리할 건수를 알린다');
        $this->assertNotFalse(strpos($output, 'acme-defer'), '정리할 확장을 목록으로 보인다');
        $summary = __('extensions.commands.apply_deferred_cleanup.summary', ['applied' => $count, 'waiting' => 0, 'failed' => 0]);
        $this->assertLessThan(strpos($output, $summary), strpos($output, $notice), '안내는 결과 요약보다 먼저 나온다');
    }

    /**
     * live 의 정리는 종전대로 곧바로 지운다(회귀).
     *
     * @scenario case=live_regression
     *
     * @effects live_applies_deferred_cleanup
     *
     * @return void 반환값 없음
     */
    public function test_live_cleanup_runs_immediately(): void
    {
        $menu = $this->staleMenu();
        $this->role('live');

        $this->assertSame(1, app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(ExtensionOwnerType::Plugin, 'acme-defer', []));
        $this->assertNull(Menu::query()->find($menu->id));
        $this->assertSame(0, ExtensionDeferredCleanup::query()->count());
    }

    /**
     * 세 매니저의 업데이트는 공유 상태 `updating` 쓰기를 stage 판정 안에 둔다 — 실제 업데이트는 파일·캐시를 바꿔 시험에서 돌릴 수 없어
     * 구조로 잠근다.
     *
     * @scenario case=status_not_updating
     *
     * @effects stage_update_keeps_shared_status
     *
     * @return void 반환값 없음
     */
    public function test_update_methods_guard_updating_status_by_role(): void
    {
        foreach ([
            [ModuleManager::class, 'updateModule'],
            [PluginManager::class, 'updatePlugin'],
            [TemplateManager::class, 'updateTemplate'],
        ] as [$class, $method]) {
            $ref = new ReflectionMethod($class, $method);
            $body = implode('', array_slice(file((string) $ref->getFileName()), $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
            $this->assertMatchesRegularExpression(
                '/if \(! RuntimeRole::isStage\(\)\) \{\s*\$this->\w+->updateByIdentifier\(\$identifier, \[\s*\'status\' => ExtensionStatus::Updating->value/s',
                $body,
                "{$class}::{$method} 의 updating 쓰기는 stage 판정 안에 있어야 한다",
            );
        }
    }
}
