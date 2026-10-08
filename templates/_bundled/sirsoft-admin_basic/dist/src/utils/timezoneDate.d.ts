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
 */
/**
 * 사이트 기본 시간대를 돌려줍니다 (환경설정 > 일반 > 시간대).
 *
 * @returns 시간대 식별자, 알 수 없으면 undefined
 */
export declare function resolveSiteTimezone(): string | undefined;
/**
 * 로그인한 사람의 계정 시간대를 돌려줍니다 (회원 정보의 시간대 → 없으면 사이트 기본 시간대).
 *
 * @returns 시간대 식별자, 알 수 없으면 undefined
 */
export declare function resolveAccountTimezone(): string | undefined;
/**
 * 주어진 순간의, 그 시간대 달력 날짜를 UTC 자정 Date 로 돌려줍니다.
 *
 * @param now 기준 순간
 * @param timeZone 시간대 (없으면 브라우저 시간대)
 * @returns 그 달력 날짜의 UTC 자정 Date
 */
export declare function calendarDateIn(now: Date, timeZone?: string): Date;
/**
 * `calendarDateIn` 이 만든 날짜를 YYYY-MM-DD 로 씁니다.
 *
 * @param date UTC 자정 Date
 * @returns YYYY-MM-DD
 */
export declare function formatCalendarDate(date: Date): string;
