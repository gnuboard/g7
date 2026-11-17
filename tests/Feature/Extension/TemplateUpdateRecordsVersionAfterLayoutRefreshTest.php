<?php

namespace Tests\Feature\Extension;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutSyncMode;
use App\Extension\ExtensionManager;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 템플릿 업데이트는 레이아웃 갱신을 마친 뒤에 새 판을 DB 에 기록한다.
 *
 * 결함(수정 전): 업데이트가 새 판(`version`)을 먼저 저장하고 상태를 되돌린 뒤 레이아웃을 갱신했다. 레이아웃 갱신 도중
 * 프로세스가 죽으면(메모리 고갈·시간 초과·강제 종료) catch 가 돌지 않아, 판은 새 판인데 템플릿 레이아웃은 옛 내용으로
 * 남았다. 상태도 이미 `active` 로 돌아가 멈춘 업데이트 정리 대상이 아니었고, 「업데이트 확인」은 같은 판이라 「업데이트 없음」
 * 으로 판정해 다시 시도할 길이 없었다(실측: 관리자 템플릿 1.2.0 기록 5초 뒤 레이아웃 갱신 중 메모리 고갈, 레이아웃 16개 옛 판).
 *
 * 프로세스가 죽는 순간은 시험에서 만들 수 없으므로, 레이아웃 갱신에 들어선 시점의 DB 행을 그대로 잡아 「여기서 죽으면 남는
 * 상태」로 본다. 그 상태가 옛 판이고 다음 「업데이트 확인」이 업데이트 있음으로 봐야 한다.
 *
 * @scenario-manifest tests/scenarios/template-update-version-after-layout-refresh.yaml
 */
class TemplateUpdateRecordsVersionAfterLayoutRefreshTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 화면 한 장짜리 레이아웃 JSON 을 만듭니다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  string  $text  화면 문구
     * @return array<string, mixed> 레이아웃 JSON
     */
    private function screen(string $name, string $text): array
    {
        return ['version' => '1.0.0', 'layout_name' => $name, 'components' => [['id' => $name, 'type' => 'basic', 'name' => 'Div', 'text' => $text]]];
    }

    /**
     * 활성 디렉토리를 번들 디렉토리로 복사하고 매니페스트 버전을 1.1.0 으로, 레이아웃 문구를 새 판으로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    private function stageBundledVersion(): void
    {
        $bundled = base_path("templates/_bundled/{$this->fixtureTemplate}");
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path("templates/{$this->fixtureTemplate}"), $bundled);

        $manifest = json_decode(File::get("{$bundled}/template.json"), true);
        $manifest['version'] = '1.1.0';
        File::put("{$bundled}/template.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put("{$bundled}/layouts/home.json", json_encode($this->screen('home', 'HOME2'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 레이아웃 갱신에 들어선 순간의 템플릿 행과 「업데이트 확인」 결과를 잡는 템플릿 관리자를 만듭니다.
     *
     * 잡은 값은 관리자의 `snapshot` 속성에 남는다(레이아웃 갱신이 없었으면 null).
     *
     * @return TemplateManager 템플릿 관리자
     */
    private function managerCapturingStateAtLayoutRefresh(): TemplateManager
    {
        $manager = new class(app(ExtensionManager::class), app(TemplateRepositoryInterface::class), app(LayoutRepositoryInterface::class), app(ModuleRepositoryInterface::class), app(PluginRepositoryInterface::class), app(LayoutExtensionService::class), app(LayoutRowSynchronizer::class), app(LayoutExtensionRepositoryInterface::class)) extends TemplateManager
        {
            /** @var array<string, mixed>|null */
            public ?array $snapshot = null;

            /**
             * 레이아웃을 다시 읽는 순간의 템플릿 버전·상태·업데이트 판정을 기록한 뒤 원래 갱신을 실행한다.
             *
             * @param  string  $identifier  템플릿 식별자
             * @param  bool|LayoutSyncMode  $preserveModified  동기화 모드 (기본 keep)
             * @param  string|null  $backupPath  백업 경로
             * @return array<string, mixed> 원래 갱신 결과
             */
            public function refreshTemplateLayouts(string $identifier, bool|LayoutSyncMode $preserveModified = LayoutSyncMode::Keep, ?string $backupPath = null): array
            {
                $row = Template::where('identifier', $identifier)->firstOrFail();
                $this->snapshot = [
                    'version' => $row->version,
                    'status' => $row->status,
                    'update_available_on_check' => $this->checkTemplateUpdate($identifier, 'bundled')['update_available'],
                ];

                return parent::refreshTemplateLayouts($identifier, $preserveModified, $backupPath);
            }
        };

        $manager->loadTemplates();

        return $manager;
    }

    /**
     * 레이아웃 갱신 도중 프로세스가 죽어도 판은 옛 판으로 남아 다음 「업데이트 확인」이 같은 업데이트를 다시 잡는다.
     *
     * @scenario source=template, path=update_process_killed_during_layout_refresh, unit=version_columns
     *
     * @effects template_update_records_version_after_layout_refresh
     *
     * @return void 반환값 없음
     */
    public function test_version_is_still_previous_while_layouts_are_refreshed(): void
    {
        $this->makeFixtureTemplate(['home.json' => $this->screen('home', 'HOME1')]);
        $this->writeFixtureTemplateManifest();
        $this->stageBundledVersion();

        $manager = $this->managerCapturingStateAtLayoutRefresh();

        $result = $manager->updateTemplate($this->fixtureTemplate, true, null, 'overwrite', 'bundled');

        $this->assertTrue($result['success']);
        $this->assertNotNull($manager->snapshot, '활성 템플릿 업데이트는 레이아웃을 갱신해야 합니다.');
        $this->assertSame('1.0.0', $manager->snapshot['version'], '레이아웃 갱신 중에 죽으면 판은 새 판, 레이아웃은 옛 판으로 남습니다 — 판은 레이아웃 갱신 뒤에 기록해야 합니다.');
        $this->assertTrue($manager->snapshot['update_available_on_check'], '레이아웃 갱신 중에 죽은 뒤 「업데이트 확인」이 같은 업데이트를 다시 잡아야 합니다.');

        // 끝까지 가면 판과 레이아웃이 모두 새 판이다
        $row = Template::where('identifier', $this->fixtureTemplate)->firstOrFail();
        $this->assertSame('1.1.0', $row->version);
        $this->assertFalse((bool) $row->update_available);
        $this->assertSame('active', $row->status);
        $layout = TemplateLayout::where('template_id', $row->id)->where('name', 'home')->firstOrFail();
        $this->assertStringContainsString('HOME2', $this->flat($layout->content), '레이아웃도 새 판이어야 합니다.');
    }

    /**
     * 꺼 둔 템플릿의 업데이트도 끝나면 새 판을 기록한다 (레이아웃은 켤 때 갱신).
     *
     * @scenario source=template, path=update_inactive_template, unit=version_columns
     *
     * @effects template_update_records_version_after_layout_refresh
     *
     * @return void 반환값 없음
     */
    public function test_inactive_template_update_still_records_new_version(): void
    {
        $this->makeFixtureTemplate(['home.json' => $this->screen('home', 'HOME1')], 'inactive');
        $this->writeFixtureTemplateManifest();
        $this->stageBundledVersion();

        $manager = $this->managerCapturingStateAtLayoutRefresh();

        $result = $manager->updateTemplate($this->fixtureTemplate, true, null, 'overwrite', 'bundled');

        $this->assertTrue($result['success']);
        $this->assertNull($manager->snapshot, '꺼 둔 템플릿은 업데이트 때 레이아웃을 갱신하지 않습니다.');
        $row = Template::where('identifier', $this->fixtureTemplate)->firstOrFail();
        $this->assertSame('1.1.0', $row->version);
        $this->assertSame('1.1.0', $row->latest_version);
        $this->assertFalse((bool) $row->update_available);
        $this->assertSame('inactive', $row->status);
    }
}
