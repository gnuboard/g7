<?php

namespace App\Enums;

/**
 * `.g7.html` 마크업 형식 정적 lint 경고 코드 Enum (S6).
 *
 * lint 은 컴파일을 **막지 않는** 경고다 — 산출 JSON 은 정상 생성되지만, 작성자·리뷰어에게
 * "이대로 두면 런타임에 문제가 될 수 있다" 를 알린다. 컴파일을 중단시키는 사유는
 * `LayoutMarkupErrorCode`(CompileError) 가 담당하고, 이 Enum 은 `LintWarning` 이 담는다.
 *
 * 코드 값이 그대로 `lang/{locale}/exceptions.php` 의 `layout_markup_lint.{value}` 메시지
 * 키가 된다. 메시지가 번역되어도 코드는 불변이므로, 테스트·규정 문서는 번역문이 아닌 이
 * 코드로 경고를 지목한다. `LayoutMarkupLintCodeTest` 가 전 로케일 키 누락을 차단한다.
 */
enum LayoutMarkupLintCode: string
{
    /**
     * 액션의 handler 가 코어 44종·템플릿 등록·확장(`.`) 네임스페이스 어디에도 없음 (D30⑦).
     */
    case UnregisteredHandler = 'unregistered_handler';

    /**
     * 부모 컨테이너의 nesting.json `accepts` 가 허용하지 않는 자식 컴포넌트를 배치함.
     * `accepts` 가 빈 목록인 부모(leaf·composite)는 검사하지 않는다 (편집기 정책이라 오검출 회피).
     */
    case NestingViolation = 'nesting_violation';

    /**
     * 한 문서가 `portable` 프리셋과 `mobile`/`tablet` 프리셋을 함께 씀 (범위가 겹쳐 예측 불가).
     */
    case ResponsiveBreakpointMix = 'responsive_breakpoint_mix';

    /**
     * 독립 레이아웃(extends 없음)이 toast·openModal 을 발화하면서 그 전역 상태를 받는 자리를 두지 않음 —
     * 핸들러는 success 로 기록되나 화면에 아무것도 나오지 않는 silent failure.
     *
     * 호스트는 컴포넌트 이름이 아니라 엔진 계약으로 판정한다(2026-09-28 결정: 템플릿 무관) —
     * toast 는 `_global.toasts` 를 읽는 노드, openModal 은 `modals` 섹션 또는 `_global.modalStack` 을 읽는 노드.
     */
    case StandaloneMissingGlobalHost = 'standalone_missing_global_host';

    /**
     * `<switch value>` 로 분기 대상을 적음 — 엔진은 `params.value` 만 읽으므로(ActionDispatcher::handleSwitch)
     * top-level `value` 는 소비되지 않고 `$args[0]` 폴백으로 넘어간다. 정본 표기는 `<switch on>` 이다.
     *
     * 이 표기가 문법에 남아 있는 이유는 이관 때문이다 — 기존 코퍼스 26건이 top-level 배치이고,
     * 기계 이관은 배치를 바꿀 수 없다(D38). 신규 마크업이 그 죽은 배치를 따라 쓰지 않게 여기서 경고한다.
     */
    case SwitchValueNotConsumed = 'switch_value_not_consumed';

    /**
     * 액션 `params` 에 **정본이 다른 자리인 키**가 실림 (S23 축 A — 78차 프로브 f3·f4a·f4d).
     *
     * 마크업 문법에는 `params` 화이트리스트가 없어 무접두 속성이 검사 없이 `params.*` 로 통과한다.
     * 그래서 이관 대장이 「액션 키 결함」으로 분류한 것들이 **한 칸 옆으로 밀려 살아남는다** —
     * `<navigate condition="…">` 은 컴파일 green 이지만 `params.condition` 이라 아무도 읽지 않고,
     * 대장 기록상 그 7건이 「조건이 무시된 채 항상 실행」됐다.
     *
     * 판정은 **닫힌 3키**(`condition`·`trigger`·`id`)에만 걸고, 코어 44종 핸들러에만 적용한다 —
     * 확장·custom 핸들러의 params 는 구현이 정하므로 알 수 없다(실측: `sirsoft-ecommerce.loadVisibleFilters`
     * 는 `params.id` 를 실제로 요구한다). 소비하는 코어 핸들러도 실측으로 면제한다
     * (`startInterval`·`stopInterval` 이 `params.id` 를 읽는다 — ActionDispatcher:5146·5181).
     * 전면 화이트리스트로 넓히지 않는 이유는 D19 다 — prop 선언 커버리지가 75~77% 라 대량 오탐이 난다.
     */
    case ActionParamMisplacedKey = 'action_param_misplaced_key';

    /**
     * Modal 자식 hoist 3종(`<footer>`·`<title-prefix>`·`<title-suffix>`)을 **대상 템플릿 Modal 이
     * 선언하지 않음** (S23 축 B — 78차 프로브 f5b·f5c).
     *
     * 컴파일은 정상 hoist 하고(`props.footer`) lint 도 통과하지만, `sirsoft-basic` 의 `Modal.tsx` 는
     * 그 prop 을 읽지 않아 **작성한 내용이 화면에서 조용히 사라진다**. 문법 결함이 아니라 컴포넌트
     * 계약 비대칭이고, 그 비대칭 자체는 의도된 것이라 문서에 명문화돼 있다(layout-markup.md §6).
     * 남아 있던 문제는 **그 계약을 문서만 지키고 기계가 강제하지 않았다**는 것이며 이 코드가 그 자리다.
     *
     * 대상은 hoist 3키(EmbeddingCompiler::DECLARATION_CHECKED_PROPS)로 닫혀 있다. hoist 가 부모 이름과
     * 무관해진 뒤(2026-09-28 결정) 판정도 `Modal` 이름이 아니라 **그 노드 컴포넌트의 선언**으로 한다.
     * 닫힌 집합이라 D19 의 「부분 선언」 문제를 타지 않는다: 번들 레이아웃 전수 × 번들 두 템플릿에서
     * 이 3키의 거짓 양성은 0 건이고, 나머지 hoist 키(`expandChildren`·`options` 등)는 선언 없이 렌더하는
     * 조합이 수백 건이라 대상에서 뺐다.
     */
    case HoistPropNotSupportedByTemplate = 'hoist_prop_not_supported_by_template';

    /**
     * 작성한 컴포넌트 종류(`data-type`, 생략 시 basic)가 검사 대상 템플릿의 선언과 다름.
     *
     * 컴파일 산출은 템플릿과 무관하게 작성자가 적은 종류를 따른다(JSON 의 `type` 과 같다). 종류가
     * 어긋나면 렌더러가 다른 갈래를 탄다 — composite 를 basic 으로 적으면 편집 표식·상태 주입이 빠지고,
     * 반대면 상태 값이 DOM 으로 샌다(D47 에서 출하 노드 345개가 실제로 어긋나 있었다). 대상 템플릿이
     * 분명한 자리(편집기·템플릿 자신의 레이아웃)에서만 낸다.
     */
    case ComponentTypeMismatch = 'component_type_mismatch';

    /**
     * 확장 컴포넌트(`식별자.이름`)가 설치된 어느 확장의 `components.json` 에도 없음.
     *
     * 확장이 지워졌거나(편집 도중 삭제·비활성화 포함) 이름을 잘못 적은 경우다. 템플릿 컴포넌트 오타
     * (`component_not_in_registry`)와 달리 **저장을 막지 않는다** — 확장은 다시 설치될 수 있고, 편집 중인
     * 레이아웃을 그 확장 하나 때문에 저장하지 못하게 하면 안 된다(2026-09-28 결정). 검사용 레지스트리가
     * 있는 자리(편집기·템플릿 자신의 레이아웃)에서만 낸다.
     */
    case ExtensionComponentNotInstalled = 'extension_component_not_installed';

    /**
     * 편집 대상 템플릿의 컴포넌트 목록에 없는 템플릿 컴포넌트가 저장본에 이미 있었음 (#712).
     *
     * 7.0 이 확장 조각을 모든 활성 템플릿에 등록해 그 템플릿에 없는 컴포넌트를 쓴 행이 남아 있다. 편집기는 그런
     * 행도 저장할 수 있게 저장본에 있던 개수까지 허용하고, 이 템플릿에서는 그려지지 않을 수 있다는 사실만 알린다.
     * 새로 쓴 미등록 컴포넌트는 종전대로 `component_not_in_registry` 오류다.
     */
    case TemplateComponentKept = 'template_component_kept';

    /**
     * 진입점이 아닌 액션(`<when>`·`<case>`·`<sequence>`·`onSuccess` 등 다른 액션 안쪽)에 `debounce` 를 적음.
     *
     * 엔진은 이벤트에 바로 묶인 진입점 액션의 debounce 만 적용한다(ActionDispatcher::bindActionsToProps).
     * 안쪽 액션은 바깥 흐름이 기다려 결과·순서를 이어받으므로 늦출 수 없어 매 이벤트 즉시 실행된다 —
     * 코드 편집기 내용 검색이 글자마다 요청을 보낸 원인(2026-10-06 실측 D-I1-8). 감싸는 진입점
     * (`<conditions :debounce>`·`<sequence :debounce>`)에 달아야 한다.
     */
    case NestedActionDebounceIgnored = 'nested_action_debounce_ignored';

    /**
     * 같은 부품·같은 이벤트에 디바운스 동작과 바로 실행하는 동작을 함께 적음.
     *
     * 엔진은 바로 실행하는 동작을 돌리기 전에 대기 중인 디바운스를 모두 즉시 실행한다(저장 직전 최신 입력
     * 반영 — ActionDispatcher::bindActionsToProps 의 flushPendingDebounceTimers). 그래서 디바운스 동작도 매
     * 입력 실행된다. 동작은 그대로 두고 알린다(7.1.0 P13 결정 — 편집기 경고).
     */
    case MixedDebounceFlushed = 'mixed_debounce_flushed';

    /**
     * 다국어 경고 본문을 반환합니다.
     *
     * @param  array<string, string|int>  $params  메시지 치환 파라미터
     * @return string 경고 본문 (lang/{locale}/exceptions.php 의 layout_markup_lint 키)
     */
    public function message(array $params = []): string
    {
        return __('exceptions.layout_markup_lint.'.$this->value, $params);
    }

    /**
     * 모든 lint 코드 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> lint 코드 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
