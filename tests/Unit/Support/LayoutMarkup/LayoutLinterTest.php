<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\ComponentManifest;
use App\Support\LayoutMarkup\ComponentRegistry;
use App\Support\LayoutMarkup\LayoutLinter;
use App\Support\LayoutMarkup\LintWarning;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;

/**
 * LayoutLinter 단위 테스트 (`.g7.html` 마크업 형식 S6 — 정적 lint 5종).
 *
 * lint 은 컴파일을 막지 않는 경고다. 각 lint 이 위반을 정확히 잡고, 위반이 없으면 조용한지(오검출
 * 없음)를 함께 본다 — 경고는 노이즈가 되면 무시되므로 오검출 회피가 정확 검출만큼 중요하다.
 */
class LayoutLinterTest extends LayoutMarkupTestCase
{
    private LayoutLinter $linter;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
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
     * 코어·템플릿·확장 어디에도 없는 핸들러는 경고한다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects unregistered_handler_is_warned
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_handler_warns(): void
    {
        $json = ['components' => [
            ['type' => 'basic', 'name' => 'Button', 'actions' => [['handler' => 'stopPropagation']]],
        ]];

        $this->assertHasLint(LayoutMarkupLintCode::UnregisteredHandler, $json);
    }

    /**
     * 코어·확장(점 포함)·템플릿 등록·custom 핸들러는 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects core_template_extension_handlers_are_not_warned
     *
     * @return void 반환값 없음
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
     * allowlist 예외였다. 설계 결정(2026-08-01)으로 정책이 뒤집혔다: 원래 금지의 근거였던 위험은
     * "화면을 갖는 것" 이 아니라 **모듈/템플릿이 소유한 라우트를 가로채는 것** 이었고, 그것은
     * 레이아웃 JSON 이 아니라 `routes.json` 에서만 판정할 수 있다(정적 검사 쪽에서 담당).
     * 린터에 남겨 두면 허용된 작성을 경고하게 되므로 검사를 걷어냈다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects plugin_complete_layout_is_not_warned
     *
     * @return void 반환값 없음
     */
    public function test_plugin_complete_layout_does_not_warn(): void
    {
        $json = ['layout_name' => 'plugin_dashboard', 'components' => []];
        $context = new CompileContext(sourceKind: 'plugin', identifier: 'sirsoft-example');

        $this->assertSame([], $this->linter->lint($json, 'layout', $context));
    }

    /**
     * 모듈의 완전 레이아웃은 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects module_complete_layout_is_not_warned
     *
     * @return void 반환값 없음
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
     * 부모의 accepts 가 허용하지 않는 자식은 경고한다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects disallowed_child_is_warned
     *
     * @return void 반환값 없음
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
     * accepts 가 빈 부모는 nesting 판정에서 뺀다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects empty_accepts_parent_is_not_checked
     *
     * @return void 반환값 없음
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

    /**
     * 확장점은 컴포넌트가 아니므로 accepts 판정 대상이 아니다.
     *
     * `type: extension_point` 노드의 `name` 은 팔레트 컴포넌트명이 아니라 **확장 지점 이름**
     * (`user_global_overlay`·`html_content` 등)이라 accepts 에 있을 수가 없고, 있어서도 안 된다 —
     * 확장이 늘 때마다 템플릿의 nesting 목록을 고쳐야 하기 때문이다. 걸러내지 않으면
     * `Div 는 자식으로 user_global_overlay 를 …` 같은 거짓 경고가 남는다(실측: 번들 전수 14건).
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects extension_point_child_is_not_nesting_checked
     *
     * @return void 반환값 없음
     */
    public function test_extension_point_child_does_not_warn(): void
    {
        $json = ['components' => [
            ['type' => 'basic', 'name' => 'Div', 'children' => [
                ['type' => 'extension_point', 'name' => 'user_global_overlay'],
            ]],
        ]];
        $context = new CompileContext(nestingRules: ['Div' => ['Span']]);

        $this->assertNoLint(LayoutMarkupLintCode::NestingViolation, $json, $context);
    }

    /**
     * 번들 모듈·플러그인의 조각·레이아웃은 템플릿 문맥 없이도 미등록 핸들러가 없다.
     *
     * 확장 화면은 어느 템플릿으로 그려질지 모르므로 네임스페이스 없는 템플릿 핸들러를 부르지 않는다 —
     * 코어 내장 핸들러나 자기(또는 다른 확장의) 네임스페이스 핸들러만 쓴다. 템플릿 핸들러 목록 없이
     * lint 하면 그 호출만 `unregistered_handler` 로 남는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects bundled_extension_screens_call_no_template_handler
     *
     * @return void 반환값 없음
     */
    public function test_bundled_extension_screens_call_no_template_handler(): void
    {
        $linter = new LayoutLinter;
        $files = [];
        foreach (glob(base_path('{modules,plugins}/_bundled/*/resources/{extensions,layouts}'), GLOB_BRACE | GLOB_ONLYDIR) as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (str_ends_with((string) $file, '.json')) {
                    $files[] = (string) $file;
                }
            }
        }

        $hits = [];
        foreach ($files as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (! is_array($json)) {
                continue;
            }
            foreach ($linter->lint($json, 'layout', new CompileContext) as $warning) {
                if ($warning->getCode() === LayoutMarkupLintCode::UnregisteredHandler) {
                    $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertGreaterThan(100, count($files), '번들 확장 화면 모집단이 비었다');
        $this->assertSame([], array_values(array_unique($hits)));
    }

    /**
     * 회원 목록의 필터 표시 선택기(FilterVisibilitySelector)는 실제 admin_basic nesting 규칙에서 경고하지 않는다.
     *
     * 선택기가 컨테이너 accepts 에 없어 편집기 문맥 컴파일마다 nesting 경고가 났다. 같은 자리에 서는
     * 열 선택기(ColumnSelector)를 받는 컨테이너가 선택기도 받는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects filter_visibility_selector_is_accepted_where_column_selector_is
     *
     * @return void 반환값 없음
     */
    public function test_filter_visibility_selector_in_user_list_does_not_warn(): void
    {
        // 번들 트리 문서를 넘겨 번들 nesting.json 을 읽는다(문서 없이 부르면 설치본을 본다).
        $document = base_path('templates/_bundled/sirsoft-admin_basic/layouts/admin_user_list.g7.html');
        $rules = app(MarkupCompileContextResolver::class)->loadNestingRules('sirsoft-admin_basic', $document);
        $json = json_decode(
            (string) file_get_contents(base_path('templates/_bundled/sirsoft-admin_basic/layouts/admin_user_list.json')),
            true,
        );

        foreach ($rules as $container => $accepts) {
            if (in_array('ColumnSelector', $accepts, true)) {
                $this->assertContains('FilterVisibilitySelector', $accepts, "{$container} 는 ColumnSelector 를 받으면서 FilterVisibilitySelector 를 받지 않는다");
            }
        }
        $this->assertNoLint(LayoutMarkupLintCode::NestingViolation, $json, new CompileContext(nestingRules: $rules));
    }

    // ---------------------------------------------------------------------
    // ④ responsive breakpoint 혼용
    // ---------------------------------------------------------------------

    /**
     * portable 과 mobile/tablet 프리셋을 한 문서에 섞으면 경고한다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects portable_mixed_with_split_presets_is_warned
     *
     * @return void 반환값 없음
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
     * 한 계열의 브레이크포인트만 쓰면 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects consistent_breakpoints_are_not_warned
     *
     * @return void 반환값 없음
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
     * 독립 레이아웃이 호스트 없이 toast 를 발화하면 경고한다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects standalone_layout_firing_toast_without_host_is_warned
     *
     * @return void 반환값 없음
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
     * 독립 레이아웃이 `_global.toasts` 를 읽는 호스트를 두면 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects standalone_layout_with_toast_host_is_not_warned
     *
     * @return void 반환값 없음
     */
    public function test_standalone_with_toast_host_does_not_warn(): void
    {
        $json = [
            'layout_name' => 'admin_login',
            'init_actions' => [['handler' => 'toast']],
            'components' => [
                ['type' => 'composite', 'name' => 'Toast', 'props' => ['toasts' => '{{_global.toasts}}']],
            ],
        ];

        $this->assertNoLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $json);
    }

    /**
     * 호스트는 컴포넌트 이름이 아니라 그 전역 상태를 읽는지로 판정한다.
     *
     * 번들 템플릿의 이름(`Toast`·`Modal`)을 보면 다른 템플릿의 호스트(`<notice-stack>`)를 놓치고, 상태를 읽지 않는
     * 같은 이름 노드를 호스트로 오인한다(2026-09-28 결정: 템플릿 무관). `showAlert` 는 브라우저 `alert()` 라
     * 호스트가 필요 없고, `openModal` 은 `modals` 섹션이 있으면 엔진이 연다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects global_host_is_judged_by_engine_state_not_component_name
     *
     * @return void 반환값 없음
     */
    public function test_global_host_is_judged_by_bound_state_not_by_name(): void
    {
        $base = ['layout_name' => 'standalone', 'init_actions' => [['handler' => 'toast']]];

        // 이름만 Toast 이고 상태를 읽지 않으면 호스트가 아니다.
        $this->assertHasLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $base + [
            'components' => [['type' => 'composite', 'name' => 'Toast']],
        ]);

        // 이름이 무엇이든 `_global.toasts` 를 읽으면 호스트다.
        $this->assertNoLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $base + [
            'components' => [['type' => 'composite', 'name' => 'NoticeStack', 'props' => ['items' => '{{_global.toasts}}']]],
        ]);

        // openModal 은 modals 섹션이 호스트 — 이름 있는 Modal 노드가 트리에 있어도 섹션이 없으면 열리지 않는다.
        $modal = ['layout_name' => 'standalone', 'init_actions' => [['handler' => 'openModal', 'target' => 'm']]];
        $this->assertHasLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $modal + [
            'components' => [['type' => 'composite', 'name' => 'Modal']],
        ]);
        $this->assertNoLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, $modal + [
            'components' => [['type' => 'basic', 'name' => 'Div']],
            'modals' => [['id' => 'm', 'type' => 'composite', 'name' => 'Dialog']],
        ]);

        // showAlert 는 브라우저 alert() — 호스트가 필요 없다.
        $this->assertNoLint(LayoutMarkupLintCode::StandaloneMissingGlobalHost, [
            'layout_name' => 'standalone',
            'init_actions' => [['handler' => 'showAlert', 'target' => 'hi']],
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ]);
    }

    /**
     * 베이스를 상속한 레이아웃은 호스트를 베이스에 맡기므로 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects extends_layout_delegates_host_to_base
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * 정상 마크업이 red 가 된다(ActionDispatcher:5146·5181).
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects param_key_consumed_by_the_handler_is_not_warned
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     * `sirsoft-basic` 의 Modal 은 hoist 3종을 하나도 선언하지 않는다 — 마크업이 조용히 사라진다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects unsupported_hoist_prop_is_warned
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
     */
    public function test_hoist_props_do_not_warn_when_target_template_declares_them(): void
    {
        foreach (['footer', 'titlePrefix', 'titleSuffix'] as $prop) {
            $this->assertNoLint(LayoutMarkupLintCode::HoistPropNotSupportedByTemplate, $this->modalJson($prop));
        }
    }

    /**
     * hoist 판정은 부모 이름이 아니라 그 노드 컴포넌트의 선언으로 한다.
     *
     * hoist 는 부모 이름과 무관하므로(`<prop-footer>` 는 어느 부모 아래서나 `props.footer`) Modal 이 아닌 컴포넌트도
     * 그 prop 을 선언하지 않았으면 경고한다. 대상 템플릿이 모르는 이름은 판정하지 않는다 — 없는 컴포넌트는 존재
     * 검사가 맡고, 선언이 없는 것을 "받지 않는다" 로 읽으면 오탐이다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects hoist_judgment_follows_declaration_not_parent_name
     *
     * @return void 반환값 없음
     */
    public function test_hoist_judgment_uses_the_node_declaration_not_the_parent_name(): void
    {
        $footer = [['type' => 'basic', 'name' => 'Span']];
        $linter = $this->linterFor('sirsoft-basic');

        $this->assertHasLint(LayoutMarkupLintCode::HoistPropNotSupportedByTemplate, ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['footer' => $footer],
        ]]], linter: $linter);

        $this->assertNoLint(LayoutMarkupLintCode::HoistPropNotSupportedByTemplate, ['layout_name' => 'x', 'components' => [[
            'type' => 'composite',
            'name' => 'MyDialog',
            'props' => ['footer' => $footer],
        ]]], linter: $linter);
    }

    // ---------------------------------------------------------------------
    // ⑩ 설치되지 않은 확장 컴포넌트
    // ---------------------------------------------------------------------

    /**
     * 목록에 없는 확장 컴포넌트는 경고한다 — 저장을 막지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects missing_extension_component_is_warned
     *
     * @return void 반환값 없음
     */
    public function test_missing_extension_component_warns(): void
    {
        $json = ['layout_name' => 'x', 'components' => [['type' => 'basic', 'name' => 'Div', 'children' => [
            ['type' => 'composite', 'name' => 'removed-module.MemoCard'],
        ]]]];

        $this->assertHasLint(LayoutMarkupLintCode::ExtensionComponentNotInstalled, $json);
    }

    /**
     * 설치된 확장이 선언한 컴포넌트는 경고하지 않고, 템플릿 nesting 판정에도 걸리지 않는다.
     *
     * 템플릿 nesting.json 은 어떤 확장이 설치될지 몰라 확장 컴포넌트를 accepts 에 적을 수 없다 — 판정하면 거짓
     * 경고다(확장점을 거르는 것과 같은 이유).
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects installed_extension_component_is_not_warned
     *
     * @return void 반환값 없음
     */
    public function test_installed_extension_component_is_neither_missing_nor_nesting_checked(): void
    {
        $registry = ComponentRegistry::fromManifests(
            ComponentManifest::fromFile(base_path('templates/_bundled/sirsoft-basic/components.json')),
            [ComponentManifest::fromArray([
                'identifier' => 'probe-module',
                'version' => '1.0.0',
                'components' => ['basic' => [], 'composite' => [['name' => 'MemoCard', 'type' => 'composite', 'props' => []]], 'layout' => []],
            ], 'probe.json')],
        );
        $linter = new LayoutLinter($registry);
        $json = ['layout_name' => 'x', 'components' => [['type' => 'basic', 'name' => 'Div', 'children' => [
            ['type' => 'composite', 'name' => 'probe-module.MemoCard'],
        ]]]];
        $context = new CompileContext(nestingRules: ['Div' => ['Span']]);

        $this->assertNoLint(LayoutMarkupLintCode::ExtensionComponentNotInstalled, $json, $context, linter: $linter);
        $this->assertNoLint(LayoutMarkupLintCode::NestingViolation, $json, $context, linter: $linter);
    }

    // ---------------------------------------------------------------------
    // ⑫ 진입점이 아닌 액션의 debounce (2026-10-06 실측 D-I1-8)
    // ---------------------------------------------------------------------

    /**
     * `<when>` 안 액션에 단 debounce 는 엔진이 적용하지 않는다 — 매 입력 즉시 실행된다.
     *
     * 코드 편집기 내용 검색이 글자마다 요청 25건을 보낸 원인이었다(D-I1-8). 엔진은 이벤트에 바로 묶인
     * 진입점 액션의 debounce 만 적용하므로 감싸는 `<conditions :debounce>` 에 달아야 한다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects nested_action_debounce_is_warned
     *
     * @return void 반환값 없음
     */
    public function test_debounce_on_action_inside_conditions_warns(): void
    {
        $json = ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Input',
            'actions' => [[
                'type' => 'change',
                'handler' => 'conditions',
                'conditions' => [[
                    'if' => '{{true}}',
                    'then' => [['handler' => 'refetchDataSource', 'debounce' => 600, 'params' => ['dataSourceId' => 'search']]],
                ]],
            ]],
        ]]];

        $this->assertHasLint(LayoutMarkupLintCode::NestedActionDebounceIgnored, $json);
    }

    /**
     * 진입점(`<conditions :debounce>`)에 단 debounce 는 정상이다 — 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects nested_action_debounce_is_warned
     *
     * @return void 반환값 없음
     */
    public function test_debounce_on_entry_action_does_not_warn(): void
    {
        $json = ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Input',
            'actions' => [[
                'type' => 'change',
                'handler' => 'conditions',
                'debounce' => 600,
                'conditions' => [[
                    'if' => '{{true}}',
                    'then' => [['handler' => 'refetchDataSource', 'params' => ['dataSourceId' => 'search']]],
                ]],
            ]],
        ]]];

        $this->assertNoLint(LayoutMarkupLintCode::NestedActionDebounceIgnored, $json);
    }

    // ---------------------------------------------------------------------
    // ⑬ 같은 이벤트에 디바운스·비디바운스 동작 혼재 (7.1.0 P13)
    // ---------------------------------------------------------------------

    /**
     * 같은 부품·같은 이벤트에 디바운스 동작과 바로 실행하는 동작이 섞이면 경고한다.
     *
     * 엔진은 바로 실행하는 동작을 돌리기 전에 대기 중인 디바운스를 모두 즉시 실행한다(최신 상태 보장) —
     * 그래서 디바운스 동작도 매 입력 실행된다. 동작은 그대로 두고 작성자에게 알린다. `type: change` 와
     * `event: onChange` 는 런타임에서 같은 prop 이라 한 묶음으로 본다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects mixed_debounce_on_same_event_is_warned
     *
     * @return void 반환값 없음
     */
    public function test_mixed_debounce_and_immediate_actions_on_same_event_warn(): void
    {
        $json = ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Input',
            'actions' => [
                ['type' => 'change', 'handler' => 'refetchDataSource', 'debounce' => 300, 'params' => ['dataSourceId' => 'search']],
                ['event' => 'onChange', 'handler' => 'setState', 'params' => ['target' => 'local', 'q' => '{{$event.target.value}}']],
            ],
        ]]];

        $this->assertHasLint(LayoutMarkupLintCode::MixedDebounceFlushed, $json);

        $warning = collect((new LayoutLinter)->lint($json, 'layout', new CompileContext))
            ->first(fn (LintWarning $w) => $w->getCode() === LayoutMarkupLintCode::MixedDebounceFlushed);
        $this->assertSame('onChange', $warning->getParams()['event']);
        $this->assertSame('Input', $warning->getParams()['name']);
    }

    /**
     * 다른 이벤트에 나뉘었거나 모두 디바운스면 경고하지 않는다.
     *
     * @scenario compile_unit=linter,source_health=valid
     *
     * @effects mixed_debounce_on_same_event_is_warned
     *
     * @return void 반환값 없음
     */
    public function test_debounce_split_across_events_or_all_debounced_does_not_warn(): void
    {
        $split = ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Input',
            'actions' => [
                ['type' => 'change', 'handler' => 'refetchDataSource', 'debounce' => 300, 'params' => ['dataSourceId' => 'search']],
                ['type' => 'keydown', 'key' => 'Enter', 'handler' => 'refetchDataSource', 'params' => ['dataSourceId' => 'search']],
            ],
        ]]];
        $allDebounced = ['layout_name' => 'x', 'components' => [[
            'type' => 'basic',
            'name' => 'Input',
            'actions' => [
                ['type' => 'change', 'handler' => 'refetchDataSource', 'debounce' => 300, 'params' => ['dataSourceId' => 'a']],
                ['type' => 'change', 'handler' => 'setState', 'debounce' => ['delay' => 300], 'params' => ['target' => 'local', 'q' => '1']],
            ],
        ]]];

        $this->assertNoLint(LayoutMarkupLintCode::MixedDebounceFlushed, $split);
        $this->assertNoLint(LayoutMarkupLintCode::MixedDebounceFlushed, $allDebounced);
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
