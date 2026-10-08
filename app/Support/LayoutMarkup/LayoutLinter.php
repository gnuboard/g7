<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupLintCode;

/**
 * 컴파일 산출 JSON 에 대한 정적 lint (S6).
 *
 * lint 은 컴파일을 **막지 않는다** — 산출 JSON 은 정상 생성되고, 작성자·리뷰어에게 "런타임에
 * 문제가 될 수 있다" 를 알린다. 강검증(`LayoutValidator`)이 "틀렸으니 못 만든다" 라면, lint 은
 * "만들 수는 있으나 권하지 않는다" 다. 그래서 예외가 아니라 `LintWarning` 목록을 모아 돌려준다.
 *
 * 아래 열세 가지를 본다:
 *  ① **미등록 핸들러** — 액션 handler 가 코어 49종·템플릿 등록·확장(`.`) 어디에도 없음(D30⑦).
 *  ② **플러그인 완전 레이아웃** — 플러그인은 layout_extensions·설정 화면만 두는 관례이나 완전
 *     레이아웃을 정의함(gdpr_consent_log·plugin_settings 예외, D15).
 *  ③ **nesting 위반** — 부모 컨테이너의 accepts 가 허용하지 않는 자식(빈 accepts 부모는 제외 —
 *     편집기 정책이라 Table→Thead 같은 오검출을 부른다는 계획서 경고를 반영).
 *  ④ **responsive breakpoint 혼용** — 한 문서에 portable 프리셋과 mobile/tablet 프리셋 병용.
 *  ⑤ **독립 레이아웃의 글로벌 호스트 누락** — extends 없는 레이아웃이 toast·openModal 을 발화하나
 *     그 전역 상태(`_global.toasts`·`modals`/`_global.modalStack`)를 받는 자리가 없음(AGENTS.md 의
 *     silent-failure 회귀 규정). 컴포넌트 이름이 아니라 엔진 계약으로 판정한다.
 *  ⑥ **switch top-level `value`** — 엔진이 `params.value` 만 읽는다(D42 G8).
 *  ⑦ **액션 `params` 오배치 키** — `condition`·`trigger`·`id` 가 정본이 아닌 자리에 실림(S23 축 A).
 *  ⑧ **hoist 3종 템플릿 미지원** — 대상 템플릿의 그 컴포넌트가 `footer`·`titlePrefix`·`titleSuffix` 를
 *     선언하지 않았는데 마크업이 그 자리를 씀(S23 축 B — 부모 이름 무관).
 *  ⑨ **종류 불일치** — `data-type` 이 대상 템플릿 선언과 다름.
 *  ⑩ **설치되지 않은 확장 컴포넌트** — `식별자.이름` 이 설치된 어느 확장에도 없음(막지 않는다).
 *  ⑪ **저장본에 있던 목록 밖 템플릿 컴포넌트** — 편집기 전용, 막지 않는다(#712).
 *  ⑫ **안쪽 액션의 debounce** — 진입점이 아닌 액션의 debounce 는 엔진이 적용하지 않는다.
 *  ⑬ **debounce 혼재** — 같은 부품·같은 이벤트에 debounce 동작과 바로 실행하는 동작이 섞임.
 *
 * lint 은 산출 JSON 을 대상으로 하므로 소스 좌표를 모른다. 위치 없는 경고가 나오면
 * `LayoutMarkupCompiler` 가 노드·액션에 소스 위치 표식({@see SOURCE_POSITION_KEY})을 붙여 한 번 더
 * 컴파일하고 그 결과로 lint 를 다시 돌린다 — 경고는 그 표식의 줄·칸을 싣고, 표식이 없는 자리는
 * 문서 전체(라인 0)로 낸다. 지어낸 좌표로 작성자를 엉뚱한 자리로 보내지 않는다(7.1.0 P16).
 */
final class LayoutLinter
{
    /**
     * 위치를 찾는 재컴파일에서 노드·액션 배열에 붙는 소스 위치 표식 키 — 값은 `[줄, 칸]`.
     *
     * 산출 JSON 을 검사하는 lint 는 소스 좌표를 모른다. 위치 없는 경고가 나왔을 때만 `LayoutMarkupCompiler` 가
     * 표식을 켜고 한 번 더 컴파일해 그 결과로 lint 를 다시 돈다. 표식 JSON 은 lint 에만 쓰고 버린다(7.1.0 P16).
     */
    public const SOURCE_POSITION_KEY = '__g7_source_position';

    /**
     * 독립 레이아웃에서 호스트를 요구하는 핸들러 → 그 핸들러가 쓰는 `_global` 상태 키.
     *
     * 호스트를 **컴포넌트 이름**(`Toast`·`Modal`)으로 찾지 않는다 — 그 이름은 번들 템플릿의 것이라 다른
     * 템플릿의 호스트를 놓치고, 이름만 같은 노드를 호스트로 오인한다(2026-09-28 결정: 템플릿 무관).
     * 대신 엔진 계약을 본다: `toast` 는 `_global.toasts` 에 쌓고(ActionDispatcher::handleToast) 그 값을 받는
     * 노드가 화면에 그린다. `openModal` 은 `_global.modalStack` 에 올리고, 엔진이 `modals` 섹션을 그 스택으로
     * 연다(template-engine.ts). `showAlert` 는 브라우저 `alert()` 라 호스트가 필요 없어 대상이 아니다.
     *
     * @var array<string, array<int, string>>
     */
    private const GLOBAL_HOST_STATE = [
        'toast' => ['toasts'],
        'openModal' => ['modalStack', 'activeModal'],
    ];

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
     * @param  ComponentRegistry|null  $registry  검사용 템플릿 컴포넌트 레지스트리 (없으면 템플릿 기준 lint 생략)
     */
    public function __construct(private readonly ?ComponentRegistry $registry = null)
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
        $this->lintComponentTypeMismatch($json, $path, $warnings);
        $this->lintMissingExtensionComponents($json, $path, $warnings);
        $this->lintKeptTemplateComponents($json, $context, $path, $warnings);
        $this->lintNestedActionDebounce($json, $path, $warnings);
        $this->lintMixedDebounce($json, $path, $warnings);

        return $warnings;
    }

    /**
     * ⑫ 다른 액션 안쪽에 놓인 액션의 `debounce` 를 경고합니다 — 엔진은 진입점 액션의 debounce 만 적용한다.
     *
     * 같은 핸들러는 한 번만 낸다(좌표 없는 경고라 반복해도 고칠 자리가 늘지 않는다).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function lintNestedActionDebounce(array $json, ?string $path, array &$warnings): void
    {
        $seen = [];
        $this->walkForNestedDebounce($json, false, $path, $warnings, $seen);
    }

    /**
     * ⑬ 같은 부품·같은 이벤트에 디바운스 동작과 바로 실행하는 동작이 섞인 자리를 경고합니다.
     *
     * 엔진은 이벤트 prop 별로 진입점 액션을 묶고, 바로 실행하는 액션을 돌리기 전에 대기 중인 디바운스를 모두
     * 즉시 실행한다. 그래서 섞어 두면 디바운스 동작도 매 입력 실행된다. 묶는 기준은 런타임과 같다 —
     * `event` 가 있으면 그것(DOM 이름은 `onClick` 꼴로), 없으면 `type`, 둘 다 없으면 click.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function lintMixedDebounce(array $json, ?string $path, array &$warnings): void
    {
        $this->walkForMixedDebounce($json, $path, $warnings);
    }

    /**
     * 부품 노드(`name` + 최상위 `actions` 배열)를 찾아 이벤트별 디바운스 혼재를 검사합니다.
     *
     * @param  mixed  $value  순회 대상
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function walkForMixedDebounce(mixed $value, ?string $path, array &$warnings): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['name']) && is_string($value['name']) && isset($value['actions']) && is_array($value['actions']) && array_is_list($value['actions'])) {
            $groups = [];

            foreach ($value['actions'] as $action) {
                if (! is_array($action) || ! isset($action['handler'])) {
                    continue;
                }

                $event = self::runtimeEventProp($action);
                $kind = ! empty($action['debounce']) ? 'debounced' : 'immediate';
                $groups[$event][$kind] = true;
            }

            foreach ($groups as $event => $kinds) {
                if (isset($kinds['debounced'], $kinds['immediate'])) {
                    $warnings[] = new LintWarning(LayoutMarkupLintCode::MixedDebounceFlushed, self::line($value), self::column($value), [
                        'name' => $value['name'],
                        'event' => $event,
                    ], $path);
                }
            }
        }

        foreach ($value as $child) {
            $this->walkForMixedDebounce($child, $path, $warnings);
        }
    }

    /**
     * 액션이 런타임에서 묶이는 이벤트 prop 이름을 구합니다 (ActionDispatcher::bindActionsToProps 와 같은 규칙).
     *
     * @param  array<string, mixed>  $action  진입점 액션
     * @return string prop 이름 (예: onChange)
     */
    private static function runtimeEventProp(array $action): string
    {
        // ActionDispatcher.ts DOM_EVENT_PROP_MAP 와 같은 표
        $map = [
            'click' => 'onClick', 'change' => 'onChange', 'input' => 'onInput', 'submit' => 'onSubmit',
            'focus' => 'onFocus', 'blur' => 'onBlur', 'keydown' => 'onKeyDown', 'keyup' => 'onKeyUp',
            'keypress' => 'onKeyPress', 'mousedown' => 'onMouseDown', 'mouseup' => 'onMouseUp',
            'mouseenter' => 'onMouseEnter', 'mouseleave' => 'onMouseLeave', 'scroll' => 'onScroll',
            'dragstart' => 'onDragStart', 'drag' => 'onDrag', 'dragend' => 'onDragEnd', 'dragenter' => 'onDragEnter',
            'dragover' => 'onDragOver', 'dragleave' => 'onDragLeave', 'drop' => 'onDrop',
        ];

        if (isset($action['event']) && is_string($action['event']) && $action['event'] !== '') {
            return $map[$action['event']] ?? $action['event'];
        }

        if (isset($action['type']) && is_string($action['type']) && $action['type'] !== '') {
            return $map[$action['type']] ?? 'on'.ucfirst($action['type']);
        }

        return 'onClick';
    }

    /**
     * 액션 안쪽 여부를 들고 심층 순회하며 안쪽 액션의 debounce 를 찾습니다.
     *
     * @param  mixed  $value  순회 대상
     * @param  bool  $insideAction  상위에 액션이 있는지
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @param  array<string, bool>  $seen  이미 낸 핸들러 (참조)
     * @return void 반환값 없음
     */
    private function walkForNestedDebounce(mixed $value, bool $insideAction, ?string $path, array &$warnings, array &$seen): void
    {
        if (! is_array($value)) {
            return;
        }

        $isAction = isset($value['handler']) && is_string($value['handler']);

        if ($isAction && $insideAction && array_key_exists('debounce', $value) && ! isset($seen[$value['handler']])) {
            $seen[$value['handler']] = true;
            $warnings[] = new LintWarning(LayoutMarkupLintCode::NestedActionDebounceIgnored, self::line($value), self::column($value), [
                'handler' => $value['handler'],
            ], $path);
        }

        foreach ($value as $child) {
            $this->walkForNestedDebounce($child, $insideAction || $isAction, $path, $warnings, $seen);
        }
    }

    /**
     * ⑨ 작성한 컴포넌트 종류가 검사 대상 템플릿의 선언과 다른 자리를 경고합니다.
     *
     * 검사용 레지스트리가 있을 때만 돈다 — 산출은 템플릿과 무관하므로 설치 경로에는 레지스트리가 없다.
     * 같은 컴포넌트·같은 종류 조합은 한 번만 낸다(좌표 없는 경고라 반복해도 고칠 자리가 늘지 않는다).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function lintComponentTypeMismatch(array $json, ?string $path, array &$warnings): void
    {
        if ($this->registry === null) {
            return;
        }

        $seen = [];

        foreach ($this->collectNodes($json) as $node) {
            $name = $node['name'] ?? null;
            $type = $node['type'] ?? null;

            if (! is_string($name) || ! in_array($type, NodeCompiler::COMPONENT_TYPES, true)) {
                continue;
            }

            $definition = $this->registry->findByName($name);

            if ($definition === null || $definition->type === $type || isset($seen[$name.'|'.$type])) {
                continue;
            }

            $seen[$name.'|'.$type] = true;
            $warnings[] = new LintWarning(LayoutMarkupLintCode::ComponentTypeMismatch, self::line($node), self::column($node), [
                'component' => $name,
                'written' => $type,
                'declared' => $definition->type,
                'template' => $this->registry->templateId,
            ], $path);
        }
    }

    /**
     * ⑦ 액션 `params` 에 정본이 다른 자리인 키가 실린 곳을 경고합니다 (S23 축 A).
     *
     * 같은 키·같은 핸들러 조합은 한 번만 낸다 — 좌표가 없는 경고(라인 0)라 반복해 봐야 작성자가
     * 고칠 자리를 더 알게 되지 않고 노이즈만 는다(⑴ 미등록 핸들러와 같은 규율).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
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
                $warnings[] = new LintWarning(LayoutMarkupLintCode::ActionParamMisplacedKey, self::line($action), self::column($action), [
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
     * ⑧ 대상 템플릿의 컴포넌트가 선언하지 않은 hoist prop 을 쓴 곳을 경고합니다 (S23 축 B).
     *
     * hoist 는 부모 이름과 무관하므로(2026-09-28 결정) 판정도 이름(`Modal`)이 아니라 **그 노드
     * 컴포넌트의 선언**으로 한다 — 어느 컴포넌트든 `footer`·`titlePrefix`·`titleSuffix` 를 선언하지 않았는데
     * 그 자리를 쓰면 작성한 내용이 렌더되지 않고 사라진다. 키 집합은
     * {@see EmbeddingCompiler::DECLARATION_CHECKED_PROPS} 로 닫혀 있다: 나머지 hoist 키는 선언 없이 렌더하는
     * 조합이 실측 수백 건이라(D19) 선언 대조가 오탐이다.
     *
     * 레지스트리에 없는 이름은 건너뛴다 — 미등록 컴포넌트는 강검증(`LayoutValidator`)·⑩ 이 맡는다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function lintUnsupportedHoistProps(array $json, ?string $path, array &$warnings): void
    {
        if ($this->registry === null) {
            return;
        }

        $seen = [];

        foreach ($this->collectNodes($json) as $node) {
            $name = $node['name'] ?? null;
            $props = $node['props'] ?? null;

            if (! is_string($name) || ! is_array($props) || ! in_array($node['type'] ?? null, NodeCompiler::COMPONENT_TYPES, true)) {
                continue;
            }

            // 대상 템플릿이 모르는 이름은 판정하지 않는다 — 그 자리는 존재 검사(강검증·⑩)가 맡는다.
            $definition = $this->registry->findByName($name);

            if ($definition === null) {
                continue;
            }

            foreach (EmbeddingCompiler::DECLARATION_CHECKED_PROPS as $prop) {
                if (! array_key_exists($prop, $props) || $definition->propType($prop) !== null || isset($seen[$name.'.'.$prop])) {
                    continue;
                }

                $seen[$name.'.'.$prop] = true;
                $warnings[] = new LintWarning(LayoutMarkupLintCode::HoistPropNotSupportedByTemplate, self::line($node), self::column($node), [
                    'tag' => (string) EmbeddingCompiler::tagForProp($prop, $name),
                    'prop' => $prop,
                    'component' => $name,
                    'template' => $this->registry->templateId,
                ], $path);
            }
        }
    }

    /**
     * ⑩ 설치된 어느 확장에도 없는 확장 컴포넌트(`식별자.이름`)를 경고합니다.
     *
     * 확장 컴포넌트는 확장이 지워지거나(편집 중 삭제·비활성화 포함) 이름을 잘못 적으면 목록에 없다. 템플릿
     * 컴포넌트 오타와 달리 **막지 않는다** — 확장은 나중에 다시 설치될 수 있고, 편집 중인 레이아웃을 그
     * 확장 하나 때문에 저장하지 못하게 하면 안 된다(2026-09-28 결정). 렌더러는 없는 컴포넌트를 그리지
     * 않으므로 작성자에게 그 사실만 알린다. 검사용 레지스트리가 있을 때만 돈다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function lintMissingExtensionComponents(array $json, ?string $path, array &$warnings): void
    {
        if ($this->registry === null) {
            return;
        }

        $seen = [];

        foreach ($this->collectNodes($json) as $node) {
            $name = $node['name'];

            if (! str_contains($name, '.') || ! in_array($node['type'], NodeCompiler::COMPONENT_TYPES, true)
                || isset($seen[$name]) || $this->registry->findByName($name) !== null) {
                continue;
            }

            $seen[$name] = true;
            $warnings[] = new LintWarning(LayoutMarkupLintCode::ExtensionComponentNotInstalled, self::line($node), self::column($node), [
                'name' => $name,
                'extension' => strstr($name, '.', true),
            ], $path);
        }
    }

    /**
     * ⑪ 저장본에 이미 있던 미등록 템플릿 컴포넌트를 경고합니다 (편집기 전용, #712).
     *
     * 검사기가 그 개수까지 허용해 저장은 되지만, 이 템플릿의 컴포넌트 목록에 없으므로 화면에 그려지지 않을 수 있다.
     * 이름마다 한 번만 낸다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext  $context  검증 문맥 (허용 목록)
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function lintKeptTemplateComponents(array $json, CompileContext $context, ?string $path, array &$warnings): void
    {
        if ($this->registry === null || $context->toleratedComponents === []) {
            return;
        }

        $seen = [];

        foreach ($this->collectNodes($json) as $node) {
            $name = $node['name'];

            if (str_contains($name, '.') || ! in_array($node['type'], NodeCompiler::COMPONENT_TYPES, true)
                || isset($seen[$name]) || ! isset($context->toleratedComponents[$name]) || $this->registry->findByName($name) !== null) {
                continue;
            }

            $seen[$name] = true;
            $warnings[] = new LintWarning(LayoutMarkupLintCode::TemplateComponentKept, self::line($node), self::column($node), ['name' => $name], $path);
        }
    }

    /**
     * ⑥ `switch` 액션이 분기 대상을 top-level `value` 에 둔 자리를 경고합니다 (D42 G8).
     *
     * 엔진은 `params.value` 만 읽는다(`ActionDispatcher::handleSwitch`) — top-level `value` 는
     * 소비되지 않고 `$args[0]` 폴백으로 넘어가므로, 값이 `$args[0]` 과 다르면 조용히 오분기한다.
     * 마크업 표기는 `<switch on="…">` 이 정본이고 `<switch value="…">` 는 이관 보존용이다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function lintSwitchValuePlacement(array $json, ?string $path, array &$warnings): void
    {
        foreach ($this->collectActions($json) as $action) {
            if (($action['handler'] ?? null) === 'switch' && array_key_exists('value', $action)) {
                $warnings[] = new LintWarning(LayoutMarkupLintCode::SwitchValueNotConsumed, self::line($action), self::column($action), [], $path);
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    private function lintUnregisteredHandlers(array $json, CompileContext $context, ?string $path, array &$warnings): void
    {
        $seen = [];

        foreach ($this->collectActions($json) as $action) {
            $handler = $action['handler'];

            if ($this->isKnownHandler($handler, $context) || isset($seen[$handler])) {
                continue;
            }

            $seen[$handler] = true;
            $warnings[] = new LintWarning(LayoutMarkupLintCode::UnregisteredHandler, self::line($action), self::column($action), ['handler' => $handler], $path);
        }
    }

    /**
     * 핸들러가 코어 49종·확장(`.`)·템플릿 등록·custom 디스패치 중 하나인지 판정합니다.
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
     * @return void 반환값 없음
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

                // 확장점은 컴포넌트가 아니다 — `name` 이 팔레트 컴포넌트명이 아니라 **확장 지점 이름**이라
                // accepts 에 있을 수가 없다(있어서도 안 된다: 확장이 늘 때마다 템플릿 목록을 고쳐야 한다).
                // 걸러내지 않으면 `Div 는 자식으로 user_global_overlay 를 …` 같은 거짓 경고가 남는다.
                if (is_array($child) && ($child['type'] ?? null) === 'extension_point') {
                    continue;
                }

                // 확장 컴포넌트(`식별자.이름`)도 같은 이유로 거른다 — 템플릿 nesting.json 은 어떤 확장이 설치될지
                // 모르므로 그 이름을 accepts 에 적을 수 없다. 거르지 않으면 편집기에서 확장 컴포넌트를 쓸 때마다
                // `Div 는 자식으로 gnuboard7-hello_module.MemoCard 를 …` 같은 거짓 경고가 붙는다.
                if (is_string($childName) && str_contains($childName, '.')) {
                    continue;
                }

                if (is_string($childName) && ! in_array($childName, $accepts, true)) {
                    $key = $parentName.'>'.$childName;

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $warnings[] = new LintWarning(LayoutMarkupLintCode::NestingViolation, self::line($child), self::column($child), [
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
     * @return void 반환값 없음
     */
    private function lintResponsiveMix(array $json, ?string $path, array &$warnings): void
    {
        $breakpoints = [];
        $this->collectResponsiveBreakpoints($json, $breakpoints);

        $hasPortable = isset($breakpoints['portable']);
        $hasSplit = isset($breakpoints['mobile']) || isset($breakpoints['tablet']);

        if ($hasPortable && $hasSplit) {
            $splitNode = $this->firstNodeWithBreakpoint($json, ['mobile', 'tablet']);
            $warnings[] = new LintWarning(LayoutMarkupLintCode::ResponsiveBreakpointMix, self::line($splitNode), self::column($splitNode), [], $path);
        }
    }

    /**
     * ⑤ 독립 레이아웃이 글로벌 호스트 없이 toast·모달을 발화하는지 확인합니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string  $rootKind  문서 봉투
     * @param  string|null  $path  소스 파일 경로
     * @param  array<int, LintWarning>  $warnings  경고 목록 (참조)
     * @return void 반환값 없음
     */
    private function lintStandaloneGlobalHost(array $json, string $rootKind, ?string $path, array &$warnings): void
    {
        // extends 로 베이스를 상속하면 호스트는 베이스가 마운트한다 — 검사 대상 아님.
        if ($rootKind !== 'layout' || isset($json['extends'])) {
            return;
        }

        $fired = array_values(array_intersect(array_keys(self::GLOBAL_HOST_STATE), $this->collectHandlers($json)));

        foreach ($fired as $handler) {
            // 모달은 `modals` 섹션이 있으면 엔진이 스택으로 연다.
            if ($handler === 'openModal' && isset($json['modals']) && $json['modals'] !== []) {
                continue;
            }

            if ($this->bindsGlobalState($json, self::GLOBAL_HOST_STATE[$handler])) {
                continue;
            }

            $firstAction = $this->firstActionOf($json, $handler);
            $warnings[] = new LintWarning(LayoutMarkupLintCode::StandaloneMissingGlobalHost, self::line($firstAction), self::column($firstAction), [
                'handler' => $handler,
                'state' => '_global.'.self::GLOBAL_HOST_STATE[$handler][0],
            ], $path);
        }
    }

    /**
     * 어떤 노드가 props·조건에서 주어진 `_global` 상태 키를 읽는지 판정합니다 (호스트 마운트 여부).
     *
     * 액션은 보지 않는다 — 상태를 **쓰는** 자리(토스트 비우기 등)는 화면에 그리는 호스트가 아니다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  array<int, string>  $keys  `_global` 아래 상태 키
     * @return bool 읽는 노드가 있으면 true
     */
    private function bindsGlobalState(array $json, array $keys): bool
    {
        foreach ($this->collectNodes($json) as $node) {
            $bound = (string) json_encode(
                [$node['props'] ?? null, $node['if'] ?? null],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );

            foreach ($keys as $key) {
                if (str_contains($bound, '_global.'.$key)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // 소스 위치 (7.1.0 P16)
    // ---------------------------------------------------------------------

    /**
     * 노드·액션 배열에 실린 소스 위치 표식의 줄을 읽습니다 (표식이 없으면 0 = 문서 전체).
     *
     * 표식은 `LayoutMarkupCompiler` 가 위치 없는 경고를 만났을 때만 켜는 재컴파일에서 붙는다
     * ({@see SOURCE_POSITION_KEY}). 저장·반환되는 JSON 에는 없다.
     *
     * @param  array<string, mixed>|null  $node  노드 또는 액션
     * @return int 줄 (1-base, 없으면 0)
     */
    private static function line(?array $node): int
    {
        $position = $node[self::SOURCE_POSITION_KEY] ?? null;

        return is_array($position) && isset($position[0]) ? (int) $position[0] : 0;
    }

    /**
     * 노드·액션 배열에 실린 소스 위치 표식의 칸을 읽습니다 (표식이 없으면 0).
     *
     * @param  array<string, mixed>|null  $node  노드 또는 액션
     * @return int 칸 (1-base, 없으면 0)
     */
    private static function column(?array $node): int
    {
        $position = $node[self::SOURCE_POSITION_KEY] ?? null;

        return is_array($position) && isset($position[1]) ? (int) $position[1] : 0;
    }

    /**
     * 주어진 핸들러를 부르는 첫 액션을 찾습니다 (문서 순서).
     *
     * @param  array<string, mixed>  $json  JSON
     * @param  string  $handler  핸들러명
     * @return array<string, mixed>|null 액션, 없으면 null
     */
    private function firstActionOf(array $json, string $handler): ?array
    {
        foreach ($this->collectActions($json) as $action) {
            if ($action['handler'] === $handler) {
                return $action;
            }
        }

        return null;
    }

    /**
     * 주어진 반응형 구간 키를 가진 첫 노드를 찾습니다 (문서 순서).
     *
     * @param  array<string, mixed>  $json  JSON
     * @param  array<int, string>  $breakpoints  구간 키
     * @return array<string, mixed>|null 노드, 없으면 null
     */
    private function firstNodeWithBreakpoint(array $json, array $breakpoints): ?array
    {
        foreach ($this->collectNodes($json) as $node) {
            if (isset($node['responsive']) && is_array($node['responsive']) && array_intersect($breakpoints, array_map('strval', array_keys($node['responsive']))) !== []) {
                return $node;
            }
        }

        return null;
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
