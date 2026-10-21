<?php

namespace Tests\Feature\Console;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\HookManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutExtensionVersion;
use App\Models\TemplateLayoutVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `layout:repair-empty-objects` — 이미 `[]` 로 굳은 빈 객체를 원본 기준으로 되돌린다 (#758).
 *
 * 기본은 미리보기(쓰기 없음)이고 `--apply` 를 붙여야 쓴다. 원본(`original_content`)이 `{}` 이고 저장본의
 * 같은 자리가 `[]` 인 곳만 고치며, 운영자가 바꾼 다른 값은 그대로 둔다. 스키마를 새로 만들지 않는다
 * (DatabaseTransactions) — 테스트 DB 의 기존 행과 공존하므로 `--template` 으로 이 테스트의 템플릿만 본다.
 */
class LayoutRepairEmptyObjectsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private Template $template;

    private TemplateLayout $layout;

    private LayoutExtension $extension;

    private TemplateLayoutVersion $layoutVersion;

    private TemplateLayoutExtensionVersion $extensionVersion;

    /**
     * 원본은 `{}`, 저장본은 `[]` 로 굳은 레이아웃·확장·버전 행을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::factory()->create(['type' => 'user', 'status' => ExtensionStatus::Active->value]);

        $this->layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'repair_probe',
            'lock_version' => 3,
            'content' => ['layout_name' => 'repair_probe'],
        ]);
        $this->setRaw('template_layouts', $this->layout->id, [
            'original_content' => '{"layout_name":"repair_probe","initLocal":{"filters":{},"items":[]},"components":[{"id":"root","props":{"text":"원본"},"actions":[{"params":{"query":{}}}]}]}',
            'content' => '{"layout_name":"repair_probe","initLocal":{"filters":[],"items":[]},"components":[{"id":"added","props":{"text":"운영자 추가"}},{"id":"root","props":{"text":"운영자 수정"},"actions":[{"params":{"query":[]}}]}]}',
        ]);

        $this->extension = LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::Overlay,
            'target_name' => 'repair_probe',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-probe',
            'lock_version' => 0,
            'content' => ['target_layout' => 'repair_probe'],
        ]);
        $this->setRaw('template_layout_extensions', $this->extension->id, [
            'original_content' => '{"target_layout":"repair_probe","data_sources":[{"id":"r","fallback":{"data":{"results":{}}}}]}',
            'content' => '{"target_layout":"repair_probe","data_sources":[{"id":"r","fallback":{"data":{"results":[]}}}]}',
        ]);

        $this->layoutVersion = TemplateLayoutVersion::create([
            'layout_id' => $this->layout->id,
            'version' => 1,
            'content' => ['layout_name' => 'repair_probe'],
            'changes_summary' => ['added' => 0, 'removed' => 0, 'char_diff' => 0],
        ]);
        $this->setRaw('template_layout_versions', $this->layoutVersion->id, [
            'content' => '{"layout_name":"repair_probe","initLocal":{"filters":[],"items":[]}}',
        ]);

        $this->extensionVersion = TemplateLayoutExtensionVersion::create([
            'extension_id' => $this->extension->id,
            'version' => 1,
            'content' => ['target_layout' => 'repair_probe'],
            'changes_summary' => ['added' => 0, 'removed' => 0, 'char_diff' => 0],
        ]);
        $this->setRaw('template_layout_extension_versions', $this->extensionVersion->id, [
            'content' => '{"target_layout":"repair_probe","data_sources":[{"id":"r","fallback":{"data":{"results":[]}}}]}',
        ]);
    }

    /**
     * 미리보기는 고칠 자리를 표로 보여 주기만 하고 아무 행도 바꾸지 않는다.
     *
     * @scenario path=repair
     *
     * @effects repair_preview_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_preview_reports_without_writing(): void
    {
        $before = $this->snapshot();

        $this->artisan('layout:repair-empty-objects', ['--template' => $this->template->identifier])
            ->expectsOutputToContain('initLocal.filters')
            ->expectsOutputToContain('data_sources[r].fallback.data.results')
            ->expectsOutputToContain('--apply')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot(), '미리보기가 행을 바꿨다');
    }

    /**
     * `--apply` 는 원본 `{}` 자리만 고치고(id 짝맞춤 포함) 운영자가 바꾼 값은 보존한다. 본 행만 lock_version 이 오른다.
     *
     * @scenario path=repair
     *
     * @effects repair_fixes_only_reference_empty_object_positions, repair_preserves_operator_edits, repair_matches_nodes_by_id, repair_covers_version_tables, repair_bumps_lock_version
     *
     * @return void 반환값 없음
     */
    public function test_apply_fixes_only_reference_empty_object_positions(): void
    {
        $this->artisan('layout:repair-empty-objects', ['--template' => $this->template->identifier, '--apply' => true])
            ->assertSuccessful();

        $layout = DB::table('template_layouts')->where('id', $this->layout->id)->first();
        $this->assertSame(
            ['layout_name' => 'repair_probe', 'initLocal' => ['filters' => '{}', 'items' => '[]'], 'components' => [
                ['id' => 'added', 'props' => ['text' => '운영자 추가']],
                ['id' => 'root', 'props' => ['text' => '운영자 수정'], 'actions' => [['params' => ['query' => '{}']]]],
            ]],
            $this->shape(json_decode($layout->content))
        );
        $this->assertSame(4, (int) $layout->lock_version, '본 행 lock_version +1');

        $extension = DB::table('template_layout_extensions')->where('id', $this->extension->id)->first();
        $this->assertStringContainsString('"results":{}', $extension->content);
        $this->assertSame(1, (int) $extension->lock_version);

        $this->assertStringContainsString(
            '"filters":{}',
            DB::table('template_layout_versions')->where('id', $this->layoutVersion->id)->value('content'),
            '레이아웃 버전 행은 소속 레이아웃 원본 기준으로 고친다'
        );
        $this->assertStringContainsString(
            '"results":{}',
            DB::table('template_layout_extension_versions')->where('id', $this->extensionVersion->id)->value('content'),
            '확장 버전 행은 소속 확장 원본 기준으로 고친다'
        );
        $this->assertSame(1, TemplateLayoutVersion::where('layout_id', $this->layout->id)->count(), '새 버전 행을 만들지 않는다');
    }

    /**
     * 다시 돌리면 고칠 자리가 0 이고, 적용 때 레이아웃 캐시를 비운다.
     *
     * @scenario path=repair
     *
     * @effects repair_is_idempotent, repair_clears_layout_cache
     *
     * @return void 반환값 없음
     */
    public function test_apply_is_idempotent_and_clears_cache(): void
    {
        $cleared = [];
        $listener = function (int $templateId, string $name) use (&$cleared): void {
            $cleared[] = $templateId.':'.$name;
        };
        HookManager::addAction('core.layout.before_cache_clear', $listener);

        try {
            $this->artisan('layout:repair-empty-objects', ['--template' => $this->template->identifier, '--apply' => true])
                ->assertSuccessful();
        } finally {
            HookManager::removeAction('core.layout.before_cache_clear', $listener);
        }

        $this->assertContains($this->template->id.':repair_probe', $cleared, '적용 뒤 대상 레이아웃 캐시를 비운다');

        $after = $this->snapshot();

        $this->artisan('layout:repair-empty-objects', ['--template' => $this->template->identifier, '--apply' => true])
            ->expectsOutputToContain(' 0')
            ->assertSuccessful();

        $this->assertSame($after, $this->snapshot(), '두 번째 적용이 행을 바꿨다');
    }

    /**
     * 삭제된 행(레이아웃·확장과 그 버전 행)과 원본이 없는 행은 건너뛰고 손대지 않으며, 모두 「기준 없음」 건수로 보고한다.
     *
     * @scenario path=repair
     *
     * @effects repair_skips_deleted_and_unreferenced_rows
     *
     * @return void 반환값 없음
     */
    public function test_skips_deleted_and_reference_less_rows(): void
    {
        $orphan = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'repair_no_reference',
            'content' => ['layout_name' => 'repair_no_reference'],
        ]);
        $this->setRaw('template_layouts', $orphan->id, [
            'original_content' => null,
            'content' => '{"layout_name":"repair_no_reference","initLocal":{"filters":[]}}',
        ]);
        DB::table('template_layouts')->where('id', $this->layout->id)->update(['deleted_at' => now()]);
        DB::table('template_layout_extensions')->where('id', $this->extension->id)->update(['deleted_at' => now()]);
        $before = $this->snapshot();

        // 원본 없음 1 + 삭제된 레이아웃 1·그 버전 1 + 삭제된 확장 1·그 버전 1
        $this->artisan('layout:repair-empty-objects', ['--template' => $this->template->identifier, '--apply' => true])
            ->expectsOutputToContain(__('layouts.repair_empty_objects.skipped', ['count' => 5]))
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot(), '건너뛴 행을 바꿨다');
        $this->assertStringContainsString(
            '"filters":[]',
            (string) DB::table('template_layouts')->where('id', $orphan->id)->value('content'),
            '원본 없는 행을 바꿨다'
        );
    }

    /**
     * 캐스트를 거치지 않고 행의 원문 컬럼을 씁니다.
     *
     * @param  string  $table  테이블 (접두사 제외)
     * @param  int  $id  행 ID
     * @param  array<string, string|null>  $columns  쓸 원문 컬럼
     * @return void 반환값 없음
     */
    private function setRaw(string $table, int $id, array $columns): void
    {
        DB::table($table)->where('id', $id)->update($columns);
    }

    /**
     * 이 테스트가 만든 네 행의 원문과 lock_version 을 모읍니다.
     *
     * @return array<string, mixed> 행별 원문 스냅숏
     */
    private function snapshot(): array
    {
        return [
            'layout' => (array) DB::table('template_layouts')->where('id', $this->layout->id)->first(['content', 'lock_version']),
            'extension' => (array) DB::table('template_layout_extensions')->where('id', $this->extension->id)->first(['content', 'lock_version']),
            'layout_version' => DB::table('template_layout_versions')->where('id', $this->layoutVersion->id)->value('content'),
            'extension_version' => DB::table('template_layout_extension_versions')->where('id', $this->extensionVersion->id)->value('content'),
        ];
    }

    /**
     * 객체 디코드 결과를 비교용 배열로 바꿉니다 — 빈 객체는 `'{}'`, 빈 배열은 `'[]'` 문자열로 표시합니다.
     *
     * @param  mixed  $node  json_decode(객체 모드) 결과 노드
     * @return mixed 비교용 값
     */
    private function shape(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            $vars = get_object_vars($node);

            return $vars === [] ? '{}' : array_map(fn ($v) => $this->shape($v), $vars);
        }

        if (is_array($node)) {
            return $node === [] ? '[]' : array_map(fn ($v) => $this->shape($v), $node);
        }

        return $node;
    }
}
