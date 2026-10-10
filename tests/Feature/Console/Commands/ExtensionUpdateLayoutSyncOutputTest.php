<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;
use App\Console\Commands\Module\UpdateModuleCommand;
use App\Console\Commands\Plugin\UpdatePluginCommand;
use App\Console\Commands\Template\UpdateTemplateCommand;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutSyncMode;
use App\Extension\ExtensionManager;
use App\Extension\Layout\DTO\LayoutSyncEntry;
use App\Extension\Layout\LayoutSyncReport;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Services\CoreUpdateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확장 업데이트 커맨드가 레이아웃 처리 결과를 실제로 출력하는지 고정한다 (#727 §5.8 D2 — CLI).
 *
 * `module:update`·`plugin:update`·`template:update` 는 keep 결과가 있으면 표(머리·행)와 요약 한 줄을, overwrite 면 업데이트 전에
 * 덮어쓰일 수정본 경고를 낸다. `core:update` 의 번들 일괄 업데이트 자식(`core:execute-bundled-updates`)은 확장마다 요약 한 줄만
 * 낸다. 관리자(업데이트 실행)는 대역으로 바꾸고 커맨드의 분기·출력만 실제로 돌린다 — 레이아웃 병합 자체는
 * `LayoutSyncPathMatrixTest` 가 실제 파일로 잰다.
 */
class ExtensionUpdateLayoutSyncOutputTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> 테스트가 만든 임시 파일 */
    private array $tempFiles = [];

    /**
     * 임시 매니페스트 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    /**
     * 출처별 커맨드 축.
     *
     * @return array<string, array{0: string}> [출처]
     */
    public static function sources(): array
    {
        return ['module' => ['module'], 'plugin' => ['plugin'], 'template' => ['template']];
    }

    /**
     * keep 보고 — 레이아웃 병합 1건 + 확장 조각 충돌 1건.
     *
     * @param  string  $name  레이아웃 이름
     * @return array<string, mixed> 보고 배열 (`LayoutSyncReport::toArray()`)
     */
    private function keepReport(string $name): array
    {
        $report = new LayoutSyncReport(LayoutSyncMode::Keep);
        $report->add(new LayoutSyncEntry('layout', 'sirsoft-admin_basic', $name, 'merged', [['unit' => 'root > partials/x/_a @ 0', 'kind' => 'partial']]));
        $report->add(new LayoutSyncEntry('extension', 'sirsoft-admin_basic', 'admin_user_detail:probe_a', 'conflict', [], [['unit' => 'key:injections', 'reason' => 'both_changed']]));

        return $report->toArray();
    }

    /**
     * overwrite 보고 — 덮어쓴 행은 보고에 싣지 않는다.
     *
     * @param  string  $name  레이아웃 이름
     * @return array<string, mixed> 보고 배열 (`LayoutSyncReport::toArray()`)
     */
    private function overwriteReport(string $name): array
    {
        $report = new LayoutSyncReport(LayoutSyncMode::Overwrite);
        $report->add(new LayoutSyncEntry('layout', 'sirsoft-admin_basic', $name, 'updated'));

        return $report->toArray();
    }

    /**
     * 출처의 update 커맨드를 대역 관리자로 등록하고 artisan 인자를 돌려줍니다.
     *
     * @param  string  $source  출처
     * @param  string  $strategy  레이아웃 전략
     * @param  array<string, mixed>  $layoutSync  관리자가 돌려줄 보고
     * @param  array<string, mixed>  $modified  덮어쓰기 경고용 `hasModifiedLayouts()` 응답
     * @return array{0: string, 1: array<string, mixed>} [커맨드 이름, 인자]
     */
    private function registerUpdateCommand(string $source, string $strategy, array $layoutSync, array $modified = ['modified_count' => 0, 'modified_layouts' => []]): array
    {
        $identifier = "probe-{$source}";
        $check = ['update_available' => true, 'current_version' => '1.0.0', 'latest_version' => '1.1.0', 'update_source' => 'bundled'];
        $result = ['success' => true, 'from_version' => '1.0.0', 'to_version' => '1.1.0', 'layout_strategy' => $strategy, 'layout_sync' => $layoutSync];

        switch ($source) {
            case 'module':
                $manager = Mockery::mock(ModuleManager::class, function (MockInterface $m) use ($identifier, $check, $result, $strategy, $modified) {
                    $m->shouldReceive('loadModules')->once();
                    $m->shouldReceive('checkModuleUpdate')->with($identifier, null)->andReturn($check);
                    $m->shouldReceive('hasModifiedLayouts')->with($identifier)->andReturn($modified);
                    $m->shouldReceive('updateModule')->once()
                        ->withArgs(fn (string $id, bool $force, $progress, VendorMode $vendor, string $layoutStrategy) => $id === $identifier && $layoutStrategy === $strategy)
                        ->andReturn($result);
                });
                $repository = Mockery::mock(ModuleRepositoryInterface::class);
                $repository->shouldReceive('findByIdentifier')->with($identifier)->andReturn(new Module(['identifier' => $identifier, 'version' => '1.0.0']));
                $command = new UpdateModuleCommand($manager, $repository);
                break;
            case 'plugin':
                $manager = Mockery::mock(PluginManager::class, function (MockInterface $m) use ($identifier, $check, $result, $strategy, $modified) {
                    $m->shouldReceive('loadPlugins')->once();
                    $m->shouldReceive('checkPluginUpdate')->with($identifier, null)->andReturn($check);
                    $m->shouldReceive('hasModifiedLayouts')->with($identifier)->andReturn($modified);
                    $m->shouldReceive('updatePlugin')->once()
                        ->withArgs(fn (string $id, bool $force, $progress, VendorMode $vendor, string $layoutStrategy) => $id === $identifier && $layoutStrategy === $strategy)
                        ->andReturn($result);
                });
                $repository = Mockery::mock(PluginRepositoryInterface::class);
                $repository->shouldReceive('findByIdentifier')->with($identifier)->andReturn(new Plugin(['identifier' => $identifier, 'version' => '1.0.0']));
                $command = new UpdatePluginCommand($manager, $repository);
                break;
            default:
                $manager = Mockery::mock(TemplateManager::class, function (MockInterface $m) use ($identifier, $check, $result, $strategy, $modified) {
                    $m->shouldReceive('loadTemplates')->once();
                    $m->shouldReceive('checkTemplateUpdate')->with($identifier, null)->andReturn($check);
                    $m->shouldReceive('hasModifiedLayouts')->with($identifier)->andReturn($modified);
                    $m->shouldReceive('updateTemplate')->once()
                        ->withArgs(fn (string $id, bool $force, $progress, string $layoutStrategy) => $id === $identifier && $layoutStrategy === $strategy)
                        ->andReturn($result);
                });
                $repository = Mockery::mock(TemplateRepositoryInterface::class);
                $repository->shouldReceive('findByIdentifier')->with($identifier)->andReturn(new Template(['identifier' => $identifier, 'version' => '1.0.0']));
                $command = new UpdateTemplateCommand($manager, $repository);
        }

        $command->setLaravel($this->app);
        $this->app[Kernel::class]->registerCommand($command);

        return ["{$source}:update", ['identifier' => $identifier, '--force' => true, '--layout-strategy' => $strategy]];
    }

    /**
     * keep 업데이트 결과에 운영자 관련 행이 있으면 세 update 커맨드가 표 머리·행(화면·종류·결과)과 요약 한 줄을 출력한다.
     *
     * @effects layout_sync_result_is_shown_in_cli
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('sources')]
    public function test_update_command_prints_layout_sync_table_and_summary(string $source): void
    {
        $layout = "probe-{$source}.list";
        [$name, $args] = $this->registerUpdateCommand($source, 'keep', $this->keepReport($layout));

        $this->assertSame(0, Artisan::call($name, $args));
        $output = Artisan::output();

        $this->assertStringContainsString(__('extensions.layout_sync.cli.header'), $output);
        $this->assertMatchesRegularExpression(
            '/'.preg_quote('sirsoft-admin_basic · '.$layout, '/').'.*'.preg_quote(__('extensions.layout_sync.cli.kind.layout'), '/').'.*'.preg_quote(__('extensions.layout_sync.verdict.merged'), '/').'/u',
            $output,
            '레이아웃 행이 화면·종류·결과 칸으로 한 줄에 나와야 한다'
        );
        $this->assertMatchesRegularExpression(
            '/'.preg_quote('sirsoft-admin_basic · admin_user_detail:probe_a', '/').'.*'.preg_quote(__('extensions.layout_sync.cli.kind.extension'), '/').'.*'.preg_quote(__('extensions.layout_sync.verdict.conflict'), '/').'/u',
            $output,
            '확장 조각 행이 화면·종류·결과 칸으로 한 줄에 나와야 한다'
        );
        $this->assertStringContainsString(__('extensions.layout_sync.summary', ['applied' => 1, 'conflicts' => 1]), $output);
    }

    /**
     * overwrite 업데이트는 실행 전에 덮어쓰일 운영자 수정본(레이아웃·조각)을 경고하고, 결과 표·요약은 내지 않는다.
     *
     * @effects overwrite_warns_about_modified_layouts, layout_sync_result_is_shown_in_cli
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('sources')]
    public function test_overwrite_update_warns_before_and_prints_no_table(string $source): void
    {
        $fragment = __('extensions.layout_sync.fragment_label', ['target' => 'admin_user_detail', 'key' => 'probe_a']);
        [$name, $args] = $this->registerUpdateCommand($source, 'overwrite', $this->overwriteReport("probe-{$source}.list"), [
            'modified_count' => 1,
            'modified_layouts' => [['name' => $fragment, 'updated_at' => '2026-09-30 10:00:00']],
        ]);

        $this->assertSame(0, Artisan::call($name, $args));
        $output = Artisan::output();

        $this->assertStringContainsString(__($source.'s.commands.update.modified_layouts_warning', ['count' => 1]), $output);
        $this->assertStringContainsString($fragment, $output);
        $this->assertLessThan(
            strpos($output, '✅'),
            strpos($output, __($source.'s.commands.update.modified_layouts_warning', ['count' => 1])),
            '경고는 업데이트 실행(성공 줄) 전에 나와야 한다'
        );
        $this->assertStringNotContainsString(__('extensions.layout_sync.cli.header'), $output);
        $this->assertStringNotContainsString(__('extensions.layout_sync.summary', ['applied' => 0, 'conflicts' => 0]), $output);
    }

    /**
     * core:update 의 번들 일괄 업데이트 자식은 운영자 선택 전략(keep)을 확장마다 그대로 넘기고, 확장마다 요약 한 줄만 낸다(표 없음).
     *
     * @effects core_update_prints_layout_sync_summary_per_extension, layout_sync_result_is_shown_in_cli
     *
     * @return void 반환값 없음
     */
    public function test_bundled_update_child_prints_one_summary_line_per_extension(): void
    {
        $this->app->instance(ExtensionManager::class, Mockery::mock(ExtensionManager::class, fn (MockInterface $m) => $m->shouldReceive('updateComposerAutoload')->once()));
        $this->app->instance(CoreUpdateService::class, Mockery::mock(CoreUpdateService::class, fn (MockInterface $m) => $m->shouldReceive('sweepEmptyStagingDirectories')->once()));
        $this->app->instance(ModuleManager::class, Mockery::mock(ModuleManager::class, fn (MockInterface $m) => $m->shouldIgnoreMissing()->shouldReceive('updateModule')->once()
            ->with('probe-module', true, null, VendorMode::Auto, 'keep', null, 'bundled')
            ->andReturn(['success' => true, 'layout_sync' => $this->keepReport('probe-module.list')])));
        $this->app->instance(PluginManager::class, Mockery::mock(PluginManager::class, fn (MockInterface $m) => $m->shouldIgnoreMissing()->shouldReceive('updatePlugin')->once()
            ->with('probe-plugin', true, null, VendorMode::Auto, 'overwrite', null, 'bundled')
            ->andReturn(['success' => true, 'layout_sync' => $this->overwriteReport('probe-plugin.list')])));
        $this->app->instance(TemplateManager::class, Mockery::mock(TemplateManager::class, fn (MockInterface $m) => $m->shouldIgnoreMissing()->shouldReceive('updateTemplate')->once()
            ->with('probe-template', true, null, 'keep', 'bundled')
            ->andReturn(['success' => true, 'layout_sync' => ['strategy' => 'keep', 'applied_count' => 0, 'conflict_count' => 0, 'kept_no_base_count' => 2, 'kept_removed_count' => 0, 'kept_deleted_by_operator_count' => 0, 'kept_name_collision_count' => 0, 'kept_invalid_count' => 0, 'modified_count' => 2, 'entries' => []]])));

        $manifest = storage_path('app/test_bundled_layout_sync_'.uniqid().'.json');
        $this->tempFiles[] = $manifest;
        File::put($manifest, json_encode([
            'modules' => [['identifier' => 'probe-module', 'strategy' => 'keep']],
            'plugins' => [['identifier' => 'probe-plugin', 'strategy' => 'overwrite']],
            'templates' => [['identifier' => 'probe-template', 'strategy' => 'keep']],
            'lang_packs' => [],
        ]));

        $this->assertSame(0, Artisan::call('core:execute-bundled-updates', ['--manifest' => $manifest]));
        $output = Artisan::output();

        $this->assertStringContainsString(PHP_EOL.'  '.__('extensions.layout_sync.summary', ['applied' => 1, 'conflicts' => 1]), $output, '모듈 요약 한 줄');
        $this->assertStringContainsString(PHP_EOL.'  '.__('extensions.layout_sync.summary', ['applied' => 0, 'conflicts' => 0]).' '.__('extensions.layout_sync.no_base', ['count' => 2]), $output, '템플릿 요약 한 줄(기준 없음 포함)');
        $this->assertSame(2, substr_count($output, __('extensions.layout_sync.summary', ['applied' => 0, 'conflicts' => 0])) + substr_count($output, __('extensions.layout_sync.summary', ['applied' => 1, 'conflicts' => 1])), 'overwrite 플러그인은 요약 줄이 없어야 한다');
        $this->assertStringNotContainsString(__('extensions.layout_sync.cli.header'), $output, 'core:update 는 표를 내지 않는다');
        $this->assertStringContainsString(ExecuteBundledUpdatesCommand::RESULT_PREFIX.'{"success":3,"failed":0}', $output);
    }
}
