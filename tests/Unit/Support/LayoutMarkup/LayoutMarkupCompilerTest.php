<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Enums\LayoutMarkupLintCode;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;

/**
 * LayoutMarkupCompiler 파사드 종단 테스트 (`.g7.html` 저작 포맷 S6).
 *
 * 파사드가 사슬(토큰→트리→문서→강검증→lint)을 올바로 잇는지를 소스 문자열에서 결과까지 한 번에
 * 본다: ① 유효 소스는 산출 JSON + 빈 경고 ② 강검증 위반은 CompileError 전파(컴파일 중단)
 * ③ lint 위반은 컴파일을 막지 않고 경고로 실려 옴.
 */
class LayoutMarkupCompilerTest extends LayoutMarkupTestCase
{
    private LayoutMarkupCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = new LayoutMarkupCompiler(
            ComponentRegistry::fromFile(base_path('templates/_bundled/sirsoft-admin_basic/components.json')),
        );
    }

    /**
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects valid_source_compiles_to_json_with_no_warnings
     */
    public function test_valid_source_compiles_clean(): void
    {
        $result = $this->compiler->compile('<layout name="sample" version="1.0.0"><div class="x"/></layout>');

        $this->assertSame('sample', $result->json['layout_name']);
        $this->assertSame('Div', $result->json['components'][0]['name']);
        $this->assertFalse($result->hasWarnings());
    }

    /**
     * @scenario compile_unit=facade,source_health=invalid
     *
     * @effects strong_validation_violation_stops_compilation
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
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects lint_warning_is_surfaced_without_stopping_compilation
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
     * @scenario compile_unit=facade,source_health=valid
     *
     * @effects context_drives_source_kind_dependent_lint
     */
    public function test_context_drives_plugin_lint(): void
    {
        $result = $this->compiler->compile(
            '<layout name="plugin_report" version="1.0.0"><div class="x"/></layout>',
            null,
            new CompileContext(sourceKind: 'plugin', identifier: 'sirsoft-example'),
        );

        $codes = array_map(fn ($w) => $w->getCode(), $result->warnings);
        $this->assertContains(LayoutMarkupLintCode::PluginCompleteLayout, $codes);
    }
}
