/**
 * 폭 경계 판정 — 부품이 폭에 따라 클래스를 고를 때 함께 쓴다.
 *
 * 부품 안에서 Tailwind 반응형 접두(`md:inline`)나 폭 미디어를 품은 의미 클래스로 모양을 바꾸면 브라우저 창 폭으로
 * 판정되어 엔진 `responsive` 와 경계가 갈라진다. 부품은 렌더 폭(`useRenderWidth()` — Provider 폭 우선, 편집기
 * 미리보기에서는 기기 폭)을 받아 이 헬퍼로 경계를 판정하고 접두 없는 클래스를 고른다. 고르는 클래스는 CSS 스캔이
 * 찾을 수 있게 부품 소스에 전체 이름으로 적는다.
 */

/** Tailwind 기본 경계(px) — 이름은 Tailwind 접두와 같다 */
export const WIDTH_BREAKPOINTS = {
  sm: 640,
  md: 768,
  lg: 1024,
  xl: 1280,
  '2xl': 1536,
} as const;

/** 경계 이름 */
export type WidthBreakpoint = keyof typeof WIDTH_BREAKPOINTS;

/**
 * 폭이 경계 이상인지 — Tailwind `md:` 같은 min-width 경계와 같은 판정이다.
 *
 * @param width 렌더 폭(px)
 * @param breakpoint 경계 이름 또는 px
 * @returns 경계 이상이면 true
 */
export function isWidthAtLeast(width: number, breakpoint: WidthBreakpoint | number): boolean {
  const min = typeof breakpoint === 'number' ? breakpoint : WIDTH_BREAKPOINTS[breakpoint];

  return width >= min;
}

/**
 * 폭이 상한 이하인지 — CSS `@media (max-width: Npx)` 와 같은 판정이다(N 포함).
 *
 * @param width 렌더 폭(px)
 * @param max 상한(px)
 * @returns 상한 이하면 true
 */
export function isWidthAtMost(width: number, max: number): boolean {
  return width <= max;
}

/**
 * 경계 이상이면 `atLeast`, 아니면 `below` 클래스를 고른다.
 *
 * @param width 렌더 폭(px)
 * @param breakpoint 경계 이름 또는 px
 * @param atLeast 경계 이상에서 쓸 클래스(전체 이름)
 * @param below 경계 아래에서 쓸 클래스(전체 이름)
 * @returns 고른 클래스
 */
export function classAtWidth(
  width: number,
  breakpoint: WidthBreakpoint | number,
  atLeast: string,
  below = ''
): string {
  return isWidthAtLeast(width, breakpoint) ? atLeast : below;
}
