<?php

namespace Tests\Feature\Extension;

use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Enums\LayoutSyncMode;
use App\Extension\Layout\DTO\LayoutSyncEntry;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\Layout\LayoutSyncReport;
use App\Extension\ModuleManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Models\LayoutExtension;
use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Support\LayoutMarkup\LayoutPartialOrigin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 레이아웃 행 동기화기 기능 테스트 (#727 §5.6 · §6.3).
 *
 * 기준(base) 출처 3가지, 기준 없음, 충돌 뒤 원본 이동(W17), 검증 실패(W9), 버전 이력·잠금 증가, 비활성 업데이트
 * 원본 저장(D11), 보고 중복 제거, 「수정한 레이아웃」 목록의 조각 포함(§5.9)을 실제 DB 행으로 고정한다.
 */
class LayoutRowSynchronizerTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use ComputesLayoutContentHash;
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
     * 조각 A·B 를 가진 원본(표식 포함)을 만듭니다.
     *
     * @param  string  $a  조각 A 문구
     * @param  string  $b  조각 B 문구
     * @param  array<int, array<string, mixed>>  $dataSources  data_sources 목록
     * @return array<string, mixed> 원본
     */
    private function original(string $a, string $b, array $dataSources = [['id' => 'items', 'endpoint' => '/a']]): array
    {
        return [
            'version' => '1.0.0',
            'layout_name' => 'sync_list',
            'data_sources' => $dataSources,
            'slots' => ['content' => [
                ['id' => 'part_a', 'type' => 'basic', 'name' => 'Div', 'text' => $a, LayoutPartialOrigin::KEY => 'partials/x/_a'],
                ['id' => 'part_b', 'type' => 'basic', 'name' => 'Div', 'text' => $b, LayoutPartialOrigin::KEY => 'partials/x/_b'],
            ]],
        ];
    }

    /**
     * 동기화 식별 정보.
     *
     * @return array<string, mixed> 식별 정보
     */
    private function identity(): array
    {
        return [
            'template_id' => $this->fixtureTemplateModel->id,
            'template' => $this->fixtureTemplate,
            'name' => 'sync_list',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => null,
        ];
    }

    /**
     * 동기화기를 받습니다.
     *
     * @return LayoutRowSynchronizer 동기화기
     */
    private function sync(): LayoutRowSynchronizer
    {
        return app(LayoutRowSynchronizer::class);
    }

    /**
     * v1 을 설치하고 운영자가 조각 A 를 고친 행을 돌려줍니다.
     *
     * @return TemplateLayout 행
     */
    private function installAndEditA(): TemplateLayout
    {
        $this->makeFixtureTemplate();
        $v1 = $this->original('A1', 'B1');
        $this->sync()->syncLayout($this->identity(), LayoutPartialOrigin::strip($v1), $v1, LayoutSyncMode::Keep);

        $row = TemplateLayout::where('template_id', $this->fixtureTemplateModel->id)->where('name', 'sync_list')->firstOrFail();
        $this->operatorEdits($row, function (array $c) {
            $c['slots']['content'][0]['text'] = 'A-op';

            return $c;
        });

        return $row->fresh();
    }

    /**
     * 설치는 원본 본문(표식 포함)을 남기고 서비스 본문에는 표식을 넣지 않는다.
     *
     * @effects compile_marks_partial_origin_only_in_original
     *
     * @return void 반환값 없음
     */
    public function test_create_stores_annotated_original_but_plain_content(): void
    {
        $row = $this->installAndEditA();

        $this->assertStringContainsString(LayoutPartialOrigin::KEY, $row->getRawOriginal('original_content'));
        $this->assertStringNotContainsString(LayoutPartialOrigin::KEY, $row->getRawOriginal('content'));
        $this->assertArrayNotHasKey('original_content', $row->toArray(), '원본 본문은 직렬화에서 숨겨야 한다');
    }

    /**
     * 병합이 일어나면 lock_version 이 오르고 편집기 버전 이력에 baseline + 병합판(출처 표식)이 남는다.
     *
     * @effects merge_bumps_lock_version_and_records_versions
     *
     * @return void 반환값 없음
     */
    public function test_merge_bumps_lock_and_records_versions(): void
    {
        $row = $this->installAndEditA();
        $lock = (int) $row->lock_version;
        $v2 = $this->original('A1', 'B2');

        $entry = $this->sync()->syncLayout($this->identity(), LayoutPartialOrigin::strip($v2), $v2, LayoutSyncMode::Keep);

        $fresh = $row->fresh();
        $this->assertSame('merged', $entry->verdict);
        $this->assertSame($lock + 1, (int) $fresh->lock_version);
        $this->assertStringContainsString('A-op', $this->flat($fresh->content));
        $this->assertStringContainsString('B2', $this->flat($fresh->content));

        $versions = TemplateLayoutVersion::where('layout_id', $row->id)->orderBy('version')->get();
        $this->assertCount(2, $versions, 'baseline + 병합판');
        $this->assertSame('extension_update', $versions->last()->changes_summary['source'] ?? null);
        $this->assertNotEmpty($versions->last()->changes_summary['applied'] ?? []);
    }

    /**
     * 원본 본문이 없고 해시만 있는 옛 행 — 해시가 맞는 백업 컴파일 결과를 기준으로 쓴다(W3 ②).
     *
     * @effects base_is_recovered_from_backup_when_hash_matches
     *
     * @return void 반환값 없음
     */
    public function test_backup_original_is_used_when_hash_matches(): void
    {
        $row = $this->installAndEditA();
        $row->forceFill(['original_content' => null])->saveQuietly();
        $v1 = $this->original('A1', 'B1');
        $v2 = $this->original('A1', 'B2');

        $entry = $this->sync()->syncLayout($this->identity(), LayoutPartialOrigin::strip($v2), $v2, LayoutSyncMode::Keep, $v1);

        $this->assertSame('merged', $entry->verdict);
        $this->assertNotNull($row->fresh()->getRawOriginal('original_content'), '병합 뒤 원본이 새 판으로 채워져야 한다');
    }

    /**
     * 기준이 없으면(원본 본문 없음 + 백업·파일 해시 불일치) 통째 보존하고 원본·해시를 건드리지 않는다(W17).
     *
     * @effects missing_base_keeps_operator_layout_untouched
     *
     * @return void 반환값 없음
     */
    public function test_no_base_keeps_everything_untouched(): void
    {
        $row = $this->installAndEditA();
        $row->forceFill(['original_content' => null])->saveQuietly();
        $hash = $row->original_content_hash;
        $v2 = $this->original('A1', 'B2');

        $entry = $this->sync()->syncLayout($this->identity(), LayoutPartialOrigin::strip($v2), $v2, LayoutSyncMode::Keep, $this->original('X', 'Y'));

        $fresh = $row->fresh();
        $this->assertSame('kept_no_base', $entry->verdict);
        $this->assertNull($fresh->getRawOriginal('original_content'));
        $this->assertSame($hash, $fresh->original_content_hash);
        $this->assertStringNotContainsString('B2', $this->flat($fresh->content));
    }

    /**
     * 충돌로 끝나도 원본은 새 판으로 옮긴다 — 겹친 새 판 변경은 다음 업데이트에서 다시 제안되지 않는다(W17).
     *
     * @effects conflicting_change_keeps_operator_version_and_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_conflict_moves_original_to_new_version(): void
    {
        $row = $this->installAndEditA();
        $v2 = $this->original('A-new', 'B1');

        $entry = $this->sync()->syncLayout($this->identity(), LayoutPartialOrigin::strip($v2), $v2, LayoutSyncMode::Keep);

        $this->assertSame('conflict', $entry->verdict);
        $this->assertStringContainsString('A-new', $row->fresh()->getRawOriginal('original_content'));
        $this->assertSame($this->computeContentHash(LayoutPartialOrigin::strip($v2)), $row->fresh()->original_content_hash);
    }

    /**
     * 병합 결과가 검증(data_sources id 중복)을 통과하지 못하면 통째 보존 + 원본·해시 불변(W9·W17).
     *
     * @effects invalid_merge_result_keeps_operator_version
     *
     * @return void 반환값 없음
     */
    public function test_invalid_merge_result_keeps_operator_version(): void
    {
        $row = $this->installAndEditA();
        $hash = $row->original_content_hash;
        // 새 판 data_sources 에 id 없는 항목이 섞여 키 통째 판정 → 운영자가 안 고친 키라 새 판을 고르는데, 그 판에 id 중복이 있다
        $v2 = $this->original('A1', 'B2', [['id' => 'items', 'endpoint' => '/a'], ['id' => 'dup', 'endpoint' => '/x'], ['id' => 'dup', 'endpoint' => '/y'], ['endpoint' => '/noid']]);

        $entry = $this->sync()->syncLayout($this->identity(), LayoutPartialOrigin::strip($v2), $v2, LayoutSyncMode::Keep);

        $this->assertSame('kept_invalid', $entry->verdict);
        $this->assertSame($hash, $row->fresh()->original_content_hash);
        $this->assertStringNotContainsString('B2', $this->flat($row->fresh()->content));
    }

    /**
     * overwrite 는 운영자가 지운 템플릿 화면을 되살려 새 판으로 덮는다(D9 — 「모두 교체」).
     *
     * @effects overwrite_replaces_operator_layout
     *
     * @return void 반환값 없음
     */
    public function test_overwrite_restores_operator_deleted_template_layout(): void
    {
        $row = $this->installAndEditA();
        $row->delete();
        $v2 = $this->original('A1', 'B2');

        $entry = $this->sync()->syncLayout($this->identity(), LayoutPartialOrigin::strip($v2), $v2, LayoutSyncMode::Overwrite);

        $this->assertSame('updated', $entry->verdict);
        $this->assertFalse(TemplateLayout::withTrashed()->find($row->id)->trashed());
        $this->assertStringContainsString('B2', $this->flat($row->fresh()->content));
    }

    /**
     * 같은 행을 두 경로가 지나도 보고에는 처음 나온 의미 있는 결과만 남는다.
     *
     * @effects layout_sync_report_lists_operator_rows
     *
     * @return void 반환값 없음
     */
    public function test_report_keeps_first_informative_entry_per_row(): void
    {
        $report = new LayoutSyncReport(LayoutSyncMode::Keep);
        $report->add(new LayoutSyncEntry('extension', 't', 'host:frag', 'merged', [['unit' => 'key:x', 'kind' => 'key']]));
        $report->add(new LayoutSyncEntry('extension', 't', 'host:frag', 'kept'));
        $report->add(new LayoutSyncEntry('layout', 't', 'a', 'took_theirs'));
        $report->add(new LayoutSyncEntry('layout', 't', 'a', 'conflict', [], [['unit' => 'u', 'reason' => 'both_changed']]));

        $array = $report->toArray();
        $this->assertSame(2, $array['modified_count']);
        $this->assertSame(['merged', 'conflict'], array_column($array['entries'], 'verdict'));
        $this->assertSame(1, $array['applied_count']);
        $this->assertSame(1, $array['conflict_count']);
    }

    /**
     * 꺼 둔 모듈을 업데이트하면 백업 원본이 해시가 맞는 행에만 미리 저장된다(D11) — 본문·해시는 그대로.
     *
     * @effects base_is_recovered_from_backup_when_hash_matches
     *
     * @return void 반환값 없음
     */
    public function test_stash_base_for_inactive_update_fills_only_matching_rows(): void
    {
        $this->makeFixtureTemplate();
        $manager = $this->makeFixtureModule($this->listScreenFiles('A1', 'B1'));
        $method = new \ReflectionMethod(ModuleManager::class, 'registerModuleLayouts');
        $method->setAccessible(true);
        $method->invoke($manager, $this->fixtureModule);

        $row = $this->fixtureModuleLayout('merge_list');
        $row->forceFill(['original_content' => null])->saveQuietly();
        $hash = $row->original_content_hash;
        $backup = $this->backupFixtureModule();

        $filled = $this->sync()->stashBaseForInactiveUpdate($backup, 'modules', $this->fixtureModule);

        $this->assertSame(1, $filled);
        $this->assertNotNull($row->fresh()->getRawOriginal('original_content'));
        $this->assertSame($hash, $row->fresh()->original_content_hash);
    }

    /**
     * 「수정한 레이아웃」 목록은 그 확장의 조각 수정본도 `kind: extension` 으로 싣고, 키 순서만 다른 행은 수정으로 세지 않는다(§5.9).
     *
     * @effects modified_layouts_check_lists_operator_rows, representation_only_differences_are_not_modifications
     *
     * @return void 반환값 없음
     */
    public function test_modified_list_includes_fragments_and_ignores_key_order(): void
    {
        $this->makeFixtureTemplate([
            'merge_host.json' => ['version' => '1.0.0', 'layout_name' => 'merge_host', 'components' => [['id' => 'anchor', 'type' => 'basic', 'name' => 'Div']]],
        ]);
        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, false);
        $manager = $this->makeFixtureModule($this->listScreenFiles('A1', 'B1'), [
            'frag_a.json' => ['target_layout' => 'merge_host', 'priority' => 900, 'injections' => [['target_id' => 'anchor', 'position' => 'append_child', 'components' => [['id' => 'fa', 'type' => 'basic', 'name' => 'Div', 'text' => 'F']]]]],
        ]);
        $register = new \ReflectionMethod(ModuleManager::class, 'registerModuleLayouts');
        $register->setAccessible(true);
        $register->invoke($manager, $this->fixtureModule);
        $fragments = new \ReflectionMethod(ModuleManager::class, 'registerLayoutExtensions');
        $fragments->setAccessible(true);
        $fragments->invoke($manager, $manager->getModule($this->fixtureModule));

        // 레이아웃: 키 순서만 바꿔 저장(수정 아님)
        $row = $this->fixtureModuleLayout('merge_list');
        $this->operatorEdits($row, fn (array $c) => array_reverse($c, true));
        // 조각: 문구 수정
        $fragment = LayoutExtension::where('source_identifier', $this->fixtureModule)->where('extension_type', LayoutExtensionType::Overlay)->firstOrFail();
        $this->operatorEdits($fragment, function (array $c) {
            $c['injections'][0]['components'][0]['text'] = '운영자';

            return $c;
        });

        $result = $manager->hasModifiedLayouts($this->fixtureModule);

        $this->assertSame(1, $result['modified_count']);
        $this->assertSame('extension', $result['modified_layouts'][0]['kind']);
        $this->assertSame(__('extensions.layout_sync.fragment_label', ['target' => 'merge_host', 'key' => 'frag_a']), $result['modified_layouts'][0]['name']);
    }

    /**
     * 템플릿 오버라이드 레이아웃 파일은 자기 레이아웃 동기화에서 빠지고 오버라이드 등록만 행을 쓴다.
     *
     * @effects template_override_layouts_are_merged
     *
     * @return void 반환값 없음
     */
    public function test_override_files_are_registered_only_as_overrides(): void
    {
        $this->makeFixtureTemplate(['home.json' => ['version' => '1.0.0', 'layout_name' => 'home', 'components' => []]]);
        $dir = base_path("templates/{$this->fixtureTemplate}/layouts/overrides/some-module");
        File::ensureDirectoryExists($dir);
        File::put($dir.'/x.json', json_encode(['version' => '1.0.0', 'layout_name' => 'some-module.x', 'extends' => '_admin_base', 'slots' => ['content' => []]]));

        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, true);

        $row = TemplateLayout::where('template_id', $this->fixtureTemplateModel->id)->where('name', 'some-module.x')->firstOrFail();
        $this->assertSame($this->fixtureTemplate, $row->source_identifier);
        $this->assertSame('_admin_base', $row->extends);
        $this->assertNotNull($row->getRawOriginal('original_content'));
    }
}
