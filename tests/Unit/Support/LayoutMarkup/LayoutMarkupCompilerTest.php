<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Enums\LayoutMarkupLintCode;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;

/**
 * LayoutMarkupCompiler 파사드 종단 테스트 (`.g7.html` 마크업 형식 S6).
 *
 * 파사드가 사슬(토큰→트리→문서→강검증→lint)을 올바로 잇는지를 소스 문자열에서 결과까지 한 번에
 * 본다: ① 유효 소스는 산출 JSON + 빈 경고 ② 강검증 위반은 CompileError 전파(컴파일 중단)
 * ③ lint 위반은 컴파일을 막지 않고 경고로 실려 옴.
 */
class LayoutMarkupCompilerTest extends LayoutMarkupTestCase
{
    private LayoutMarkupCompiler $compiler;

    /**
     * 테스트 준비 — 번들 admin_basic 레지스트리로 컴파일러를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new LayoutMarkupCompiler(
            ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json')),
        );
    }

    /**
     * 올바른 소스는 산출 JSON 을 내고 경고가 없다.
     *
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects valid_source_compiles_to_json_with_no_warnings
     *
     * @return void 반환값 없음
     */
    public function test_valid_source_compiles_clean(): void
    {
        $result = $this->compiler->compile('<layout name="sample" version="1.0.0"><div class="x"/></layout>');

        $this->assertSame('sample', $result->json['layout_name']);
        $this->assertSame('Div', $result->json['components'][0]['name']);
        $this->assertFalse($result->hasWarnings());
    }

    /**
     * 강검증 위반은 CompileError 로 올라와 컴파일을 멈춘다.
     *
     * @scenario compile_unit=facade,source_health=invalid
     *
     * @effects strong_validation_violation_stops_compilation
     *
     * @return void 반환값 없음
     */
    public function test_strong_validation_error_propagates(): void
    {
        try {
            $this->compiler->compile(
                '<layout name="x" version="1.0.0"><data-source id="d" endpoint="/nope/x"/><div class="y"/></layout>',
            );
            $this->fail('CompileError 를 기대했으나 통과했습니다.');
        } catch (CompileError $error) {
            $this->assertSame(LayoutMarkupErrorCode::EndpointNotWhitelisted, $error->getErrorCode());
        }
    }

    /**
     * 레지스트리에 없는 컴포넌트 오류는 그 태그의 소스 줄·칸을 싣는다 — 편집기가 해당 줄에 물결을 긋는다.
     *
     * 강검증은 산출 JSON 을 봐서 위치를 모른다(0:0). 그대로 두면 편집기 마커가 1행에 찍혀 사용자가 고칠 자리를
     * 찾지 못한다(2026-10-01 실측: 14행에 새로 쓴 `<icon>` 이 1행에 표시). 저장본 허용 개수가 있으면 허용분을
     * 건너뛴 첫 초과 태그를 가리킨다.
     *
     * @scenario compile_unit=facade,source_health=invalid
     *
     * @effects unregistered_component_error_carries_source_position
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_component_error_points_at_the_offending_tag(): void
    {
        $source = "<layout name=\"x\" version=\"1.0.0\">\n  <div>\n    <dvi/>\n  </div>\n  <dvi/>\n</layout>";

        try {
            $this->compiler->compile($source);
            $this->fail('CompileError 를 기대했으나 통과했습니다.');
        } catch (CompileError $error) {
            $this->assertSame(LayoutMarkupErrorCode::ComponentNotInRegistry, $error->getErrorCode());
            $this->assertSame(3, $error->getSourceLine(), '첫 미등록 태그의 줄');
            $this->assertSame(5, $error->getSourceColumn());
        }

        try {
            $this->compiler->compile($source, null, new CompileContext(toleratedComponents: ['Dvi' => 1]));
            $this->fail('허용 개수를 넘는 두 번째 태그는 거부돼야 합니다.');
        } catch (CompileError $error) {
            $this->assertSame(5, $error->getSourceLine(), '허용분(1개)을 건너뛴 첫 초과 태그의 줄');
        }
    }

    /**
     * lint 위반은 컴파일을 막지 않고 경고로 실려 온다.
     *
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects lint_warning_is_surfaced_without_stopping_compilation
     *
     * @return void 반환값 없음
     */
    public function test_lint_warning_is_surfaced(): void
    {
        $result = $this->compiler->compile(
            '<layout name="admin_login" version="1.0.0"><button><on event="click"><toast message="hi"/></on></button></layout>',
        );

        // 컴파일은 성공하고 산출 JSON 이 나온다.
        $this->assertSame('admin_login', $result->json['layout_name']);

        // 독립 레이아웃이 Toast 호스트 없이 toast 를 발화 → 경고.
        $codes = array_map(fn ($w) => $w->getCode(), $result->warnings);
        $this->assertContains(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $codes);
    }

    /**
     * 문맥의 `sourceKind` 는 소스 종류별 lint 를 태우는 축이다.
     *
     * 종전에는 이 축을 `plugin_complete_layout` 경고로 관측했으나, 그 검사는 정책 반전으로
     * 폐기됐다(플러그인도 자기 화면을 가질 수 있다 — 설계 결정 2026-08-01). 지금은 **플러그인
     * 완전 레이아웃이 경고를 낳지 않는다**는 것 자체가 관측 대상이다.
     *
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects context_drives_source_kind_dependent_lint
     *
     * @return void 반환값 없음
     */
    public function test_context_drives_plugin_lint(): void
    {
        $result = $this->compiler->compile(
            '<layout name="plugin_report" version="1.0.0"><div class="x"/></layout>',
            null,
            new CompileContext(sourceKind: 'plugin', identifier: 'sirsoft-example'),
        );

        $this->assertSame([], $result->warnings);
    }

    /**
     * `<modal name>` 은 인라인 모달의 `layout_name` 이다 — 설치가 조각을 인라인하며 붙이는 이름의 표현.
     *
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects inline_modal_name_compiles_to_layout_name
     *
     * @return void 반환값 없음
     */
    public function test_inline_modal_name_compiles_to_layout_name(): void
    {
        $result = $this->compiler->compile(
            '<layout name="m" version="1.0.0"><div class="x"/><modal name="partials/_confirm"><modal data-ref="confirm" title="확인"/></modal></layout>',
        );

        $this->assertSame('partials/_confirm', $result->json['modals'][0]['layout_name']);
        $this->assertSame('Modal', $result->json['modals'][0]['name']);
        $this->assertArrayNotHasKey('layout_name', $result->json['modals'][0]['meta'] ?? []);
    }

    /**
     * 외부 팝업(`src`)에 이름을 함께 쓰면 거부한다.
     *
     * @scenario compile_unit=facade,source_health=invalid
     *
     * @effects modal_src_rejects_name_attribute
     *
     * @return void 반환값 없음
     */
    public function test_modal_src_with_name_is_rejected(): void
    {
        try {
            $this->compiler->compile(
                '<layout name="m" version="1.0.0"><div class="x"/><modal src="partials/_confirm.g7.html" name="partials/_confirm"/></layout>',
            );
            $this->fail('CompileError 를 기대했으나 통과했습니다.');
        } catch (CompileError $error) {
            $this->assertSame(LayoutMarkupErrorCode::ModalNameWithSrc, $error->getErrorCode());
        }
    }
}
