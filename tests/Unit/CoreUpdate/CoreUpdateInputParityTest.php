<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\Http\Controllers\Api\Admin\CoreUpdateController;
use App\Search\SearchIndexMaintenanceManager;
use App\Services\Core\BundledPlacementPlan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 웹 코어 업데이트가 받는 입력이 터미널(`core:update`)과 같은 판정을 쓰는지 잰다.
 *
 * - 미배치 공식 번들 배치 후보: 확장 언어팩은 대상 확장을 따라가므로 터미널·웹 모두 묻지 않는다.
 * - 고른 번들의 의존 번들이 함께 배치되면 터미널과 같은 계산으로 알린다(웹은 실행 기록 줄).
 * - 검색 인덱스 다시 만들기는 엔진이 점검 기능을 제공할 때만 고를 수 있다(상태 응답 힌트).
 *
 * 커버 축: core_update_web input_parity
 */
class CoreUpdateInputParityTest extends TestCase
{
    /**
     * Mockery 를 정리한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 배치 후보에서 확장 언어팩이 빠지고 확장은 남는다.
     *
     * @scenario actor=with_permission, run_state=running, bundled_extensions=on
     *
     * @effects the_web_bundled_source_candidates_skip_extension_language_packs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function askable_unplaced_drops_language_packs(): void
    {
        $plan = new BundledPlacementPlan([], [
            'module:acme-board' => ['type' => 'module', 'identifier' => 'acme-board', 'path' => 'modules/_bundled/acme-board'],
            'language_pack:acme-board-ja' => ['type' => 'language_pack', 'identifier' => 'acme-board-ja', 'path' => 'lang-packs/_bundled/acme-board-ja'],
        ]);

        $this->assertSame(['module:acme-board'], array_keys($plan->askableUnplaced()));
        // 미배치 집합 자체는 그대로다 — 건너뛸 경로에는 언어팩도 남는다
        $this->assertCount(2, $plan->unplaced);
    }

    /**
     * 웹 선택 창(`planBundledPlacement`)과 터미널 질문이 같은 후보 함수를 쓴다.
     *
     * @scenario actor=with_permission, run_state=running, bundled_extensions=on
     *
     * @effects the_web_bundled_source_candidates_skip_extension_language_packs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function web_and_terminal_share_the_candidate_function(): void
    {
        $orchestrator = (string) file_get_contents(app_path('CoreUpdate/CoreUpdateOrchestrator.php'));
        $terminal = (string) file_get_contents(app_path('Console/Commands/Core/Concerns/BundledSourcePlacementPrompt.php'));

        $this->assertStringContainsString('$plan->askableUnplaced()', $orchestrator);
        $this->assertStringContainsString('$base->askableUnplaced()', $terminal);
    }

    /**
     * 고른 번들이 새로 끌어온 의존 번들만 「함께 배치」로 센다 — 원래 갱신 대상이던 의존 번들은 뺀다.
     *
     * @scenario actor=with_permission, run_state=running, bundled_extensions=on
     *
     * @effects the_web_logs_bundles_placed_as_dependencies_of_the_selection
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function added_by_dependency_counts_only_newly_pulled_bundles(): void
    {
        $row = static fn (string $type, string $id, string $reason): array => [
            'entry' => ['type' => $type, 'identifier' => $id],
            'reason' => $reason,
            'required_by' => ['module:acme-shop'],
        ];

        $base = new BundledPlacementPlan(['module:acme-core' => $row('module', 'acme-core', 'dependency')], [
            'module:acme-shop' => ['type' => 'module', 'identifier' => 'acme-shop'],
            'plugin:acme-pay' => ['type' => 'plugin', 'identifier' => 'acme-pay'],
        ]);
        $picked = new BundledPlacementPlan([
            'module:acme-core' => $row('module', 'acme-core', 'dependency'),
            'module:acme-shop' => $row('module', 'acme-shop', 'selected'),
            'plugin:acme-pay' => $row('plugin', 'acme-pay', 'dependency'),
        ], []);

        $this->assertSame(['plugin:acme-pay'], array_keys($picked->addedByDependencySince($base)));
        $this->assertSame([], $base->addedByDependencySince($base));
    }

    /**
     * 웹 계획 단계가 의존 안내를 터미널과 같은 문구 키로 실행 기록에 남긴다.
     *
     * @scenario actor=with_permission, run_state=running, bundled_extensions=on
     *
     * @effects the_web_logs_bundles_placed_as_dependencies_of_the_selection
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function web_plan_step_logs_dependency_additions_with_the_terminal_message(): void
    {
        $orchestrator = (string) file_get_contents(app_path('CoreUpdate/CoreUpdateOrchestrator.php'));

        $this->assertStringContainsString('$this->logBundledDependencyAdditions($pendingPath, $bundledPlan);', $orchestrator);
        $this->assertStringContainsString("'settings.bundled_sources.dependency_added'", $orchestrator);
        $this->assertStringContainsString('addedByDependencySince($base)', $orchestrator);
    }

    /**
     * 점검기가 있고 쓸 수 있을 때만 다시 만들기를 고를 수 있다고 알린다.
     *
     * @scenario actor=with_permission, run_state=idle, rebuild_search_index=on
     *
     * @effects the_rebuild_search_index_choice_follows_engine_maintenance_support
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function search_index_rebuild_hint_follows_maintainer_availability(): void
    {
        $cases = [
            'no_maintainer' => [false, null, false],
            'unavailable' => [true, '엔진에 연결할 수 없습니다', false],
            'available' => [true, null, true],
        ];

        foreach ($cases as $label => [$hasMaintainer, $reason, $expected]) {
            $manager = Mockery::mock(SearchIndexMaintenanceManager::class);
            $manager->shouldReceive('hasMaintainer')->andReturn($hasMaintainer);
            $manager->shouldReceive('unavailableReason')->andReturn($reason);
            $this->app->instance(SearchIndexMaintenanceManager::class, $manager);

            $this->assertSame($expected, $this->invokeAvailable(), $label);
        }
    }

    /**
     * 판정 자체가 실패하면 선택지를 숨긴다(착수는 막지 않는다).
     *
     * @scenario actor=with_permission, run_state=idle, rebuild_search_index=off
     *
     * @effects the_rebuild_search_index_choice_follows_engine_maintenance_support
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function search_index_rebuild_hint_is_false_when_the_check_throws(): void
    {
        $manager = Mockery::mock(SearchIndexMaintenanceManager::class);
        $manager->shouldReceive('hasMaintainer')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(SearchIndexMaintenanceManager::class, $manager);

        $this->assertFalse($this->invokeAvailable());
    }

    /**
     * 상태 응답 힌트는 착수 전(run=null)에만 실린다 — 화면 조건과 짝인 자리.
     *
     * @scenario actor=with_permission, run_state=idle
     *
     * @effects the_rebuild_search_index_choice_follows_engine_maintenance_support
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function search_index_hint_is_attached_in_with_screen_hints(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Api/Admin/CoreUpdateController.php'));

        $this->assertStringContainsString("\$status['search_index_rebuild_available'] = \$this->searchIndexRebuildAvailable();", $source);
    }

    /**
     * 컨트롤러의 판정 메서드를 생성자 없이 부른다.
     *
     * @return bool 판정 결과
     */
    private function invokeAvailable(): bool
    {
        $controller = (new \ReflectionClass(CoreUpdateController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CoreUpdateController::class, 'searchIndexRebuildAvailable');

        return (bool) $method->invoke($controller);
    }
}
