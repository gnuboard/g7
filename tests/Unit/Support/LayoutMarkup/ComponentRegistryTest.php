<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ComponentRegistry 단위 테스트 (`.g7.html` 마크업 형식 S2 — 태그 해석 소스).
 *
 * 이 층이 지켜야 하는 계약은 둘이다:
 *
 *  1. **런타임과 같은 강도의 매니페스트 검증** — 작성 시점에 통과시켜 봐야 런타임이
 *     `MANIFEST_INVALID` 로 거부하면 화면이 통째로 죽는다(ComponentRegistry.ts:287-347).
 *  2. **결정적 태그 해석** — 한 태그가 한 컴포넌트로만 해석되어야 한다. 런타임은 같은 이름이
 *     겹치면 나중 등록이 조용히 덮어쓰지만, 컴파일러는 침묵 승자를 만들지 않는다.
 */
class ComponentRegistryTest extends LayoutMarkupTestCase
{
    // ---------------------------------------------------------------------
    // 태그 해석
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects component_name_maps_to_kebab_tag
     */
    #[DataProvider('tagProvider')]
    public function test_component_name_maps_to_kebab_tag(string $name, string $tag): void
    {
        $this->assertSame($tag, ComponentRegistry::toTag($name));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tagProvider(): array
    {
        return [
            '합성어' => ['DataGrid', 'data-grid'],
            '3단 합성어' => ['MultilingualTagInput', 'multilingual-tag-input'],
            'HTML 표준명' => ['Div', 'div'],
            '한 글자' => ['A', 'a'],
            '숫자 접미' => ['H2', 'h2'],
            '연속 대문자' => ['HtmlContent', 'html-content'],
        ];
    }

    /**
     * @effects bundled_template_manifests_resolve_without_tag_collision
     */
    #[DataProvider('bundledTemplateProvider')]
    public function test_bundled_template_manifest_loads_without_tag_collision(string $template): void
    {
        // 태그가 겹치면 로드 자체가 실패한다. 이 테스트는 번들 템플릿이 그 전제를 계속
        // 만족하는지 지키는 회귀다 — 신규 컴포넌트가 기존 태그와 겹치면 여기서 잡힌다.
        $registry = ComponentRegistry::fromFile(base_path("templates/_bundled/{$template}/components.json"));

        $this->assertNotEmpty($registry->tags());
        $this->assertSame($template, $registry->templateId);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bundledTemplateProvider(): array
    {
        return [
            'admin' => ['sirsoft-admin_basic'],
            'user' => ['sirsoft-basic'],
        ];
    }

    /**
     * @effects entry_type_field_wins_over_category_array
     */
    public function test_node_type_comes_from_entry_field_not_category_array(): void
    {
        // admin_basic 의 RichSelect 는 layout 배열에 실려 있으나 엔트리는 composite 를 선언한다.
        // 레이아웃 JSON 실사용도 composite 이고, 런타임이 등록하는 메타데이터가 엔트리 객체다.
        $registry = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));

        $this->assertSame('composite', $registry->resolveTag('rich-select')?->type);
        $this->assertSame('layout', $registry->resolveTag('container')?->type);
        $this->assertSame('basic', $registry->resolveTag('div')?->type);
    }

    /**
     * @effects unknown_tag_is_rejected_with_registry_suggestion
     */
    public function test_suggests_nearby_tag_only_within_typo_distance(): void
    {
        $registry = $this->registry();

        $this->assertSame('data-grid', $registry->suggestTag('data-gird'));
        $this->assertNull($registry->suggestTag('completely-unrelated-name'));
    }

    // ---------------------------------------------------------------------
    // 선평가 금지 키 병합
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects skip_binding_keys_merge_defaults_with_manifest_declarations
     */
    public function test_skip_binding_keys_merge_defaults_with_manifest_declaration(): void
    {
        // 하드코딩 기본 4종만 쓰면 DataGrid 의 subRowChildren/subRowCondition 이 빠져
        // 선평가 패리티가 깨진다(런타임은 둘을 합집합으로 병합 — DynamicRenderer.tsx:2246).
        $registry = ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json'));
        $grid = $registry->resolveTag('data-grid');

        foreach (ComponentRegistry::DEFAULT_SKIP_BINDING_KEYS as $key) {
            $this->assertTrue($grid->shouldSkipBinding($key), "기본 선평가 금지 키 누락: {$key}");
        }

        $this->assertTrue($grid->shouldSkipBinding('subRowChildren'));
        $this->assertTrue($grid->shouldSkipBinding('subRowCondition'));
        $this->assertFalse($grid->shouldSkipBinding('columns'));
    }

    /**
     * @effects skip_binding_keys_merge_defaults_with_manifest_declarations
     */
    public function test_component_without_declaration_still_gets_defaults(): void
    {
        $div = $this->registry()->resolveTag('div');

        $this->assertSame(ComponentRegistry::DEFAULT_SKIP_BINDING_KEYS, $div->skipBindingKeys);
    }

    // ---------------------------------------------------------------------
    // 매니페스트 검증
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects manifest_violating_runtime_schema_is_rejected
     */
    #[DataProvider('invalidManifestProvider')]
    public function test_manifest_violating_runtime_schema_is_rejected(array $manifest, string $field): void
    {
        $error = $this->loadError($manifest);

        $this->assertSame(LayoutMarkupErrorCode::ManifestInvalid, $error->getErrorCode());
        $this->assertStringContainsString($field, $error->getDetail());
    }

    /**
     * @return array<string, array{0: array<mixed>, 1: string}>
     */
    public static function invalidManifestProvider(): array
    {
        $components = ['basic' => [], 'composite' => [], 'layout' => []];

        return [
            'version 누락' => [['templateId' => 't', 'components' => $components], 'version'],
            'components 누락' => [['version' => '1.0.0', 'templateId' => 't'], 'components'],
            '분류 배열 누락' => [
                ['version' => '1.0.0', 'templateId' => 't', 'components' => ['basic' => []]],
                'components.composite',
            ],
            // gnuboard7-hello_admin_template 이 실제로 이 형태다 — 배열이어야 할 자리에 객체 맵.
            '분류가 객체 맵' => [
                ['version' => '1.0.0', 'templateId' => 't', 'components' => ['basic' => ['Div' => []], 'composite' => [], 'layout' => []]],
                'components.basic',
            ],
            'name 누락' => [
                ['version' => '1.0.0', 'templateId' => 't', 'components' => ['basic' => [['type' => 'basic']], 'composite' => [], 'layout' => []]],
                'components.basic[0].name',
            ],
            'type 가 알 수 없는 값' => [
                ['version' => '1.0.0', 'templateId' => 't', 'components' => ['basic' => [['name' => 'Div', 'type' => 'widget']], 'composite' => [], 'layout' => []]],
                'components.basic[0].type',
            ],
            // 아래 넷은 런타임이 falsy 검사만 해서 비대칭이 생기는 자리다. templateId 는
            // 식별자로 그대로 쓰이므로 문자열이 아닌 값이 통과하면 "Array" 같은 유령
            // 식별자가 만들어진다 — 작성 시점에 막는다.
            'version 이 빈 문자열' => [['version' => '', 'templateId' => 't', 'components' => $components], 'version'],
            'version 이 숫자' => [['version' => 100, 'templateId' => 't', 'components' => $components], 'version'],
            'templateId 가 false' => [['version' => '1.0.0', 'templateId' => false, 'components' => $components], 'templateId'],
            'templateId 가 배열' => [['version' => '1.0.0', 'templateId' => ['t'], 'components' => $components], 'templateId'],
        ];
    }

    /**
     * @effects ambiguous_tag_registration_is_rejected
     */
    public function test_two_components_resolving_to_the_same_tag_are_rejected(): void
    {
        $error = $this->loadError([
            'version' => '1.0.0',
            'templateId' => 't',
            'components' => [
                'basic' => [['name' => 'DataGrid', 'type' => 'basic']],
                'composite' => [['name' => 'DataGrid', 'type' => 'composite']],
                'layout' => [],
            ],
        ]);

        $this->assertSame(LayoutMarkupErrorCode::AmbiguousComponentTag, $error->getErrorCode());
        $this->assertStringContainsString('data-grid', $error->getDetail());
    }

    /**
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects unreadable_or_malformed_manifest_file_is_rejected
     */
    public function test_missing_manifest_file_is_rejected(): void
    {
        try {
            ComponentRegistry::fromFile(base_path('templates/_bundled/does-not-exist/components.json'));
            $this->fail('컴파일 오류를 기대했으나 통과했습니다.');
        } catch (CompileError $error) {
            $this->assertSame(LayoutMarkupErrorCode::ManifestUnreadable, $error->getErrorCode());
            // 좌표가 없는 오류라 "1행 1열" 같은 없는 위치를 지어내지 않는다.
            $this->assertSame(0, $error->getSourceLine());
            $this->assertStringNotContainsString('행', $error->getMessage());
        }
    }

    /**
     * 테스트용 최소 레지스트리를 만듭니다.
     *
     * @return ComponentRegistry 레지스트리
     */
    private function registry(): ComponentRegistry
    {
        return ComponentRegistry::fromArray([
            'version' => '1.0.0',
            'templateId' => 'test-template',
            'components' => [
                'basic' => [['name' => 'Div', 'type' => 'basic']],
                'composite' => [['name' => 'DataGrid', 'type' => 'composite', 'skipBindingKeys' => ['subRowChildren']]],
                'layout' => [],
            ],
        ]);
    }

    /**
     * 매니페스트 로드가 실패할 것을 전제로 오류를 잡아 반환합니다.
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @return CompileError 발생한 오류
     */
    private function loadError(array $manifest): CompileError
    {
        try {
            ComponentRegistry::fromArray($manifest, 'components.json');
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail('매니페스트 오류를 기대했으나 통과했습니다.');
    }
}
