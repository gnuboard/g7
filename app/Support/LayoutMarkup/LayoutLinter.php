<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;

/**
 * 컴파일 산출 JSON 에 대한 정적 lint (S6).
 *
 * lint 은 컴파일을 **막지 않는다** — 산출 JSON 은 정상 생성되고, 저작자·리뷰어에게 "런타임에
 * 문제가 될 수 있다" 를 알린다. 강검증(`LayoutValidator`)이 "틀렸으니 못 만든다" 라면, lint 은
 * "만들 수는 있으나 권하지 않는다" 다. 그래서 예외가 아니라 `LintWarning` 목록을 모아 돌려준다.
 *
 * 다섯 가지를 본다:
 *  ① **미등록 핸들러** — 액션 handler 가 코어 44종·템플릿 등록·확장(`.`) 어디에도 없음(D30⑦).
 *  ② **플러그인 완전 레이아웃** — 플러그인은 layout_extensions·설정 화면만 두는 관례이나 완전
 *     레이아웃을 정의함(gdpr_consent_log·plugin_settings 예외, D15).
 *  ③ **nesting 위반** — 부모 컨테이너의 accepts 가 허용하지 않는 자식(빈 accepts 부모는 제외 —
 *     편집기 정책이라 Table→Thead 같은 오검출을 부른다는 계획서 경고를 반영).
 *  ④ **responsive breakpoint 혼용** — 한 문서에 portable 프리셋과 mobile/tablet 프리셋 병용.
 *  ⑤ **독립 레이아웃의 글로벌 호스트 누락** — extends 없는 레이아웃이 toast·openModal 을 발화하나
 *     Toast·Modal 호스트를 마운트하지 않음(AGENTS.md 의 silent-failure 회귀 규정).
 *  ⑥ **switch top-level `value`** — 엔진이 `params.value` 만 읽는다(D42 G8).
 *  ⑦ **액션 `params` 오배치 키** — `condition`·`trigger`·`id` 가 정본이 아닌 자리에 실림(S23 축 A).
 *  ⑧ **hoist 3종 템플릿 미지원** — 대상 템플릿 Modal 이 `footer`·`titlePrefix`·`titleSuffix` 를
 *     선언하지 않았는데 저작이 그 자리를 씀(S23 축 B).
 *
 * lint 은 산출 JSON 을 대상으로 하므로 소스 좌표를 특정하기 어렵다 — 경고는 문서 전체(라인 0)로
 * 낸다. 지어낸 좌표로 저작자를 엉뚱한 자리로 보내지 않는다.
 */
final class LayoutLinter
{
    /** toast·모달을 화면에 띄우려면 마운트돼야 하는 호스트 컴포넌트 name */
    private const GLOBAL_HOST_COMPONENTS = ['Toast', 'ModalRoot', 'Modal'];

    /** 독립 레이아웃에서 호스트를 요구하는 글로벌 상태 기반 핸들러 */
    private const GLOBAL_HOST_HANDLERS = ['toast', 'openModal', 'showAlert'];

    /**
     * ⑦ 축 A — 액션 `params` 에 오면 안 되는 **닫힌 3키** → 그 키를 정당하게 소비하는 코어 핸들러.
     *
     * 값 배열이 비면 "어떤 코어 핸들러도 읽지 않는다" 는 뜻이다. 면제 목록은 추측이 아니라
     * 엔진 실측이다 — `params.id` 를 읽는 코어 핸들러는 `startInterval`(ActionDispatcher:5146)과
     * `stopInterval`(:5181) 둘뿐이고, `params.condition`·`params.trigger` 는 엔진 전역에 소비처가 0 이다.
     *
     * 확장·custom 핸들러는 애초에 이 판정에 들어오지 않는다(consumesUnknownParams) — 그쪽 params 는
     * 구현이 정하므로 알 수 없고, 실제로 `sirsoft-ecommerce.loadVisibleFilters` 는 `params.id` 를
     * 필수 인자로 읽는다. 키 집합을 넓히지 않는 이유는 D19 다: prop/params 전면 화이트리스트는
     * 선언 커버리지 75~77% 위에서 대량 오탐이 된다.
     *
     * @var array<string, array<int, string>>
     */
    private const MISPLACED_PARAM_KEYS = [
        'condition' => [],
        'trigger' => [],
        'id' => ['startInterval', 'stopInterval'],
    ];

    private readonly ActionHandlerManifest $handlers;

    /**
     * 레지스트리는 **필수**다 — 기본값을 두면 그것을 안 넘긴 호출부에서 축 ⑧ 이 조용히 꺼지고,
     * 그 침묵은 "위반 0" 과 구분되지 않는다. 컴파일러가 이미 레지스트리를 들고 있으므로 넘기면 된다.
     *
     * @param  ComponentRegistry  $registry  대상 템플릿(+활성 확장) 컴포넌트 레지스트리
     */
    public function __construct(private readonly ComponentRegistry $registry)
    {
        $this->handlers = new ActionHandlerManifest;
    }

    /**
     * 산출 JSON 을 lint 하고 경고 목록을 반환합니다.
     *
     * @param  array<string, mixed>  $json  컴파일 산출 레이아웃 JSON
     * @param  string  $rootKind  문서 봉투 (`layout`·`partial`·`extension`·`override`)
     * @param  CompileContext  $context  lint 문맥
     * @param  string|null  $path  소스 파일 경로 (경고 메시지용)
     * @return array<int, LintWarning> 경고 목록
     */
    public function lint(array $json, string $rootKind, CompileContext $context, ?string $path = null): array
    {
        $warnings = [];

        $this->lintUnregisteredHandlers($json, $context, $path, $warnings);
        $this->lintNesting($json, $context, $path, $warnings);
        $this->lintResponsiveMix($json, $path, $warnings);
        $this->lintStandaloneGlobalHost($json, $rootKind, $path, $warnings);
        $this->lintSwitchValuePlacement($json, $path, $warnings);
        $this->lintMisplacedActionParams($json, $path, $warnings);
        $this->lintUnsupportedHoistProps($json, $path, $warnings);

        return $warnings;
    }

    /**
     * ⑦ 액션 `params` 에 정본이 다른 자리인 키가 실린 곳을 경고합니다 (S23 축 A).
     *
     * 같은 키·같은 핸들러 조합은 한 번만 낸다 — 좌표가 없는 경고(라인 0)라 반복해 봐야 저작자가
     * 고칠 자리를 더 알게 되지 않고 노이즈만 는다(⑴ 미등록 핸들러와 같은 규율).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintMisplacedActionParams(array $json, ?string $path, array &$warnings): void
    {
        $seen = [];

        foreach ($this->collectActions($json) as $action) {
            $handler = $action['handler'];
            $params = $action['params'] ?? null;

            if (! is_array($params) || $this->consumesUnknownParams($handler)) {
                continue;
            }

            foreach (self::MISPLACED_PARAM_KEYS as $key => $consumers) {
                if (! array_key_exists($key, $params) || in_array($handler, $consumers, true)) {
                    continue;
                }

                $signature = $handler.'.'.$key;

                if (isset($seen[$signature])) {
                    continue;
                }

                $seen[$signature] = true;
                $warnings[] = new LintWarning(LayoutMarkupLintCode::ActionParamMisplacedKey, 0, 0, [
                    'handler' => $handler,
                    'key' => $key,
                ], $path);
            }
        }
    }

    /**
     * 이 핸들러의 params 표면을 알 수 없는지 판정합니다 (확장·템플릿·custom).
     *
     * `isKnownHandler` 와 판정 대상이 다르다 — 저쪽은 "이 이름이 존재하는가", 이쪽은 "이 핸들러가
     * 어떤 params 를 읽는지 우리가 아는가" 다. 확장 핸들러는 존재하지만 params 는 구현 소유라
     * 알 수 없다(실측: `sirsoft-ecommerce.loadVisibleFilters` 는 `params.id` 를 필수로 읽는다).
     *
     * @param  string  $handler  핸들러명
     * @return bool params 표면을 알 수 없으면 true (판정 제외)
     */
    private function consumesUnknownParams(string $handler): bool
    {
        return ! $this->handlers->isCoreHandler($handler);
    }

    /**
     * ⑧ 대상 템플릿 Modal 이 선언하지 않은 hoist prop 을 쓴 곳을 경고합니다 (S23 축 B).
     *
     * 판정 집합은 {@see EmbeddingCompiler::MODAL_TAGS} 에서 도출한다 — hoist 를 하나 더하는 날
     * 이 검사가 자동으로 따라오게 하기 위해서다. 부모도 같은 곳의 {@see EmbeddingCompiler::MODAL_PARENT}
     * 를 쓴다: 이 3키의 저작 경로는 Modal 자식뿐이므로 그 밖의 노드까지 넓히면 매니페스트 부분
     * 선언(75~77%) 위에서 오탐이 나기 시작한다(D19).
     *
     * 레지스트리에 없는 이름은 건너뛴다 — 미등록 컴포넌트는 강검증(`LayoutValidator`)이 이미 세운다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintUnsupportedHoistProps(array $json, ?string $path, array &$warnings): void
    {
        $definition = $this->registry->findByName(EmbeddingCompiler::MODAL_PARENT);

        if ($definition === null) {
            return;
        }

        $seen = [];

        foreach ($this->collectNodes($json) as $node) {
            if (($node['name'] ?? null) !== EmbeddingCompiler::MODAL_PARENT) {
                continue;
            }

            $props = $node['props'] ?? null;

            if (! is_array($props)) {
                continue;
            }

            foreach (EmbeddingCompiler::MODAL_TAGS as $tag => $prop) {
                if (! array_key_exists($prop, $props) || $definition->propType($prop) !== null || isset($seen[$prop])) {
                    continue;
                }

                $seen[$prop] = true;
                $warnings[] = new LintWarning(LayoutMarkupLintCode::HoistPropNotSupportedByTemplate, 0, 0, [
                    'tag' => $tag,
                    'prop' => $prop,
                    'component' => EmbeddingCompiler::MODAL_PARENT,
                    'template' => $this->registry->templateId,
                ], $path);
            }
        }
    }

    /**
     * ⑥ `switch` 액션이 분기 대상을 top-level `value` 에 둔 자리를 경고합니다 (D42 G8).
     *
     * 엔진은 `params.value` 만 읽는다(`ActionDispatcher::handleSwitch`) — top-level `value` 는
     * 소비되지 않고 `$args[0]` 폴백으로 넘어가므로, 값이 `$args[0]` 과 다르면 조용히 오분기한다.
     * 저작 표기는 `<switch on="…">` 이 정본이고 `<switch value="…">` 는 이관 보존용이다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintSwitchValuePlacement(array $json, ?string $path, array &$warnings): void
    {
        foreach ($this->collectActions($json) as $action) {
            if (($action['handler'] ?? null) === 'switch' && array_key_exists('value', $action)) {
                $warnings[] = new LintWarning(LayoutMarkupLintCode::SwitchValueNotConsumed, 0, 0, [], $path);
            }
        }
    }

    /**
     * 임의 구조를 심층 순회하며 `handler` 키를 가진 액션 객체 자체를 모읍니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @return array<int, array<string, mixed>> 액션 객체 목록
     */
    private function collectActions(array $json): array
    {
        $actions = [];
        $this->walkForActions($json, $actions);

        return $actions;
    }

    /**
     * 임의 구조를 심층 순회하며 액션 객체를 수집합니다 (자식 키를 열거하지 않는다 — collectHandlers 와 동일 규율).
     *
     * @param  mixed  $value  순회 대상
     * @param  array<int, array<string, mixed>>  $actions  액션 목록 (참조)
     */
    private function walkForActions(mixed $value, array &$actions): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['handler']) && is_string($value['handler'])) {
            $actions[] = $value;
        }

        foreach ($value as $child) {
            $this->walkForActions($child, $actions);
        }
    }

    /**
     * ① 산출된 모든 액션의 handler 가 알려진 핸들러인지 확인합니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext  $context  문맥 (템플릿 등록 핸들러)
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintUnregisteredHandlers(array $json, CompileContext $context, ?string $path, array &$warnings): void
    {
        $seen = [];

        foreach ($this->collectHandlers($json) as $handler) {
            if ($this->isKnownHandler($handler, $context) || isset($seen[$handler])) {
                continue;
            }

            $seen[$handler] = true;
            $warnings[] = new LintWarning(LayoutMarkupLintCode::UnregisteredHandler, 0, 0, ['handler' => $handler], $path);
        }
    }

    /**
     * 핸들러가 코어 44종·확장(`.`)·템플릿 등록·custom 디스패치 중 하나인지 판정합니다.
     *
     * @param  string  $handler  핸들러명
     * @param  CompileContext  $context  문맥
     * @return bool 알려진 핸들러이면 true
     */
    private function isKnownHandler(string $handler, CompileContext $context): bool
    {
        // 확장·템플릿 네임스페이스 핸들러는 점을 품는다(sirsoft-ecommerce.toggleArrayValue).
        if (str_contains($handler, '.')) {
            return true;
        }

        // custom = 이름(name/params.name)으로 템플릿 핸들러를 부르는 디스패처. 이름 판정은
        // 템플릿 종속이라 여기서는 알려진 것으로 둔다(오검출 회피).
        if ($handler === 'custom') {
            return true;
        }

        return $this->handlers->isCoreHandler($handler) || in_array($handler, $context->templateHandlers, true);
    }

    /**
     * ③ 부모 컨테이너의 accepts 가 허용하지 않는 자식이 있는지 확인합니다.
     *
     * accepts 가 빈 목록인 부모(leaf·composite)는 검사하지 않는다 — nesting.json 은 편집기 드래그
     * 정책이라 Table(accepts:[]) 밑의 Thead 를 오검출한다는 계획서 경고를 반영한 것이다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext  $context  문맥 (nesting 규칙)
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintNesting(array $json, CompileContext $context, ?string $path, array &$warnings): void
    {
        if ($context->nestingRules === []) {
            return;
        }

        $seen = [];

        foreach ($this->collectNodes($json) as $node) {
            $parentName = $node['name'] ?? null;
            $accepts = is_string($parentName) ? ($context->nestingRules[$parentName] ?? null) : null;

            // 규칙 없음 또는 빈 accepts(leaf·composite) → 검사 제외.
            if (! is_array($accepts) || $accepts === []) {
                continue;
            }

            foreach ($node['children'] ?? [] as $child) {
                $childName = is_array($child) ? ($child['name'] ?? null) : null;

                if (is_string($childName) && ! in_array($childName, $accepts, true)) {
                    $key = $parentName.'>'.$childName;

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $warnings[] = new LintWarning(LayoutMarkupLintCode::NestingViolation, 0, 0, [
                        'parent' => (string) $parentName,
                        'child' => $childName,
                    ], $path);
                }
            }
        }
    }

    /**
     * ④ portable 프리셋과 mobile/tablet 프리셋이 한 문서에 병용됐는지 확인합니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintResponsiveMix(array $json, ?string $path, array &$warnings): void
    {
        $breakpoints = [];
        $this->collectResponsiveBreakpoints($json, $breakpoints);

        $hasPortable = isset($breakpoints['portable']);
        $hasSplit = isset($breakpoints['mobile']) || isset($breakpoints['tablet']);

        if ($hasPortable && $hasSplit) {
            $warnings[] = new LintWarning(LayoutMarkupLintCode::ResponsiveBreakpointMix, 0, 0, [], $path);
        }
    }

    /**
     * ⑤ 독립 레이아웃이 글로벌 호스트 없이 toast·모달을 발화하는지 확인합니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string  $rootKind  문서 봉투
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     */
    private function lintStandaloneGlobalHost(array $json, string $rootKind, ?string $path, array &$warnings): void
    {
        // extends 로 베이스를 상속하면 호스트는 베이스가 마운트한다 — 검사 대상 아님.
        if ($rootKind !== 'layout' || isset($json['extends'])) {
            return;
        }

        $firesGlobal = false;
        foreach ($this->collectHandlers($json) as $handler) {
            if (in_array($handler, self::GLOBAL_HOST_HANDLERS, true)) {
                $firesGlobal = true;
                break;
            }
        }

        if (! $firesGlobal) {
            return;
        }

        // 호스트 컴포넌트가 트리에 있거나 modals 섹션이 있으면 화면에 뜬다.
        if (isset($json['modals']) && $json['modals'] !== []) {
            return;
        }

        foreach ($this->collectNodes($json) as $node) {
            if (in_array($node['name'] ?? null, self::GLOBAL_HOST_COMPONENTS, true)) {
                return;
            }
        }

        $warnings[] = new LintWarning(LayoutMarkupLintCode::StandaloneMissingGlobalHost, 0, 0, [], $path);
    }

    // ---------------------------------------------------------------------
    // 수집 헬퍼
    // ---------------------------------------------------------------------

    /**
     * 문서 전역의 모든 액션 handler 문자열을 모읍니다 (노드 actions·init·named·콜백·중첩 액션).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @return array<int, string> handler 문자열 목록
     */
    private function collectHandlers(array $json): array
    {
        $handlers = [];
        $this->walkForHandlers($json, $handlers);

        return $handlers;
    }

    /**
     * 임의 구조를 심층 순회하며 `handler` 키를 가진 액션 객체를 찾습니다.
     *
     * 자식 키를 열거하지 않고 전 구조를 훑는다 — 열거하면 새 키(콜백·케이스 등)가 생길 때 그
     * 가지가 조용히 빠진다.
     *
     * @param  mixed  $value  순회 대상
     * @param  array<int, string>  $handlers  handler 목록 (참조)
     */
    private function walkForHandlers(mixed $value, array &$handlers): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['handler']) && is_string($value['handler'])) {
            $handlers[] = $value['handler'];
        }

        foreach ($value as $child) {
            $this->walkForHandlers($child, $handlers);
        }
    }

    /**
     * 컴포넌트 노드(type+name 보유)를 심층 순회로 모읍니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @return array<int, array<string, mixed>> 노드 목록
     */
    private function collectNodes(array $json): array
    {
        $nodes = [];
        $this->walkForNodes($json, $nodes);

        return $nodes;
    }

    /**
     * 임의 구조를 심층 순회하며 컴포넌트 노드를 찾습니다.
     *
     * @param  mixed  $value  순회 대상
     * @param  array<int, array<string, mixed>>  $nodes  노드 목록 (참조)
     */
    private function walkForNodes(mixed $value, array &$nodes): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['type'], $value['name']) && is_string($value['type']) && is_string($value['name'])) {
            $nodes[] = $value;
        }

        foreach ($value as $child) {
            $this->walkForNodes($child, $nodes);
        }
    }

    /**
     * responsive 오버라이드의 breakpoint 키를 심층 순회로 모읍니다.
     *
     * @param  mixed  $value  순회 대상
     * @param  array<string, bool>  $breakpoints  breakpoint 키 집합 (참조)
     */
    private function collectResponsiveBreakpoints(mixed $value, array &$breakpoints): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['responsive']) && is_array($value['responsive'])) {
            foreach (array_keys($value['responsive']) as $breakpoint) {
                $breakpoints[(string) $breakpoint] = true;
            }
        }

        foreach ($value as $child) {
            $this->collectResponsiveBreakpoints($child, $breakpoints);
        }
    }
}
