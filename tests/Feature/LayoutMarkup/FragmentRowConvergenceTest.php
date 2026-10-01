<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\ModuleInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Extension\Traits\RefreshesLayoutExtensions;
use App\Extension\Traits\SynchronizesLayoutRows;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 조각 행이 어느 등록 경로를 타든 소스 파일 한 벌로 수렴하는지 검증합니다 (#703).
 *
 * 조각 식별자(`fragment_key`) 마이그레이션은 기존 행의 키를 대상 이름으로 백필했고, 등록은 소스 파일 이름을
 * 키로 쓴다. 7.0.11 에서 올라온 설치에서 그 차이가 세 갈래로 드러났다.
 *
 *  - 활성화·재등록(다른 확장 업데이트·템플릿 활성화가 부르는 `registerAllActiveExtensionsToTemplate`)은 새 키로 행을
 *    만들고 옛 행을 지우지 않아 같은 조각이 두 번 주입됐다.
 *  - 재활성화의 `restoreBySource` 는 정리돼 지워진 옛 행까지 되살렸다.
 *  - 운영자가 고친 옛 행은 갱신 때 새 키 행에 밀려 지워졌고(keep 전략이 못 봄), 재등록은 다른 확장의 수정본까지
 *    `preserveModified=false` 로 덮어썼다.
 */
class FragmentRowConvergenceTest extends TestCase
{
    use ComputesLayoutContentHash;
    use DatabaseTransactions;

    private string $tmp;

    private Template $template;

    private string $host;

    /**
     * 임시 템플릿·호스트 레이아웃·조각 디렉토리를 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = base_path('storage/framework/testing/fragment_conv_'.uniqid());
        File::ensureDirectoryExists($this->tmp.'/a');
        File::ensureDirectoryExists($this->tmp.'/b');

        $this->template = Template::factory()->create([
            'identifier' => 'tpl-conv-'.uniqid(),
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->host = 'conv_host_'.uniqid();
        TemplateLayout::factory()->create(['template_id' => $this->template->id, 'name' => $this->host]);
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 조각 소스(JSON)를 씁니다.
     *
     * @param  string  $dir  모듈 디렉토리(a|b)
     * @param  string  $key  조각 식별자(파일 기본 이름)
     * @param  string  $label  조각을 가를 표시 문자열
     * @return array<string, mixed> 쓴 정의
     */
    private function writeFragment(string $dir, string $key, string $label = 'file'): array
    {
        $data = [
            'target_layout' => $this->host,
            'priority' => 100,
            'injections' => [[
                'target_id' => 'root',
                'position' => 'append_child',
                'components' => [['type' => 'basic', 'name' => 'Div', 'text' => $label]],
            ]],
        ];
        File::put("{$this->tmp}/{$dir}/{$key}.json", json_encode($data));

        return $data;
    }

    /**
     * 마이그레이션 백필 모양의 옛 행(키 = 대상 이름)을 둡니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  array<string, mixed>  $original  원본 정의 (원본 해시 기준)
     * @param  array<string, mixed>|null  $content  현재 content (운영자 수정본이면 원본과 다르게)
     * @param  string|null  $fragmentKey  조각 키 (기본 = 대상 이름)
     * @return LayoutExtension 만든 행
     */
    private function putRow(string $identifier, array $original, ?array $content = null, ?string $fragmentKey = null): LayoutExtension
    {
        return LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => $this->host,
            'fragment_key' => $fragmentKey ?? $this->host,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $identifier,
            'content' => $content ?? $original,
            'original_content_hash' => $this->computeContentHash($original),
            'original_content_size' => $this->computeContentSize($original),
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /**
     * 모듈 목을 만듭니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string  $dir  조각 디렉토리(a|b)
     * @return ModuleInterface 모듈 목
     */
    private function module(string $identifier, string $dir): ModuleInterface
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn($identifier);
        $module->method('getExtensionsPath')->willReturn("{$this->tmp}/{$dir}");
        $module->method('getLayoutExtensions')->willReturn([]);

        return $module;
    }

    /**
     * 활성 모듈 목록을 목으로 바인딩합니다.
     *
     * @param  array<string, ModuleInterface>  $modules  식별자 → 모듈
     * @return void 없음
     */
    private function bindActiveModules(array $modules): void
    {
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn($modules);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn([]);
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * 이 템플릿에 살아 있는 한 출처의 행을 조회합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return Collection<int, LayoutExtension> 행 목록
     */
    private function liveRows(string $identifier)
    {
        return LayoutExtension::where('template_id', $this->template->id)
            ->where('source_identifier', $identifier)
            ->orderBy('fragment_key')
            ->get();
    }

    /**
     * 재등록은 백필된 옛 행을 파일 키로 옮긴다 — 같은 조각이 두 행이 되지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects backfilled_fragment_row_is_rekeyed_not_duplicated
     *
     * @return void 없음
     */
    public function test_reregistration_rekeys_backfilled_row_instead_of_adding_a_duplicate(): void
    {
        $data = $this->writeFragment('a', 'conv-tab');
        $legacy = $this->putRow('vendor-conva', $data);
        $this->bindActiveModules(['vendor-conva' => $this->module('vendor-conva', 'a')]);

        app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->template->id);

        $rows = $this->liveRows('vendor-conva');
        $this->assertSame(['conv-tab'], $rows->pluck('fragment_key')->all(), '옛 행과 새 행이 함께 살면 조각이 두 번 주입된다');
        $this->assertSame($legacy->id, $rows->first()->id, '옛 행을 지우고 새로 만들지 않고 키만 옮긴다');
    }

    /**
     * 한 대상에 조각이 둘이라 옛 행이 어느 조각이었는지 모르면, 옛 행은 정리하고 파일 키 행 둘만 남긴다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects backfilled_fragment_row_is_rekeyed_not_duplicated
     *
     * @return void 없음
     */
    public function test_ambiguous_backfilled_row_is_removed_by_reregistration(): void
    {
        $data = $this->writeFragment('a', 'conv-tab-one');
        $this->writeFragment('a', 'conv-tab-two');
        $legacy = $this->putRow('vendor-conva', $data);
        $this->bindActiveModules(['vendor-conva' => $this->module('vendor-conva', 'a')]);

        app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->template->id);

        $this->assertSame(['conv-tab-one', 'conv-tab-two'], $this->liveRows('vendor-conva')->pluck('fragment_key')->all());
        $this->assertSoftDeleted($legacy);
    }

    /**
     * 운영자가 고친 옛 행은 갱신(keep)에서 그 수정본을 지닌 채 파일 키로 옮겨진다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects edited_backfilled_row_keeps_its_edits_when_rekeyed
     *
     * @return void 없음
     */
    public function test_keep_refresh_carries_admin_edits_of_backfilled_row(): void
    {
        $data = $this->writeFragment('a', 'conv-tab');
        $edited = $data;
        $edited['injections'][0]['components'][0]['text'] = 'admin edited';
        $legacy = $this->putRow('vendor-conva', $data, $edited);

        // 갱신 경로는 행 쓰기를 동기화기에 맡긴다(#727) — 관리자와 같이 생성자로 받는다.
        $harness = new class(app(LayoutExtensionService::class), app(LayoutRowSynchronizer::class))
        {
            use RefreshesLayoutExtensions;
            use SynchronizesLayoutRows;

            /**
             * 갱신 트레이트를 부를 대역을 만듭니다.
             *
             * @param  LayoutExtensionService  $layoutExtensionService  확장 서비스
             * @param  LayoutRowSynchronizer  $layoutRowSynchronizer  레이아웃·확장 조각 행 동기화기
             */
            public function __construct(
                protected LayoutExtensionService $layoutExtensionService,
                protected LayoutRowSynchronizer $layoutRowSynchronizer,
            ) {}

            /**
             * 모듈 조각을 keep 전략으로 갱신합니다.
             *
             * @param  ModuleInterface  $module  모듈
             * @param  mixed  $templates  템플릿 목록
             * @return array<string, int> 갱신 통계
             */
            public function refresh(ModuleInterface $module, $templates): array
            {
                return $this->refreshExtensionLayoutExtensions($module, $templates, LayoutSourceType::Module, true);
            }
        };

        $harness->refresh($this->module('vendor-conva', 'a'), collect([$this->template]));

        $rows = $this->liveRows('vendor-conva');
        $this->assertCount(1, $rows);
        $this->assertSame($legacy->id, $rows->first()->id, '고친 옛 행이 지워지고 파일 원본 행이 새로 생겼다');
        $this->assertSame('conv-tab', $rows->first()->fragment_key);
        $this->assertSame('admin edited', $rows->first()->content['injections'][0]['components'][0]['text']);
    }

    /**
     * 한 확장의 활성화·업데이트가 부르는 전 확장 재등록은 다른 확장의 운영자 수정본을 덮어쓰지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects reregistration_preserves_other_extensions_edits
     *
     * @return void 없음
     */
    public function test_reregistration_does_not_overwrite_other_extensions_edits(): void
    {
        $this->writeFragment('a', 'conv-tab');
        $other = $this->writeFragment('b', 'other-tab', 'other');
        $edited = $other;
        $edited['injections'][0]['components'][0]['text'] = 'other edited';
        $row = $this->putRow('vendor-convb', $other, $edited, 'other-tab');
        $this->bindActiveModules([
            'vendor-conva' => $this->module('vendor-conva', 'a'),
            'vendor-convb' => $this->module('vendor-convb', 'b'),
        ]);

        app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($this->template->id);

        $this->assertSame('other edited', $row->fresh()->content['injections'][0]['components'][0]['text'], '다른 확장의 수정본이 파일 원본으로 덮어써졌다');
    }

    /**
     * 재활성화는 정리돼 지워진 옛 행을 되살리지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects reactivation_does_not_revive_cleaned_fragment_rows
     *
     * @return void 없음
     */
    public function test_reactivation_does_not_revive_rows_cleaned_as_orphans(): void
    {
        $data = $this->writeFragment('a', 'conv-tab');
        $live = $this->putRow('vendor-conva', $data, null, 'conv-tab');
        $orphan = $this->putRow('vendor-conva', $data);
        $orphan->delete();

        $manager = app(ModuleManager::class);
        $module = $this->module('vendor-conva', 'a');
        $this->bindActiveModules(['vendor-conva' => $module]);

        $service = app(LayoutExtensionService::class);
        $service->unregisterBySource(LayoutSourceType::Module, 'vendor-conva');

        $restore = new \ReflectionMethod(ModuleManager::class, 'restoreLayoutExtensions');
        $restore->invoke($manager, $module);
        $register = new \ReflectionMethod(ModuleManager::class, 'registerLayoutExtensions');
        $register->invoke($manager, $module);

        $this->assertSame([$live->id], $this->liveRows('vendor-conva')->pluck('id')->all(), '정리된 옛 행이 재활성화로 되살아나 두 번 주입된다');
        $this->assertSoftDeleted($orphan);
    }
}
