<?php

namespace Tests\Feature\LayoutMarkup;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\TemplateManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use App\Support\LayoutMarkup\EmptyMap;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 손대지 않은 레이아웃·확장이 "사용자 수정" 으로 판정되지 않는지 검증합니다 (#703).
 *
 * `.g7.html` 컴파일 산출은 빈 map 을 {@see EmptyMap} 표식으로 들고 있어 원본 해시를 계산할 때 `{}` 로
 * 직렬화된다. 그런데 DB 에서 읽은 content 는 `'array'` 캐스트를 거쳐 `{}` 가 `[]` 가 되므로, 현재 해시를
 * 다시 계산하면 크기는 같은데 해시만 달라 **한 번도 편집하지 않은 행이 수정본**으로 보였다(개발 DB 실측:
 * template_layouts 165행 중 61행, 판본 이력 0건). 결과는 keep 전략이 파일 갱신을 건너뛰고, 업데이트 모달이
 * 수정본 목록을 부풀리는 것이다.
 */
class LayoutContentHashEmptyMapTest extends TestCase
{
    use DatabaseTransactions;

    private Template $template;

    /**
     * 임시 템플릿을 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::factory()->create([
            'identifier' => 'tpl-hash-'.uniqid(),
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 빈 map 을 담은 컴파일 산출 모양의 확장 정의를 만듭니다.
     *
     * @param  string  $extensionPoint  확장점 이름
     * @return array<string, mixed> 확장 정의
     */
    private function compiledExtension(string $extensionPoint): array
    {
        return [
            'extension_point' => $extensionPoint,
            'priority' => 100,
            'components' => [
                ['type' => 'basic', 'name' => 'Div', 'props' => EmptyMap::instance()],
            ],
        ];
    }

    /**
     * 파일에서 등록한 확장을 그대로 두면 수정본 목록에 나오지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects unedited_rows_with_empty_maps_are_not_judged_modified
     *
     * @return void 없음
     */
    public function test_registered_extension_with_empty_map_is_not_reported_modified(): void
    {
        $service = app(LayoutExtensionService::class);
        $service->registerExtension($this->compiledExtension('hash_slot'), LayoutSourceType::Module, 'vendor-hashmod', $this->template->id, false, 'hash-fragment');

        $this->assertSame([], $service->hasModifiedExtensions($this->template->id), '편집하지 않은 확장이 수정본으로 보인다');
    }

    /**
     * keep 전략으로 다시 등록하면 편집하지 않은 확장은 파일 기준으로 갱신된다(건너뛰지 않는다).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects unedited_rows_with_empty_maps_are_not_judged_modified
     *
     * @return void 없음
     */
    public function test_keep_strategy_updates_unedited_extension_with_empty_map(): void
    {
        $service = app(LayoutExtensionService::class);
        $service->registerExtension($this->compiledExtension('hash_slot'), LayoutSourceType::Module, 'vendor-hashmod', $this->template->id, false, 'hash-fragment');

        $next = $this->compiledExtension('hash_slot');
        $next['priority'] = 50;

        $result = $service->registerExtension($next, LayoutSourceType::Module, 'vendor-hashmod', $this->template->id, true, 'hash-fragment');

        $this->assertSame('updated', $result, '편집하지 않은 확장을 사용자 수정으로 보고 갱신을 건너뛰었다');
    }

    /**
     * 표준형 도입 전에 `{}` 를 담아 저장된 원본 해시도 수정 안 됨으로 판정한다 (기존 행 — 데이터 이관 없이).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects unedited_rows_with_empty_maps_are_not_judged_modified
     *
     * @return void 없음
     */
    public function test_legacy_hash_stored_with_object_empty_map_still_matches(): void
    {
        $content = [
            'layout_name' => 'hash_home',
            'components' => [['type' => 'basic', 'name' => 'Div', 'props' => EmptyMap::instance()]],
        ];
        $legacyHash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'hash_home',
            'content' => $content,
            'source_type' => 'template',
            'source_identifier' => null,
            'original_content_hash' => $legacyHash,
            'original_content_size' => strlen(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);

        LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => 'extension_point',
            'target_name' => 'legacy_slot',
            'fragment_key' => 'legacy-fragment',
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'vendor-hashmod',
            'content' => $this->compiledExtension('legacy_slot'),
            'original_content_hash' => hash('sha256', json_encode($this->compiledExtension('legacy_slot'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'priority' => 100,
            'is_active' => true,
        ]);

        $layouts = app(TemplateManager::class)->hasModifiedLayouts($this->template->identifier);

        $this->assertSame(0, $layouts['modified_count'], '편집하지 않은 템플릿 레이아웃이 수정본으로 집계됐다');
        $this->assertSame([], app(LayoutExtensionService::class)->hasModifiedExtensions($this->template->id));
    }

    /**
     * 실제로 편집한 행은 여전히 수정본이다 (판정이 느슨해지지 않았다).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects edited_rows_are_still_judged_modified
     *
     * @return void 없음
     */
    public function test_edited_extension_is_still_reported_modified(): void
    {
        $service = app(LayoutExtensionService::class);
        $service->registerExtension($this->compiledExtension('hash_slot'), LayoutSourceType::Module, 'vendor-hashmod', $this->template->id, false, 'hash-fragment');

        $row = LayoutExtension::where('template_id', $this->template->id)->firstOrFail();
        $edited = $row->content;
        $edited['components'][] = ['type' => 'basic', 'name' => 'Span'];
        $row->update(['content' => $edited]);

        $this->assertCount(1, $service->hasModifiedExtensions($this->template->id));
        $this->assertSame('skipped', $service->registerExtension($this->compiledExtension('hash_slot'), LayoutSourceType::Module, 'vendor-hashmod', $this->template->id, true, 'hash-fragment'));
    }
}
