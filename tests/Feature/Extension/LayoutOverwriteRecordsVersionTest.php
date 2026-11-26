<?php

namespace Tests\Feature\Extension;

use App\Enums\LayoutSyncMode;
use App\Extension\Layout\LayoutSyncOptions;
use App\Extension\ModuleManager;
use App\Extension\TemplateManager;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use App\Services\ModuleService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\Support\Concerns\GrantsExtensionAdminToken;
use Tests\TestCase;

/**
 * 운영자 판을 파일 판으로 덮기 직전에 그 판이 편집기 버전 이력에 남는다 — 레이아웃·확장 조각, 덮어쓰기 경로마다.
 *
 * 경로: 덮어쓰기 갱신(refresh) · 확장 업데이트 덮어쓰기(extension_update) · 화면별 「새 판 적용」(decision_take_theirs) ·
 * 운영자가 지운 템플릿 화면 되살려 덮기 · 운영자가 만든 같은 이름 화면 덮기. 남은 버전은 복원 API 로 되돌릴 수 있고,
 * 운영자가 고치지 않은 행은 버전이 늘지 않는다.
 */
class LayoutOverwriteRecordsVersionTest extends TestCase
{
    use BuildsLayoutSyncScenario;
    use DatabaseTransactions;
    use GrantsExtensionAdminToken;

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
     * 화면 행의 최신 버전.
     *
     * @param  TemplateLayout  $row  행
     * @return TemplateLayoutVersion|null 최신 버전
     */
    private function latestVersion(TemplateLayout $row): ?TemplateLayoutVersion
    {
        return TemplateLayoutVersion::where('layout_id', $row->id)->orderByDesc('version')->first();
    }

    /**
     * 덮어쓰기 경로 — [이름, 실행, 기대 출처].
     *
     * @return array<string, array{0: string, 1: string}> [경로, 기대 출처]
     */
    public static function overwritePaths(): array
    {
        return [
            'refresh_overwrite' => ['refresh_overwrite', 'refresh'],
            'extension_update_overwrite' => ['extension_update_overwrite', 'extension_update'],
            'decision_take_theirs' => ['decision_take_theirs', 'decision_take_theirs'],
        ];
    }

    /**
     * 경로대로 갱신을 실행합니다 (모듈 출처).
     *
     * @param  string  $path  경로
     * @return void 반환값 없음
     */
    private function runPath(string $path): void
    {
        $key = LayoutSyncOptions::layoutKey($this->fixtureTemplate, $this->scenarioLayoutName('module'));
        $fragmentKey = LayoutSyncOptions::extensionKey($this->fixtureTemplate, $this->fixtureModule, 'frag_a');

        match ($path) {
            'refresh_overwrite' => app(ModuleService::class)->refreshModuleLayouts($this->fixtureModule, LayoutSyncMode::Overwrite),
            // 확장 업데이트는 범위 밖(기본 출처 extension_update)에서 매니저를 덮어쓰기로 부른다
            'extension_update_overwrite' => app(ModuleManager::class)->refreshModuleLayouts($this->fixtureModule, LayoutSyncMode::Overwrite),
            'decision_take_theirs' => app(ModuleService::class)->refreshModuleLayouts($this->fixtureModule, LayoutSyncMode::Keep, [$key => 'take-theirs', $fragmentKey => 'take-theirs']),
        };
    }

    // @scenario source=module, entry=service, strategy=overwrite

    /**
     * 덮어쓰기 경로마다 레이아웃·조각의 운영자 판이 버전 +1 로 남고 요약에 출처가 적힌다.
     *
     * @scenario source=template, entry=service, strategy=overwrite
     *
     * @effects overwrite_records_operator_version
     *
     * @param  string  $path  경로
     * @param  string  $source  기대 출처
     * @return void 반환값 없음
     */
    #[DataProvider('overwritePaths')]
    public function test_overwrite_path_records_operator_version(string $path, string $source): void
    {
        $this->installScenario('module');
        $row = $this->editScenarioPartA('module');
        $fragment = $this->editScenarioFragmentA('module');
        $before = TemplateLayoutVersion::where('layout_id', $row->id)->count();
        $fragmentBefore = TemplateLayoutExtensionVersion::where('extension_id', $fragment->id)->count();

        $this->runPath($path);

        $this->assertSame('A1', $this->scenarioPartAText($row), '새 판으로 덮여야 한다');
        $this->assertSame($before + 1, TemplateLayoutVersion::where('layout_id', $row->id)->count());
        $version = $this->latestVersion($row);
        $this->assertSame($source, $version->changes_summary['source'] ?? null);
        $this->assertTrue($version->changes_summary['before_overwrite'] ?? false);
        $this->assertStringContainsString('운영자 A', $this->flat($version->content), '버전 본문은 덮기 직전 운영자 판이어야 한다');

        $this->assertSame($fragmentBefore + 1, TemplateLayoutExtensionVersion::where('extension_id', $fragment->id)->count());
        $fragmentVersion = TemplateLayoutExtensionVersion::where('extension_id', $fragment->id)->orderByDesc('version')->first();
        $this->assertStringContainsString('운영자 조각', $this->flat($fragmentVersion->content));
    }

    /**
     * 남은 운영자 판을 편집기 복원 API 로 되돌릴 수 있다.
     *
     * @scenario source=template, entry=api, strategy=overwrite
     *
     * @effects overwrite_records_operator_version
     *
     * @return void 반환값 없음
     */
    public function test_recorded_version_restores_operator_edit(): void
    {
        $this->installScenario('template');
        $row = $this->editScenarioPartA('template');

        app(TemplateService::class)->refreshTemplateLayouts($this->fixtureTemplate, LayoutSyncMode::Overwrite);
        $this->assertSame('A1', $this->scenarioPartAText($row));
        $version = $this->latestVersion($row);

        $token = $this->extensionAdminToken(['core.templates.layouts.edit', 'core.templates.read']);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/admin/templates/{$this->fixtureTemplate}/layouts/{$row->name}/versions/{$version->id}/restore")
            ->assertOk();

        $this->assertSame('운영자 A', $this->scenarioPartAText($row), '복원 뒤 운영자 판이어야 한다');
    }

    /**
     * 운영자가 지운 템플릿 화면을 덮어쓰기로 되살릴 때, 지우기 전 운영자 판이 버전으로 남는다.
     *
     * @scenario source=template, entry=service, strategy=overwrite
     *
     * @effects overwrite_records_operator_version
     *
     * @return void 반환값 없음
     */
    public function test_restoring_operator_deleted_screen_records_its_edit(): void
    {
        $this->installScenario('template');
        $row = $this->editScenarioPartA('template');
        $before = TemplateLayoutVersion::where('layout_id', $row->id)->count();
        $row->delete();

        app(TemplateService::class)->refreshTemplateLayouts($this->fixtureTemplate, LayoutSyncMode::Overwrite);

        $this->assertFalse($row->fresh()->trashed());
        $this->assertSame($before + 1, TemplateLayoutVersion::where('layout_id', $row->id)->count());
        $this->assertStringContainsString('운영자 A', $this->flat($this->latestVersion($row)->content));
    }

    /**
     * 운영자가 만든 같은 이름 화면(파일 출신 아님)을 덮어쓰기로 덮을 때 그 판이 버전으로 남는다.
     *
     * @scenario source=template, entry=service, strategy=overwrite
     *
     * @effects overwrite_records_operator_version
     *
     * @return void 반환값 없음
     */
    public function test_overwriting_operator_created_same_name_screen_records_it(): void
    {
        $this->installScenario('template');
        $row = $this->scenarioLayout('template');
        $row->forceFill(['original_content' => null, 'original_content_hash' => null])->saveQuietly();
        $this->operatorEdits($row->fresh(), fn (array $c) => ['version' => '1.0.0', 'layout_name' => 'merge_list', 'components' => [['id' => 'mine', 'type' => 'basic', 'name' => 'Div', 'text' => '운영자 화면']]]);
        $before = TemplateLayoutVersion::where('layout_id', $row->id)->count();

        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, LayoutSyncMode::Overwrite);

        $this->assertSame($before + 1, TemplateLayoutVersion::where('layout_id', $row->id)->count());
        $this->assertStringContainsString('운영자 화면', $this->flat($this->latestVersion($row)->content));
    }

    /**
     * 결정 없이 적용한 충돌 화면은 본문이 그대로라 버전이 늘지 않는다 — 갱신을 거듭해도 같은 내용 행이 쌓이지 않는다.
     *
     * 운영자가 고친 곳(조각 A)만 새 판도 바꾸면 겹치는 부분은 운영자 판을 두므로 병합 결과가 지금 본문과 같다.
     *
     * @scenario source=module, entry=service, strategy=keep
     *
     * @effects overwrite_records_operator_version
     *
     * @return void 반환값 없음
     */
    public function test_conflict_without_decision_keeps_body_and_adds_no_version(): void
    {
        $this->installScenario('module');
        $row = $this->editScenarioPartA('module');
        $fragment = $this->editScenarioFragmentA('module');
        $before = TemplateLayoutVersion::where('layout_id', $row->id)->count();
        $fragmentBefore = TemplateLayoutExtensionVersion::where('extension_id', $fragment->id)->count();
        $this->writeScenarioVersion('module', ['a' => 'A2', 'fa' => 'FA2']);

        app(ModuleService::class)->refreshModuleLayouts($this->fixtureModule, LayoutSyncMode::Keep);
        app(ModuleService::class)->refreshModuleLayouts($this->fixtureModule, LayoutSyncMode::Keep);

        $this->assertSame('운영자 A', $this->scenarioPartAText($row), '충돌한 곳은 운영자 판이어야 한다');
        $this->assertSame($before, TemplateLayoutVersion::where('layout_id', $row->id)->count(), '본문이 그대로면 화면 버전이 늘지 않아야 한다');
        $this->assertSame($fragmentBefore, TemplateLayoutExtensionVersion::where('extension_id', $fragment->id)->count(), '본문이 그대로면 조각 버전이 늘지 않아야 한다');
    }

    /**
     * 운영자가 고치지 않은 행은 덮어써도 버전이 늘지 않는다.
     *
     * @scenario source=template, entry=service, strategy=default
     *
     * @effects overwrite_records_operator_version
     *
     * @return void 반환값 없음
     */
    public function test_unmodified_row_gets_no_extra_version(): void
    {
        $this->installScenario('template');
        $row = $this->scenarioLayout('template');
        $before = TemplateLayoutVersion::where('layout_id', $row->id)->count();
        $this->writeScenarioVersion('template', ['a' => 'A9']);

        app(TemplateService::class)->refreshTemplateLayouts($this->fixtureTemplate, LayoutSyncMode::Overwrite);

        $this->assertSame('A9', $this->scenarioPartAText($row));
        $this->assertSame($before, TemplateLayoutVersion::where('layout_id', $row->id)->count());
    }
}
