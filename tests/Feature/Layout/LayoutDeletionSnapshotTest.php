<?php

namespace Tests\Feature\Layout;

use App\Enums\LayoutSyncMode;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\TemplateManager;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\LayoutExtension;
use App\Models\TemplateCustomTranslation;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutAttachment;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use App\Services\Layout\LayoutDeletionService;
use App\Services\Layout\TemplateChildrenDeleter;
use App\Services\LayoutFileService;
use App\Services\TemplateLayoutAttachmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\TestCase;

/**
 * 레이아웃·조각 영구 삭제 직전 기록과 버전 명시 삭제.
 *
 * 운영자가 고친(또는 만든) 행을 영구 삭제하는 경로 — 확장 제거·덮어쓰기 갱신에서 새 판에 없는 화면·템플릿 제거·설정 화면 정리 —
 * 는 지우기 직전 활동 로그(`layout.force_deleted`·`layout_extension.force_deleted`)에 스냅샷을 남기고, 버전 행을 명시적으로
 * 지운다. FK 를 끈 상태에서도 고아 버전이 0 이어야 한다 — 그래야 DB CASCADE 에 기대지 않는다는 뜻이다.
 */
class LayoutDeletionSnapshotTest extends TestCase
{
    use BuildsLayoutSyncScenario;
    use DatabaseTransactions;

    /**
     * FK 를 켜고 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Schema::enableForeignKeyConstraints();
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 부모가 없는 버전 행 수 (레이아웃 + 조각).
     *
     * @return int 고아 버전 수
     */
    private function orphanVersions(): int
    {
        $layouts = TemplateLayoutVersion::whereNotIn('layout_id', TemplateLayout::withTrashed()->select('id'))->count();
        $fragments = TemplateLayoutExtensionVersion::whereNotIn('extension_id', LayoutExtension::withTrashed()->select('id'))->count();

        return $layouts + $fragments;
    }

    /**
     * 행에 버전 하나를 남깁니다 (편집기 저장처럼).
     *
     * @param  TemplateLayout|LayoutExtension  $row  행
     * @return void 반환값 없음
     */
    private function addVersion(TemplateLayout|LayoutExtension $row): void
    {
        if ($row instanceof TemplateLayout) {
            TemplateLayoutVersion::create(['layout_id' => $row->id, 'version' => 1, 'content' => $row->content, 'changes_summary' => []]);

            return;
        }

        TemplateLayoutExtensionVersion::create(['extension_id' => $row->id, 'version' => 1, 'content' => $row->content, 'changes_summary' => []]);
    }

    /**
     * 모듈 제거: 고친 화면·조각은 스냅샷 1건씩, 안 고친 행은 0건, FK 를 꺼도 고아 버전 0.
     *
     * @scenario case=uninstall__modified
     *
     * @effects deletion_snapshot_logged_for_operator_data, versions_deleted_explicitly
     *
     * @return void 반환값 없음
     */
    public function test_module_uninstall_snapshots_operator_rows_and_deletes_versions(): void
    {
        $this->installScenario('module');
        $row = $this->editScenarioPartA('module');
        $fragment = $this->editScenarioFragmentA('module');
        $this->addVersion($row);
        $this->addVersion($fragment);
        $this->addVersion($this->scenarioLayout('module', 'merge_gone'));
        $logsBefore = ActivityLog::whereIn('action', ['layout.force_deleted', 'layout_extension.force_deleted'])->count();

        Schema::disableForeignKeyConstraints();
        $manager = app(ModuleManager::class);
        (new ReflectionMethod($manager, 'deleteModuleLayouts'))->invoke($manager, $this->fixtureModule);
        (new ReflectionMethod($manager, 'deleteLayoutExtensions'))->invoke($manager, $manager->getModule($this->fixtureModule));
        Schema::enableForeignKeyConstraints();

        $this->assertNull($this->scenarioLayout('module'));
        $this->assertSame(0, $this->orphanVersions(), 'FK 를 꺼도 고아 버전이 없어야 한다 — 명시 삭제');

        $logs = ActivityLog::whereIn('action', ['layout.force_deleted', 'layout_extension.force_deleted'])->orderBy('id')->get()->slice($logsBefore)->values();
        $this->assertSame(['layout.force_deleted', 'layout_extension.force_deleted'], $logs->pluck('action')->all(), '고친 화면·조각만 기록한다(안 고친 merge_gone·frag_b 는 기록 없음)');
        $this->assertSame('uninstall', $logs[0]->properties['reason']);
        $this->assertSame('uninstall', $logs[0]->description_params['reason'], '설명 치환값에도 사유 코드를 저장한다(번역은 표시 시점)');
        $this->assertSame('uninstall', $logs[1]->description_params['reason']);
        $this->assertStringContainsString('운영자 A', $this->flat($logs[0]->properties['content']));
        $this->assertCount(1, $logs[0]->properties['versions']);
        $this->assertFalse($logs[0]->properties['versions_truncated']);
        $this->assertStringContainsString('운영자 조각', $this->flat($logs[1]->properties['content']));
        $this->assertCount(1, $logs[1]->properties['versions']);
        $this->assertNotNull($logs[1]->properties['versions'][0]['content'], '조각 버전 스냅샷도 본문을 싣는다');
    }

    /**
     * 스냅샷 버전은 상한까지만 싣고, 상한을 넘으면 넘었다고 표시한다 (상한 + 1 건만 읽는다).
     *
     * @scenario case=uninstall__modified
     *
     * @effects deletion_snapshot_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
    public function test_snapshot_versions_are_capped(): void
    {
        $this->installScenario('template');
        $row = $this->editScenarioPartA('template');
        $limit = LayoutDeletionService::MAX_SNAPSHOT_VERSIONS;
        for ($v = 1; $v <= $limit + 2; $v++) {
            TemplateLayoutVersion::create(['layout_id' => $row->id, 'version' => $v, 'content' => $row->content, 'changes_summary' => []]);
        }

        app(LayoutDeletionService::class)->forceDeleteLayout($row, 'uninstall', $this->fixtureTemplate);
        $log = ActivityLog::where('action', 'layout.force_deleted')->latest('id')->first();

        $this->assertCount($limit, $log->properties['versions']);
        $this->assertTrue($log->properties['versions_truncated']);
        $this->assertSame($limit + 2, $log->properties['versions'][0]['version'], '최신 버전부터 싣는다');
    }

    /**
     * 덮어쓰기 갱신에서 새 판에 없는 화면을 지울 때 운영자가 고친 화면이면 기록한다.
     *
     * @scenario case=refresh_overwrite_removed__modified
     *
     * @effects deletion_snapshot_logged_for_operator_data, versions_deleted_explicitly
     *
     * @return void 반환값 없음
     */
    public function test_overwrite_refresh_removal_snapshots_modified_screen(): void
    {
        $this->installScenario('template');
        $gone = $this->scenarioLayout('template', 'merge_gone');
        $this->operatorEdits($gone, fn (array $c) => array_merge($c, ['components' => [['id' => 'gone', 'type' => 'basic', 'name' => 'Div', 'text' => '운영자 G']]]));
        $this->addVersion($gone->fresh());
        $this->writeScenarioVersion('template', ['gone' => false]);

        Schema::disableForeignKeyConstraints();
        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, LayoutSyncMode::Overwrite);
        Schema::enableForeignKeyConstraints();

        $this->assertNull($this->scenarioLayout('template', 'merge_gone'));
        $this->assertSame(0, $this->orphanVersions());
        $log = ActivityLog::where('action', 'layout.force_deleted')->latest('id')->first();
        $this->assertSame('refresh_overwrite_removed', $log->properties['reason']);
        $this->assertSame('merge_gone', $log->properties['name']);
    }

    /**
     * 수정 안 된 행을 지우면 기록이 없다 (keep 갱신의 고아 정리·모듈 제거 공통).
     *
     * @scenario case=uninstall__unmodified
     *
     * @effects deletion_snapshot_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
    public function test_unmodified_row_deletion_is_not_logged(): void
    {
        $this->installScenario('template');
        $row = $this->scenarioLayout('template');
        $before = ActivityLog::where('action', 'layout.force_deleted')->count();

        app(LayoutDeletionService::class)->forceDeleteLayout($row, 'uninstall', $this->fixtureTemplate);

        $this->assertSame($before, ActivityLog::where('action', 'layout.force_deleted')->count());
        $this->assertNull(TemplateLayout::withTrashed()->find($row->id));
    }

    /**
     * 템플릿 제거: 모든 조각(다른 출처 포함)·커스텀 번역(기록)·첨부(파일 삭제 서비스 경유)·미리보기·레이아웃을 명시 삭제한다.
     *
     * @scenario case=template_uninstall__modified
     *
     * @effects template_children_deleted_explicitly, deletion_snapshot_logged_for_operator_data
     *
     * @return void 반환값 없음
     */
    public function test_template_uninstall_deletes_children_explicitly(): void
    {
        $this->installScenario('module');
        $this->editScenarioFragmentA('module');
        $templateId = $this->fixtureTemplateModel->id;
        TemplateCustomTranslation::create(['template_id' => $templateId, 'layout_name' => 'merge_list', 'translation_key' => 'custom.merge_list.t1', 'values' => ['ko' => '운영자 번역'], 'status' => 'active', 'lock_version' => 1]);
        $attachment = TemplateLayoutAttachment::create(['template_id' => $templateId, 'layout_name' => 'merge_list', 'disk' => 'local', 'path' => 'x/banner.png', 'original_name' => 'banner.png', 'mime_type' => 'image/png', 'size' => 10]);

        $attachments = Mockery::mock(TemplateLayoutAttachmentService::class);
        $attachments->shouldReceive('delete')->once()->withArgs(fn ($row) => $row->id === $attachment->id)->andReturnUsing(fn ($row) => (bool) $row->delete());
        $this->app->instance(TemplateLayoutAttachmentService::class, $attachments);

        Schema::disableForeignKeyConstraints();
        $stats = app(TemplateChildrenDeleter::class)->deleteChildrenBeforeLayouts($templateId);
        app(LayoutDeletionService::class)->forceDeleteLayoutsByTemplate($templateId, 'template_uninstall');
        Schema::enableForeignKeyConstraints();

        $this->assertGreaterThanOrEqual(2, $stats['extensions'], '다른 출처(모듈) 조각까지 지운다');
        $this->assertSame(1, $stats['custom_translations']);
        $this->assertSame(1, $stats['attachments']);
        $this->assertSame(0, LayoutExtension::withTrashed()->where('template_id', $templateId)->count());
        $this->assertSame(0, TemplateLayout::withTrashed()->where('template_id', $templateId)->count());
        $this->assertSame(0, TemplateCustomTranslation::where('template_id', $templateId)->count());
        $this->assertSame(0, $this->orphanVersions());
        $this->assertSame('custom.merge_list.t1', ActivityLog::where('action', 'template_custom_translation.deleted')->latest('id')->first()->properties['translation_key']);
        $this->assertSame('template_uninstall', ActivityLog::where('action', 'layout_extension.force_deleted')->latest('id')->first()->properties['reason']);
    }

    /**
     * 템플릿 설정 화면 파일이 새 판에서 빠져도 keep 이면 운영자가 고친 설정 화면 행은 남는다.
     *
     * @scenario case=settings_layout_removed__modified
     *
     * @effects settings_layout_kept_when_modified
     *
     * @return void 반환값 없음
     */
    public function test_settings_layout_removal_keeps_modified_row_in_keep(): void
    {
        $this->installScenario('template');
        $row = TemplateLayout::create([
            'template_id' => $this->fixtureTemplateModel->id,
            'name' => TemplateLayout::templateSettingsLayoutName('testmerge-owner'),
            'content' => ['components' => [['id' => 's', 'type' => 'basic', 'name' => 'Div', 'text' => '운영자 설정']]],
            'source_type' => 'template',
            'source_identifier' => 'testmerge-owner',
            'original_content' => json_encode(['components' => [['id' => 's', 'type' => 'basic', 'name' => 'Div', 'text' => '원본']]]),
        ]);

        $manager = app(TemplateManager::class);
        $sync = new ReflectionMethod($manager, 'syncSettingsLayout');
        $sync->invoke($manager, 'testmerge-owner', LayoutSyncMode::Keep, null);
        $this->assertNotNull(TemplateLayout::find($row->id), 'keep 이면 운영자가 고친 설정 화면은 남는다');

        $sync->invoke($manager, 'testmerge-owner', LayoutSyncMode::Overwrite, null);
        $this->assertNull(TemplateLayout::withTrashed()->find($row->id), 'overwrite 면 지운다');
        $this->assertSame('settings_layout_removed', ActivityLog::where('action', 'layout.force_deleted')->latest('id')->first()->properties['reason']);
    }

    /**
     * 편집기에서 지운 이름으로 새 화면을 만들면(지운 행 되살리기) 원본 본문도 비워 「파일 출신」으로 읽히지 않는다 (W0-3).
     *
     * @scenario case=orphan_override__operator_created
     *
     * @effects restored_row_is_not_file_origin
     *
     * @return void 반환값 없음
     */
    public function test_restored_editor_layout_is_not_file_origin(): void
    {
        $this->installScenario('template');
        $row = $this->scenarioLayout('template');
        $this->assertNotNull($row->getRawOriginal('original_content'));
        app(LayoutFileService::class)->delete($this->fixtureTemplateModel->id, $row->name, true);

        $created = app(LayoutFileService::class)->create($this->fixtureTemplateModel->id, $this->fixtureTemplate, $row->name, ['components' => [['id' => 'n', 'type' => 'basic', 'name' => 'Div']]]);

        $this->assertSame($row->id, $created->id, '지운 행을 되살려 쓴다');
        $fresh = TemplateLayout::find($created->id);
        $this->assertNull($fresh->getRawOriginal('original_content'), '되살린 행의 원본 본문이 남으면 고아 정리가 이 화면을 지운다');
        $this->assertFalse(app(LayoutRowSynchronizer::class)->hasFileOrigin($fresh));
    }

    /**
     * 활동 로그 응답이 삭제 직전 기록을 화면용 요약(대상·사유·본문 미리보기·버전 수)으로 싣고 줄을 펼칠 수 있게 한다.
     *
     * @scenario case=uninstall__modified
     *
     * @effects activity_log_shows_deletion_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_resource_exposes_snapshot_summary(): void
    {
        $this->installScenario('template');
        $row = $this->editScenarioPartA('template');
        app(LayoutDeletionService::class)->forceDeleteLayout($row, 'uninstall', $this->fixtureTemplate);
        $log = ActivityLog::where('action', 'layout.force_deleted')->latest('id')->first();

        $data = (new ActivityLogResource($log))->resolve(request());

        $this->assertTrue($data['has_details']);
        $this->assertSame('merge_list', $data['snapshot']['target']);
        $this->assertSame('uninstall', $data['snapshot']['reason']);
        $this->assertSame(__('activity_log.deletion_reason.uninstall'), $data['snapshot']['reason_label']);
        $this->assertStringContainsString('운영자 A', $data['snapshot']['content_preview']);
        $this->assertFalse($data['snapshot']['content_truncated']);
        $this->assertSame(__('activity_log.action.force_deleted'), $data['action_label']);
        $this->assertStringContainsString(__('activity_log.deletion_reason.uninstall'), $data['localized_description'], '저장된 사유 코드는 표시할 때 번역한다');
    }

    /**
     * 설명의 삭제 사유는 보는 사람의 언어로 번역되고, 코드 저장 전에 번역문으로 남은 행은 저장값을 그대로 보인다.
     *
     * @scenario case=template_uninstall__modified
     *
     * @effects activity_log_shows_deletion_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_deletion_reason_in_description_is_translated_at_display_time(): void
    {
        $this->installScenario('template');
        $row = $this->editScenarioPartA('template');
        app(LayoutDeletionService::class)->forceDeleteLayout($row, 'template_uninstall', $this->fixtureTemplate);
        $log = ActivityLog::where('action', 'layout.force_deleted')->latest('id')->first();

        $this->assertSame('template_uninstall', $log->description_params['reason']);

        $previous = app()->getLocale();
        try {
            app()->setLocale('en');
            $this->assertStringContainsString(__('activity_log.deletion_reason.template_uninstall'), $log->localized_description);
            app()->setLocale('ko');
            $this->assertStringContainsString(__('activity_log.deletion_reason.template_uninstall'), $log->localized_description);
        } finally {
            app()->setLocale($previous);
        }

        // 사유를 번역문으로 저장하던 행 — 번역 키가 아니므로 저장값 그대로 보인다
        $legacy = $log->replicate();
        $legacy->description_params = ['name' => 'merge_list', 'template' => $this->fixtureTemplate, 'reason' => '템플릿 제거'];
        $legacy->save();

        $this->assertStringContainsString('템플릿 제거', $legacy->fresh()->localized_description);
    }
}
