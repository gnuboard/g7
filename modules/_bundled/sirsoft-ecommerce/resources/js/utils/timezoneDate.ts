// e2e:allow 주석 속 짝 파일 경로 표기만 정정(동작 무변경). 날짜 계산은 accountTimezoneDates.test.ts 가 잠근다.
/**
 * 시간대 기준 「오늘」 날짜 계산 도우미
 *
 * 화면의 빠른 기간 버튼(오늘·1주일·1개월…)이 만드는 날짜는 서버가 같은 날짜를 해석하는 시간대와 같아야 한다.
 * 브라우저 시간대(`new Date().getDate()`·`toLocaleDateString()`)나 UTC(`toISOString()`)로 만들면, 계정 시간대와
 * 브라우저·UTC 의 날짜가 다른 시간(예: 한국 계정을 UTC 브라우저에서 쓰는 오전 0~9시)에 「오늘」 이 어제가 된다.
 *
 * - 계정 시간대: 로그인한 사람의 시간대 설정 → 없으면 사이트 기본 시간대. 서버가 목록 날짜 필터를 해석하는 기준과 같다.
 * - 사이트 시간대: 방문자 통계처럼 사이트 표준시로 집계하는 화면의 기준.
 * - 둘 다 알 수 없으면(엔진 밖 단위 테스트 등) 브라우저 시간대로 떨어진다 — 종전 동작.
 *
 * 날짜 계산은 「그 시간대의 달력 날짜」를 UTC 자정 Date 로 들고 `setUTC*` 로 더하고 빼서, 브라우저 시간대가
 * 끼어들지 않게 한다.
 *
 * 모듈은 특정 관리자 템플릿에 기대지 않도록 같은 도우미를 모듈 안에 둔다(관리자 템플릿 sirsoft-admin_basic 의
 * `templates/_bundled/sirsoft-admin_basic/src/utils/timezoneDate.ts` 와 같은 규칙). 읽는 곳은 코어 공용 API(AuthManager·전역 설정)뿐이다.
 */

/**
 * 시간대 식별자가 이 브라우저에서 쓸 수 있는지 판정합니다.
 *
 * @param timeZone IANA 시간대 식별자
 * @returns 쓸 수 있으면 true
 */
function isUsableTimeZone(timeZone: unknown): timeZone is string {
  if (typeof timeZone !== 'string' || timeZone === '') {
    return false;
  }
  try {
    new Intl.DateTimeFormat('en-US', { timeZone });
    return true;
  } catch {
    return false;
  }
}

/**
 * 사이트 기본 시간대를 돌려줍니다 (환경설정 > 일반 > 시간대).
 *
 * @returns 시간대 식별자, 알 수 없으면 undefined
 */
export function resolveSiteTimezone(): string | undefined {
  const w = typeof window !== 'undefined' ? (window as any) : undefined;
  const fromState = w?.G7Core?.state?.get?.()?.settings?.general?.timezone;
  if (isUsableTimeZone(fromState)) {
    return fromState;
  }
  const fromConfig = w?.G7Config?.settings?.general?.timezone;
  return isUsableTimeZone(fromConfig) ? fromConfig : undefined;
}

/**
 * 로그인한 사람의 계정 시간대를 돌려줍니다 (회원 정보의 시간대 → 없으면 사이트 기본 시간대).
 *
 * @returns 시간대 식별자, 알 수 없으면 undefined
 */
export function resolveAccountTimezone(): string | undefined {
  const w = typeof window !== 'undefined' ? (window as any) : undefined;
  let userTimezone: unknown;
  try {
    userTimezone = w?.G7Core?.AuthManager?.getInstance?.()?.getUser?.()?.timezone;
  } catch {
    userTimezone = undefined;
  }
  if (isUsableTimeZone(userTimezone)) {
    return userTimezone;
  }
  return resolveSiteTimezone();
}

/**
 * 주어진 순간의, 그 시간대 달력 날짜를 UTC 자정 Date 로 돌려줍니다.
 *
 * @param now 기준 순간
 * @param timeZone 시간대 (없으면 브라우저 시간대)
 * @returns 그 달력 날짜의 UTC 자정 Date
 */
export function calendarDateIn(now: Date, timeZone?: string): Date {
  if (!isUsableTimeZone(timeZone)) {
    return new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
  }
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
  }).formatToParts(now);
  const pick = (type: string): number => Number(parts.find((p) => p.type === type)?.value);
  return new Date(Date.UTC(pick('year'), pick('month') - 1, pick('day')));
}

/**
 * `calendarDateIn` 이 만든 날짜를 YYYY-MM-DD 로 씁니다.
 *
 * @param date UTC 자정 Date
 * @returns YYYY-MM-DD
 */
export function formatCalendarDate(date: Date): string {
  const year = date.getUTCFullYear();
  const month = String(date.getUTCMonth() + 1).padStart(2, '0');
  const day = String(date.getUTCDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

/**
 * `calendarDateIn` 이 만든 날짜에 날수를 더해 YYYY-MM-DD 로 씁니다.
 *
 * @param date UTC 자정 Date
 * @param days 더할 날수 (음수면 뺀다)
 * @returns YYYY-MM-DD
 */
export function addCalendarDays(date: Date, days: number): string {
  const next = new Date(date);
  next.setUTCDate(next.getUTCDate() + days);
  return formatCalendarDate(next);
}
