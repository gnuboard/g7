/**
 * 방문자 통계 기간 프리셋 핸들러
 *
 * 통계 화면의 기간 바 프리셋(오늘·어제·7일·30일·90일·이번 달·지난달·올해)을
 * 시작일·종료일 **날짜 문자열(YYYY-MM-DD)** 로 계산해 돌려준다.
 *
 * `setDateRange` 를 재사용하지 않는 이유: 그 핸들러는 시·분·초가 붙은 datetime-local 문자열을
 * 돌려주는데, 통계 API 의 `from`·`to` 는 날짜만 받아 그 값을 422 로 거절한다. 사이트 표준시
 * 해석은 서버 몫이므로 여기서는 브라우저의 로컬 날짜를 그대로 쓴다 — 서버의 프리셋 계산
 * (`PeriodRequest::resolvedRange`)과 같은 규칙이라 화면에 표시한 달력 값과 서버가 실제로
 * 조회한 구간이 어긋나지 않는다.
 *
 * 상태 반영은 레이아웃이 sequence + setState 로 처리한다(`$prev.from`·`$prev.to`·`$prev.preset`).
 */
/**
 * 기간 프리셋 — 서버 `PeriodRequest` 의 허용값과 같다.
 */
export type AnalyticsPeriodPreset = 'today' | 'yesterday' | '7d' | '30d' | '90d' | 'this_month' | 'last_month' | 'this_year';
/**
 * 프리셋 전체 목록 — 기간 바 버튼 8개와 같은 순서.
 */
export declare const ANALYTICS_PERIOD_PRESETS: readonly AnalyticsPeriodPreset[];
interface SetAnalyticsPeriodParams {
    /** 기간 프리셋 */
    preset?: AnalyticsPeriodPreset | string;
}
export interface SetAnalyticsPeriodResult {
    /** 시작일 (YYYY-MM-DD) */
    from: string;
    /** 종료일 (YYYY-MM-DD) */
    to: string;
    /** 적용된 프리셋 (모르는 값은 7d 로 떨어진다) */
    preset: AnalyticsPeriodPreset;
}
/**
 * 프리셋 값이 허용 목록에 있는지 판정한다.
 *
 * @param value 검사할 값
 * @returns 허용 프리셋이면 true
 */
export declare function isAnalyticsPeriodPreset(value: unknown): value is AnalyticsPeriodPreset;
/**
 * 프리셋을 시작일·종료일로 계산한다.
 *
 * @param preset 기간 프리셋
 * @param now 기준 시각 (테스트 주입용, 기본 현재)
 * @returns 시작일·종료일 (YYYY-MM-DD)
 */
export declare function resolveAnalyticsPeriod(preset: AnalyticsPeriodPreset, now?: Date): {
    from: string;
    to: string;
};
/**
 * 기간 프리셋 핸들러
 *
 * 기간 바 프리셋 버튼 클릭 시 호출된다. 시작일·종료일을 날짜 문자열로 계산해 돌려주며,
 * 레이아웃은 `$prev` 로 받아 로컬 상태와 URL 질의에 옮긴다.
 *
 * @example
 * ```html
 * <sequence>
 *   <action handler="setAnalyticsPeriod" preset="30d"/>
 *   <set-state target="local" period.preset="{{$prev.preset}}" period.from="{{$prev.from}}" period.to="{{$prev.to}}"/>
 *   <navigate path="/admin/analytics" :mergeQuery="true" :replace="true">
 *     <query preset="{{$prev.preset}}" :from="undefined" :to="undefined" :page="1"/>
 *   </navigate>
 * </sequence>
 * ```
 *
 * @param action 액션 정의 (params.preset)
 * @returns 계산된 기간
 */
export declare function setAnalyticsPeriodHandler(action?: {
    params?: SetAnalyticsPeriodParams;
}): SetAnalyticsPeriodResult;
export {};
