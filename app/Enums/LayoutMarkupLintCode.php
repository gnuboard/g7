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
     * 독립 레이아웃(extends 없음)이 toast·openModal 을 발화하면서 호스트 컴포넌트(Toast·Modal)를
     * 마운트하지 않음 — 핸들러는 success 로 기록되나 화면에 아무것도 나오지 않는 silent failure.
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
     * 대상은 hoist 3키로 닫혀 있다 — 소스 경로가 `<footer>`/`<title-prefix>`/`<title-suffix>` 뿐이고
     * 컴파일러가 부모를 Modal 로 못박는다(EmbeddingCompiler::applyChild). 닫힌 집합이라 D19 의
     * 「부분 선언」 문제를 타지 않는다: 두 번들 템플릿 182 컴포넌트가 전건 props 표면을 갖고,
     * 이 3키는 admin 선언 있음 / basic 선언 없음이 **컴포넌트 소스와 일치**함을 실측했다.
     */
    case HoistPropNotSupportedByTemplate = 'hoist_prop_not_supported_by_template';

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
