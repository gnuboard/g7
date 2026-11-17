<?php

namespace Tests\Unit\Extension\Layout;

use App\Enums\LayoutSyncMode;
use App\Extension\Layout\LayoutSyncOptions;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\TestCase;

/**
 * 화면별 결정 — `take-theirs` 는 그 화면만 새 판으로 덮고(운영자 판은 버전 이력에), `keep-ours` 는 운영자 판을 통째로 둔다.
 */
class LayoutSyncDecisionsTest extends TestCase
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
     * keep 갱신을 결정과 함께 부릅니다.
     *
     * @param  string  $source  출처
     * @param  array<string, string>  $decisions  화면별 결정
     * @return array<string, mixed> 갱신 결과의 판정 목록
     */
    private function refreshWith(string $source, array $decisions): array
    {
        $id = $this->scenarioIdentifier($source);

        $result = match ($source) {
            'template' => app(TemplateService::class)->refreshTemplateLayouts($id, LayoutSyncMode::Keep, $decisions),
            'module' => app(ModuleService::class)->refreshModuleLayouts($id, LayoutSyncMode::Keep, $decisions),
            'plugin' => app(PluginService::class)->refreshPluginLayouts($id, LayoutSyncMode::Keep, $decisions),
        };

        return $result['layout_preview'];
    }

    /**
     * 조각 결정 키.
     *
     * @param  string  $source  출처
     * @return string 키
     */
    private function fragmentKey(string $source): string
    {
        return LayoutSyncOptions::extensionKey($this->fixtureTemplate, $this->scenarioIdentifier($source), 'frag_a');
    }

    // @scenario source=module, entry=service, strategy=default
    // @scenario source=plugin, entry=service, strategy=default

    /**
     * take-theirs: 운영자가 고친 화면·조각을 새 판으로 덮고, 덮기 직전 운영자 판을 버전으로 남긴다.
     *
     * @scenario source=template, entry=service, strategy=default
     *
     * @effects decision_take_theirs_overwrites_one_screen, overwrite_records_operator_version
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_take_theirs_overwrites_only_that_screen(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);
        $fragment = $this->editScenarioFragmentA($source);
        $this->writeScenarioVersion($source, ['b' => 'B2']);
        $versionsBefore = TemplateLayoutVersion::where('layout_id', $row->id)->count();
        $fragmentVersionsBefore = TemplateLayoutExtensionVersion::where('extension_id', $fragment->id)->count();

        $preview = $this->refreshWith($source, [
            LayoutSyncOptions::layoutKey($this->fixtureTemplate, $this->scenarioLayoutName($source)) => LayoutSyncOptions::TAKE_THEIRS,
            $this->fragmentKey($source) => LayoutSyncOptions::TAKE_THEIRS,
        ]);

        $this->assertSame('A1', $this->scenarioPartAText($row), 'take-theirs 화면은 새 판이어야 한다');
        $this->assertStringContainsString('FA1', $this->flat($fragment->fresh()->content), 'take-theirs 조각은 새 판이어야 한다');

        $versions = TemplateLayoutVersion::where('layout_id', $row->id)->orderByDesc('version')->get();
        $this->assertSame($versionsBefore + 1, $versions->count(), '덮기 직전 운영자 판이 버전 하나로 남아야 한다');
        $this->assertSame('decision_take_theirs', $versions->first()->changes_summary['source'] ?? null);
        $this->assertStringContainsString('운영자 A', $this->flat($versions->first()->content));
        $this->assertSame($fragmentVersionsBefore + 1, TemplateLayoutExtensionVersion::where('extension_id', $fragment->id)->count());

        $verdicts = array_column($preview['entries'], 'verdict', 'key');
        $this->assertSame('updated', $verdicts[LayoutSyncOptions::layoutKey($this->fixtureTemplate, $this->scenarioLayoutName($source))]);
    }

    // @scenario source=module, entry=service, strategy=default
    // @scenario source=plugin, entry=service, strategy=default

    /**
     * keep-ours: 새 판이 바꾼 다른 조각도 반영하지 않고 운영자 판을 통째로 둔다 (`kept`).
     *
     * @scenario source=template, entry=service, strategy=default
     *
     * @effects decision_keep_ours_keeps_whole_screen
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_keep_ours_keeps_operator_version_whole(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);
        $this->writeScenarioVersion($source, ['b' => 'B2']);
        $key = LayoutSyncOptions::layoutKey($this->fixtureTemplate, $this->scenarioLayoutName($source));

        $preview = $this->refreshWith($source, [$key => LayoutSyncOptions::KEEP_OURS]);

        $content = $this->flat(json_decode((string) $row->fresh()->getRawOriginal('content'), true));
        $this->assertStringContainsString('운영자 A', $content);
        $this->assertStringNotContainsString('B2', $content, 'keep-ours 는 새 판 변경도 반영하지 않는다');
        $this->assertSame('kept', array_column($preview['entries'], 'verdict', 'key')[$key]);
    }

    // @scenario source=module, entry=service, strategy=invalid
    // @scenario source=plugin, entry=service, strategy=invalid

    /**
     * 결정 값이 잘못되면 예외 — 조용히 무시하지 않는다.
     *
     * @scenario source=template, entry=service, strategy=invalid
     *
     * @effects refresh_invalid_strategy_fails
     *
     * @return void 반환값 없음
     */
    public function test_invalid_decision_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LayoutSyncOptions(false, ['layout:a:b' => 'theirs']);
    }
}
