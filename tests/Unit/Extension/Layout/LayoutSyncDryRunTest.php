<?php

namespace Tests\Unit\Extension\Layout;

use App\Enums\LayoutSyncMode;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\TestCase;

/**
 * 레이아웃 갱신 미리보기(dry-run)는 판정만 계산하고 DB 를 바꾸지 않으며, 같은 입력의 실제 갱신과 판정 목록이 같다.
 *
 * 준비: 운영자가 조각 A 를 고친 화면(병합 대상) + 새 판이 조각 B 를 바꿈 + 새 판에서 빠진 화면(삭제 후보) + 운영자가
 * 고친 확장 조각 A + 새 판이 바꾼 확장 조각 B.
 */
class LayoutSyncDryRunTest extends TestCase
{
    use BuildsLayoutSyncScenario;
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
     * 출처 3 × 전략 2.
     *
     * @return array<string, array{0: string, 1: LayoutSyncMode}> [출처, 전략]
     */
    public static function sourcesAndModes(): array
    {
        $cells = [];
        foreach (['template', 'module', 'plugin'] as $source) {
            foreach ([LayoutSyncMode::Keep, LayoutSyncMode::Overwrite] as $mode) {
                $cells["{$source}/{$mode->value}"] = [$source, $mode];
            }
        }

        return $cells;
    }

    /**
     * 미리보기를 부릅니다.
     *
     * @param  string  $source  출처
     * @param  LayoutSyncMode  $mode  전략
     * @param  array<string, string>  $decisions  화면별 결정
     * @return array<string, mixed> 미리보기
     */
    private function preview(string $source, LayoutSyncMode $mode, array $decisions = []): array
    {
        $id = $this->scenarioIdentifier($source);

        return match ($source) {
            'template' => app(TemplateService::class)->previewTemplateLayoutRefresh($id, $mode, $decisions),
            'module' => app(ModuleService::class)->previewModuleLayoutRefresh($id, $mode, $decisions),
            'plugin' => app(PluginService::class)->previewPluginLayoutRefresh($id, $mode, $decisions),
        };
    }

    /**
     * 실제 갱신을 부르고 판정 목록을 돌려줍니다.
     *
     * @param  string  $source  출처
     * @param  LayoutSyncMode  $mode  전략
     * @param  array<string, string>  $decisions  화면별 결정
     * @return array<string, mixed> 갱신 결과의 판정 목록
     */
    private function refresh(string $source, LayoutSyncMode $mode, array $decisions = []): array
    {
        $id = $this->scenarioIdentifier($source);

        $result = match ($source) {
            'template' => app(TemplateService::class)->refreshTemplateLayouts($id, $mode, $decisions),
            'module' => app(ModuleService::class)->refreshModuleLayouts($id, $mode, $decisions),
            'plugin' => app(PluginService::class)->refreshPluginLayouts($id, $mode, $decisions),
        };

        return $result['layout_preview'];
    }

    /**
     * 판정 매트릭스가 나오는 상태(병합·새 판 반영·삭제 후보·조각 병합)를 만듭니다.
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    private function prepare(string $source): void
    {
        $this->installScenario($source);
        $this->editScenarioPartA($source);
        $this->editScenarioFragmentA($source);
        $this->writeScenarioVersion($source, ['b' => 'B2', 'gone' => false, 'fb' => 'FB2']);
    }

    // @scenario source=module, entry=service, strategy=default
    // @scenario source=plugin, entry=service, strategy=default

    /**
     * 미리보기는 레이아웃·조각·버전 행을 바꾸지 않고 쓰기 쿼리를 하나도 내지 않는다.
     *
     * @scenario source=template, entry=service, strategy=default
     *
     * @effects dry_run_writes_nothing
     *
     * @param  string  $source  출처
     * @param  LayoutSyncMode  $mode  전략
     * @return void 반환값 없음
     */
    #[DataProvider('sourcesAndModes')]
    public function test_dry_run_leaves_database_untouched(string $source, LayoutSyncMode $mode): void
    {
        $this->prepare($source);
        $before = $this->scenarioChecksum();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $preview = $this->preview($source, $mode);

        $this->assertSame([], $writes, '미리보기가 쓰기 쿼리를 냈다');
        $this->assertSame($before, $this->scenarioChecksum(), '미리보기 뒤 레이아웃·조각·버전 체크섬이 바뀌었다');
        $this->assertNotEmpty($preview['entries']);
        $this->assertContains('deleted', array_column($preview['entries'], 'verdict'), '새 판에서 빠진 화면은 삭제 예정으로 보여야 한다');
        $this->assertTrue(collect($preview['entries'])->firstWhere('verdict', 'deleted')['will_delete']);
    }

    // @scenario source=module, entry=service, strategy=overwrite
    // @scenario source=plugin, entry=service, strategy=overwrite

    /**
     * 같은 입력이면 미리보기와 실제 갱신의 판정 목록이 같다.
     *
     * @scenario source=template, entry=service, strategy=overwrite
     *
     * @effects dry_run_matches_real_run
     *
     * @param  string  $source  출처
     * @param  LayoutSyncMode  $mode  전략
     * @return void 반환값 없음
     */
    #[DataProvider('sourcesAndModes')]
    public function test_preview_entries_match_real_refresh(string $source, LayoutSyncMode $mode): void
    {
        $this->prepare($source);

        $preview = $this->preview($source, $mode);
        $real = $this->refresh($source, $mode);

        $this->assertSame($preview['entries'], $real['entries'], '미리보기와 실제 갱신의 판정이 갈렸다');
        $this->assertSame($preview['summary'], $real['summary']);

        if ($mode === LayoutSyncMode::Keep) {
            $verdicts = array_column($preview['entries'], 'verdict', 'key');
            $this->assertSame('merged', $verdicts['layout:'.$this->fixtureTemplate.':'.$this->scenarioLayoutName($source)] ?? null, '운영자가 고친 화면은 병합 판정이어야 한다');
        }
    }
}
