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
export type AnalyticsPeriodPreset =
  | 'today'
  | 'yesterday'
  | '7d'
  | '30d'
  | '90d'
  | 'this_month'
  | 'last_month'
  | 'this_year';

/**
 * 프리셋 전체 목록 — 기간 바 버튼 8개와 같은 순서.
 */
export const ANALYTICS_PERIOD_PRESETS: readonly AnalyticsPeriodPreset[] = [
  'today',
  'yesterday',
  '7d',
  '30d',
  '90d',
  'this_month',
  'last_month',
  'this_year',
] as const;

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
 * 날짜를 YYYY-MM-DD 로 포맷한다 (로컬 날짜 기준 — toISOString 은 UTC 로 바뀌어 하루가 밀린다).
 *
 * @param date Date 객체
 * @returns YYYY-MM-DD
 */
function formatDate(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

/**
 * 프리셋 값이 허용 목록에 있는지 판정한다.
 *
 * @param value 검사할 값
 * @returns 허용 프리셋이면 true
 */
export function isAnalyticsPeriodPreset(value: unknown): value is AnalyticsPeriodPreset {
  return typeof value === 'string' && (ANALYTICS_PERIOD_PRESETS as readonly string[]).includes(value);
}

/**
 * 프리셋을 시작일·종료일로 계산한다.
 *
 * @param preset 기간 프리셋
 * @param now 기준 시각 (테스트 주입용, 기본 현재)
 * @returns 시작일·종료일 (YYYY-MM-DD)
 */
export function resolveAnalyticsPeriod(
  preset: AnalyticsPeriodPreset,
  now: Date = new Date(),
): { from: string; to: string } {
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const start = new Date(today);
  let end = new Date(today);

  switch (preset) {
    case 'today':
      break;

    case 'yesterday':
      start.setDate(start.getDate() - 1);
      end = new Date(start);
      break;

    case '7d':
      // 오늘을 포함한 7일
      start.setDate(start.getDate() - 6);
      break;

    case '30d':
      start.setDate(start.getDate() - 29);
      break;

    case '90d':
      start.setDate(start.getDate() - 89);
      break;

    case 'this_month':
      start.setDate(1);
      break;

    case 'last_month': {
      // 지난달 1일 ~ 지난달 말일. setMonth(-1) 은 31일에서 넘치면 이번 달로 되돌아오므로
      // 먼저 1일로 옮긴 뒤 달을 뺀다.
      start.setDate(1);
      start.setMonth(start.getMonth() - 1);
      end = new Date(start.getFullYear(), start.getMonth() + 1, 0);
      break;
    }

    case 'this_year':
      start.setMonth(0, 1);
      break;
  }

  return { from: formatDate(start), to: formatDate(end) };
}

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
export function setAnalyticsPeriodHandler(
  action?: { params?: SetAnalyticsPeriodParams },
): SetAnalyticsPeriodResult {
  const raw = action?.params?.preset;
  const preset: AnalyticsPeriodPreset = isAnalyticsPeriodPreset(raw) ? raw : '7d';

  const { from, to } = resolveAnalyticsPeriod(preset);

  return { from, to, preset };
}
