<?php

namespace App\Enums;

/**
 * `.g7.html` 저작 포맷 정적 lint 경고 코드 Enum (S6).
 *
 * lint 은 컴파일을 **막지 않는** 경고다 — 산출 JSON 은 정상 생성되지만, 저작자·리뷰어에게
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
     * 플러그인이 완전 레이아웃을 정의함 — 플러그인은 layout_extensions·설정 화면만 두는 것이
     * 관례다 (gdpr_consent_log·plugin_settings 는 허용 목록 예외, D15).
     */
    case PluginCompleteLayout = 'plugin_complete_layout';

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
     * 기계 이관은 배치를 바꿀 수 없다(D38). 신규 저작이 그 죽은 배치를 따라 쓰지 않게 여기서 경고한다.
     */
    case SwitchValueNotConsumed = 'switch_value_not_consumed';

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
