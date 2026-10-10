<?php

namespace Tests\Feature\Upgrade;

use App\Extension\TemplateManager;
use App\Extension\UpgradeContext;
use App\Models\TemplateLayout;
use App\Upgrades\Data\V7_1_0\Migrations\BackfillTemplateOverrideOriginals;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 7.1.0 데이터 마이그레이션 — 7.0.x 가 원본 없이 등록한 템플릿 오버라이드 행에 병합 기준을 채운다 (#727 C-1).
 *
 * 7.0.x 의 오버라이드 등록은 `layouts/overrides/{모듈}/` 파일을 원본 해시·원본 본문 없이 저장했다. 7.1.0 은 그런 행을
 * 운영자가 편집기로 만든 화면으로 보므로, keep 갱신이 영영 `kept_name_collision` 으로 비켜 가 새 판이 들어오지 않았다.
 * 스텝이 활성 디렉토리의 오버라이드 파일로 원본을 채운 뒤에는 keep 갱신이 그 행을 평소처럼 병합하고, 파일이 없는
 * 편집기 화면은 건드리지 않으며, 두 번 돌려도 같은 결과인지 고정한다.
 */
class BackfillTemplateOverrideOriginalsTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /** 오버라이드 레이아웃 이름 (파일의 layout_name) */
    private const OVERRIDE_NAME = 'some-module.some_page';

    /**
     * 데이터 마이그레이션 클래스를 불러옵니다(업그레이드 스텝이 동적으로 require 하는 파일).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once base_path('upgrades/data/7.1.0/migrations/04_BackfillTemplateOverrideOriginals.php');
    }

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
     * 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new BackfillTemplateOverrideOriginals)->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
    }

    /**
     * 오버라이드 파일을 씁니다.
     *
     * @param  string  $title  제목 문구
     * @param  string  $note  안내 문구
     * @return void 반환값 없음
     */
    private function writeOverride(string $title, string $note): void
    {
        $dir = base_path("templates/{$this->fixtureTemplate}/layouts/overrides/some-module");
        File::ensureDirectoryExists($dir);
        File::put($dir.'/some_page.json', json_encode([
            'version' => '1.0.0',
            'layout_name' => self::OVERRIDE_NAME,
            'meta' => ['title' => $title],
            'slots' => ['content' => [['id' => 'note', 'type' => 'basic', 'name' => 'Div', 'text' => $note]]],
        ], JSON_UNESCAPED_UNICODE));
        clearstatcache();
    }

    /**
     * 7.0.x 가 남긴 모양의 오버라이드 행을 만듭니다 — 지금 파일로 등록한 뒤 원본 정보만 비웁니다.
     *
     * @return TemplateLayout 원본 정보가 없는 오버라이드 행
     */
    private function makeLegacyOverrideRow(): TemplateLayout
    {
        $this->makeFixtureTemplate([
            'home.json' => ['version' => '1.0.0', 'layout_name' => 'home', 'components' => []],
        ]);
        $this->writeOverride('TITLE1', 'NOTE1');
        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, false);

        $row = $this->overrideRow();
        DB::table('template_layouts')->where('id', $row->id)->update([
            'original_content' => null,
            'original_content_hash' => null,
            'original_content_size' => null,
        ]);

        return $row->fresh();
    }

    /**
     * 픽스처 템플릿의 오버라이드 행을 찾습니다.
     *
     * @return TemplateLayout 오버라이드 행
     */
    private function overrideRow(): TemplateLayout
    {
        return TemplateLayout::withTrashed()
            ->where('template_id', $this->fixtureTemplateModel->id)
            ->where('name', self::OVERRIDE_NAME)
            ->firstOrFail();
    }

    /**
     * 운영자가 편집기 「새 레이아웃」 으로 만든 화면(파일 없음, 원본 정보 없음)을 만듭니다.
     *
     * @return int 행 ID
     */
    private function makeEditorCreatedRow(): int
    {
        return DB::table('template_layouts')->insertGetId([
            'template_id' => $this->fixtureTemplateModel->id,
            'name' => 'operator-made.page',
            'content' => json_encode(['slots' => ['content' => [['id' => 'x', 'type' => 'basic', 'name' => 'Div', 'text' => '운영자 화면']]]]),
            'source_type' => 'template',
            'source_identifier' => $this->fixtureTemplate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 결함 고정 — 스텝 전에는 7.0.x 오버라이드 행이 편집기 화면으로 보여 keep 갱신이 새 판을 들이지 않는다.
     *
     * @effects legacy_override_rows_get_a_base_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_legacy_override_row_is_kept_as_name_collision_without_backfill(): void
    {
        $this->makeLegacyOverrideRow();

        $this->writeOverride('TITLE2', 'NOTE1');
        $result = app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, true);

        $this->assertSame(1, $result['layout_sync']['kept_name_collision_count'], '원본 없는 오버라이드 행은 keep 에서 이름 충돌로 비켜 간다');
        $this->assertStringContainsString('TITLE1', $this->flat($this->overrideRow()->content), '새 판이 들어오지 않는다');
    }

    /**
     * 스텝이 지금 파일로 원본 본문·해시·크기를 채운다 — 값은 7.1.0 등록이 같은 파일로 남기는 값과 같다.
     *
     * @effects legacy_override_rows_get_a_base_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_backfill_fills_original_exactly_as_current_registration(): void
    {
        $row = $this->makeLegacyOverrideRow();
        $this->assertNull($row->original_content_hash);

        $this->runStep();

        $filled = DB::table('template_layouts')->where('id', $row->id)->first(['original_content', 'original_content_hash', 'original_content_size']);
        $this->assertNotNull($filled->original_content_hash, '원본 해시가 채워져야 한다');
        $this->assertNotNull($filled->original_content, '원본 본문이 채워져야 한다');

        // 7.1.0 등록이 같은 파일로 남기는 값과 바이트 단위로 같아야 한다
        app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, false);
        $registered = DB::table('template_layouts')->where('id', $row->id)->first(['original_content', 'original_content_hash', 'original_content_size']);

        $this->assertSame($registered->original_content_hash, $filled->original_content_hash);
        $this->assertSame((int) $registered->original_content_size, (int) $filled->original_content_size);
        $this->assertSame($registered->original_content, $filled->original_content);
    }

    /**
     * 스텝 뒤에는 keep 갱신이 그 행을 평소처럼 병합한다 — 운영자가 고친 곳은 남고 나머지는 새 판이 들어온다.
     *
     * @effects legacy_override_rows_get_a_base_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_keep_refresh_merges_legacy_override_after_backfill(): void
    {
        $row = $this->makeLegacyOverrideRow();
        $this->operatorEdits($row, function (array $c) {
            $c['slots']['content'][0]['text'] = '운영자 안내';

            return $c;
        });

        $this->runStep();

        $this->writeOverride('TITLE2', 'NOTE1');
        $result = app(TemplateManager::class)->refreshTemplateLayouts($this->fixtureTemplate, true);

        $this->assertSame(0, $result['layout_sync']['kept_name_collision_count'], '원본을 채운 뒤에는 이름 충돌로 비켜 가지 않는다');
        $content = $this->flat($this->overrideRow()->content);
        $this->assertStringContainsString('운영자 안내', $content, 'keep 인데 운영자 수정이 덮어써졌다');
        $this->assertStringContainsString('TITLE2', $content, '운영자가 안 고친 meta 에는 새 판이 들어와야 한다');
    }

    /**
     * 파일이 없는 편집기 화면은 건드리지 않고, 두 번 돌려도 같은 결과다.
     *
     * @effects legacy_override_rows_get_a_base_on_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_editor_created_rows_untouched_and_step_is_idempotent(): void
    {
        $row = $this->makeLegacyOverrideRow();
        $editorId = $this->makeEditorCreatedRow();

        $this->runStep();
        $first = DB::table('template_layouts')->whereIn('id', [$row->id, $editorId])->orderBy('id')->get(['id', 'content', 'original_content', 'original_content_hash', 'original_content_size', 'updated_at']);

        $editor = $first->firstWhere('id', $editorId);
        $this->assertNull($editor->original_content_hash, '파일에서 온 적 없는 편집기 화면은 원본을 채우지 않는다');
        $this->assertNull($editor->original_content);

        $this->runStep();
        $second = DB::table('template_layouts')->whereIn('id', [$row->id, $editorId])->orderBy('id')->get(['id', 'content', 'original_content', 'original_content_hash', 'original_content_size', 'updated_at']);

        $this->assertEquals($first->toArray(), $second->toArray(), '두 번째 실행은 아무것도 바꾸지 않는다');
    }
}
