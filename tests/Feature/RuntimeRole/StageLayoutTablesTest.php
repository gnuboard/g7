<?php

namespace Tests\Feature\RuntimeRole;

use App\Enums\LayoutSourceType;
use App\Exceptions\StageLayoutWriteException;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutPreview;
use App\Repositories\LayoutPreviewRepository;
use App\Repositories\StageLayoutTableRepository;
use App\Rules\ValidParentLayout;
use App\Services\Layout\StageLayoutTableService;
use App\Services\LayoutFileService;
use App\Services\LayoutResolverService;
use App\Services\LayoutService;
use App\Support\LayoutTable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 검수 서버(stage)의 레이아웃 테이블 — 검수 서버의 레이아웃 쓰기는 `stage_` 테이블로만 가고 운영 레이아웃은 그대로다.
 *
 * @scenario-manifest tests/scenarios/stage-layout-tables.yaml
 */
class StageLayoutTablesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 역할을 정합니다.
     *
     * @param  string  $role  실행 역할 (live|stage)
     * @return void 반환값 없음
     */
    private function role(string $role): void
    {
        config(['core.runtime_role' => $role]);
    }

    /**
     * 시험용 템플릿을 만듭니다.
     *
     * @return Template 템플릿
     */
    private function template(): Template
    {
        return Template::factory()->create(['identifier' => 'acme-stage-'.Str::random(6)]);
    }

    /**
     * 레이아웃 행을 만듭니다(현재 역할 테이블).
     *
     * @param  Template  $template  템플릿
     * @param  string  $name  이름
     * @return TemplateLayout 레이아웃
     */
    private function layout(Template $template, string $name): TemplateLayout
    {
        return TemplateLayout::factory()->create(['template_id' => $template->id, 'name' => $name]);
    }

    /**
     * stage 의 레이아웃 쓰기(갱신·편집 저장)는 스테이지 테이블로만 가고 운영 테이블 체크섬은 그대로다.
     *
     * @scenario case=stage_write
     *
     * @effects stage_layout_writes_stay_in_stage_tables
     *
     * @return void 반환값 없음
     */
    public function test_stage_writes_go_to_stage_tables_only(): void
    {
        $template = $this->template();
        $this->role('live');
        $live = $this->layout($template, 'acme/home');
        $before = app(StageLayoutTableService::class)->checksum('template_layouts', false);

        $this->role('stage');
        $stageRow = $this->layout($template, 'acme/stage_only');
        $stageRow->update(['content' => ['changed' => true]]);

        $this->assertSame(LayoutTable::stage('template_layouts'), $stageRow->getTable());
        $this->assertGreaterThanOrEqual(StageLayoutTableRepository::STAGE_ID_FLOOR, $stageRow->id, '스테이지 ID 는 10억부터');
        $this->assertSame($before, app(StageLayoutTableService::class)->checksum('template_layouts', false), '운영 테이블 무변경');
        $this->assertNull(TemplateLayout::query()->find($live->id), 'stage 는 운영 행을 읽지 않는다');

        $this->role('live');
        $this->assertSame(0, TemplateLayout::query()->where('name', 'acme/stage_only')->count(), 'live 회귀 — 스테이지 행은 보이지 않는다');
    }

    /**
     * 쓰기 방어 — 역할과 맞지 않는 테이블(live 가 스테이지 테이블)에 쓰려 하면 예외다. stage 는 운영 테이블 이름을 지정해도 스테이지로 간다.
     *
     * @scenario case=write_guard
     *
     * @effects stage_layout_writes_stay_in_stage_tables
     *
     * @return void 반환값 없음
     */
    public function test_writing_to_other_role_table_throws(): void
    {
        $this->role('stage');
        $routed = new TemplateLayout;
        $routed->setTable('template_layouts');
        $this->assertSame(LayoutTable::stage('template_layouts'), $routed->getTable(), 'stage 는 운영 이름을 지정해도 스테이지로 간다');

        $this->role('live');
        $row = new TemplateLayout(['template_id' => $this->template()->id, 'name' => 'acme/forced']);
        $row->setTable(LayoutTable::stage('template_layouts'));

        $this->expectException(StageLayoutWriteException::class);
        $row->save();
    }

    /**
     * resync — 스테이지 행이 운영 행과 같아지고(ID·본문·원본 해시), 그동안 운영 테이블에는 쓰지 않는다.
     *
     * @scenario case=resync
     *
     * @effects resync_copies_live_rows_without_writing_live
     *
     * @return void 반환값 없음
     */
    public function test_resync_copies_live_rows_without_writing_live(): void
    {
        $template = $this->template();
        $this->role('live');
        $live = $this->layout($template, 'acme/copied');

        $this->role('stage');
        $this->layout($template, 'acme/stage_edit');

        $liveWrites = 0;
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$liveWrites): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql) && preg_match('/(into|update|from)\s+[`"]?(\w*_)?template_layout/i', $q->sql, $m) && ! str_contains(strtolower($q->sql), 'stage_template_layout')) {
                $liveWrites++;
            }
        });

        $result = app(StageLayoutTableService::class)->resetFromLive();

        $this->assertTrue($result['checksum_match']);
        $this->assertSame(0, $liveWrites, 'resync 는 운영 테이블에 쓰지 않는다');
        $copied = TemplateLayout::query()->find($live->id);
        $this->assertNotNull($copied, '운영 행이 ID 그대로 복사된다');
        $this->assertSame(0, TemplateLayout::query()->where('name', 'acme/stage_edit')->count(), '검수 서버 편집본은 지워진다');
    }

    /**
     * 미리보기는 역할별 — stage 의 정리가 운영 미리보기를 지우지 않는다.
     *
     * @scenario case=preview
     *
     * @effects previews_are_scoped_by_runtime_role
     *
     * @return void 반환값 없음
     */
    public function test_stage_preview_cleanup_keeps_live_previews(): void
    {
        $template = $this->template();
        $repo = app(LayoutPreviewRepository::class);
        $data = ['template_id' => $template->id, 'layout_name' => 'acme/p', 'content' => [], 'admin_id' => 1, 'expires_at' => now()->addHour()];

        $this->role('live');
        $livePreview = $repo->create(['token' => (string) Str::uuid()] + $data);
        $this->role('stage');
        $stagePreview = $repo->create(['token' => (string) Str::uuid()] + $data);

        $repo->deleteByLayoutAndAdmin($template->id, 'acme/p', 1);

        $this->assertNull(TemplateLayoutPreview::withoutGlobalScope('runtime_role')->find($stagePreview->id));
        $this->assertNotNull(TemplateLayoutPreview::withoutGlobalScope('runtime_role')->find($livePreview->id), '운영 미리보기 0건 삭제');
        $this->assertNull($repo->findByToken($livePreview->token), 'stage 는 운영 미리보기를 보지 않는다');
    }

    /**
     * 모델을 거치는 직접 사용처(부모 레이아웃 검증)도 stage 에서 스테이지 테이블을 읽는다.
     *
     * @scenario case=direct_readers
     *
     * @effects stage_layout_writes_stay_in_stage_tables
     *
     * @return void 반환값 없음
     */
    public function test_parent_layout_rule_reads_stage_table_on_stage(): void
    {
        $template = $this->template();
        $this->role('stage');
        $this->layout($template, 'acme/parent_stage');

        $failed = false;
        (new ValidParentLayout)->setData(['template_id' => $template->id])->validate('extends', 'acme/parent_stage', function () use (&$failed): void {
            $failed = true;
        });
        $this->assertFalse($failed, 'stage 의 부모 레이아웃을 스테이지 테이블에서 찾는다');

        $this->role('live');
        $failed = false;
        (new ValidParentLayout)->setData(['template_id' => $template->id])->validate('extends', 'acme/parent_stage', function () use (&$failed): void {
            $failed = true;
        });
        $this->assertTrue($failed, 'live 는 스테이지 행을 모른다');
    }

    /**
     * 템플릿 제거 — 운영 서버가 다른 역할(스테이지) 테이블의 그 템플릿 행을 명시적으로 지운다.
     *
     * @scenario case=template_uninstall
     *
     * @effects other_role_rows_removed_on_template_uninstall
     *
     * @return void 반환값 없음
     */
    public function test_template_uninstall_removes_other_role_rows(): void
    {
        $template = $this->template();
        $this->role('stage');
        $this->layout($template, 'acme/stage_row');

        $this->role('live');
        $deleted = app(StageLayoutTableService::class)->deleteOtherRoleRowsForTemplate($template->id);

        $this->assertSame(1, $deleted);
        $this->assertSame(0, DB::table(LayoutTable::stage('template_layouts'))->where('template_id', $template->id)->count());
    }

    /**
     * resync 뒤 검수 서버는 새로 복사한 레이아웃을 바로 내준다 — 복사 전에 캐시해 둔 옛 병합본을 들고 있지 않는다.
     *
     * 복사는 행을 한꺼번에 넣어 모델 저장 이벤트(레이아웃 캐시 무효화)를 거치지 않는다. 캐시 버전만 올리면 버전 없는 병합본
     * 캐시(`template.{id}.layout.{name}`)가 캐시 수명(기본 1시간) 동안 남아, 검수 서버가 옛 화면을 계속 보였다.
     *
     * @scenario case=resync
     *
     * @effects resync_copies_live_rows_without_writing_live, resync_drops_stage_layout_cache
     *
     * @return void 반환값 없음
     */
    public function test_resync_drops_stage_layout_cache(): void
    {
        $template = $this->template();
        $content = fn (string $probe): array => ['version' => '1.0.0', 'layout_name' => 'acme/cached', 'meta' => ['probe' => $probe], 'components' => []];

        $this->role('stage');
        TemplateLayout::factory()->create(['template_id' => $template->id, 'name' => 'acme/cached', 'content' => $content('stage-old')]);
        $before = app(LayoutService::class)->loadAndMergeLayout($template->id, 'acme/cached');
        $this->assertSame('stage-old', $before['meta']['probe'] ?? null, '검수 서버 병합본이 캐시된다');

        $this->role('live');
        TemplateLayout::factory()->create(['template_id' => $template->id, 'name' => 'acme/cached', 'content' => $content('live-new')]);

        $this->role('stage');
        $this->assertSame(0, Artisan::call('stage-layouts:reset-from-live', ['--yes' => true]), Artisan::output());

        $after = app(LayoutService::class)->loadAndMergeLayout($template->id, 'acme/cached');
        $this->assertSame('live-new', $after['meta']['probe'] ?? null, 'resync 뒤에도 검수 서버가 복사 전 캐시를 내준다');

        $this->role('live');
        $this->assertSame('live-new', app(LayoutService::class)->loadAndMergeLayout($template->id, 'acme/cached')['meta']['probe'] ?? null, 'live 회귀');
    }

    /**
     * resync 뒤 검수 서버의 역변환 마크업(`layout:markup:{ID}:{lock_version}`)은 복사한 운영 본문으로 다시 만든다.
     *
     * 복사는 운영 행을 ID·잠금 판(lock_version) 그대로 옮긴다. 검수 서버에서 고친 행과 운영 행이 같은 ID·잠금 판이면(양쪽이 같은
     * 횟수만큼 고침) 마크업 캐시 키가 같아, 캐시 수명 동안 코드 편집기·내용 검색이 지워진 검수 서버 편집본을 보였다.
     *
     * @scenario case=resync
     *
     * @effects resync_drops_stage_markup_and_resolution_cache
     *
     * @return void 반환값 없음
     */
    public function test_resync_drops_stage_markup_cache(): void
    {
        $template = $this->template();
        $content = fn (string $marker): array => [
            'layout_name' => 'acme/markup',
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['className' => $marker]]],
        ];

        $this->role('live');
        $live = TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'acme/markup',
            'source_type' => LayoutSourceType::Template->value,
            'source_identifier' => $template->identifier,
            'lock_version' => 3,
            'content' => $content('live-marker'),
        ]);

        // 검수 서버에 같은 행을 복사한 뒤 본문만 고친다 — 잠금 판은 운영과 같다(운영도 같은 횟수만큼 고친 경우)
        $this->role('stage');
        app(StageLayoutTableService::class)->resetFromLive();
        DB::table(LayoutTable::stage('template_layouts'))->where('id', $live->id)->update(['content' => json_encode($content('stage-marker'))]);
        $warmed = app(LayoutFileService::class)->markupFor(TemplateLayout::query()->findOrFail($live->id), $template->identifier);
        $this->assertStringContainsString('stage-marker', $warmed, '전제 — 검수 서버 편집본의 마크업이 캐시된다');

        $this->assertSame(0, Artisan::call('stage-layouts:reset-from-live', ['--yes' => true]), Artisan::output());

        $copied = TemplateLayout::query()->findOrFail($live->id);
        $this->assertSame(3, (int) $copied->lock_version, '전제 — 잠금 판도 그대로 복사된다(같은 캐시 키)');
        $after = app(LayoutFileService::class)->markupFor($copied, $template->identifier);
        $this->assertStringContainsString('live-marker', $after, 'resync 뒤에도 지워진 검수 서버 편집본의 마크업 캐시를 내준다');
        $this->assertStringNotContainsString('stage-marker', $after);
    }

    /**
     * resync 뒤 검수 서버의 레이아웃 해석 캐시(`layout_resolver.{템플릿}.{이름}`)는 지운 검수 서버 전용 행(ID 10억대)을 가리키지 않는다.
     *
     * 해석 캐시 값은 행 ID 다. 지운 행 ID 가 캐시에 남으면 그 ID 로 찾은 행이 없어 캐시 수명 동안 그 화면 레이아웃이 사라진다.
     *
     * @scenario case=resync
     *
     * @effects resync_drops_stage_markup_and_resolution_cache
     *
     * @return void 반환값 없음
     */
    public function test_resync_drops_stage_layout_resolution_cache(): void
    {
        $template = $this->template();
        $name = 'acme-stagemod.admin_index';
        $content = ['layout_name' => $name, 'version' => '1.0.0', 'components' => []];

        $this->role('live');
        $moduleDefault = TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => $name,
            'source_type' => LayoutSourceType::Module->value,
            'source_identifier' => 'acme-stagemod',
            'content' => $content,
        ]);

        // 검수 서버에서만 템플릿 오버라이드를 만들고 해석 결과(그 행 ID)를 캐시한다
        $this->role('stage');
        app(StageLayoutTableService::class)->resetFromLive();
        $override = TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => $name,
            'source_type' => LayoutSourceType::Template->value,
            'source_identifier' => $template->identifier,
            'content' => $content,
        ]);
        $this->assertGreaterThanOrEqual(StageLayoutTableRepository::STAGE_ID_FLOOR, $override->id, '전제 — 검수 서버 전용 행');
        $this->assertSame($override->id, app(LayoutResolverService::class)->resolve($name, $template->id)?->id, '전제 — 오버라이드가 해석·캐시된다');

        $this->assertSame(0, Artisan::call('stage-layouts:reset-from-live', ['--yes' => true]), Artisan::output());

        $resolved = app(LayoutResolverService::class)->resolve($name, $template->id);
        $this->assertNotNull($resolved, 'resync 뒤에도 지운 검수 서버 전용 행 ID 를 해석 캐시가 들고 있어 레이아웃이 사라진다');
        $this->assertSame($moduleDefault->id, $resolved->id, '운영에서 복사한 모듈 기본 레이아웃으로 해석한다');
    }

    /**
     * 명령 이름이 복사 방향(운영 → 검수 서버)과 파괴성을 드러내고, `--yes` 여도 실행 전에 방향과 지워질 검수 서버 편집본 수를 알린다.
     *
     * 종전 이름 `stage-layouts:resync` 는 「검수 서버 레이아웃을 운영으로 올린다」로 읽힐 수 있었고, 문서가 쓰는 `--yes` 형태에서는
     * 방향을 말하는 유일한 문구(확인 질문)가 생략됐다. 그렇게 실행하면 검수 서버의 편집본과 그 버전 기록이 되살릴 수 없게 지워진다.
     *
     * @scenario case=reset_from_live_notice
     *
     * @effects reset_from_live_announces_direction_and_discarded_stage_edits
     *
     * @return void 반환값 없음
     */
    public function test_reset_from_live_announces_direction_and_stage_edits_to_discard(): void
    {
        $template = $this->template();
        $content = fn (string $probe): array => ['version' => '1.0.0', 'layout_name' => 'acme/same', 'meta' => ['probe' => $probe], 'components' => []];

        $this->role('live');
        $shared = TemplateLayout::factory()->create(['template_id' => $template->id, 'name' => 'acme/same', 'content' => $content('live')]);

        // 다른 시험 행이 섞이지 않게 한 번 맞춘 뒤, 검수 서버에서 같은 화면을 고치고(내용 다름) 검수 서버에만 있는 화면을 하나 만든다
        $this->role('stage');
        app(StageLayoutTableService::class)->resetFromLive();
        DB::table(LayoutTable::stage('template_layouts'))->where('id', $shared->id)->update(['content' => json_encode($content('stage-edit'))]);
        TemplateLayout::factory()->create(['template_id' => $template->id, 'name' => 'acme/stage-only', 'content' => $content('stage-only')]);

        $this->assertSame(0, Artisan::call('stage-layouts:reset-from-live', ['--yes' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString(__('common.console.stage_layouts.direction'), $output, '--yes 여도 복사 방향을 먼저 알린다');
        $this->assertStringContainsString(
            __('common.console.stage_layouts.discard_summary', ['layouts' => 2, 'extensions' => 0, 'versions' => 0]),
            $output,
            '운영과 다른 검수 서버 레이아웃 2건(고친 화면·검수 서버에만 있는 화면)을 지운다고 알린다'
        );
        $copiedPrefix = (string) strstr(__('common.console.stage_layouts.copied', ['layouts' => '§', 'extensions' => '§']), '§', true);
        $this->assertNotFalse(strpos($output, $copiedPrefix));
        $this->assertLessThan(strpos($output, $copiedPrefix), strpos($output, __('common.console.stage_layouts.direction')), '안내는 복사 결과보다 먼저 나온다');
        $this->assertSame(0, DB::table(LayoutTable::stage('template_layouts'))->where('name', 'acme/stage-only')->count(), '검수 서버에만 있던 화면은 지워진다');
    }

    /**
     * 방향을 알 수 없는 종전 이름은 등록하지 않는다 — 7.2.0 에서 새로 생긴 명령이라 별칭을 남기지 않는다.
     *
     * @scenario case=reset_from_live_notice
     *
     * @effects reset_from_live_announces_direction_and_discarded_stage_edits
     *
     * @return void 반환값 없음
     */
    public function test_ambiguous_resync_name_is_not_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('stage-layouts:reset-from-live', $commands);
        $this->assertArrayNotHasKey('stage-layouts:resync', $commands);
    }
}
