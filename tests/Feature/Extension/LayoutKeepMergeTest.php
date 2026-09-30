<?php

namespace Tests\Feature\Extension;

use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\ModuleManager;
use App\Extension\TemplateManager;
use App\Models\TemplateLayout;
use App\Services\LayoutFileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 확장 업데이트·활성화가 운영자가 고친 화면을 조각 단위로 병합하는지 고정하는 회귀 테스트 (#727).
 *
 * 결함(수정 전):
 *  - keep 업데이트가 운영자가 한 곳이라도 고친 화면을 **통째로** 옛 판으로 남겨, 새 버전이 다른 조각에 넣은
 *    수정이 들어오지 않았다 (R1).
 *  - 모듈 활성화·템플릿 활성화가 전략을 모른 채 파일로 덮어써 운영자 수정이 흔적 없이 지워졌다 (R2·R3).
 *  - 모듈 갱신이 새 버전에서 빠진 화면을 수정본까지 지웠다 (R6·R18).
 *  - 운영자가 지운·만든·이름 바꾼 템플릿 화면을 keep 이 되살리거나 덮거나 지웠다 (R14·R15·R16).
 *  - 꺼 둔 템플릿을 업데이트하면 켜도 새 판이 들어오지 않았다 (R17).
 *
 * 실제 업데이트는 파일 교체·다운로드를 동반하므로, 업데이트 경로가 부르는 레이아웃 단계를 가짜 모듈·템플릿
 * 파일로 실제 실행한다({@see BuildsLayoutMergeFixtures}).
 *
 * 트러블슈팅 `[case:backend-48]` 의 회귀 그물이다.
 */
class LayoutKeepMergeTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
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
     * v1 모듈을 설치(활성화 경로)한 상태를 만듭니다.
     *
     * @return ModuleManager 모듈 관리자
     */
    private function installModuleV1(): ModuleManager
    {
        $this->makeFixtureTemplate();
        $manager = $this->makeFixtureModule($this->listScreenFiles('A1', 'B1'));
        $this->invokeRegisterModuleLayouts($manager);

        return $manager;
    }

    /**
     * 활성화 경로의 등록 메서드(protected)를 실행합니다.
     *
     * @param  ModuleManager  $manager  모듈 관리자
     * @return int 등록 수
     */
    private function invokeRegisterModuleLayouts(ModuleManager $manager): int
    {
        $method = new ReflectionMethod(ModuleManager::class, 'registerModuleLayouts');
        $method->setAccessible(true);

        return (int) $method->invoke($manager, $this->fixtureModule);
    }

    /**
     * 운영자가 조각 A 의 문구를 편집기로 고친 상태를 만듭니다.
     *
     * @return TemplateLayout 고친 행
     */
    private function operatorEditsPartialA(): TemplateLayout
    {
        $row = $this->fixtureModuleLayout('merge_list');
        $this->assertNotNull($row, '설치 뒤 목록 화면 행이 있어야 한다');

        $this->operatorEdits($row, function (array $content) {
            foreach ($content['slots']['content'] as $i => $node) {
                if (($node['id'] ?? null) === 'part_a') {
                    $content['slots']['content'][$i]['text'] = '운영자 A';
                }
            }

            return $content;
        });

        return $row->fresh();
    }

    /**
     * R1 — keep 업데이트는 운영자가 고친 조각은 두고 새 버전이 바꾼 다른 조각·설정은 반영한다.
     *
     * @scenario source=module, path=update_keep, unit=partial
     *
     * @effects keep_update_merges_new_version_into_modified_layout
     *
     * @return void 반환값 없음
     */
    public function test_keep_update_applies_new_version_changes_to_untouched_partials(): void
    {
        $manager = $this->installModuleV1();
        $row = $this->operatorEditsPartialA();

        // v2: 조각 B 문구와 per_page 변경 (운영자는 둘 다 안 건드림)
        $this->writeModuleFiles($this->listScreenFiles('A1', 'B2', 20));
        $manager->refreshModuleLayouts($this->fixtureModule, true);

        $content = $this->flat($row->fresh()->content);
        $this->assertStringContainsString('운영자 A', $content, '운영자가 고친 조각 A 가 보존되어야 한다');
        $this->assertStringContainsString('B2', $content, '운영자가 안 고친 조각 B 에는 새 판이 들어와야 한다');
        $this->assertStringContainsString('"per_page":20', $content, '운영자가 안 고친 data_sources 에는 새 판이 들어와야 한다');
    }

    /**
     * R2 — 모듈을 다시 켜는 활성화 경로는 운영자 수정을 지우지 않는다.
     *
     * @scenario source=module, path=activate, unit=partial
     *
     * @effects activation_preserves_operator_modification
     *
     * @return void 반환값 없음
     */
    public function test_module_activation_preserves_operator_modification(): void
    {
        $manager = $this->installModuleV1();
        $row = $this->operatorEditsPartialA();

        $this->invokeRegisterModuleLayouts($manager);

        $this->assertStringContainsString('운영자 A', $this->flat($row->fresh()->content), '활성화가 운영자 수정을 원본으로 되돌렸다');
    }

    /**
     * R3 — 템플릿 활성화(활성 모듈 레이아웃 재등록)는 운영자 수정을 지우지 않는다.
     *
     * @scenario source=module, path=template_activate, unit=partial
     *
     * @effects activation_preserves_operator_modification
     *
     * @return void 반환값 없음
     */
    public function test_template_activation_preserves_module_layout_modification(): void
    {
        $manager = $this->installModuleV1();
        $row = $this->operatorEditsPartialA();

        $manager->registerLayoutsForAllActiveModules();

        $this->assertStringContainsString('운영자 A', $this->flat($row->fresh()->content), '템플릿 활성화가 운영자 수정을 원본으로 되돌렸다');
    }

    /**
     * R6 — keep 갱신은 새 버전에서 빠진 화면이라도 운영자 수정본이면 남긴다 (D4).
     *
     * @scenario source=module, path=removed_in_new_version, unit=partial
     *
     * @effects modified_layout_removed_in_new_version_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_keep_refresh_keeps_modified_layout_removed_in_new_version(): void
    {
        $manager = $this->installModuleV1();
        $row = $this->operatorEditsPartialA();

        // v2: 목록 화면 파일 자체가 사라지고 다른 화면만 남음
        $this->writeModuleFiles([
            'admin/merge_other.json' => ['version' => '1.0.0', 'layout_name' => 'merge_other', 'components' => []],
        ]);
        $manager->refreshModuleLayouts($this->fixtureModule, true);

        $fresh = TemplateLayout::find($row->id);
        $this->assertNotNull($fresh, '새 버전에서 빠졌지만 운영자 수정본인 화면이 삭제되었다');
        $this->assertStringContainsString('운영자 A', $this->flat($fresh->content));
    }

    /**
     * R18 — layouts 디렉토리째 사라진 새 버전도 운영자 수정본은 남긴다 (D4 횡단).
     *
     * @effects modified_layout_removed_in_new_version_is_kept
     *
     * @return void 반환값 없음
     */
    public function test_keep_refresh_keeps_modified_layout_when_layouts_directory_removed(): void
    {
        $manager = $this->installModuleV1();
        $row = $this->operatorEditsPartialA();

        $this->writeModuleFiles([]);
        $manager->refreshModuleLayouts($this->fixtureModule, true);

        $this->assertNotNull(TemplateLayout::find($row->id), 'layouts 디렉토리가 사라진 새 버전이 운영자 수정본을 지웠다');
    }

    /**
     * 템플릿 자기 레이아웃 두 장(홈·점검)을 만듭니다.
     *
     * @param  string  $homeText  홈 문구
     * @param  string  $maintText  점검 화면 문구
     * @return array<string, array<string, mixed>> 상대 경로 => JSON
     */
    private function templateFiles(string $homeText, string $maintText): array
    {
        return [
            'home.json' => ['version' => '1.0.0', 'layout_name' => 'home', 'components' => [['id' => 'home', 'type' => 'basic', 'name' => 'Div', 'text' => $homeText]]],
            'maint.json' => ['version' => '1.0.0', 'layout_name' => 'maint', 'components' => [['id' => 'maint', 'type' => 'basic', 'name' => 'Div', 'text' => $maintText]]],
        ];
    }

    /**
     * 템플릿 v1 을 설치하고 레이아웃을 등록합니다.
     *
     * @return TemplateManager 템플릿 관리자
     */
    private function installTemplateV1(): TemplateManager
    {
        $this->makeFixtureTemplate($this->templateFiles('HOME1', 'MAINT1'));
        $manager = app(TemplateManager::class);
        $manager->refreshTemplateLayouts($this->fixtureTemplate, false);

        return $manager;
    }

    /**
     * 템플릿 파일을 새 판으로 바꿉니다.
     *
     * @param  array<string, array<string, mixed>>  $files  상대 경로 => JSON
     * @return void 반환값 없음
     */
    private function writeTemplateFiles(array $files): void
    {
        $root = base_path("templates/{$this->fixtureTemplate}/layouts");
        File::cleanDirectory($root);

        foreach ($files as $relative => $json) {
            File::put($root.'/'.$relative, json_encode($json, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * R14 — 운영자가 지운 템플릿 화면은 keep 갱신이 되살리지 않고 "운영자가 지운 화면" 으로 보고한다 (D9).
     *
     * @scenario source=template, path=operator_deleted, unit=partial
     *
     * @effects operator_created_or_deleted_layout_is_respected
     *
     * @return void 반환값 없음
     */
    public function test_keep_refresh_leaves_operator_deleted_layout_deleted(): void
    {
        $manager = $this->installTemplateV1();
        $maint = TemplateLayout::where('template_id', $this->fixtureTemplateModel->id)->where('name', 'maint')->firstOrFail();
        $maint->delete();

        $this->writeTemplateFiles($this->templateFiles('HOME1', 'MAINT2'));
        $result = $manager->refreshTemplateLayouts($this->fixtureTemplate, true);

        $this->assertTrue(TemplateLayout::withTrashed()->find($maint->id)->trashed(), '운영자가 지운 화면이 되살아났다');
        $this->assertSame(1, $result['layout_sync']['kept_deleted_by_operator_count'] ?? null, '운영자가 지운 화면을 건너뛴 사실이 결과에 없다');
    }

    /**
     * R15 — 운영자가 만든 화면과 새 버전 화면의 이름이 겹치면 keep 은 운영자 것을 남긴다 (D10).
     *
     * @scenario source=template, path=operator_name_collision, unit=partial
     *
     * @effects operator_created_or_deleted_layout_is_respected
     *
     * @return void 반환값 없음
     */
    public function test_keep_refresh_keeps_operator_created_layout_on_name_collision(): void
    {
        $manager = $this->installTemplateV1();

        // 편집기 파일 목록의 "새 파일" 과 같은 모양 — 원본 해시 없음
        $own = TemplateLayout::create([
            'template_id' => $this->fixtureTemplateModel->id,
            'name' => 'probe_new',
            'content' => ['version' => '1.0.0', 'layout_name' => 'probe_new', 'components' => [['id' => 'probe_new', 'type' => 'basic', 'name' => 'Div', 'text' => 'OPERATOR']]],
            'source_type' => 'template',
        ]);

        $this->writeTemplateFiles($this->templateFiles('HOME1', 'MAINT1') + [
            'probe_new.json' => ['version' => '1.0.0', 'layout_name' => 'probe_new', 'components' => [['id' => 'probe_new', 'type' => 'basic', 'name' => 'Div', 'text' => 'THEIRS']]],
        ]);
        $result = $manager->refreshTemplateLayouts($this->fixtureTemplate, true);

        $this->assertStringContainsString('OPERATOR', $this->flat($own->fresh()->content), '운영자가 만든 화면이 새 버전 화면으로 덮였다');
        $this->assertSame(1, $result['layout_sync']['kept_name_collision_count'] ?? null, '이름 겹침 경고가 결과에 없다');
    }

    /**
     * R16 — 운영자가 이름만 바꾼 템플릿 화면은 "새 버전에서 빠진 행" 으로 지워지지 않는다 (W15).
     *
     * @scenario source=template, path=operator_renamed, unit=partial
     *
     * @effects operator_created_or_deleted_layout_is_respected
     *
     * @return void 반환값 없음
     */
    public function test_keep_refresh_does_not_delete_operator_renamed_layout(): void
    {
        $manager = $this->installTemplateV1();
        $maint = TemplateLayout::where('template_id', $this->fixtureTemplateModel->id)->where('name', 'maint')->firstOrFail();
        $maint->update(['name' => 'maint_renamed']);

        $manager->refreshTemplateLayouts($this->fixtureTemplate, true);

        $this->assertNotNull(TemplateLayout::find($maint->id), '운영자가 이름을 바꾼 화면이 삭제되었다');
    }

    /**
     * R16-b — 운영자가 만든(원본 해시 없는) 화면도 파일에 없다는 이유로 지워지지 않는다 (W15).
     *
     * @effects operator_created_or_deleted_layout_is_respected
     *
     * @return void 반환값 없음
     */
    public function test_refresh_does_not_delete_operator_created_layout(): void
    {
        $manager = $this->installTemplateV1();
        $own = TemplateLayout::create([
            'template_id' => $this->fixtureTemplateModel->id,
            'name' => 'operator_page',
            'content' => ['version' => '1.0.0', 'layout_name' => 'operator_page', 'components' => []],
            'source_type' => 'template',
        ]);

        $manager->refreshTemplateLayouts($this->fixtureTemplate, true);

        $this->assertNotNull(TemplateLayout::find($own->id), '운영자가 만든 화면이 갱신 때 삭제되었다');
    }

    /**
     * R16-c — 편집기 「새 레이아웃」(레이아웃 파일 만들기)으로 만든 화면이 갱신 때 지워지지 않는다.
     *
     * 이 경로가 만드는 행은 `source_identifier` 에 템플릿 식별자가 들어가 템플릿 오버라이드 행과 모양이 같다.
     * 고아 오버라이드 정리가 그 행을 "파일이 사라진 오버라이드" 로 보고 영구 삭제했다(P8 라이브 실측에서 발견 —
     * 사용자 템플릿을 켜기만 해도 운영자가 만든 화면이 사라졌다). 원본(파일 판)이 없는 행은 오버라이드 파일에서
     * 온 행이 아니므로 정리 대상이 아니다.
     *
     * @effects operator_created_or_deleted_layout_is_respected
     *
     * @return void 반환값 없음
     */
    public function test_refresh_does_not_delete_layout_created_in_editor(): void
    {
        $manager = $this->installTemplateV1();
        $created = app(LayoutFileService::class)->create(
            $this->fixtureTemplateModel->id,
            $this->fixtureTemplate,
            'operator_new_page',
            ['version' => '1.0.0', 'layout_name' => 'operator_new_page', 'components' => []],
            null,
        );

        $manager->refreshTemplateLayouts($this->fixtureTemplate, true);
        $this->assertNotNull(TemplateLayout::find($created->id), '편집기에서 만든 화면이 keep 갱신 때 삭제되었다');

        $manager->refreshTemplateLayouts($this->fixtureTemplate, false);
        $this->assertNotNull(TemplateLayout::find($created->id), '편집기에서 만든 화면이 덮어쓰기 갱신 때 삭제되었다');
    }

    /**
     * R17 — 꺼 둔 템플릿을 업데이트한 뒤 켜면 운영자 수정은 두고 새 판이 들어온다 (D11).
     *
     * 업데이트 경로는 비활성 템플릿의 레이아웃을 갱신하지 않고 백업만 지우므로, 그 전에 옛 판 원본을
     * 미리 저장해 두고(stash) 활성화가 그 원본을 기준으로 병합해야 한다.
     *
     * @scenario source=template, path=inactive_update_then_activate, unit=partial
     *
     * @effects keep_update_merges_new_version_into_modified_layout, base_is_recovered_from_backup_when_hash_matches
     *
     * @return void 반환값 없음
     */
    public function test_inactive_template_update_then_activation_merges_new_version(): void
    {
        $manager = $this->installTemplateV1();
        $home = TemplateLayout::where('template_id', $this->fixtureTemplateModel->id)->where('name', 'home')->firstOrFail();
        $maint = TemplateLayout::where('template_id', $this->fixtureTemplateModel->id)->where('name', 'maint')->firstOrFail();
        $this->operatorEdits($home, function (array $c) {
            $c['components'][0]['text'] = 'HOME-OPERATOR';

            return $c;
        });

        // 비활성 상태에서 업데이트: 백업(v1) → 파일 교체(v2) → stash → 백업 삭제
        $backup = storage_path('app/extension_backups/templates/'.$this->fixtureTemplate.'_'.date('Ymd_His'));
        $this->fixturePaths[] = $backup;
        File::copyDirectory(base_path("templates/{$this->fixtureTemplate}"), $backup);
        $this->fixtureTemplateModel->update(['status' => 'inactive']);
        $this->writeTemplateFiles($this->templateFiles('HOME1', 'MAINT2'));

        $this->assertTrue(method_exists(LayoutRowSynchronizer::class, 'stashBaseForInactiveUpdate'), '비활성 업데이트용 원본 저장 경로가 없다');
        app(LayoutRowSynchronizer::class)->stashBaseForInactiveUpdate($backup, 'templates', $this->fixtureTemplate);

        // 활성화 — 템플릿 자기 레이아웃도 keep 규칙으로 갱신해야 한다
        $this->fixtureTemplateModel->update(['status' => 'active']);
        $manager->refreshTemplateLayouts($this->fixtureTemplate, true);

        $this->assertStringContainsString('HOME-OPERATOR', $this->flat($home->fresh()->content));
        $this->assertStringContainsString('MAINT2', $this->flat($maint->fresh()->content), '꺼 둔 채 업데이트한 새 판이 켠 뒤에도 들어오지 않았다');
    }

    /**
     * R17 (호출 계약) — 템플릿 활성화는 자기 레이아웃을 keep 규칙으로 갱신한다.
     *
     * @effects activation_preserves_operator_modification
     *
     * @return void 반환값 없음
     */
    public function test_template_activation_refreshes_its_own_layouts_with_keep(): void
    {
        $ref = new ReflectionMethod(TemplateManager::class, 'activateTemplate');
        $lines = file($ref->getFileName());
        $source = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        $this->assertMatchesRegularExpression('/refreshTemplateLayouts\(\s*\$templateName\s*,\s*true/', $source, '템플릿 활성화가 자기 레이아웃을 갱신하지 않는다');
    }
}
