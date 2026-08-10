<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\ComponentRegistry;
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

        $this->linter = $this->linterFor('sirsoft-admin_basic');
    }

    /**
     * 대상 템플릿 레지스트리를 물린 린터를 만듭니다.
     *
     * 축 ⑧(hoist 3종 템플릿 지원)은 **대상 템플릿마다 답이 갈리는** 유일한 lint 이라 두 벌이 필요하다 —
     * `sirsoft-admin_basic` 의 Modal 은 셋을 선언하고 `sirsoft-basic` 은 하나도 선언하지 않는다
     * (매니페스트·`Modal.tsx` 양쪽 실측 일치).
     *
     * @param  string  $templateId  템플릿 식별자
     * @return LayoutLinter 해당 템플릿 레지스트리를 문 린터
     */
    private function linterFor(string $templateId): LayoutLinter
    {
        return new LayoutLinter(
            ComponentRegistry::fromFile(base_path("templates/_bundled/{$templateId}/components.json")),
        );
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
     * 플러그인도 자기 화면(완전 레이아웃)을 가질 수 있다 — 린터는 그것만으로 경고하지 않는다.
     *
     * 종전에는 `plugin_complete_layout` 경고가 있었고 `gdpr_consent_log`·`plugin_settings` 만
     * allowlist 예외였다. PO 결정(2026-08-01)으로 정책이 뒤집혔다: 원래 금지의 근거였던 위험은
     * "화면을 갖는 것" 이 아니라 **모듈/템플릿이 소유한 라우트를 가로채는 것** 이었고, 그것은
     * 레이아웃 JSON 이 아니라 `routes.json` 에서만 판정할 수 있다(정적 검사 쪽에서 담당).
     * 린터에 남겨 두면 허용된 작성을 경고하게 되므로 검사를 걷어냈다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects plugin_complete_layout_is_not_warned
     */
    public function test_plugin_complete_layout_does_not_warn(): void
    {
        $json = ['layout_name' => 'plugin_dashboard', 'components' => []];
        $context = new CompileContext(sourceKind: 'plugin', identifier: 'sirsoft-example');

        $this->assertSame([], $this->linter->lint($json, 'layout', $context));
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

        $this->assertSame([], $this->linter->lint($json, 'layout', $context));
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
    // ⑥ switch 분기 대상 배치 (D42 G8)
    // ---------------------------------------------------------------------

    /**
     * 엔진은 `params.value` 만 읽는다 — top-level `value`(=`<switch value>`)는 경고한다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects switch_top_level_value_is_warned_as_not_consumed
     */
    public function test_switch_top_level_value_warns(): void
    {
        $json = [
            'layout_name' => 'x',
            'components' => [[
                'type' => 'basic',
                'name' => 'Div',
                'actions' => [[
                    'event' => 'onItemClick',
                    'handler' => 'switch',
                    'value' => '{{$args[0]}}',
                    'cases' => ['a' => ['handler' => 'toast']],
                ]],
            ]],
        ];

        $this->assertHasLint(LayoutMarkupLintCode::SwitchValueNotConsumed, $json);
    }

    /**
     * `params.value`(=`<switch on>`)는 정본이라 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects switch_params_value_is_the_canonical_placement
     */
    public function test_switch_params_value_does_not_warn(): void
    {
        $json = [
            'layout_name' => 'x',
            'components' => [[
                'type' => 'basic',
                'name' => 'Div',
                'actions' => [[
                    'event' => 'onItemClick',
                    'handler' => 'switch',
                    'params' => ['value' => '{{$args[0]}}'],
                    'cases' => ['a' => ['handler' => 'toast']],
                ]],
            ]],
        ];

        $this->assertNoLint(LayoutMarkupLintCode::SwitchValueNotConsumed, $json);
    }

    // ---------------------------------------------------------------------
    // ⑦ 액션 params 오배치 키 (S23 축 A)
    // ---------------------------------------------------------------------

    /**
     * 액션 조건은 top-level `if` 가 정본이다 — `params.condition` 은 아무도 읽지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects misplaced_action_param_key_is_warned
     */
    public function test_action_params_condition_warns(): void
    {
        $this->assertHasLint(LayoutMarkupLintCode::ActionParamMisplacedKey, $this->actionJson('navigate', [
            'condition' => '{{user.is_admin}}',
        ]));
    }

    /**
     * 트리거는 `<on event>` 가 정본이다 — `params.trigger` 는 죽은 키다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects misplaced_action_param_key_is_warned
     */
    public function test_action_params_trigger_warns(): void
    {
        $this->assertHasLint(LayoutMarkupLintCode::ActionParamMisplacedKey, $this->actionJson('navigate', [
            'trigger' => 'click',
        ]));
    }

    /**
     * `closeModal` 은 params 를 아예 읽지 않는다(ActionDispatcher::handleCloseModal) — `params.id` 는 죽는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects misplaced_action_param_key_is_warned
     */
    public function test_action_params_id_warns_for_handler_that_does_not_read_it(): void
    {
        $this->assertHasLint(LayoutMarkupLintCode::ActionParamMisplacedKey, $this->actionJson('closeModal', [
            'id' => 'bank_management_modal',
        ]));
    }

    /**
     * `startInterval`·`stopInterval` 은 `params.id` 를 **필수 인자로 읽는다** — 경고하면 오탐이다.
     *
     * 이 음성 표본이 이 축의 축소 경계를 지킨다: 키 하나를 통째로 금지하는 판정으로 넓히는 순간
     * 정상 저작이 red 가 된다(ActionDispatcher:5146·5181).
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects param_key_consumed_by_the_handler_is_not_warned
     */
    public function test_action_params_id_does_not_warn_for_interval_handlers(): void
    {
        foreach (['startInterval', 'stopInterval'] as $handler) {
            $this->assertNoLint(LayoutMarkupLintCode::ActionParamMisplacedKey, $this->actionJson($handler, [
                'id' => 'identity_challenge_tick',
            ]));
        }
    }

    /**
     * 확장·custom 핸들러의 params 표면은 구현 소유라 판정 대상이 아니다.
     *
     * 실측 근거: `sirsoft-ecommerce.loadVisibleFilters` 는 `params.id` 를 필수로 읽는다
     * (orderHandlers.ts — id 누락 시 warn 후 중단).
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects param_key_consumed_by_the_handler_is_not_warned
     */
    public function test_extension_and_custom_handlers_are_not_judged(): void
    {
        foreach (['sirsoft-ecommerce.loadVisibleFilters', 'custom'] as $handler) {
            $this->assertNoLint(LayoutMarkupLintCode::ActionParamMisplacedKey, $this->actionJson($handler, [
                'id' => 'admin_ecommerce_order_filters',
                'condition' => '{{x}}',
            ]));
        }
    }

    /**
     * 정본 자리에 적은 액션은 조용하다 (오검출 없음).
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects canonical_action_placement_is_not_warned
     */
    public function test_canonical_action_placement_does_not_warn(): void
    {
        $json = ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Button',
            'actions' => [[
                'type' => 'click',
                'handler' => 'navigate',
                'if' => '{{user.is_admin}}',
                'params' => ['path' => '/admin'],
            ]],
        ]]];

        $this->assertNoLint(LayoutMarkupLintCode::ActionParamMisplacedKey, $json);
    }

    // ---------------------------------------------------------------------
    // ⑧ hoist 3종 템플릿 미지원 (S23 축 B)
    // ---------------------------------------------------------------------

    /**
     * `sirsoft-basic` 의 Modal 은 hoist 3종을 하나도 선언하지 않는다 — 저작이 조용히 사라진다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects unsupported_hoist_prop_is_warned
     */
    public function test_hoist_props_warn_when_target_template_modal_does_not_declare_them(): void
    {
        $linter = $this->linterFor('sirsoft-basic');

        foreach (['footer', 'titlePrefix', 'titleSuffix'] as $prop) {
            $this->assertHasLint(
                LayoutMarkupLintCode::HoistPropNotSupportedByTemplate,
                $this->modalJson($prop),
                linter: $linter,
            );
        }
    }

    /**
     * `sirsoft-admin_basic` 의 Modal 은 셋을 선언하고 실제로 렌더한다 — 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects declared_hoist_prop_is_not_warned
     */
    public function test_hoist_props_do_not_warn_when_target_template_declares_them(): void
    {
        foreach (['footer', 'titlePrefix', 'titleSuffix'] as $prop) {
            $this->assertNoLint(LayoutMarkupLintCode::HoistPropNotSupportedByTemplate, $this->modalJson($prop));
        }
    }

    /**
     * 같은 이름의 prop 이 Modal 이 아닌 노드에 있으면 판정 대상이 아니다.
     *
     * hoist 3키의 저작 경로는 Modal 자식뿐이고(EmbeddingCompiler 가 부모를 못박는다), 다른 노드까지
     * 넓히면 매니페스트 부분 선언(75~77%) 위에서 오탐이 시작된다(D19).
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects hoist_judgment_is_scoped_to_the_hoist_parent
     */
    public function test_same_named_prop_on_other_components_is_not_judged(): void
    {
        $json = ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['footer' => [['type' => 'basic', 'name' => 'Span']]],
        ]]];

        $this->assertNoLint(LayoutMarkupLintCode::HoistPropNotSupportedByTemplate, $json, linter: $this->linterFor('sirsoft-basic'));
    }

    // ---------------------------------------------------------------------
    // 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 핸들러 하나짜리 액션을 문 최소 문서를 만듭니다.
     *
     * @param  string  $handler  핸들러명
     * @param  array<string, mixed>  $params  액션 params
     * @return array<string, mixed> 산출 JSON
     */
    private function actionJson(string $handler, array $params): array
    {
        return ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Button',
            'actions' => [['type' => 'click', 'handler' => $handler, 'params' => $params]],
        ]]];
    }

    /**
     * hoist prop 하나를 문 Modal 노드 문서를 만듭니다.
     *
     * @param  string  $prop  hoist props 키 (`footer`·`titlePrefix`·`titleSuffix`)
     * @return array<string, mixed> 산출 JSON
     */
    private function modalJson(string $prop): array
    {
        return ['layout_name' => 'x', 'components' => [[
            'type' => 'composite',
            'name' => 'Modal',
            'props' => [$prop => [['type' => 'basic', 'name' => 'Button']]],
        ]]];
    }

    /**
     * 지정 lint 코드가 경고 목록에 있는지 단언합니다.
     *
     * @param  LayoutMarkupLintCode  $code  기대 lint 코드
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext|null  $context  lint 문맥
     * @param  string  $rootKind  문서 봉투
     * @param  LayoutLinter|null  $linter  대상 린터 (축 ⑧ 처럼 템플릿마다 답이 갈리는 경우 지정)
     */
    private function assertHasLint(LayoutMarkupLintCode $code, array $json, ?CompileContext $context = null, string $rootKind = 'layout', ?LayoutLinter $linter = null): void
    {
        $codes = array_map(
            fn (LintWarning $w): LayoutMarkupLintCode => $w->getCode(),
            ($linter ?? $this->linter)->lint($json, $rootKind, $context ?? new CompileContext),
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
     * @param  LayoutLinter|null  $linter  대상 린터 (축 ⑧ 처럼 템플릿마다 답이 갈리는 경우 지정)
     */
    private function assertNoLint(LayoutMarkupLintCode $code, array $json, ?CompileContext $context = null, string $rootKind = 'layout', ?LayoutLinter $linter = null): void
    {
        $codes = array_map(
            fn (LintWarning $w): LayoutMarkupLintCode => $w->getCode(),
            ($linter ?? $this->linter)->lint($json, $rootKind, $context ?? new CompileContext),
        );

        $this->assertNotContains($code, $codes);
    }
}
