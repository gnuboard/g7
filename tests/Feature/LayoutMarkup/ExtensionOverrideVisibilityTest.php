<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 템플릿 오버라이드의 「실제로 적용될 행」 판정을 화면 렌더와 편집기 트리가 하나로 쓴다 (#725 W10 · 결정 D5·D9).
 *
 * 종전에는 판정이 세 벌이었다 — 오버레이는 버전 호환까지 보고 원본으로 대체했고(`getVersionAwareOverlays`),
 * 확장점은 원본을 먼저 지운 뒤 비호환 오버라이드를 건너뛰어 **자리가 비었고**, 편집기 트리는 활성 여부를 보지 않아
 * 대상 확장이 꺼진 오버라이드까지 보여 「편집해도 화면에 안 나오는 항목」 이 남았다.
 *
 *  ⓐ 대상 확장이 꺼진 오버라이드는 트리에 없고, 켜면 나타난다(조회 시 판정 — DB 컬럼 없음).
 *  ⓑ 확장점 + 비호환 오버라이드 → 원본으로 대체(호환이면 오버라이드만). 트리도 같은 결과.
 *  ⓒ 오버레이의 기존 대체 동작은 그대로다(회귀).
 */
class ExtensionOverrideVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private string $target;

    private Template $template;

    /**
     * 사용자 템플릿과 오버라이드 대상 플러그인 식별자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $this->target = 'probe-override-target-'.$suffix;
        $this->template = Template::factory()->create(['identifier' => 'probe-ovuser-'.$suffix, 'type' => 'user', 'status' => ExtensionStatus::Active->value]);
    }

    /**
     * ⓐ 대상 플러그인이 꺼져 있으면 오버라이드는 트리에 보이지 않고, 켜면 보인다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects editor_tree_hides_override_of_inactive_target
     *
     * @return void 반환값 없음
     */
    public function test_editor_tree_hides_override_of_inactive_target(): void
    {
        $override = $this->extensionPointRow(LayoutSourceType::Template, 'override_btn');

        $this->bindTargetPlugin(active: false);
        $this->assertNull($this->treeGroup(), '대상이 꺼진 오버라이드가 트리에 보인다 — 편집해도 화면에 나오지 않는 항목');

        $this->bindTargetPlugin(active: true);
        $this->assertSame([$override->id], $this->treeGroup(), '대상을 켜면 오버라이드가 트리에 다시 나타나야 한다');
    }

    /**
     * ⓑ 확장점 오버라이드가 버전 비호환이면 원본 조각이 그 자리를 채우고, 호환이면 오버라이드만 채운다. 트리도 같다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects incompatible_extension_point_override_falls_back_to_original
     *
     * @return void 반환값 없음
     */
    public function test_incompatible_extension_point_override_falls_back_to_original(): void
    {
        $original = $this->extensionPointRow(LayoutSourceType::Plugin, 'orig_btn');
        $override = $this->extensionPointRow(LayoutSourceType::Template, 'override_btn', '>=99.0.0');
        $this->bindTargetPlugin(active: true);

        $this->assertSame(['orig_btn'], $this->servedSlotIds(), '비호환 오버라이드가 있는 확장점이 빈 자리가 됐다');
        $this->assertSame([$original->id], $this->treeGroup(), '트리가 화면과 다른 행을 보여 준다');

        $override->update(['content' => array_merge($override->content, ['version_constraint' => '>=1.0.0'])]);

        $this->assertSame(['override_btn'], $this->servedSlotIds(), '호환 오버라이드는 원본을 대체해야 한다');
        $this->assertSame([$override->id], $this->treeGroup());
    }

    /**
     * ⓒ 오버레이 오버라이드의 버전 대체는 종전 그대로다 — 비호환이면 원본, 호환이면 오버라이드.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects overlay_version_fallback_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_overlay_version_fallback_is_unchanged(): void
    {
        $this->overlayRow(LayoutSourceType::Plugin, 'orig_overlay');
        $override = $this->overlayRow(LayoutSourceType::Template, 'override_overlay', '>=99.0.0');
        $this->bindTargetPlugin(active: true);

        $this->assertSame(['orig_overlay'], $this->servedAnchorIds());

        $override->update(['content' => array_merge($override->content, ['version_constraint' => '>=1.0.0'])]);

        $this->assertSame(['override_overlay'], $this->servedAnchorIds());
    }

    /**
     * 호환 오버라이드는 **자기 대상의 원본만** 가린다 — 같은 자리의 다른 출처 행과 오버라이드 없는 범위는 그대로다.
     *
     * 저장소 해석 함수(`resolveOverrides`·`getResolved*`)를 서비스 판정 한 벌로 옮기며(#725) 저장소 단위 테스트에서
     * 이관한 관측이다 — 편집기 트리가 화면에 적용되지 않는 「가려진 행」 을 보여 주던 회귀의 잠금 포함.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects override_hides_only_its_target_original_in_render_and_tree
     *
     * @return void 반환값 없음
     */
    public function test_override_hides_only_its_target_original(): void
    {
        $original = $this->extensionPointRow(LayoutSourceType::Plugin, 'orig_btn');
        $override = $this->extensionPointRow(LayoutSourceType::Template, 'override_btn');
        $other = LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'probe_slot',
            'fragment_key' => 'other_btn',
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->template->identifier,
            'override_target' => null,
            'content' => ['extension_point' => 'probe_slot', 'components' => [['id' => 'other_btn', 'type' => 'basic', 'name' => 'Button']]],
            'priority' => 200,
            'is_active' => true,
        ]);
        $unrelated = $this->overlayRow(LayoutSourceType::Plugin, 'orig_overlay');
        $this->bindTargetPlugin(active: true);

        $this->assertSame(['override_btn', 'other_btn'], $this->servedSlotIds(), '오버라이드는 자기 대상 원본만 가려야 한다');
        $this->assertEqualsCanonicalizing([$override->id, $unrelated->id], $this->treeGroup(), '가려진 원본이 트리에 남았다');
        $this->assertNotContains($original->id, $this->treeGroup());

        $override->forceDelete();

        $this->assertSame(['orig_btn', 'other_btn'], $this->servedSlotIds(), '오버라이드가 없으면 원본이 그대로 적용된다');
        $this->assertEqualsCanonicalizing([$original->id, $unrelated->id], $this->treeGroup());
        $this->assertNotNull($other->fresh());
    }

    /**
     * 확장점 조각 행을 만듭니다 (원본 플러그인 또는 템플릿 오버라이드).
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $componentId  주입 노드 id
     * @param  string|null  $constraint  오버라이드 버전 제약
     * @return LayoutExtension 만든 행
     */
    private function extensionPointRow(LayoutSourceType $sourceType, string $componentId, ?string $constraint = null): LayoutExtension
    {
        $content = ['extension_point' => 'probe_slot', 'components' => [['id' => $componentId, 'type' => 'basic', 'name' => 'Button']]];

        return $this->createRow(LayoutExtensionType::ExtensionPoint, 'probe_slot', $sourceType, $componentId, $content, $constraint);
    }

    /**
     * 오버레이 조각 행을 만듭니다 (원본 플러그인 또는 템플릿 오버라이드).
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $componentId  주입 노드 id
     * @param  string|null  $constraint  오버라이드 버전 제약
     * @return LayoutExtension 만든 행
     */
    private function overlayRow(LayoutSourceType $sourceType, string $componentId, ?string $constraint = null): LayoutExtension
    {
        $content = ['target_layout' => 'probe_page', 'injections' => [['target_id' => 'anchor', 'position' => 'append_child', 'components' => [['id' => $componentId, 'type' => 'basic', 'name' => 'Span']]]]];

        return $this->createRow(LayoutExtensionType::Overlay, 'probe_page', $sourceType, $componentId, $content, $constraint);
    }

    /**
     * 조각 행을 만듭니다.
     *
     * @param  LayoutExtensionType  $type  확장 타입
     * @param  string  $targetName  대상 이름
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $key  조각 식별자
     * @param  array<string, mixed>  $content  본문
     * @param  string|null  $constraint  오버라이드 버전 제약
     * @return LayoutExtension 만든 행
     */
    private function createRow(LayoutExtensionType $type, string $targetName, LayoutSourceType $sourceType, string $key, array $content, ?string $constraint): LayoutExtension
    {
        $isOverride = $sourceType === LayoutSourceType::Template;

        if ($constraint !== null) {
            $content['version_constraint'] = $constraint;
        }

        return LayoutExtension::create([
            'template_id' => $this->template->id,
            'extension_type' => $type,
            'target_name' => $targetName,
            'fragment_key' => $key,
            'source_type' => $sourceType,
            'source_identifier' => $isOverride ? $this->template->identifier : $this->target,
            'override_target' => $isOverride ? $this->target : null,
            'content' => $content,
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /**
     * 오버라이드 대상 플러그인의 활성 상태·버전(1.0.0)을 대역으로 바인딩합니다.
     *
     * @param  bool  $active  활성 여부
     * @return void 반환값 없음
     */
    private function bindTargetPlugin(bool $active): void
    {
        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getIdentifier')->willReturn($this->target);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn([]);
        $moduleManager->method('getModuleVersion')->willReturn(null);
        $moduleManager->method('getModule')->willReturn(null);
        $this->app->instance(ModuleManager::class, $moduleManager);

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn($active ? [$this->target => $plugin] : []);
        $pluginManager->method('getPluginVersion')->willReturnCallback(fn (string $id): ?string => $id === $this->target ? '1.0.0' : null);
        $pluginManager->method('getPlugin')->willReturnCallback(fn (string $id) => $id === $this->target ? $plugin : null);
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * 편집기 트리에서 대상 플러그인 그룹의 행 ID 목록을 얻습니다 (그룹이 없으면 null).
     *
     * @return array<int, int>|null 행 ID 목록
     */
    private function treeGroup(): ?array
    {
        $groups = $this->app->make(LayoutExtensionService::class)->getExtensionsByTemplateId($this->template->id);

        foreach ($groups as $group) {
            if ($group['source_identifier'] === $this->target) {
                return array_map(static fn (LayoutExtension $extension): int => $extension->id, $group['extensions']);
            }
        }

        return null;
    }

    /**
     * 확장점이 있는 화면을 서빙 조립해 그 자리에 들어간 노드 id 목록을 얻습니다.
     *
     * @return array<int, string> 노드 id 목록
     */
    private function servedSlotIds(): array
    {
        $layout = $this->app->make(LayoutExtensionService::class)->applyExtensions([
            'layout_name' => 'probe_slot_page',
            'components' => [['id' => 'host', 'type' => 'basic', 'name' => 'Div', 'children' => [['type' => 'extension_point', 'name' => 'probe_slot']]]],
        ], $this->template->id);

        return array_column($layout['components'][0]['children'][0]['children'] ?? [], 'id');
    }

    /**
     * 오버레이 대상 화면을 서빙 조립해 주입 대상 노드의 자식 id 목록을 얻습니다.
     *
     * @return array<int, string> 노드 id 목록
     */
    private function servedAnchorIds(): array
    {
        $layout = $this->app->make(LayoutExtensionService::class)->applyExtensions([
            'layout_name' => 'probe_page',
            'components' => [['id' => 'anchor', 'type' => 'basic', 'name' => 'Div', 'children' => []]],
        ], $this->template->id);

        return array_column($layout['components'][0]['children'] ?? [], 'id');
    }
}
