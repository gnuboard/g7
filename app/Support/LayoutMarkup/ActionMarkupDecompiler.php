<?php

namespace App\Support\LayoutMarkup;

/**
 * 액션 JSON 을 `<on>`/핸들러/제어 태그로 되돌리는 역변환 층 (S9 — ActionCompiler 의 역).
 *
 * 역변환이 단순해지는 핵심 관찰 둘:
 *
 * **① 마크업 속성은 배치 중립이다.** 컴파일러는 `target`·params 키가 top-level 로 갈지 params 로 갈지
 * 핸들러별 매니페스트로 정하지만, **마크업 표면에서는 둘 다 그냥 태그 속성**이다 — `<api-call target=..>`
 * 이든 `<set-state target=..>` 이든 `target="..."` 한 표기다. 그래서 역변환은 매니페스트를 볼 필요가
 * 없다: 소스 액션의 top-level 스칼라와 params 스칼라를 **어디에 있든** 속성으로 찍으면 컴파일러가
 * 같은 자리로 되돌린다((handler,키)→위치가 결정적이라 소스가 넣은 자리와 일치).
 *
 * **② `body`/`query` 도 타입 리터럴 속성으로 접힌다.** `<body name=..>` 대신 `:body='{...}'` 로 찍어도
 * 같은 params.body 객체로 컴파일된다(decodeTypeLiteral == compileFieldMap 산출). 그래서 자식 태그가
 * 꼭 필요한 것은 **액션 구조**를 담는 셋뿐이다 — `onSuccess`(`<success>`)·`onError`(`<fail>`)·
 * `errorHandling`(`<error-handling>`), 그리고 제어 핸들러의 자식(`cases`/`conditions`/`actions`).
 */
final class ActionMarkupDecompiler
{
    use EmitsMarkup;

    /** 제어 핸들러 — 전용 태그 + 자식 문법 */
    private const CONTROL_HANDLERS = ['switch', 'conditions', 'sequence', 'parallel'];

    /**
     * 코어 단순 핸들러 41종 → kebab 태그. 이 목록 밖(템플릿·확장·점 네임스페이스)은 `<action handler>`.
     *
     * @var array<string, string>
     */
    private const HANDLER_TAGS = [
        'navigate' => 'navigate', 'navigateBack' => 'navigate-back', 'navigateForward' => 'navigate-forward',
        'openWindow' => 'open-window', 'replaceUrl' => 'replace-url', 'redirect' => 'redirect',
        'apiCall' => 'api-call',
        'login' => 'login', 'logout' => 'logout', 'refetchDataSource' => 'refetch-data-source',
        'appendDataSource' => 'append-data-source', 'updateDataSource' => 'update-data-source',
        'ensureIdentityVerified' => 'ensure-identity-verified', 'resolveIdentityChallenge' => 'resolve-identity-challenge',
        'setState' => 'set-state', 'setError' => 'set-error', 'updateProductField' => 'update-product-field',
        'updateOptionField' => 'update-option-field', 'openModal' => 'open-modal', 'closeModal' => 'close-modal',
        'showAlert' => 'show-alert', 'toast' => 'toast', 'scrollIntoView' => 'scroll-into-view',
        'suppress' => 'suppress', 'startInterval' => 'start-interval', 'stopInterval' => 'stop-interval',
        'showErrorPage' => 'show-error-page', 'saveToLocalStorage' => 'save-to-local-storage',
        'loadFromLocalStorage' => 'load-from-local-storage', 'setLocale' => 'set-locale', 'emitEvent' => 'emit-event',
        'loadScript' => 'load-script', 'callExternal' => 'call-external', 'callExternalEmbed' => 'call-external-embed',
        'reloadExtensions' => 'reload-extensions', 'reloadRoutes' => 'reload-routes', 'refresh' => 'refresh',
        'remount' => 'remount', 'reloadTranslations' => 'reload-translations',
        'reloadModuleHandlers' => 'reload-module-handlers', 'reloadPluginHandlers' => 'reload-plugin-handlers',
    ];

    /** 조건식 정규화가 걸리는 top-level 키 — 문자열은 무접두, 불리언은 타입 */
    private const CONDITION_KEYS = ['if'];

    /** 액션 객체에서 속성이 아닌 구조 키 (자식 태그·트리거·컨테이너) */
    private const STRUCTURAL_KEYS = [
        'handler', 'comment', 'type', 'event', 'params',
        'cases', 'conditions', 'actions', 'onSuccess', 'onError', 'errorHandling',
    ];

    /**
     * 노드의 액션 3계통(actions/lifecycle/onComponentEvent)을 `<on>` 요소 목록으로 찍습니다.
     *
     * @param  array<string, mixed>  $node  노드 JSON
     * @return array<int, MarkupElement> `<on>` 요소 목록
     */
    public function nodeActions(array $node): array
    {
        $ons = [];

        foreach ($node['actions'] ?? [] as $entry) {
            $ons[] = $this->selfEventOn($entry);
        }

        foreach (['onMount' => 'mount', 'onUnmount' => 'unmount'] as $key => $event) {
            if (! empty($node['lifecycle'][$key])) {
                $ons[] = $this->el(
                    'on',
                    [$this->attr('event', $event)],
                    array_map(fn (array $action): MarkupElement => $this->action($action), $node['lifecycle'][$key]),
                );
            }
        }

        foreach ($node['onComponentEvent'] ?? [] as $entry) {
            $listen = $entry['event'] ?? '';
            unset($entry['event']);
            $ons[] = $this->el('on', [$this->attr('listen', (string) $listen)], $this->blockBody($entry));
        }

        return $ons;
    }

    /**
     * self-event `<on>` 하나를 찍습니다 (트리거는 태그로, 본문은 block-body 규칙).
     *
     * @param  array<string, mixed>  $entry  actions[] 항목
     * @return MarkupElement `<on>` 요소
     */
    private function selfEventOn(array $entry): MarkupElement
    {
        $attributes = [];

        if (isset($entry['type'])) {
            $attributes[] = $this->attr('type', (string) $entry['type']);
        }

        if (isset($entry['event'])) {
            $attributes[] = $this->attr('event', (string) $entry['event']);
        }

        // 키 한정자는 트리거의 일부다 — 핸들러 속성이 아니라 `<on>` 에 얹는다(ActionCompiler::applyOn).
        if (isset($entry['key'])) {
            $attributes[] = $this->attr('key', (string) $entry['key']);
        }

        unset($entry['type'], $entry['event'], $entry['key']);

        return $this->el('on', $attributes, $this->blockBody($entry));
    }

    /**
     * `<action name="x">` 본문 요소(들)를 찍습니다 (named_actions 값).
     *
     * @param  array<string, mixed>  $action  named action JSON
     * @return array<int, MarkupElement> 본문 요소
     */
    public function namedActionBody(array $action): array
    {
        return $this->blockBody($action);
    }

    /**
     * `<init>` 자식 액션 하나를 찍습니다 (축소 화이트리스트 — 단일 루트 액션).
     *
     * @param  array<string, mixed>  $action  init action JSON
     * @return MarkupElement 액션 요소
     */
    public function initAction(array $action): MarkupElement
    {
        return $this->action($action);
    }

    /**
     * `errorHandling` 맵을 `<on-code>`/`<default>` 자식 목록으로 찍습니다.
     *
     * @param  array<string, mixed>  $map  `{code|default: action}`
     * @return array<int, MarkupElement> 자식 요소
     */
    public function errorHandlingChildren(array $map): array
    {
        $children = [];

        foreach ($map as $code => $action) {
            // `comment` 는 상태 코드가 아니라 이 섹션의 소스 주석이다 — 호출부가 요소 앞
            // `<!-- -->` 로 찍으므로 여기서는 건너뛴다(코드로 오인하면 문자열이 액션으로 흘러간다).
            if ($code === 'comment') {
                continue;
            }

            $children[] = $code === 'default'
                ? $this->el('default', [], $this->blockBody($action))
                : $this->el('on-code', [$this->attr('code', (string) $code)], $this->blockBody($action));
        }

        return $children;
    }

    /**
     * 콜백(onSuccess/onError/onReceive) 값을 액션 요소 목록으로 찍습니다 (단일 객체=1개, 배열=N개).
     *
     * @param  mixed  $callback  콜백 값 (객체 또는 배열)
     * @return array<int, MarkupElement> 액션 요소
     */
    public function callbackChildren(mixed $callback): array
    {
        if ($this->isList($callback)) {
            return array_map(fn (array $action): MarkupElement => $this->action($action), $callback);
        }

        return [$this->action($callback)];
    }

    /**
     * 블록 본문 규칙 — 암묵 sequence(트리거만 있는 sequence)는 자식을 직접 펼치고, 그 밖은 단일 액션.
     *
     * **자식이 둘 이상일 때만 펼친다.** 컴파일러는 블록 자식이 하나면 그 액션에 트리거만 얹고
     * sequence 로 감싸지 않으므로([ActionCompiler::compileBlockBody](app/Support/LayoutMarkup/ActionCompiler.php#L280)),
     * 단일 자식 sequence 를 펼치면 되컴파일 때 래퍼가 복원되지 않아 왕복이 깨진다. 그 경우는
     * 펼치지 않고 `<sequence>` 를 명시해 래퍼를 보존한다(자식 0인 퇴화 형태도 같은 경로).
     *
     * @param  array<string, mixed>  $action  본문 액션 (트리거 제거 후)
     * @return array<int, MarkupElement> 자식 요소
     */
    private function blockBody(array $action): array
    {
        if (($action['handler'] ?? null) === 'sequence' && $this->onlyKeys($action, ['handler', 'actions'])) {
            $children = $action['actions'] ?? [];

            if (count($children) >= 2) {
                return array_map(fn (array $child): MarkupElement => $this->action($child), $children);
            }
        }

        return [$this->action($action)];
    }

    /**
     * 액션 객체 하나를 요소로 찍습니다 (핸들러 태그 / 제어 태그 / 제네릭 `<action>`).
     *
     * @param  array<string, mixed>  $action  액션 JSON
     * @return MarkupElement 액션 요소
     */
    private function action(array $action): MarkupElement
    {
        $handler = $action['handler'] ?? '';

        if (in_array($handler, self::CONTROL_HANDLERS, true)) {
            return $this->control($action);
        }

        // 핸들러 없이 actionRef 만 있는 액션 = named_actions 참조 — 전용 태그로 찍는다.
        // (`<action>` 은 handler 필수라 이 형태를 지을 수 없다 — ActionCompiler::compileActionRef)
        if ($handler === '' && isset($action['actionRef']) && $this->onlyKeys($action, ['actionRef', 'comment'])) {
            return $this->el(
                'action-ref',
                [$this->attr('name', (string) $action['actionRef'])],
                [],
                null,
                $action['comment'] ?? null,
            );
        }

        $tag = self::HANDLER_TAGS[$handler] ?? null;
        $attributes = [];

        if ($tag === null) {
            $tag = 'action';
            $attributes[] = $this->attr('handler', (string) $handler);
        }

        $attributes = array_merge($attributes, $this->attributes($action));
        $children = $this->subStructural($action);

        return $this->el($tag, $attributes, $children, null, $action['comment'] ?? null);
    }

    /**
     * 제어 액션(switch/conditions/sequence/parallel)을 전용 태그로 찍습니다.
     *
     * @param  array<string, mixed>  $action  제어 액션 JSON
     * @return MarkupElement 제어 요소
     */
    private function control(array $action): MarkupElement
    {
        $handler = $action['handler'];
        $attributes = $this->attributes($action);
        $comment = $action['comment'] ?? null;

        // 제어 자식(cases/conditions/actions)은 top-level 이 정본이나 일부 레거시 소스는 params 안에 둔다.
        // params 폴백으로 읽어 유효한 마크업을 내면(빈 블록 방지) 컴파일이 서고, params 형은 노드 자기검증이
        // actions 키만 `<json>` 으로 정밀 탈출한다(무너지면 노드 전 키가 통째로 탈출하는 캐스케이드 방지).
        $cases = $action['cases'] ?? $action['params']['cases'] ?? [];
        $conditions = $action['conditions'] ?? $action['params']['conditions'] ?? [];
        $actions = $action['actions'] ?? $action['params']['actions'] ?? [];

        return match ($handler) {
            'switch' => $this->el('switch', $attributes, $this->switchChildren($cases), null, $comment),
            'conditions' => $this->el('conditions', $attributes, $this->conditionsChildren($conditions), null, $comment),
            'sequence' => $this->el('sequence', $attributes, array_map(fn (array $a): MarkupElement => $this->action($a), $actions), null, $comment),
            'parallel' => $this->el('parallel', $attributes, array_map(fn (array $a): MarkupElement => $this->action($a), $actions), null, $comment),
        };
    }

    /**
     * `cases` 맵을 `<case>`/`<default>` 자식으로 찍습니다 (block-body 규칙, 순서 보존).
     *
     * @param  array<string, mixed>  $cases  `{when|default: action}`
     * @return array<int, MarkupElement> 자식 요소
     */
    private function switchChildren(array $cases): array
    {
        $children = [];

        foreach ($cases as $when => $action) {
            $children[] = $when === 'default'
                ? $this->el('default', [], $this->blockBody($action))
                : $this->el('case', [$this->attr('when', (string) $when)], $this->blockBody($action));
        }

        return $children;
    }

    /**
     * `conditions` 배열을 `<when>`/`<else>` 자식으로 찍습니다 (then 은 flat actions).
     *
     * @param  array<int, array<string, mixed>>  $branches  조건 분기 목록
     * @return array<int, MarkupElement> 자식 요소
     */
    private function conditionsChildren(array $branches): array
    {
        $children = [];

        foreach ($branches as $branch) {
            // `then` 은 액션 배열이거나 **단일 액션 객체**다 — 엔진이 둘 다 받고(handleConditions),
            // 전 코퍼스에서는 객체가 다수형이다. 배열로만 보고 순회하면 객체가 올 때 TypeError 로
            // 죽어 그 노드가 통째로 `<json>` 으로 탈출한다. 컴파일러는 자식 하나를 객체로 되돌린다.
            $then = array_map(
                fn (array $a): MarkupElement => $this->action($a),
                $this->thenActions($branch['then'] ?? []),
            );
            $comment = $branch['comment'] ?? null;

            $children[] = array_key_exists('if', $branch)
                ? $this->el('when', [$this->conditionAttr('if', $branch['if'])], $then, null, $comment)
                : $this->el('else', [], $then, null, $comment);
        }

        return $children;
    }

    /**
     * 분기의 `then` 값을 액션 목록으로 정규화합니다 (단일 객체 → 1원소 목록).
     *
     * @param  mixed  $then  `then` 값 (액션 객체 또는 액션 배열)
     * @return array<int, array<string, mixed>> 액션 목록
     */
    private function thenActions(mixed $then): array
    {
        if (! is_array($then) || $then === []) {
            return [];
        }

        // 리스트(0부터 연속 정수 키)면 액션 배열, 그 밖은 액션 객체 하나.
        return array_is_list($then) ? $then : [$then];
    }

    /**
     * 액션 구조 자식(onSuccess/onError/errorHandling)을 찍습니다.
     *
     * @param  array<string, mixed>  $action  액션 JSON
     * @return array<int, MarkupElement> 자식 요소
     */
    private function subStructural(array $action): array
    {
        $children = [];

        if (isset($action['onSuccess'])) {
            $children[] = $this->el('success', [], $this->callbackChildren($action['onSuccess']));
        }

        if (isset($action['onError'])) {
            $children[] = $this->el('fail', [], $this->callbackChildren($action['onError']));
        }

        if (isset($action['errorHandling'])) {
            $children[] = $this->el('error-handling', [], $this->errorHandlingChildren($action['errorHandling']));
        }

        // `params.body` / `params.query` 는 1급 문법이 있다 — `<body>`/`<query>` 로 되돌린다(D41).
        // 속성에 JSON 을 박으면 D2("속성 JSON 추방")를 어기고, 계량은 `<json>` 태그만 세므로
        // escape-0 판정을 받고도 원시 JSON 이 그대로 남는다.
        foreach (['body', 'query'] as $key) {
            $map = $action['params'][$key] ?? null;

            if ($this->isFieldMap($map)) {
                $children[] = $this->el($key, $this->fieldMapAttributes($map));
            }
        }

        // 비-제어 핸들러의 `params.actions` 는 실행 목록이다 (startInterval — D42 G10).
        // `actions` 라는 이름은 제어 핸들러의 레거시 params 배치와 겹치는데, 그쪽은 control() 이
        // top-level 로 찍는다. 여기서 걸러내지 않으면 비-제어 핸들러의 목록을 **아무도 찍지 않아**
        // 키가 통째로 소실되고 그 섹션이 `<json>` 으로 탈출한다.
        $tickActions = $action['params']['actions'] ?? null;

        if (! in_array($action['handler'] ?? '', self::CONTROL_HANDLERS, true)
            && is_array($tickActions) && $tickActions !== [] && array_is_list($tickActions)) {
            $children[] = $this->el('actions', [], array_map(
                fn (array $a): MarkupElement => $this->action($a),
                $tickActions,
            ));
        }

        // 임의 이름의 **구조값 파라미터**는 `<field name>` 자식으로 되돌린다 (D42 G6).
        // 제어 자식(actions/cases/conditions)과 위에서 `<body>`/`<query>` 로 나간 평면 맵만 제외한다 —
        // 중첩 body/query 는 `<field name="body">` 로 나가고 컴파일러가 같은 `params.body` 를 만든다.
        foreach ($action['params'] ?? [] as $key => $value) {
            if (in_array($key, ['actions', 'cases', 'conditions'], true)) {
                continue;
            }

            if (in_array($key, ['body', 'query'], true) && $this->isFieldMap($value)) {
                continue;
            }

            $structured = $this->structuredChildren($value);

            if ($structured !== null) {
                $children[] = $this->el('field', [$this->attr('name', (string) $key)], $structured);
            }
        }

        // top-level 키의 구조값도 같은 `<field name>` 으로 되돌린다 (D51 G15).
        // `resultTo`·`identity_target` 은 값이 객체인데 top-level 배치라 params 루프가 못 본다 —
        // 그대로 두면 `:resultTo='{"target":…}'` 속성 JSON 으로 나가 D2("속성 JSON 추방")를 어긴다.
        // 컴파일러가 같은 매니페스트 배치 규칙으로 top-level 에 되돌려 놓으므로 왕복이 성립한다.
        foreach ($action as $key => $value) {
            if (! $this->topLevelEmittedAsChild((string) $key, $value)) {
                continue;
            }

            $children[] = $this->el('field', [$this->attr('name', (string) $key)], $this->structuredChildElements($value));
        }

        return $children;
    }

    /**
     * top-level 액션 키가 **자식 태그로** 나가는지 판정합니다 (속성 중복·누락 방지 — `paramEmittedAsChild` 의 짝).
     *
     * @param  string  $key  top-level 키
     * @param  mixed  $value  값
     * @return bool `<field name>` 자식으로 나가면 true
     */
    private function topLevelEmittedAsChild(string $key, mixed $value): bool
    {
        if (in_array($key, self::STRUCTURAL_KEYS, true) || in_array($key, self::CONDITION_KEYS, true)) {
            return false;
        }

        return $this->isStructuredValue($value);
    }

    /**
     * params 항목이 **자식 태그로** 나가는지 판정합니다 (속성 중복·누락 방지).
     *
     * `subStructural`(자식 방출)과 `attributes`(속성 방출)가 서로 다른 판정을 쓰면 같은 값이 두 번
     * 나가거나(중복) 아예 안 나간다(소실). 두 곳이 이 하나를 공유한다.
     *
     * @param  string  $key  params 키
     * @param  mixed  $value  값
     * @return bool 자식으로 나가면 true
     */
    private function paramEmittedAsChild(string $key, mixed $value): bool
    {
        // 제어 자식(cases/conditions/actions)이 params 안에 온 레거시 shape 는 자식으로 방출된다.
        if (in_array($key, ['actions', 'cases', 'conditions'], true)) {
            return true;
        }

        if (in_array($key, ['body', 'query'], true) && $this->isFieldMap($value)) {
            return true;
        }

        return $this->isStructuredValue($value);
    }

    /**
     * `<body>`/`<query>` 로 되돌릴 수 있는 평면 키-값 객체인지 판정합니다.
     *
     * `ActionCompiler::compileFieldMap` 은 **속성만** 받고 자식을 거부한다 — 값이 다시 객체·배열이면
     * 속성으로 되돌릴 때 또 JSON 이 되므로, 그 경우는 문법으로 표현 불가라 판정에서 뺀다.
     *
     * @param  mixed  $map  후보 값
     * @return bool 평면 키-값 객체면 true
     */
    private function isFieldMap(mixed $map): bool
    {
        if (! is_array($map)) {
            return false;
        }

        // 빈 맵도 표현 가능하다 — 속성 없는 `<query/>` 가 `compileFieldMap` 에서 `[]` 로 되돌아온다.
        if ($map === []) {
            return true;
        }

        if (array_is_list($map)) {
            return false;
        }

        foreach ($map as $value) {
            if (is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 평면 키-값 객체를 `<body>`/`<query>` 속성 목록으로 찍습니다.
     *
     * @param  array<string, mixed>  $map  키-값 객체
     * @return array<int, MarkupAttribute> 속성 목록
     */
    private function fieldMapAttributes(array $map): array
    {
        $attributes = [];

        foreach ($map as $key => $value) {
            $attributes[] = $this->valueAttr((string) $key, $value);
        }

        return $attributes;
    }

    /**
     * 액션의 top-level 스칼라 + params 스칼라를 속성으로 모읍니다 (배치 중립 — §① 참조).
     *
     * @param  array<string, mixed>  $action  액션 JSON
     * @return array<int, MarkupAttribute> 속성 목록
     */
    private function attributes(array $action): array
    {
        $attributes = [];

        foreach ($action as $key => $value) {
            if (in_array($key, self::STRUCTURAL_KEYS, true)) {
                continue;
            }

            // 구조값은 `<field name>` 자식으로 나간다 — 속성으로 중복하면 컴파일이 중복 키로 거부한다.
            if ($this->topLevelEmittedAsChild((string) $key, $value)) {
                continue;
            }

            $attributes[] = in_array($key, self::CONDITION_KEYS, true)
                ? $this->conditionAttr($key, $value)
                : $this->valueAttr($key, $value);
        }

        $handler = (string) ($action['handler'] ?? '');

        foreach ($action['params'] ?? [] as $key => $value) {
            // 자식 태그로 나가는 항목은 속성으로 중복하지 않는다 (제어 자식·`<body>`/`<query>`·구조값 파라미터).
            if ($this->paramEmittedAsChild((string) $key, $value)) {
                continue;
            }

            // params 별칭 — `switch` 의 `params.value` 는 `on` 으로 찍어 top-level `value` 와 가른다 (D42 G8).
            $attributes[] = $this->valueAttr($this->paramAttributeName($handler, (string) $key), $value);
        }

        return $attributes;
    }

    /**
     * params 키를 속성명으로 바꿉니다 — 같은 이름의 top-level 키와 배치가 갈리는 자리만 별칭을 쓴다.
     *
     * @param  string  $handler  camel 핸들러명
     * @param  string  $key  params 키
     * @return string 속성명
     */
    private function paramAttributeName(string $handler, string $key): string
    {
        return $handler === 'switch' && $key === 'value'
            ? ActionHandlerManifest::SWITCH_SUBJECT_ATTRIBUTE
            : $key;
    }

    /**
     * 배열이 리스트(순차 배열)인지 판정합니다.
     *
     * @param  mixed  $value  값
     * @return bool 리스트면 true
     */
    private function isList(mixed $value): bool
    {
        return is_array($value) && ($value === [] || array_is_list($value));
    }

    /**
     * 액션이 주어진 키들만 가졌는지 판정합니다.
     *
     * @param  array<string, mixed>  $action  액션
     * @param  array<int, string>  $keys  허용 키
     * @return bool 허용 키만 있으면 true
     */
    private function onlyKeys(array $action, array $keys): bool
    {
        return array_diff(array_keys($action), $keys) === [];
    }
}
