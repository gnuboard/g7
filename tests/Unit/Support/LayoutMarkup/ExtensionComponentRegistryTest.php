<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\ComponentOwnerKind;
use App\Enums\LayoutMarkupErrorCode;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentManifest;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 확장 컴포넌트 3개념 단위 테스트 (`.g7.html` 마크업 형식 S2.5 — D25).
 *
 * 이 층이 지켜야 하는 계약:
 *
 *  1. **제공** — 확장 컴포넌트는 `{식별자}` 프리픽스 태그로만 작성되고, 노드 `name` 은 핸들러
 *     관례와 같은 `{식별자}.{이름}` 이다. 프리픽스 덕에 템플릿·타 확장과의 충돌이 구조적으로
 *     불가능하다.
 *  2. **오버라이드** — 템플릿만 선언할 수 있고, 승패는 집합 필터링이며(priority 아님), 버전
 *     비호환이면 원본으로 폴백한다.
 *  3. **템플릿 전용 판본(`for`)** — 확장만 선언할 수 있고, 컴파일러의 태그 해석을 바꾸지 않는다
 *     (판본 선택은 런타임 관심사).
 *
 * 그리고 세 개념 어디에서도 **침묵 승자를 만들지 않는다** — 런타임이 나중 등록으로 조용히
 * 덮어쓰는 자리(ComponentRegistry.ts:456)를 컴파일 오류로 끌어올린다.
 */
class ExtensionComponentRegistryTest extends LayoutMarkupTestCase
{
    // ---------------------------------------------------------------------
    // 매니페스트 소유자 판정 (스키마 통일)
    // ---------------------------------------------------------------------

    /**
     * 주인 종류는 선언한 필드(templateId·identifier)로 정해진다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects manifest_owner_kind_resolved_from_declaring_field
     *
     * @return void 반환값 없음
     */
    public function test_owner_kind_is_resolved_from_the_declaring_field(): void
    {
        $this->assertSame(ComponentOwnerKind::Template, $this->templateManifest()->ownerKind);
        $this->assertSame(ComponentOwnerKind::Extension, $this->extensionManifest()->ownerKind);
    }

    /**
     * 주인(템플릿·확장)을 선언하지 않은 매니페스트는 거부된다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects manifest_without_owner_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_manifest_without_an_owner_is_rejected(): void
    {
        $error = $this->manifestError([
            'version' => '1.0.0',
            'components' => $this->emptyComponents(),
        ]);

        $this->assertSame(LayoutMarkupErrorCode::ManifestOwnerMissing, $error->getErrorCode());
    }

    /**
     * 두 소유자 필드를 함께 적으면 프리픽스 없는 템플릿 좌표인지 `{id}.{name}` 확장 좌표인지
     * 매니페스트만으로 정할 수 없다. 침묵 선택 대신 거부한다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects manifest_with_two_owners_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_manifest_declaring_both_owners_is_rejected(): void
    {
        $error = $this->manifestError([
            'version' => '1.0.0',
            'templateId' => 'sirsoft-basic',
            'identifier' => 'sirsoft-ecommerce',
            'components' => $this->emptyComponents(),
        ]);

        $this->assertSame(LayoutMarkupErrorCode::ManifestOwnerAmbiguous, $error->getErrorCode());
    }

    // ---------------------------------------------------------------------
    // 개념 ① 제공 — 프리픽스 태그
    // ---------------------------------------------------------------------

    /**
     * 확장 컴포넌트는 `식별자.케밥` 태그로 쓰고 이름은 `식별자.이름` 이 된다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects extension_component_gets_prefixed_tag_and_namespaced_name
     *
     * @return void 반환값 없음
     */
    public function test_extension_component_is_authored_with_a_prefixed_tag(): void
    {
        $registry = ComponentRegistry::fromManifests($this->templateManifest(), [$this->extensionManifest()]);

        // `식별자.케밥` — 점 앞은 확장 식별자 그대로라 목록 없이도 JSON 의 `식별자.이름` 으로 되돌린다.
        $card = $registry->resolveTag('sirsoft-ecommerce.product-card');
        $this->assertSame('sirsoft-ecommerce.ProductCard', ComponentRegistry::fromTag('sirsoft-ecommerce.product-card'));

        $this->assertNotNull($card);
        // 노드 name = 런타임 레지스트리 키. 핸들러의 `{identifier}.{name}` 관례와 같다.
        $this->assertSame('sirsoft-ecommerce.ProductCard', $card->name);
        $this->assertSame('composite', $card->type);
        $this->assertSame('sirsoft-ecommerce', $card->owner);
        $this->assertTrue($card->isExtensionOwned());
    }

    /**
     * 템플릿 컴포넌트는 프리픽스를 받지 않는다 — 확장 컴포넌트가 합류해도 기존 마크업이 그대로다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects template_component_tag_unchanged_by_extension_merge
     *
     * @return void 반환값 없음
     */
    public function test_template_components_keep_their_bare_tags(): void
    {
        $registry = ComponentRegistry::fromManifests($this->templateManifest(), [$this->extensionManifest()]);

        $div = $registry->resolveTag('div');

        $this->assertNotNull($div);
        $this->assertSame('Div', $div->name);
        $this->assertNull($div->owner);
        $this->assertFalse($div->isExtensionOwned());
    }

    /**
     * 번들 확장 14개 중 7개가 `gnuboard7-hello_module`·`sirsoft-pay_kginicis` 처럼 밑줄을 품는다.
     * 태그는 식별자를 **그대로** 싣는다(`식별자.케밥`) — 종전처럼 `_` 를 `-` 로 접으면 경계와 원문이
     * 사라져 목록 없이는 이름을 되돌릴 수 없었다(2026-09-28 결정: 컴파일은 목록과 무관).
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects extension_component_tag_keeps_identifier_verbatim
     *
     * @return void 반환값 없음
     */
    public function test_underscored_extension_id_is_kept_verbatim_in_the_tag(): void
    {
        $manifest = ComponentManifest::fromArray([
            'version' => '0.1.0',
            'identifier' => 'gnuboard7-hello_module',
            'components' => [
                'basic' => [],
                'composite' => [['name' => 'MemoCard', 'type' => 'composite']],
                'layout' => [],
            ],
        ], 'hello.json');

        $registry = ComponentRegistry::fromManifests($this->templateManifest(), [$manifest]);

        $card = $registry->resolveTag('gnuboard7-hello_module.memo-card');

        $this->assertNotNull($card);
        $this->assertSame('gnuboard7-hello_module.MemoCard', $card->name);
        $this->assertSame('gnuboard7-hello_module.MemoCard', ComponentRegistry::fromTag('gnuboard7-hello_module.memo-card'));
        $this->assertNull($registry->resolveTag('gnuboard7-hello-module-memo-card'));
    }

    /**
     * 식별자는 시스템 유일이지만 태그 프리픽스는 `_` 를 접은 결과라 유일성이 깨질 수 있다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects extension_ids_differing_by_underscore_stay_distinct
     *
     * @return void 반환값 없음
     */
    public function test_extension_ids_differing_only_by_underscore_stay_distinct(): void
    {
        // 식별자를 그대로 싣으므로 밑줄·하이픈만 다른 두 확장도 태그가 갈린다 — 접어서 생기던 충돌이 없다.
        $registry = ComponentRegistry::fromManifests($this->templateManifest(), [
            $this->extensionManifest('vendor-pay_x', ['Form']),
            $this->extensionManifest('vendor-pay-x', ['Sheet']),
        ]);

        $this->assertSame('vendor-pay_x.Form', $registry->resolveTag('vendor-pay_x.form')?->name);
        $this->assertSame('vendor-pay-x.Sheet', $registry->resolveTag('vendor-pay-x.sheet')?->name);
    }

    /**
     * 확장 태그는 점을 품으므로 템플릿 컴포넌트 태그와 구조적으로 겹치지 않는다 — 종전에는 템플릿이
     * 확장 프리픽스와 같은 이름(`SirsoftEcommerceProductCard`)을 쓰면 같은 태그가 만들어졌다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects extension_tag_cannot_collide_with_template_tag
     *
     * @return void 반환값 없음
     */
    public function test_template_name_resembling_an_extension_tag_stays_distinct(): void
    {
        $template = ComponentManifest::fromArray([
            'version' => '1.0.0',
            'templateId' => 'sirsoft-basic',
            'components' => [
                'basic' => [],
                // <sirsoft-ecommerce-product-card> — 확장 컴포넌트와 같은 태그로 접힌다.
                'composite' => [['name' => 'SirsoftEcommerceProductCard', 'type' => 'composite']],
                'layout' => [],
            ],
        ], 'template.json');

        $registry = ComponentRegistry::fromManifests($template, [$this->extensionManifest()]);

        $this->assertSame('SirsoftEcommerceProductCard', $registry->resolveTag('sirsoft-ecommerce-product-card')?->name);
        $this->assertSame('sirsoft-ecommerce.ProductCard', $registry->resolveTag('sirsoft-ecommerce.product-card')?->name);
    }

    // ---------------------------------------------------------------------
    // 개념 ② 오버라이드 — 템플릿 전용 권한
    // ---------------------------------------------------------------------

    /**
     * 승패는 집합 필터링이다 — 원본이 집합에서 빠지고 대체본이 같은 좌표에 들어앉는다.
     * 태그도 노드 name 도 그대로라 마크업은 바뀌지 않는다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects template_override_replaces_extension_component_in_place
     *
     * @return void 반환값 없음
     */
    public function test_template_override_replaces_the_original_at_the_same_coordinate(): void
    {
        $registry = ComponentRegistry::fromManifests(
            $this->templateManifest(overrides: [[
                'target' => 'sirsoft-ecommerce',
                'targetVersion' => '^1.0',
                'name' => 'ProductCard',
                'type' => 'layout',
            ]]),
            [$this->extensionManifest()]
        );

        $card = $registry->resolveTag('sirsoft-ecommerce.product-card');

        $this->assertNotNull($card);
        $this->assertSame('sirsoft-ecommerce.ProductCard', $card->name);
        // 대체본의 엔트리 메타가 이긴다 — 원본은 composite 였다.
        $this->assertSame('layout', $card->type);
    }

    /**
     * 버전 비호환 → 대체본만 폐기되고 원본이 자리를 지킨다(레이아웃 선례 :2089-2101).
     * 화면이 사라지지 않는 것이 이 규약의 요점이다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects incompatible_override_falls_back_to_original
     *
     * @return void 반환값 없음
     */
    public function test_override_with_an_incompatible_constraint_falls_back_to_the_original(): void
    {
        $registry = ComponentRegistry::fromManifests(
            $this->templateManifest(overrides: [[
                'target' => 'sirsoft-ecommerce',
                'targetVersion' => '^9.0',
                'name' => 'ProductCard',
                'type' => 'layout',
            ]]),
            [$this->extensionManifest()]
        );

        $card = $registry->resolveTag('sirsoft-ecommerce.product-card');

        $this->assertNotNull($card);
        $this->assertSame('composite', $card->type, '비호환 오버라이드가 적용되어 원본이 밀려났습니다.');
    }

    /**
     * 대상 확장이 없으면 선언은 사문이다 — 오류가 아니라 무시. 나중에 설치될 수 있고, 아직
     * 없는 확장을 위해 대체본을 미리 두는 것도 정상이다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects override_for_absent_extension_is_inert
     *
     * @return void 반환값 없음
     */
    public function test_override_targeting_an_absent_extension_is_ignored(): void
    {
        $registry = ComponentRegistry::fromManifests(
            $this->templateManifest(overrides: [[
                'target' => 'vendor-not-installed',
                'name' => 'Ghost',
                'type' => 'composite',
            ]]),
            []
        );

        $this->assertNull($registry->resolveTag('vendor-not-installed-ghost'));
        $this->assertNotNull($registry->resolveTag('div'));
    }

    /**
     * 대상이 활성인데 이름이 없다면 오타이거나 제약이 틀린 것이다 — 둘 다 작성자가 고쳐야 한다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects override_naming_unknown_component_of_active_extension_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_override_naming_a_component_the_target_does_not_have_is_rejected(): void
    {
        $error = $this->registryError(
            $this->templateManifest(overrides: [[
                'target' => 'sirsoft-ecommerce',
                'name' => 'NoSuchCard',
                'type' => 'composite',
            ]]),
            [$this->extensionManifest()]
        );

        $this->assertSame(LayoutMarkupErrorCode::OverrideTargetComponentUnknown, $error->getErrorCode());
        $this->assertStringContainsString('NoSuchCard', $error->getDetail());
    }

    /**
     * 오버라이드 권한은 템플릿 전용이다 — 레이아웃 오버라이드가 `source_type=template` 일 때만
     * 성립하는 것과 같은 규약(migration `:22`). 확장이 남의 것을 대체하기 시작하면 교착이 생기고
     * 그 승자 규칙은 선례가 없다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects extension_declaring_overrides_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_extension_cannot_declare_overrides(): void
    {
        $error = $this->manifestError([
            'version' => '1.0.0',
            'identifier' => 'sirsoft-ecommerce',
            'components' => $this->emptyComponents(),
            'overrides' => [['target' => 'sirsoft-board', 'name' => 'PostCard', 'type' => 'composite']],
        ]);

        $this->assertSame(LayoutMarkupErrorCode::ExtensionOverridesForbidden, $error->getErrorCode());
    }

    /**
     * 같은 컴포넌트를 두 번 오버라이드하면 거부된다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects duplicate_override_of_same_component_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_overriding_the_same_component_twice_is_rejected(): void
    {
        $error = $this->manifestError([
            'version' => '1.0.0',
            'templateId' => 'sirsoft-basic',
            'components' => $this->emptyComponents(),
            'overrides' => [
                ['target' => 'sirsoft-ecommerce', 'name' => 'ProductCard', 'type' => 'composite'],
                ['target' => 'sirsoft-ecommerce', 'name' => 'ProductCard', 'type' => 'layout'],
            ],
        ]);

        $this->assertSame(LayoutMarkupErrorCode::DuplicateOverrideTarget, $error->getErrorCode());
    }

    // ---------------------------------------------------------------------
    // 개념 ③ 템플릿 전용 판본 (`for`)
    // ---------------------------------------------------------------------

    /**
     * 판본 선택은 런타임 관심사다 — 어느 판본이든 같은 이름·같은 태그로 해석되고, props 는
     * verbatim 통과(D19)라 컴파일 계약이 갈리지 않는다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects for_variant_does_not_change_tag_resolution
     *
     * @return void 반환값 없음
     */
    public function test_for_variants_do_not_change_tag_resolution(): void
    {
        $manifest = ComponentManifest::fromArray([
            'version' => '1.0.0',
            'identifier' => 'sirsoft-ecommerce',
            'components' => [
                'basic' => [],
                'composite' => [['name' => 'ProductCard', 'type' => 'composite']],
                'layout' => [],
            ],
            'for' => ['sirsoft-basic' => ['ProductCard']],
        ], 'ext.json');

        $this->assertSame(['sirsoft-basic' => ['ProductCard']], $manifest->forVariants);

        $registry = ComponentRegistry::fromManifests($this->templateManifest(), [$manifest]);
        $card = $registry->resolveTag('sirsoft-ecommerce.product-card');

        $this->assertNotNull($card);
        $this->assertSame('sirsoft-ecommerce.ProductCard', $card->name);
    }

    /**
     * 판본만 있고 원본이 없으면 그 판본은 어느 태그로도 작성할 수 없는 죽은 코드다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects for_variant_without_an_original_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_for_variant_naming_a_component_the_extension_lacks_is_rejected(): void
    {
        $error = $this->manifestError([
            'version' => '1.0.0',
            'identifier' => 'sirsoft-ecommerce',
            'components' => $this->emptyComponents(),
            'for' => ['sirsoft-basic' => ['Ghost']],
        ]);

        $this->assertSame(LayoutMarkupErrorCode::ForVariantComponentUnknown, $error->getErrorCode());
        $this->assertStringContainsString('Ghost', $error->getDetail());
    }

    /**
     * 템플릿이 "다른 템플릿용 판본" 을 낸다는 것은 뜻이 성립하지 않는다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects template_declaring_for_variants_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_template_cannot_declare_for_variants(): void
    {
        $error = $this->manifestError([
            'version' => '1.0.0',
            'templateId' => 'sirsoft-basic',
            'components' => $this->emptyComponents(),
            'for' => ['sirsoft-admin_basic' => ['Div']],
        ]);

        $this->assertSame(LayoutMarkupErrorCode::TemplateForVariantForbidden, $error->getErrorCode());
    }

    // ---------------------------------------------------------------------
    // 제안 (확장 컴포넌트도 같은 평면)
    // ---------------------------------------------------------------------

    /**
     * 확장 컴포넌트 태그 오타도 가까운 후보를 제안한다.
     *
     * @scenario compile_unit=registry,source_health=invalid
     *
     * @effects suggestion_covers_extension_component_tags
     *
     * @return void 반환값 없음
     */
    public function test_typo_in_an_extension_tag_is_suggested(): void
    {
        $registry = ComponentRegistry::fromManifests($this->templateManifest(), [$this->extensionManifest()]);

        $this->assertSame(
            'sirsoft-ecommerce.product-card',
            $registry->suggestTag('sirsoft-ecommerce.produkt-card')
        );
    }

    // ---------------------------------------------------------------------
    // fixture 쌍 (HTML ↔ JSON) — 3개념
    // ---------------------------------------------------------------------

    /**
     * 세 개념 모두 **마크업 표면과 산출이 같다**는 것이 이 fixture 들의 요점이다. 확장이 원본을 내든,
     * 템플릿이 대체하든, 확장이 그 템플릿 전용 판본을 내든 작성자가 쓰는 태그와 방출되는 노드는 하나다 —
     * 컴파일은 목록과 무관하게 태그 표기·`data-type` 만으로 산출을 정한다(2026-09-28 결정). 대체·판본은
     * 런타임이 고르는 구현체의 문제이고, 그래서 오버라이드가 붙거나 떨어져도 레이아웃을 고칠 일이 없다.
     *
     * @scenario compile_unit=node,source_health=valid
     *
     * @effects extension_component_authoring_is_identical_across_three_concepts,
     *          node_json_key_order_is_deterministic
     *
     * @param  string  $fixture  픽스처 이름
     * @param  string  $variant  레지스트리 변형
     * @return void 반환값 없음
     */
    #[DataProvider('extensionFixtureProvider')]
    public function test_fixture_pair_compiles_to_expected_json(string $fixture, string $variant): void
    {
        $directory = __DIR__.'/fixtures/extension';

        $registry = $this->registryForVariant($variant);
        $compiler = new NodeCompiler($registry);

        $tokens = (new MarkupTokenizer)->tokenize(file_get_contents("{$directory}/{$fixture}.g7.html"));
        $roots = (new MarkupTreeBuilder)->build($tokens);
        $node = $compiler->compile($roots[0]);

        $expected = json_decode(file_get_contents("{$directory}/{$fixture}.json"), true);

        // 배열 비교가 아니라 인코딩 문자열 비교다 — 배열 비교는 키 순서 차이를 통과시킨다.
        $this->assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($node, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        // 목록 없이 컴파일해도 같다 — 산출은 어떤 템플릿·확장 목록에도 기대지 않는다.
        $this->assertSame($node, (new NodeCompiler)->compile($roots[0]));
    }

    /**
     * 확장 컴포넌트 세 개념(제공·오버라이드·전용 판본) 픽스처 목록.
     *
     * @return array<string, 반환값
     */
    public static function extensionFixtureProvider(): array
    {
        return [
            '① 확장 제공' => ['provided', 'plain'],
            '② 템플릿 오버라이드' => ['overridden', 'overridden'],
            '③ 템플릿 전용 판본' => ['for_variant', 'for'],
        ];
    }

    /**
     * fixture 별 3소스 레지스트리를 만듭니다.
     *
     * @param  string  $variant  `plain`(원본) / `overridden`(템플릿 대체) / `for`(전용 판본 선언)
     * @return ComponentRegistry 레지스트리
     */
    private function registryForVariant(string $variant): ComponentRegistry
    {
        $extension = [
            'version' => '0.1.0',
            'identifier' => 'gnuboard7-hello_module',
            'components' => [
                'basic' => [],
                'composite' => [['name' => 'MemoCard', 'type' => 'composite']],
                'layout' => [],
            ],
        ];

        if ($variant === 'for') {
            $extension['for'] = ['sirsoft-basic' => ['MemoCard']];
        }

        $overrides = $variant === 'overridden'
            ? [[
                'target' => 'gnuboard7-hello_module',
                'targetVersion' => '^0.1',
                'name' => 'MemoCard',
                'type' => 'layout',
            ]]
            : [];

        return ComponentRegistry::fromManifests(
            $this->templateManifest($overrides),
            [ComponentManifest::fromArray($extension, 'hello.json')]
        );
    }

    // ---------------------------------------------------------------------
    // 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 빈 3분류 배열을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 빈 components 값
     */
    private function emptyComponents(): array
    {
        return ['basic' => [], 'composite' => [], 'layout' => []];
    }

    /**
     * 테스트용 템플릿 매니페스트를 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $overrides  오버라이드 선언 목록
     * @return ComponentManifest 템플릿 매니페스트
     */
    private function templateManifest(array $overrides = []): ComponentManifest
    {
        $manifest = [
            'version' => '1.0.0',
            'templateId' => 'sirsoft-basic',
            'components' => [
                'basic' => [['name' => 'Div', 'type' => 'basic']],
                'composite' => [],
                'layout' => [],
            ],
        ];

        if ($overrides !== []) {
            $manifest['overrides'] = $overrides;
        }

        return ComponentManifest::fromArray($manifest, 'template.json');
    }

    /**
     * 테스트용 확장 매니페스트를 만듭니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  array<int, string>  $names  제공 컴포넌트 이름 목록
     * @return ComponentManifest 확장 매니페스트
     */
    private function extensionManifest(string $identifier = 'sirsoft-ecommerce', array $names = ['ProductCard']): ComponentManifest
    {
        return ComponentManifest::fromArray([
            'version' => '1.4.0',
            'identifier' => $identifier,
            'components' => [
                'basic' => [],
                'composite' => array_map(
                    static fn (string $name): array => ['name' => $name, 'type' => 'composite'],
                    $names
                ),
                'layout' => [],
            ],
        ], $identifier.'.json');
    }

    /**
     * 매니페스트 정규화가 실패할 것을 전제로 오류를 잡아 반환합니다.
     *
     * @param  array<mixed>  $manifest  매니페스트 배열
     * @return CompileError 발생한 오류
     */
    private function manifestError(array $manifest): CompileError
    {
        try {
            ComponentManifest::fromArray($manifest, 'components.json');
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail('매니페스트 오류를 기대했으나 통과했습니다.');
    }

    /**
     * 레지스트리 병합이 실패할 것을 전제로 오류를 잡아 반환합니다.
     *
     * @param  ComponentManifest  $template  템플릿 매니페스트
     * @param  array<int, ComponentManifest>  $extensions  확장 매니페스트 목록
     * @return CompileError 발생한 오류
     */
    private function registryError(ComponentManifest $template, array $extensions): CompileError
    {
        try {
            ComponentRegistry::fromManifests($template, $extensions);
        } catch (CompileError $error) {
            return $error;
        }

        $this->fail('레지스트리 병합 오류를 기대했으나 통과했습니다.');
    }
}
