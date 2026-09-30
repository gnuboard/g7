<?php

namespace Tests\Feature\LayoutMarkup;

use App\Enums\ExtensionStatus;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutFileService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 템플릿 레이아웃 갱신이 편집기에서 만든·지운 레이아웃을 올바르게 다루는지 검증합니다 (#703).
 *
 *  - 편집기 「새 파일」 은 행을 `source_type=template, source_identifier=<템플릿>` 으로 만든다. 템플릿 오버라이드
 *    레이아웃과 같은 신원이라, 갱신의 고아 오버라이드 정리가 그 행을 영구 삭제했다(판본 이력까지 연쇄 삭제).
 *  - 편집기에서 지운(soft delete) 번들 레이아웃은 유니크 키 `(template_id, name, source_type)` 를 계속 차지해,
 *    갱신의 생성 분기가 매번 중복 키로 실패하고 레이아웃이 다시 생기지 않았다.
 */
class TemplateLayoutRefreshPreservationTest extends TestCase
{
    use DatabaseTransactions;

    private Template $template;

    private string $root;

    /**
     * 번들 레이아웃 파일 하나를 가진 임시 템플릿을 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $identifier = 'tpl-refresh-'.uniqid();
        $this->template = Template::factory()->create([
            'identifier' => $identifier,
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->root = base_path("templates/{$identifier}");

        File::ensureDirectoryExists($this->root.'/layouts');
        File::put($this->root.'/layouts/refresh_home.json', json_encode([
            'layout_name' => 'refresh_home',
            'components' => [['id' => 'root', 'type' => 'basic', 'name' => 'Div']],
        ]));
    }

    /**
     * 임시 템플릿 디렉토리를 지웁니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 편집기에서 만든 레이아웃은 템플릿 갱신 뒤에도 판본 이력과 함께 남는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects editor_created_layout_survives_template_refresh
     *
     * @return void 없음
     */
    public function test_editor_created_layout_survives_template_refresh(): void
    {
        $created = app(LayoutFileService::class)->create(
            $this->template->id,
            $this->template->identifier,
            'custom/landing',
            ['components' => [['id' => 'root', 'type' => 'basic', 'name' => 'Div']]],
        );

        app(TemplateManager::class)->refreshTemplateLayouts($this->template->identifier);

        $this->assertNotNull(TemplateLayout::find($created->id), '편집기에서 만든 레이아웃이 고아 오버라이드로 영구 삭제됐다');
        $this->assertGreaterThan(0, $created->versions()->count(), '판본 이력이 함께 사라졌다');
    }

    /**
     * 오버라이드 파일에서 등록된 레이아웃은 파일이 사라지면 여전히 정리된다 (정리 자체가 멈추지 않았다).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects editor_created_layout_survives_template_refresh
     *
     * @return void 없음
     */
    public function test_override_layout_whose_file_is_gone_is_still_cleaned(): void
    {
        $overrideDir = $this->root.'/layouts/overrides/vendor-refreshmod';
        File::ensureDirectoryExists($overrideDir);
        File::put($overrideDir.'/index.json', json_encode([
            'layout_name' => 'vendor-refreshmod.index',
            'components' => [['id' => 'root', 'type' => 'basic', 'name' => 'Div']],
        ]));

        $register = new \ReflectionMethod(TemplateManager::class, 'registerLayoutOverrides');
        $register->invoke(app(TemplateManager::class), $this->template->identifier, $this->template->id);
        $this->assertNotNull(TemplateLayout::where('template_id', $this->template->id)->where('name', 'vendor-refreshmod.index')->first());

        File::deleteDirectory($this->root.'/layouts/overrides');
        $register->invoke(app(TemplateManager::class), $this->template->identifier, $this->template->id);

        $this->assertNull(TemplateLayout::withTrashed()->where('template_id', $this->template->id)->where('name', 'vendor-refreshmod.index')->first());
    }

    /**
     * 편집기에서 지운 번들 레이아웃은 다음 갱신에서 파일 기준으로 되살아난다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects deleted_bundled_layout_is_recreated_on_refresh
     *
     * @return void 없음
     */
    public function test_editor_deleted_bundled_layout_is_recreated_on_refresh(): void
    {
        $manager = app(TemplateManager::class);
        $manager->refreshTemplateLayouts($this->template->identifier);

        $row = TemplateLayout::where('template_id', $this->template->id)->where('name', 'refresh_home')->firstOrFail();
        app(LayoutFileService::class)->delete($this->template->id, 'refresh_home', true);
        $this->assertSoftDeleted($row);

        $manager->refreshTemplateLayouts($this->template->identifier);

        $live = TemplateLayout::where('template_id', $this->template->id)->where('name', 'refresh_home')->first();
        $this->assertNotNull($live, '지워진 행이 유니크 키를 차지해 갱신의 생성이 중복 키로 실패했다');
        $this->assertSame($row->id, $live->id, '지워진 행을 되살려 쓴다(판본 이력 승계)');
        $this->assertNull($live->source_identifier);
    }
}
