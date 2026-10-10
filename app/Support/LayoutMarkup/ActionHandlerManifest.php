<?php

namespace App\Support\LayoutMarkup;

/**
 * 액션 컴파일(S3)의 SSoT — 내장 핸들러 49종과 키 배치 규칙.
 *
 * 액션 마크업의 두 가지 사실을 이 한 곳에 모은다.
 *
 * **① 핸들러 태그명 ↔ 핸들러명.** 마크업 태그는 소문자·kebab 이고 엔진 핸들러명은 camelCase 다
 * (`<set-state>` ↔ `setState`). 이 변환은 규칙적(대문자 앞 하이픈)이라 자동 도출할 수 있으나,
 * 49종을 명시 목록으로 못박아 "레지스트리에 없는 kebab 은 핸들러가 아니다" 를 결정적으로 판정한다.
 * 확장·템플릿 핸들러(`sirsoft-ecommerce.toggleArrayValue`)는 점을 품어 태그명이 될 수 없으므로
 * 범용 `<action handler="...">` 로 작성하고 이름을 원문 그대로 싣는다.
 *
 * **② 키가 top-level 이냐 params 냐는 핸들러가 정한다.** 엔진은 대부분의 인자를 `params` 에서
 * 읽지만, 제어·메타 키(if·confirm·onSuccess 등)와 일부 핸들러의 `target` 은 액션 객체 최상위에서
 * 읽는다. 넣는 위치가 틀리면 조용히 무시되므로(계획 §198) 위치를 여기서 결정한다.
 *
 * `target` 의 위치가 핸들러마다 다른 것이 가장 미묘한 지점이다(전 번들 실측):
 *   - apiCall·openModal·closeModal·setLocale·login(·2단계 인증·비밀번호 변경 단계)·logout → **top-level**(엔드포인트·모달 id·로케일)
 *   - setState·showErrorPage 등 그 밖 전부 → **params**(setState 는 params.target 이 상태 스코프)
 * 그래서 `target` 은 핸들러별 집합으로 가른다. 나머지 top-level 키는 핸들러 무관 공통이다.
 */
final class ActionHandlerManifest
{
    /**
     * 엔진 내장 핸들러 49종 (camelCase) — ActionDispatcher switch 32 + registerDefaultHandlers 17.
     *
     * 제어 핸들러(switch·conditions·sequence·parallel)는 전용 태그·자식 문법을 가지므로 단순
     * 핸들러 태그 목록에서 제외된다(아래 CONTROL_HANDLERS).
     *
     * 이 목록은 엔진과 **독립 선언**이라 손으로 갈릴 수 있다. 한쪽에만 핸들러가 생기면
     * 엔진 전용은 컴파일이 마크업을 거부하고, 이쪽 전용은 런타임이 조용히 무시한다(후자가 더
     * 나쁘다 — 화면이 멀쩡해 보이고 아무 게이트도 red 가 아니다). 그래서 drift 를 기계가
     * 본다: 하네스 가드가 `ActionDispatcher.ts` 를 토크나이즈해
     * `switch (action.handler)` 라벨 + `registerHandler('...')` 를 뽑아 이 배열과 대조한다.
     * 핸들러를 추가·삭제할 때는 **양쪽을 함께** 고치면 되고, 한쪽만 고치면 그 가드가 red 다.
     * (S19 ① · 가드는 audit 하네스의 `handler-manifest-drift` 회귀 테스트)
     *
     * @var array<int, string>
     */
    private const CORE_HANDLERS = [
        // nav
        'navigate', 'navigateBack', 'navigateForward', 'openWindow', 'replaceUrl', 'redirect',
        // api / auth / data source
        'apiCall', 'login', 'loginTwoFactor', 'loginTwoFactorResend', 'loginPasswordChange', 'loginPasswordChangePostpone', 'logout', 'refetchDataSource', 'appendDataSource', 'updateDataSource',
        'ensureIdentityVerified', 'resolveIdentityChallenge',
        // state
        'setState', 'setError', 'updateProductField', 'updateOptionField',
        // ui
        'openModal', 'closeModal', 'showAlert', 'toast', 'scrollIntoView',
        // flow (제어 — 전용 태그)
        'switch', 'conditions', 'sequence', 'parallel', 'suppress', 'startInterval', 'stopInterval',
        'showErrorPage',
        // storage
        'saveToLocalStorage', 'loadFromLocalStorage',
        // i18n / event / script / reload
        'setLocale', 'emitEvent', 'refreshConsent', 'loadScript', 'callExternal', 'callExternalEmbed',
        'reloadExtensions', 'reloadRoutes', 'refresh', 'remount', 'reloadTranslations',
        'reloadModuleHandlers', 'reloadPluginHandlers',
    ];

    /**
     * 전용 태그·자식 문법을 갖는 제어 핸들러 — 단순 핸들러 태그로 등록하지 않는다.
     *
     * `<switch>`+`<case>` / `<conditions>`+`<when>`/`<else>` / `<parallel>` / `<sequence>` 는
     * ActionCompiler 가 직접 해석한다(자식이 액션이거나 케이스 맵이라 attr→params 규칙이 안 맞는다).
     *
     * @var array<int, string>
     */
    private const CONTROL_HANDLERS = ['switch', 'conditions', 'sequence', 'parallel'];

    /**
     * `target` 을 액션 top-level 로 읽는 핸들러 (전 번들 실측 — 그 밖은 params.target).
     *
     * @var array<int, string>
     */
    private const TARGET_TOP_LEVEL_HANDLERS = [
        'apiCall', 'openModal', 'closeModal', 'setLocale', 'login', 'loginTwoFactor', 'loginTwoFactorResend',
        'loginPasswordChange', 'loginPasswordChangePostpone', 'logout',
    ];

    /**
     * 커스텀(비-코어) 핸들러 중 `target` 을 액션 top-level 로 읽는 것 — 전 번들 실측.
     *
     * 커스텀 핸들러는 dispatch default 갈래에서 top-level `target` 을 해석해 받고
     * (`handleCustomAction({...action, target: resolvedTarget})` — ActionDispatcher.ts:2472-2480),
     * 동시에 구현이 `params.target` 을 읽어도 된다. 이름만으로는 못 가르므로 실측이 유일한 근거다:
     * 전 코퍼스에서 top-level 은 `initTheme` 3건뿐이고 나머지(`sirsoft-ecommerce.toggleArrayValue`
     * 15·`scrollTo` 1)는 전부 `params.target` → 기본을 params 로 두고 여기만 지목한다.
     * setTheme 도 여기 든다 — 템플릿 핸들러(setThemeHandler)가 action.target 만 읽고 params.theme 은 무시하므로
     * (dev-g7#640, 감사 handler-registry 와 동일) params 형태로 구우면 조용한 no-op 이 된다.
     *
     * @var array<int, string>
     */
    private const CUSTOM_TARGET_TOP_LEVEL_HANDLERS = ['initTheme', 'setTheme'];

    /**
     * 핸들러 무관 공통 top-level 키 — 나머지 속성은 전부 `params` 로 간다.
     *
     * 트리거(`type`/`event`)·구조(`cases`/`conditions`/`actions`/`onSuccess`/`onError`/`errorHandling`)·
     * 주석(`comment`)은 속성이 아니라 태그·자식으로 실리므로 이 목록에 없다. 여기 있는 것은 핸들러
     * 태그에 **속성으로** 얹혔을 때 top-level 로 보내야 하는 제어·인증·메타 키다.
     *
     * `condition`(=if 오기)·`trigger` 는 결함 키라 넣지 않는다(속성으로 쓰면 NodeCompiler 와 같은
     * "노드/액션 키를 속성으로" 거부에 걸리게 둔다 — 결함 인벤토리 S16).
     *
     * `stopPropagation` 은 **결함 키에서 정식 표면으로 승격됐다** — engine-v1.68.0 이 진입점 액션에서
     * 이 키를 실제로 소비하므로 아래 목록에 있다. 승격 이전 기준으로 이 문장을 되돌리지 말 것.
     *
     * `key`(keydown/keypress 필터)도 여기 없다 — 그것은 핸들러 인자가 아니라 **트리거 한정자**라
     * `<on type="keypress" key="Enter">` 로 실린다(ActionCompiler::applyOn). 여기 두면 `params.key`
     * 를 쓰는 핸들러(saveToLocalStorage·loadFromLocalStorage)의 인자가 트리거 키로 오해석돼
     * 조용히 소실된다 — U-ESCAPE 실측에서 32건이 이 충돌로 `<json>` 탈출했다.
     *
     * @var array<int, string>
     */
    private const UNIVERSAL_TOP_LEVEL_KEYS = [
        'if', 'confirm', 'auth_mode', 'auth_required', 'identity_target',
        'resultTo', 'debounce', 'actionRef', 'render', 'onLoad',
        // engine-v1.68.0 — 진입점 액션에서 `event.stopPropagation()` 을 부른다.
        // 종전에는 엔진 소비처가 없어 죽은 키였고(S16 F4), 그래서 매니페스트에도 없었다.
        'stopPropagation',
    ];

    /**
     * 핸들러별 top-level 키 — 같은 이름이 핸들러에 따라 배치가 갈리는 자리 (D42 G8).
     *
     * `switch` 의 `value` 는 **두 배치가 실물로 공존**한다: 엔진이 읽는 정본은 `params.value`
     * (ActionDispatcher::handleSwitch:4970)인데, 코퍼스 26건은 top-level 에 둔다(엔진 미소비 —
     * S16 결함 F2, 값이 `{{$args[0]}}` 라 폴백과 우연 일치해 무증상). 기계 이관은 배치를 바꿀 수
     * 없으므로(D38 바이트 동등) **둘 다 표기 수단이 있어야** 한다:
     *
     *  - `<switch on="…">`  → `params.value` — 엔진이 읽는 정본. 신규 마크업은 이쪽.
     *  - `<switch value="…">` → top-level `value` — 결함 보존형. lint 이 경고한다.
     *
     * 표기를 안 가르면 26건이 통째로 `<json>` 탈출해 소스 파일에 액션 트리가 JSON 으로 남는다
     * (D42 가 없애려는 바로 그 형태). `on` 은 `<switch-value prop on>` 에서 이미 "무엇을 기준으로
     * 분기하는가" 를 뜻하는 개념어라 새 어휘가 아니다(명명 3순위 규칙 ①).
     *
     * @var array<string, array<int, string>>
     */
    private const HANDLER_TOP_LEVEL_KEYS = ['switch' => ['value']];

    /**
     * `<switch>` 에서 `params.value` 를 지목하는 속성명 (top-level `value` 와 가르는 별칭).
     */
    public const SWITCH_SUBJECT_ATTRIBUTE = 'on';

    /**
     * init_actions 루트 액션이 지니는 키 화이트리스트 (TemplateApp::executeInitActions 실측).
     *
     * executeInitActions 는 자식 actionDef 에 이 키만 전달하고 그 밖(cases·actions·confirm·debounce·
     * event·key·identity_target·render·onLoad·actionRef·errorHandling)은 **조용히 탈락**시킨다.
     * 그러므로 init 루트에 화이트리스트 밖 키가 있으면 컴파일 에러로 잡아(중첩 onSuccess 이동 또는
     * named_actions+actionRef 안내) 무동작 함정을 작성 시점에 차단한다.
     *
     * @var array<int, string>
     */
    private const INIT_ROOT_KEYS = [
        'handler', 'type', 'target', 'params', 'resultTo', 'onSuccess', 'onError',
        'if', 'conditions', 'auth_mode', 'auth_required', 'comment',
    ];

    /** @var array<string, string> kebab 태그 → camel 핸들러명 (단순 핸들러만) */
    private array $tagToHandler;

    /** @var array<string, string> camel 핸들러명 → kebab 태그 (역변환·제안용) */
    private array $handlerToTag;

    /**
     * 태그 ↔ 핸들러 두 방향 표를 `CORE_HANDLERS` 에서 **도출**합니다.
     *
     * 표를 손으로 적지 않는 이유는 그 목록이 낡기 때문이다 — 핸들러를 하나 더하면 두 표가 함께
     * 따라와야 하는데, 손유지 표는 한쪽만 갱신돼 "태그는 있는데 해석이 안 되는" 상태를 만든다.
     * 제어 핸들러는 전용 자식 문법을 가져 단순 태그 해석 대상이 아니므로 역방향 표에서만 뺀다.
     */
    public function __construct()
    {
        $this->tagToHandler = [];
        $this->handlerToTag = [];

        foreach (self::CORE_HANDLERS as $handler) {
            $tag = self::camelToKebab($handler);
            $this->handlerToTag[$handler] = $tag;

            if (! in_array($handler, self::CONTROL_HANDLERS, true)) {
                $this->tagToHandler[$tag] = $handler;
            }
        }
    }

    /**
     * 단순 핸들러 kebab 태그를 camel 핸들러명으로 해석합니다.
     *
     * @param  string  $tag  kebab 태그명
     * @return string|null camel 핸들러명, 등록되지 않은 태그면 null
     */
    public function resolveTag(string $tag): ?string
    {
        return $this->tagToHandler[$tag] ?? null;
    }

    /**
     * 이름이 내장 49종 핸들러인지 판정합니다 (범용 `<action handler>` 검증용).
     *
     * @param  string  $handler  camel 핸들러명
     * @return bool 내장 핸들러이면 true
     */
    public function isCoreHandler(string $handler): bool
    {
        return isset($this->handlerToTag[$handler]);
    }

    /**
     * 해당 핸들러에서 `target` 이 액션 top-level 키인지 판정합니다.
     *
     * @param  string  $handler  camel 핸들러명
     * @return bool top-level 이면 true (아니면 params.target)
     */
    public function targetIsTopLevel(string $handler): bool
    {
        if (in_array($handler, self::TARGET_TOP_LEVEL_HANDLERS, true)) {
            return true;
        }

        // 커스텀(비-코어) 핸들러는 dispatch default 갈래에서 `target` 을 top-level 로도 받고
        // (`handleCustomAction({...action, target: resolvedTarget})` — ActionDispatcher.ts:2472-2480)
        // `params.target` 으로도 받는다(핸들러 구현이 params 를 읽으면 그만). **양쪽 다 유효**하므로
        // 핸들러 이름만으로는 가를 수 없다 — 전 코퍼스 실측도 두 배치가 공존한다(top-level 3 : params 16).
        // 그래서 기본은 params(다수형)로 두고, top-level 을 쓰는 핸들러만 아래 목록으로 지목한다.
        return in_array($handler, self::CUSTOM_TARGET_TOP_LEVEL_HANDLERS, true);
    }

    /**
     * 속성 키가 해당 핸들러에서 액션 top-level 키인지 판정합니다.
     *
     * @param  string  $handler  camel 핸들러명
     * @param  string  $key  속성명
     * @return bool top-level 이면 true (아니면 params)
     */
    public function keyIsTopLevel(string $handler, string $key): bool
    {
        if ($key === 'target') {
            return $this->targetIsTopLevel($handler);
        }

        if (in_array($key, self::HANDLER_TOP_LEVEL_KEYS[$handler] ?? [], true)) {
            return true;
        }

        return in_array($key, self::UNIVERSAL_TOP_LEVEL_KEYS, true);
    }

    /**
     * 해당 핸들러에서 이 속성이 **params 별칭**인지 판정하고 실제 params 키를 돌려줍니다 (D42 G8).
     *
     * @param  string  $handler  camel 핸들러명
     * @param  string  $key  속성명
     * @return string|null params 키, 별칭이 아니면 null
     */
    public function paramAliasFor(string $handler, string $key): ?string
    {
        return $handler === 'switch' && $key === self::SWITCH_SUBJECT_ATTRIBUTE ? 'value' : null;
    }

    /**
     * init 루트 액션에서 허용되는 키인지 판정합니다.
     *
     * @param  string  $key  액션 객체 키
     * @return bool 허용되면 true
     */
    public function isInitRootKey(string $key): bool
    {
        return in_array($key, self::INIT_ROOT_KEYS, true);
    }

    /**
     * 등록된 kebab 태그와 가장 가까운 후보를 찾습니다 (편집 거리 ≤ 2).
     *
     * @param  string  $tag  미해석 태그명
     * @return string|null 제안 태그명, 없으면 null
     */
    public function suggestTag(string $tag): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (array_keys($this->tagToHandler) as $candidate) {
            $distance = levenshtein($tag, $candidate);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $bestDistance <= 2 ? $best : null;
    }

    /**
     * camelCase 핸들러명을 kebab 태그명으로 변환합니다.
     *
     * @param  string  $handler  camel 핸들러명
     * @return string kebab 태그명
     */
    private static function camelToKebab(string $handler): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $handler));
    }
}
