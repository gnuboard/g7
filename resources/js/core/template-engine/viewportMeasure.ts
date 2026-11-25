/**
 * viewportMeasure.ts
 *
 * 엔진이 반응형 판정(mobile·tablet·desktop·portable)에 쓸 화면 폭을 재는 순수 함수.
 *
 * `window.innerWidth` 는 지금 **보이는 영역**의 폭이다. 휴대폰에서 화면보다 넓은 내용이 그려지면 브라우저가
 * 전체가 보이도록 화면을 축소하고, 그때 `innerWidth` 도 함께 부푼다(390 → 909·980). 그 값을 그대로 쓰면
 * 엔진이 PC·태블릿 화면을 고르고, 그 화면이 다시 축소를 부르는 고리가 생겨 새로고침 전까지 풀리지 않는다.
 * 그래서 CSS 폭(미디어 쿼리 기준)이 `innerWidth` 와 어긋나고 레이아웃 기준 폭(`documentElement.clientWidth`)과
 * 맞으면 레이아웃 기준 폭을 쓴다. PC(세로 스크롤 막대)·브라우저 확대(Ctrl +)·소수 배율 경계에서는 CSS 폭이
 * `innerWidth` 와 같으므로 지금처럼 `innerWidth` 를 쓴다.
 *
 * import 시점에 실행되는 코드가 없다 — `ResponsiveManager` 모듈을 통째로 모의하는 시험에서도 다른 importer
 * (자산 실패 배너 등)가 이 함수를 그대로 쓸 수 있도록 별도 모듈로 둔다.
 *
 * @since engine-v1.97.1
 */

/**
 * 측정에 필요한 창 모양 (실제 `window` 또는 시험용 가짜 객체)
 *
 * @since engine-v1.97.1
 */
export interface ViewportMeasureSource {
  innerWidth: number;
  document?: { documentElement?: { clientWidth?: number } | null } | null;
  matchMedia?: ((query: string) => { matches?: boolean } | null | undefined) | null;
}

/**
 * innerWidth 와 CSS 폭을 같다고 볼 허용 오차(px) — 소수 배율(dPR 1.0000000447)에서 CSS 폭이 1023.00004 가 되는 경우를 흡수한다.
 *
 * @since engine-v1.97.1
 */
export const VIEWPORT_MATCH_TOLERANCE_PX = 2;

/**
 * 숫자로 읽을 수 있는 양의 폭이면 반올림한 정수, 아니면 0 을 돌려줍니다.
 *
 * @param value 읽은 값
 * @return 정수 폭(px) 또는 0
 */
function toWidth(value: unknown): number {
  const width = Number(value);

  return Number.isFinite(width) && width > 0 ? Math.round(width) : 0;
}

/**
 * CSS 폭(미디어 쿼리 기준)이 주어진 폭 ±허용 오차 안인지 확인합니다.
 *
 * matchMedia 가 없거나 예외를 던지거나 결과가 비정상이면 false 다(호출자는 innerWidth 로 폴백한다).
 *
 * @param win 측정 대상 창
 * @param width 비교할 폭(px)
 * @return CSS 폭이 그 폭과 같다고 볼 수 있으면 true
 */
function cssWidthNear(win: ViewportMeasureSource, width: number): boolean {
  if (typeof win.matchMedia !== 'function') {
    return false;
  }

  try {
    const min = Math.max(0, width - VIEWPORT_MATCH_TOLERANCE_PX);
    const max = width + VIEWPORT_MATCH_TOLERANCE_PX;

    return win.matchMedia(`(min-width: ${min}px) and (max-width: ${max}px)`)?.matches === true;
  } catch {
    return false;
  }
}

/**
 * 엔진이 반응형 판정에 쓸 화면 폭을 잽니다.
 *
 * 판정 순서(처음 맞는 것):
 * 1. innerWidth·clientWidth 둘 다 0·NaN → null (아직 그려지지 않은 문서)
 * 2. clientWidth 가 0 → innerWidth (jsdom 등)
 * 3. innerWidth 가 0 → clientWidth
 * 4. 두 값이 같음 → innerWidth (matchMedia 를 부르지 않는다 — 휴대폰 평상시)
 * 5. CSS 폭이 innerWidth ±2 안 → innerWidth (PC 스크롤 막대·브라우저 확대·소수 배율 — 종전과 같은 값)
 * 6. CSS 폭이 clientWidth ±2 안 → clientWidth (휴대폰 화면 축소로 부푼 innerWidth·손가락 확대로 줄어든 innerWidth)
 * 7. 그 밖(matchMedia 없음·늘 false·예외) → innerWidth (종전 동작으로 폴백)
 *
 * @param win 측정 대상 창 (생략하면 전역 window)
 * @return 폭(px, 정수). 잴 수 없으면 null — 호출자는 이전 값을 유지한다
 * @since engine-v1.97.1
 */
export function measureViewportWidth(win?: ViewportMeasureSource): number | null {
  const source: ViewportMeasureSource | undefined =
    win ?? (typeof window !== 'undefined' ? (window as unknown as ViewportMeasureSource) : undefined);

  if (!source) {
    return null;
  }

  const innerWidth = toWidth(source.innerWidth);
  let clientWidth = 0;

  try {
    clientWidth = toWidth(source.document?.documentElement?.clientWidth);
  } catch {
    clientWidth = 0;
  }

  if (innerWidth === 0 && clientWidth === 0) {
    return null;
  }

  if (clientWidth === 0) {
    return innerWidth;
  }

  if (innerWidth === 0) {
    return clientWidth;
  }

  if (innerWidth === clientWidth) {
    return innerWidth;
  }

  if (cssWidthNear(source, innerWidth)) {
    return innerWidth;
  }

  if (cssWidthNear(source, clientWidth)) {
    return clientWidth;
  }

  return innerWidth;
}
