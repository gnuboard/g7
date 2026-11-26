<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateStep;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Exceptions\CoreUpdateOperationException;
use App\Execution\CommandRunnerResolver;
use App\Services\Core\BundledInventory;
use App\Services\Core\BundledPlacementPlan;
use App\Services\Core\BundledSourcePlanner;
use App\Services\CoreUpdateService;
use App\Services\LanguagePackService;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 웹 코어 업데이트 진행 중 번들 선택(7.2.1) 검증.
 *
 * 명령줄 `core:update` 는 코어 파일을 적용한 뒤 두 가지를 묻는다 — 새로 들어온 미배치 공식 번들을 배치할지
 * (`--bundled-sources`), 번들 확장·언어팩 중 무엇을 어떤 레이아웃 전략으로 올릴지. 웹은 일괄(전부 아니면 전부)만 됐다.
 * 고르는 화면이 시작할 때 `ask_bundled_selection` 을 실으면 같은 자리에서 멈춰 목록을 주고, 받은 선택대로 진행한다.
 * 표식이 없는 시작(7.2.0 화면에서 시작한 업데이트)은 종전대로 묻지 않는다.
 */
class CoreUpdateBundledSelectionTest extends TestCase
{
    /** 격리 루트 */
    private string $root;

    /**
     * 격리 루트를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->root = base_path('storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'core_update_bundled_selection_'.uniqid());
        File::ensureDirectoryExists($this->root);
    }

    /**
     * 픽스처를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 고르는 화면이 시작한 실행은 번들 단계에서 멈추고, 항목마다 지금 판·새 판·기본 전략을 준다.
     *
     * @scenario selection=bundled_updates, client=asks
     *
     * @effects bundled_step_waits_for_selection, waiting_items_carry_versions_and_default_strategy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bundled_step_waits_with_candidates_when_the_screen_asks(): void
    {
        $state = $this->startedRun(new CoreUpdateOptions(updateBundledExtensions: true, layoutStrategy: 'keep', askBundledSelection: true));

        $this->orchestrator($this->bundledUpdatesService())->advance();

        $after = $this->state();
        $awaiting = $after->context('awaiting');
        $this->assertSame(CoreUpdateOrchestrator::SELECTION_BUNDLED_UPDATES, $awaiting['kind'] ?? null);
        $this->assertSame('keep', $awaiting['default_strategy'] ?? null);
        $this->assertSame([
            ['key' => 'plugin:sirsoft-popup', 'type' => 'plugin', 'identifier' => 'sirsoft-popup', 'current_version' => '1.0.0', 'latest_version' => '1.0.1', 'has_layouts' => true, 'strategy' => 'keep'],
            ['key' => 'lang_pack:g7-core-ja', 'type' => 'lang_pack', 'identifier' => 'g7-core-ja', 'current_version' => '1.2.0', 'latest_version' => '1.2.1', 'has_layouts' => false, 'strategy' => null],
        ], $awaiting['items']);
        $this->assertNull($after->context('bundled_progress'), '선택 전에 항목을 돌리기 시작했다');
        $this->assertSame(CoreUpdateStep::Bundled->value, $after->all()['next_step'] ?? null, '선택을 기다리는데 다음 단계로 넘어갔다');
        unset($state);
    }

    /**
     * 받은 선택을 저장하고 대기를 푼다 — 기다리던 목록에 없던 항목은 버린다.
     *
     * @scenario selection=bundled_updates, client=asks
     *
     * @effects selection_saved_and_waiting_cleared, unknown_items_dropped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_submit_saves_the_selection_and_clears_waiting(): void
    {
        $this->startedRun(new CoreUpdateOptions(askBundledSelection: true));
        $this->orchestrator($this->bundledUpdatesService())->advance();

        $status = $this->orchestrator($this->bundledUpdatesService())->submitSelection(CoreUpdateOrchestrator::SELECTION_BUNDLED_UPDATES, [
            ['key' => 'plugin:sirsoft-popup', 'selected' => true, 'strategy' => 'keep'],
            ['key' => 'lang_pack:g7-core-ja', 'selected' => false],
            ['key' => 'module:not-offered', 'selected' => true],
        ]);

        $this->assertNull($status['run']['awaiting'] ?? null);
        $this->assertSame([
            'plugin:sirsoft-popup' => ['selected' => true, 'strategy' => 'keep'],
            'lang_pack:g7-core-ja' => ['selected' => false, 'strategy' => 'overwrite'],
        ], $this->state()->context('selections')[CoreUpdateOrchestrator::SELECTION_BUNDLED_UPDATES] ?? null);
    }

    /**
     * 기다리지 않는 선택은 받지 않는다 — 종류가 다르거나 대기가 없으면 거절.
     *
     * @scenario selection=bundled_sources, client=asks
     *
     * @effects selection_rejected_when_not_awaited
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_submit_is_rejected_when_that_selection_is_not_awaited(): void
    {
        $this->startedRun(new CoreUpdateOptions(askBundledSelection: true));

        try {
            $this->orchestrator(app(CoreUpdateService::class))->submitSelection(CoreUpdateOrchestrator::SELECTION_BUNDLED_SOURCES, []);
            $this->fail('기다리지 않는 선택을 받았다');
        } catch (CoreUpdateOperationException $e) {
            $this->assertSame('settings.core_update.web.selection_not_awaited', $e->errorKey);
        }
    }

    /**
     * 선택 반영 — 고른 항목만 남고, 확장은 고른 레이아웃 전략, 언어팩은 전략이 없다. 아무것도 고르지 않으면 null.
     *
     * @scenario selection=bundled_updates, client=asks
     *
     * @effects only_selected_items_are_updated_with_their_strategy
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_apply_selection_keeps_only_selected_items_with_their_strategy(): void
    {
        $manifest = [
            'modules' => [['identifier' => 'sirsoft-board', 'strategy' => 'overwrite']],
            'plugins' => [['identifier' => 'sirsoft-popup', 'strategy' => 'overwrite']],
            'templates' => [['identifier' => 'sirsoft-basic', 'strategy' => 'overwrite']],
            'lang_packs' => [['identifier' => 'g7-core-ja']],
            'rebuild_search_index' => false,
        ];

        $applied = CoreUpdateOrchestrator::applyBundledSelection($manifest, [
            'module:sirsoft-board' => ['selected' => true, 'strategy' => 'keep'],
            'plugin:sirsoft-popup' => ['selected' => false, 'strategy' => 'overwrite'],
            'lang_pack:g7-core-ja' => ['selected' => true, 'strategy' => 'overwrite'],
        ]);

        $this->assertSame([['identifier' => 'sirsoft-board', 'strategy' => 'keep']], $applied['modules']);
        $this->assertSame([], $applied['plugins']);
        $this->assertSame([], $applied['templates'], '선택 창에서 보지 못한 항목을 올렸다');
        $this->assertSame([['identifier' => 'g7-core-ja']], $applied['lang_packs']);

        $this->assertNull(CoreUpdateOrchestrator::applyBundledSelection($manifest, []));
    }

    /**
     * 7.2.0 화면이 시작한 실행(표식 없음)은 묻지 않는다 — 저장된 선택 배열에 키가 없으면 거짓.
     *
     * @scenario selection=bundled_updates, client=legacy
     *
     * @effects legacy_client_run_never_waits
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_run_started_by_the_previous_screen_does_not_ask(): void
    {
        $legacy = CoreUpdateOptions::fromArray([
            'update_bundled_extensions' => true,
            'rebuild_search_index' => false,
            'layout_strategy' => 'overwrite',
            'zip_file' => null,
            'allow_unsigned' => true,
            'integrity_aware' => true,
        ]);

        $this->assertFalse($legacy->askBundledSelection);
        $this->assertTrue(CoreUpdateOptions::fromArray(array_merge($legacy->toArray(), ['ask_bundled_selection' => true]))->askBundledSelection);
    }

    /**
     * 새로 들어온 미배치 공식 번들이 있으면 적용 대상 산출 전에 묻고, 받은 뒤에는 고른 것만 배치한다.
     *
     * @scenario selection=bundled_sources, client=asks
     *
     * @effects unplaced_bundles_wait_for_selection, selected_unplaced_bundles_are_placed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_unplaced_bundles_wait_for_selection_then_only_selected_are_placed(): void
    {
        $this->startedRun(new CoreUpdateOptions(askBundledSelection: true));
        $entry = ['type' => 'plugin', 'identifier' => 'sirsoft-new', 'version' => '1.0.0', 'name' => ['ko' => '새 플러그인', 'en' => 'New plugin'], 'path' => 'plugins/_bundled/sirsoft-new'];
        $inventory = new BundledInventory('7.2.1', ['plugin:sirsoft-new' => $entry], null);

        $planner = Mockery::mock(BundledSourcePlanner::class);
        $planner->shouldReceive('readInventoryFromDirectory')->andReturn($inventory);
        $planner->shouldReceive('plan')->with($inventory)->andReturn(new BundledPlacementPlan([], ['plugin:sirsoft-new' => $entry]));
        $planner->shouldReceive('plan')->with($inventory, ['plugin:sirsoft-new'])->andReturn(new BundledPlacementPlan(
            ['plugin:sirsoft-new' => ['entry' => $entry, 'reason' => 'selected', 'required_by' => []]],
            [],
        ));
        $this->app->instance(BundledSourcePlanner::class, $planner);

        $orchestrator = $this->orchestrator(app(CoreUpdateService::class));
        $method = new \ReflectionMethod($orchestrator, 'planBundledPlacement');
        $options = $this->state()->options();

        $this->assertNull($method->invoke($orchestrator, $this->root, $options), '미배치 번들이 있는데 묻지 않았다');
        $awaiting = $this->state()->context('awaiting');
        $this->assertSame(CoreUpdateOrchestrator::SELECTION_BUNDLED_SOURCES, $awaiting['kind']);
        $this->assertSame([['key' => 'plugin:sirsoft-new', 'type' => 'plugin', 'identifier' => 'sirsoft-new', 'name' => '새 플러그인', 'version' => '1.0.0']], $awaiting['items']);

        $orchestrator->submitSelection(CoreUpdateOrchestrator::SELECTION_BUNDLED_SOURCES, [['key' => 'plugin:sirsoft-new', 'selected' => true]]);
        $plan = $method->invoke($this->orchestrator(app(CoreUpdateService::class)), $this->root, $options);

        $this->assertInstanceOf(BundledPlacementPlan::class, $plan);
        $this->assertArrayHasKey('plugin:sirsoft-new', $plan->placed, '고른 미배치 번들이 배치되지 않았다');
    }

    /**
     * 7.2.0 화면이 시작한 실행은 미배치 번들이 있어도 묻지 않고, 종전처럼 배치하지 않는다.
     *
     * @scenario selection=bundled_sources, client=legacy
     *
     * @effects legacy_client_run_never_waits
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_a_previous_screen_run_skips_unplaced_bundles_without_asking(): void
    {
        $this->startedRun(new CoreUpdateOptions(askBundledSelection: false));
        $entry = ['type' => 'plugin', 'identifier' => 'sirsoft-new', 'version' => '1.0.0', 'name' => 'New', 'path' => 'plugins/_bundled/sirsoft-new'];
        $inventory = new BundledInventory('7.2.1', ['plugin:sirsoft-new' => $entry], null);

        $planner = Mockery::mock(BundledSourcePlanner::class);
        $planner->shouldReceive('readInventoryFromDirectory')->andReturn($inventory);
        $planner->shouldReceive('plan')->once()->with($inventory)->andReturn(new BundledPlacementPlan([], ['plugin:sirsoft-new' => $entry]));
        $this->app->instance(BundledSourcePlanner::class, $planner);

        $orchestrator = $this->orchestrator(app(CoreUpdateService::class));
        $plan = (new \ReflectionMethod($orchestrator, 'planBundledPlacement'))->invoke($orchestrator, $this->root, $this->state()->options());

        $this->assertInstanceOf(BundledPlacementPlan::class, $plan, '옛 화면 실행이 선택을 기다렸다 — 옛 화면은 선택 창을 그리지 못해 멈춘다');
        $this->assertArrayHasKey('plugin:sirsoft-new', $plan->unplaced);
        $this->assertNull($this->state()->context('awaiting'));
    }

    /**
     * 번들 단계까지 온 실행을 만든다.
     *
     * @param  CoreUpdateOptions  $options  착수 선택
     * @return CoreUpdateRunState 상태
     */
    private function startedRun(CoreUpdateOptions $options): CoreUpdateRunState
    {
        $state = $this->state();
        $state->start($options, '7.2.0', '7.2.1', 1);
        $state->patch(['next_step' => CoreUpdateStep::Bundled->value]);

        return $state;
    }

    /**
     * 번들에 새 판이 있는 플러그인 1·언어팩 1 을 돌려주는 서비스.
     *
     * @return CoreUpdateService 부분 모의 서비스
     */
    private function bundledUpdatesService(): CoreUpdateService
    {
        $service = Mockery::mock(CoreUpdateService::class)->makePartial();
        $service->shouldReceive('collectBundledExtensionUpdates')->andReturn([
            'modules' => [],
            'plugins' => [['identifier' => 'sirsoft-popup', 'current_version' => '1.0.0', 'latest_version' => '1.0.1']],
            'templates' => [],
        ]);
        $langPacks = Mockery::mock(LanguagePackService::class);
        $langPacks->shouldReceive('collectBundledLangPackUpdates')->andReturn([
            ['identifier' => 'g7-core-ja', 'current_version' => '1.2.0', 'latest_version' => '1.2.1'],
        ]);
        $this->app->instance(LanguagePackService::class, $langPacks);

        return $service;
    }

    /**
     * 격리 상태를 쓰는 오케스트레이터.
     *
     * @param  CoreUpdateService  $service  코어 업데이트 서비스
     * @return CoreUpdateOrchestrator 오케스트레이터
     */
    private function orchestrator(CoreUpdateService $service): CoreUpdateOrchestrator
    {
        return new CoreUpdateOrchestrator($service, $this->state(), new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300));
    }

    /**
     * 격리 경로의 실행 상태.
     *
     * @return CoreUpdateRunState 상태
     */
    private function state(): CoreUpdateRunState
    {
        return new CoreUpdateRunState($this->root.DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.CoreUpdateRunState::FILENAME);
    }
}
