<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\LayoutLinter;
use App\Support\LayoutMarkup\LintWarning;

/**
 * LayoutLinter 단위 테스트 (`.g7.html` 저작 포맷 S6 — 정적 lint 5종).
 *
 * lint 은 컴파일을 막지 않는 경고다. 각 lint 이 위반을 정확히 잡고, 위반이 없으면 조용한지(오검출
 * 없음)를 함께 본다 — 경고는 노이즈가 되면 무시되므로 오검출 회피가 정확 검출만큼 중요하다.
 */
class LayoutLinterTest extends LayoutMarkupTestCase
{
    private LayoutLinter $linter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->linter = new LayoutLinter;
    }

    // ---------------------------------------------------------------------
    // ① 미등록 핸들러
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects unregistered_handler_is_warned
     */
    public function test_unregistered_handler_warns(): void
    {
        $json = ['components' => [
            ['type' => 'basic', 'name' => 'Button', 'actions' => [['handler' => 'stopPropagation']]],
        ]];

        $this->assertHasLint(LayoutMarkupLintCode::UnregisteredHandler, $json);
    }

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects core_template_extension_handlers_are_not_warned
     */
    public function test_known_handlers_do_not_warn(): void
    {
        $json = ['components' => [
            ['type' => 'basic', 'name' => 'Button', 'actions' => [
                ['handler' => 'navigate'],                         // 코어
                ['handler' => 'sirsoft-ecommerce.toggleArrayValue'], // 확장(.)
                ['handler' => 'setTheme'],                          // 템플릿 등록
                ['handler' => 'custom', 'params' => ['name' => 'x']], // custom 디스패치
            ]],
        ]];

        $context = new CompileContext(templateHandlers: ['setTheme']);

        $this->assertNoLint(LayoutMarkupLintCode::UnregisteredHandler, $json, $context);
    }

    // ---------------------------------------------------------------------
    // ② 플러그인 완전 레이아웃
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects plugin_complete_layout_is_warned
     */
    public function test_plugin_complete_layout_warns(): void
    {
        $json = ['layout_name' => 'plugin_dashboard', 'components' => []];
        $context = new CompileContext(sourceKind: 'plugin', identifier: 'sirsoft-example');

        $this->assertHasLint(LayoutMarkupLintCode::PluginCompleteLayout, $json, $context);
    }

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects allowlisted_plugin_layout_is_not_warned
     */
    public function test_allowlisted_plugin_layout_does_not_warn(): void
    {
        $json = ['layout_name' => 'gdpr_consent_log', 'components' => []];
        $context = new CompileContext(sourceKind: 'plugin', identifier: 'sirsoft-gdpr');

        $this->assertNoLint(LayoutMarkupLintCode::PluginCompleteLayout, $json, $context);
    }

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects module_complete_layout_is_not_warned
     */
    public function test_module_complete_layout_does_not_warn(): void
    {
        $json = ['layout_name' => 'module_dashboard', 'components' => []];
        $context = new CompileContext(sourceKind: 'module', identifier: 'sirsoft-board');

        $this->assertNoLint(LayoutMarkupLintCode::PluginCompleteLayout, $json, $context);
    }

    // ---------------------------------------------------------------------
    // ③ nesting 위반
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects disallowed_child_is_warned
     */
    public function test_nesting_violation_warns(): void
    {
        $json = ['components' => [
            ['type' => 'basic', 'name' => 'Ul', 'children' => [
                ['type' => 'basic', 'name' => 'Div'],
            ]],
        ]];
        $context = new CompileContext(nestingRules: ['Ul' => ['Li']]);

        $this->assertHasLint(LayoutMarkupLintCode::NestingViolation, $json, $context);
    }

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects empty_accepts_parent_is_not_checked
     */
    public function test_empty_accepts_parent_does_not_warn(): void
    {
        // Table accepts:[] 밑의 Thead 오검출을 회피한다 (계획서 경고).
        $json = ['components' => [
            ['type' => 'basic', 'name' => 'Table', 'children' => [
                ['type' => 'basic', 'name' => 'Thead'],
            ]],
        ]];
        $context = new CompileContext(nestingRules: ['Table' => []]);

        $this->assertNoLint(LayoutMarkupLintCode::NestingViolation, $json, $context);
    }

    // ---------------------------------------------------------------------
    // ④ responsive breakpoint 혼용
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects portable_mixed_with_split_presets_is_warned
     */
    public function test_responsive_breakpoint_mix_warns(): void
    {
        $json = ['components' => [
            ['type' => 'basic', 'name' => 'Div', 'responsive' => ['portable' => ['props' => []]]],
            ['type' => 'basic', 'name' => 'Span', 'responsive' => ['mobile' => ['props' => []]]],
        ]];

        $this->assertHasLint(LayoutMarkupLintCode::ResponsiveBreakpointMix, $json);
    }

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects consistent_breakpoints_are_not_warned
     */
    public function test_consistent_breakpoints_do_not_warn(): void
    {
        $json = ['components' => [
            ['type' => 'basic', 'name' => 'Div', 'responsive' => ['mobile' => [], 'tablet' => []]],
        ]];

        $this->assertNoLint(LayoutMarkupLintCode::ResponsiveBreakpointMix, $json);
    }

    // ---------------------------------------------------------------------
    // ⑤ 독립 레이아웃 글로벌 호스트 누락
    // ---------------------------------------------------------------------

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects standalone_layout_firing_toast_without_host_is_warned
     */
    public function test_standalone_missing_global_host_warns(): void
    {
        $json = [
            'layout_name' => 'admin_login',
            'init_actions' => [['handler' => 'toast', 'params' => ['message' => 'hi']]],
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ];

        $this->assertHasLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $json);
    }

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects standalone_layout_with_toast_host_is_not_warned
     */
    public function test_standalone_with_toast_host_does_not_warn(): void
    {
        $json = [
            'layout_name' => 'admin_login',
            'init_actions' => [['handler' => 'toast']],
            'components' => [
                ['type' => 'composite', 'name' => 'Toast', 'props' => []],
            ],
        ];

        $this->assertNoLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $json);
    }

    /**
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects extends_layout_delegates_host_to_base
     */
    public function test_extends_layout_does_not_warn(): void
    {
        $json = [
            'layout_name' => 'child',
            'extends' => '_admin_base',
            'init_actions' => [['handler' => 'toast']],
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ];

        $this->assertNoLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $json);
    }

    // ---------------------------------------------------------------------
    // 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 지정 lint 코드가 경고 목록에 있는지 단언합니다.
     *
     * @param  LayoutMarkupLintCode  $code  기대 lint 코드
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext|null  $context  lint 문맥
     * @param  string  $rootKind  문서 봉투
     */
    private function assertHasLint(LayoutMarkupLintCode $code, array $json, ?CompileContext $context = null, string $rootKind = 'layout'): void
    {
        $codes = array_map(
            fn (LintWarning $w): LayoutMarkupLintCode => $w->getCode(),
            $this->linter->lint($json, $rootKind, $context ?? new CompileContext),
        );

        $this->assertContains($code, $codes);
    }

    /**
     * 지정 lint 코드가 경고 목록에 없는지 단언합니다.
     *
     * @param  LayoutMarkupLintCode  $code  없어야 할 lint 코드
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext|null  $context  lint 문맥
     * @param  string  $rootKind  문서 봉투
     */
    private function assertNoLint(LayoutMarkupLintCode $code, array $json, ?CompileContext $context = null, string $rootKind = 'layout'): void
    {
        $codes = array_map(
            fn (LintWarning $w): LayoutMarkupLintCode => $w->getCode(),
            $this->linter->lint($json, $rootKind, $context ?? new CompileContext),
        );

        $this->assertNotContains($code, $codes);
    }
}
