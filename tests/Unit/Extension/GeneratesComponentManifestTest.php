<?php

namespace Tests\Unit\Extension;

use App\Extension\Traits\GeneratesComponentManifest;
use App\Support\LayoutMarkup\ComponentManifest;
use App\Support\LayoutMarkup\ComponentRegistry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 확장 컴포넌트 매니페스트 생성 테스트 (S2.5 — D25).
 *
 * 학습용 샘플 `gnuboard7-hello_module` 의 **실물 소스**를 스캔한다. 픽스처를 따로 두지 않는
 * 이유: 이 트레이트가 지키는 계약은 "디렉토리 컨벤션 → 매니페스트" 이고, 컨벤션을 실제로
 * 따르는 것이 저 샘플이다. 픽스처로 대체하면 샘플이 컨벤션에서 벗어나도 테스트가 green 이
 * 되어, 정작 작성자가 복사할 실물이 깨진 채 남는다.
 */
class GeneratesComponentManifestTest extends TestCase
{
    use GeneratesComponentManifest;

    /**
     * 테스트 정리 — 만든 임시 자원을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        DB::disconnect();

        parent::tearDown();
    }

    /**
     * 스캔 결과를 반환합니다 (파일 쓰기 없이 스캔만).
     *
     * @return array{components: 반환값
     */
    private function scanHelloModule(): array
    {
        return $this->scanComponentSources(base_path('modules/_bundled/gnuboard7-hello_module'));
    }

    /**
     * 컴포넌트 종류는 소스 디렉터리로 분류된다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects extension_component_sources_are_classified_by_directory
     *
     * @return void 반환값 없음
     */
    public function test_component_is_classified_by_its_directory(): void
    {
        $scanned = $this->scanHelloModule();

        $names = array_column($scanned['components']['composite'], 'name');

        $this->assertContains('MemoCard', $names);
        $this->assertSame([], $scanned['components']['basic']);
        $this->assertSame([], $scanned['components']['layout']);
    }

    /**
     * `for/{템플릿식별자}/{이름}.tsx` 는 같은 이름의 **다른 구현**이지 별개 컴포넌트가 아니다.
     * components 에 함께 등재하면 같은 이름이 둘이 되어 컴파일러가 모호로 거부한다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects for_variant_is_listed_only_in_the_for_map_not_duplicated_as_a_component
     *
     * @return void 반환값 없음
     */
    public function test_for_variant_is_not_duplicated_as_a_separate_component(): void
    {
        $scanned = $this->scanHelloModule();

        $names = array_column($scanned['components']['composite'], 'name');

        $this->assertSame(['MemoCard'], array_values(array_unique($names)));
        $this->assertCount(1, $names, 'for/ 판본이 별개 컴포넌트로 중복 등재되었습니다.');
        $this->assertSame(['gnuboard7-hello_admin_template' => ['MemoCard']], $scanned['for']);
    }

    /**
     * 편집기가 확장 컴포넌트의 prop 을 편집하려면 선언이 있어야 한다. 종전에는 빈 객체였다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects props_are_extracted_from_the_component_source_declaration
     *
     * @return void 반환값 없음
     */
    public function test_props_are_extracted_from_the_props_interface(): void
    {
        $scanned = $this->scanHelloModule();

        $memoCard = collect($scanned['components']['composite'])->firstWhere('name', 'MemoCard');
        $props = $memoCard['props'];

        $this->assertSame('string', $props['title']['type']);
        $this->assertFalse($props['title']['required']);
        $this->assertSame('메모 제목', $props['title']['description']);
        $this->assertSame('boolean', $props['highlighted']['type']);
    }

    /**
     * 생성된 매니페스트가 컴파일러의 강검증을 그대로 통과해야 한다 — 생성기와 컴파일러가
     * 서로 다른 스키마를 이해하고 있으면 빌드는 성공하는데 마크업이 전부 실패한다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects generated_extension_manifest_passes_compiler_validation
     *
     * @return void 반환값 없음
     */
    public function test_generated_manifest_is_accepted_by_the_compiler(): void
    {
        $scanned = $this->scanHelloModule();

        $manifest = ComponentManifest::fromArray([
            'identifier' => 'gnuboard7-hello_module',
            'version' => '0.2.0',
            'components' => $scanned['components'],
            'for' => $scanned['for'],
        ], 'generated.json');

        $this->assertSame('gnuboard7-hello_module', $manifest->ownerId);
        $this->assertSame(['gnuboard7-hello_admin_template' => ['MemoCard']], $manifest->forVariants);

        $definition = $manifest->definitions[0];

        $this->assertSame('gnuboard7-hello_module.MemoCard', $definition->name);
        $this->assertSame('gnuboard7-hello_module.memo-card', $definition->tag);
    }

    /**
     * 샘플 3개념의 **실물 종단 검증** — 생성된 모듈 매니페스트와 손으로 쓴 템플릿 매니페스트가
     * 실제로 맞물려 동작하는가.
     *
     * 단위 테스트가 각 개념을 따로 통과시켜도, 두 매니페스트가 서로 다른 스키마를 이해하고
     * 있으면 실물에서는 아무것도 동작하지 않는다. 여기서만 그것이 드러난다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects sample_extension_three_concepts_work_end_to_end
     *
     * @return void 반환값 없음
     */
    public function test_sample_extension_and_template_manifests_interlock(): void
    {
        $scanned = $this->scanHelloModule();

        $module = ComponentManifest::fromArray([
            'identifier' => 'gnuboard7-hello_module',
            'version' => '0.2.0',
            'components' => $scanned['components'],
            'for' => $scanned['for'],
        ], 'hello-module.json');

        // 템플릿 매니페스트는 생성되지 않고 손으로 유지된다 — 실파일을 읽어야 검증이 성립한다.
        $template = ComponentManifest::fromFile(
            base_path('templates/_bundled/gnuboard7-hello_user_template/components.json')
        );

        $registry = ComponentRegistry::fromManifests($template, [$module]);

        // ① 제공 — 모듈 컴포넌트가 프리픽스 태그로 작성된다.
        $card = $registry->resolveTag('gnuboard7-hello_module.memo-card');
        $this->assertNotNull($card, '모듈이 제공한 컴포넌트가 마크업 어휘에 합류하지 않았습니다.');
        $this->assertSame('gnuboard7-hello_module.MemoCard', $card->name);

        // ② 오버라이드 — 이 템플릿의 대체본이 같은 좌표를 차지한다. targetVersion(^0.2)이
        //    모듈 버전(0.2.0)과 호환이므로 대체본이 적용된다. `accent` 는 대체본에만 있는
        //    prop 이라, 이것이 보이면 원본이 아니라 대체본이 자리를 잡았다는 뜻이다.
        $this->assertSame('gnuboard7-hello_module', $card->owner);
        $this->assertSame('string', $card->propType('accent'), '템플릿의 대체본이 적용되지 않았습니다.');

        // ③ 템플릿 전용 판본 — 선언은 모듈 매니페스트에 있고 태그 해석은 바꾸지 않는다.
        $this->assertSame(['gnuboard7-hello_admin_template' => ['MemoCard']], $module->forVariants);

        // 템플릿 자신의 컴포넌트는 프리픽스 없이 그대로다.
        $this->assertNotNull($registry->resolveTag('div'));
    }

    /**
     * 오버라이드의 버전 제약이 어긋나면 원본이 자리를 지킨다 — 실물 매니페스트로 확인한다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects sample_template_override_falls_back_when_module_version_moves_on
     *
     * @return void 반환값 없음
     */
    public function test_sample_override_falls_back_if_the_module_moves_to_an_incompatible_version(): void
    {
        $scanned = $this->scanHelloModule();

        // 모듈이 1.0.0 으로 올라가면 템플릿의 `^0.2` 대체본은 낡은 것이 된다.
        $module = ComponentManifest::fromArray([
            'identifier' => 'gnuboard7-hello_module',
            'version' => '1.0.0',
            'components' => $scanned['components'],
        ], 'hello-module.json');

        $template = ComponentManifest::fromFile(
            base_path('templates/_bundled/gnuboard7-hello_user_template/components.json')
        );

        $registry = ComponentRegistry::fromManifests($template, [$module]);

        // 대체본이 폐기되어도 태그는 살아 있다 — 원본이 폴백으로 자리를 지킨다.
        $card = $registry->resolveTag('gnuboard7-hello_module.memo-card');

        $this->assertNotNull($card, '비호환 오버라이드가 원본까지 지웠습니다 — 화면이 통째로 사라집니다.');
        $this->assertSame('gnuboard7-hello_module.MemoCard', $card->name);

        // `accent` 는 대체본에만 있는 prop 이다 — 없다는 것이 곧 원본이 쓰였다는 증거다.
        $this->assertNull($card->propType('accent'), '비호환인데도 대체본이 적용되었습니다.');
        $this->assertSame('string', $card->propType('title'), '원본의 props 가 유실되었습니다.');
    }

    /**
     * 컴포넌트 소스가 없는 확장(핸들러 전용 등)은 빈 매니페스트를 얻는다.
     *
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects extension_without_component_sources_yields_an_empty_manifest
     *
     * @return void 반환값 없음
     */
    public function test_extension_without_component_sources_scans_empty(): void
    {
        $scanned = $this->scanComponentSources(base_path('modules/_bundled/sirsoft-page'));

        $this->assertSame(['basic' => [], 'composite' => [], 'layout' => []], $scanned['components']);
        $this->assertSame([], $scanned['for']);
    }
}
