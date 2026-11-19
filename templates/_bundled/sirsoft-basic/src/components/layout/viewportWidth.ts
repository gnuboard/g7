/**
 * 화면 폭 판정 공용 헬퍼 — 부품이 폭에 따라 클래스를 고를 때 함께 쓴다(Grid 열 수는 `gridColumns.ts`).
 *
 * 부품 안에서 Tailwind 반응형 접두를 쓰면 브라우저 창 폭으로 판정되어 레이아웃 편집기 미리보기 폭을
 * 따르지 못하고, 엔진 `responsive` 와 경계가 갈라진다. 그래서 엔진 폭(`G7Core.useResponsive().width`,
 * 미리보기에서는 기기 폭)으로 접두 없는 클래스 하나를 고른다. 경계는 Tailwind 와 같아 일반 화면은 그대로다.
 *
 * 고르는 클래스는 전체 이름으로 적는다 — 런타임에 조립하면 CSS 스캔이 찾지 못한다.
 */

/** Tailwind 기본 경계(px) */
export const BREAKPOINTS = {
  sm: 640,
  md: 768,
  lg: 1024,
  xl: 1280,
  '2xl': 1536,
} as const;

/** 경계 이름 */
export type BreakpointName = keyof typeof BREAKPOINTS;

/** 넓은 쪽부터 — cascade 판정 순서 */
const WIDE_FIRST: ReadonlyArray<BreakpointName> = ['2xl', 'xl', 'lg', 'md', 'sm'];

/**
 * 폭을 모를 때 쓰는 값 — 엔진 기본 폭(데스크톱 1024px).
 *
 * 엔진 훅도 창도 없을 때(엔진 밖에서 그리는 경우)만 쓰인다. 엔진 `ResponsiveManager`·`ResponsiveContext` 의
 * 기본값과 검색 봇 렌더가 가정하는 폭이 1024 라, 부품도 같은 폭으로 골라 엔진 `responsive` 와 어긋나지 않게 한다.
 * Grid·화면 뼈대(PageSkeleton)를 포함한 이 템플릿의 모든 폭 판정이 이 값을 쓴다. 브라우저에서는 엔진이 첫 렌더부터
 * 창 폭을 주므로 이 값으로 그렸다가 바뀌는 깜박임은 생기지 않는다.
 */
export const UNKNOWN_VIEWPORT_WIDTH = 1024;

/**
 * 엔진 훅 값에서 판정 폭을 읽는다 — 훅 값 → 창 폭 → `UNKNOWN_VIEWPORT_WIDTH` 순.
 *
 * @param responsiveValue `G7Core.useResponsive()` 반환값 (없으면 undefined)
 * @returns 판정 폭(px)
 */
export function resolveViewportWidth(responsiveValue: unknown): number {
  const width = (responsiveValue as { width?: unknown } | null | undefined)?.width;
  if (typeof width === 'number' && Number.isFinite(width)) {
    return width;
  }
  if (typeof window !== 'undefined' && typeof window.innerWidth === 'number' && Number.isFinite(window.innerWidth)) {
    return window.innerWidth;
  }

  return UNKNOWN_VIEWPORT_WIDTH;
}

/**
 * 엔진 폭을 구독한다 — 창 크기와 편집기 미리보기 폭이 바뀌면 다시 그린다.
 *
 * 컴포넌트 본문에서 매 렌더 같은 순서로 부른다(훅 규칙). `G7Core.useResponsive` 가 없는 환경은 창 폭을 쓴다.
 * 그 창 폭 폴백은 렌더 때 한 번 읽을 뿐 창 resize 를 구독하지 않는다(엔진 안에서는 늘 훅 폭이 있어 해당 없음).
 *
 * @returns 판정 폭(px)
 */
export function useViewportWidth(): number {
  const G7Core = typeof window !== 'undefined' ? (window as any).G7Core : undefined;
  const useResponsive = G7Core?.useResponsive as (() => unknown) | undefined;

  return resolveViewportWidth(useResponsive?.());
}

/**
 * 폭에 맞는 값을 고른다 — Tailwind 와 같은 cascade(폭 이상인 구간 중 가장 넓은 구간의 값, 없으면 기본값).
 *
 * @param width 판정 폭(px)
 * @param base 어느 경계에도 닿지 않을 때의 값 (접두 없는 클래스에 해당)
 * @param steps 경계별 값 (`sm:` `md:` … 에 해당)
 * @returns 고른 값
 */
export function pickByWidth<T>(width: number, base: T, steps: Partial<Record<BreakpointName, T>>): T {
  for (const name of WIDE_FIRST) {
    const value = steps[name];
    if (value !== undefined && width >= BREAKPOINTS[name]) {
      return value;
    }
  }

  return base;
}

/**
 * 본문 칸 좌우 여백 — 헤더·푸터·뼈대가 함께 쓴다(640px 미만 px-4, 640px 이상 px-6, 1024px 이상 px-8).
 *
 * @param width 판정 폭(px)
 * @returns `px-4` · `px-6` · `px-8` 가운데 하나
 */
export function containerPaddingClass(width: number): 'px-4' | 'px-6' | 'px-8' {
  return pickByWidth<'px-4' | 'px-6' | 'px-8'>(width, 'px-4', { sm: 'px-6', lg: 'px-8' });
}

/** 가로 상품 줄 열 폭 클래스 */
export type ProductRailColumnClass =
  | 'auto-cols-[calc(50%_-_8px)]'
  | 'auto-cols-[calc(33.333%_-_11px)]'
  | 'auto-cols-[calc(25%_-_12px)]';

/**
 * 가로 상품 줄(`ProductRail`)의 열 폭 — 줄과 그 화면 뼈대(PageSkeleton)가 함께 쓴다.
 *
 * 한 화면 카드 수는 640px 미만 2장, 640px 이상 3장, 1024px 이상 4장이다. 열 폭은 간격 gap-4(16px)를 장수로 나눠 뺀 값이다.
 *
 * @param width 판정 폭(px)
 * @returns 열 폭 클래스
 */
export function productRailColumnClass(width: number): ProductRailColumnClass {
  return pickByWidth<ProductRailColumnClass>(width, 'auto-cols-[calc(50%_-_8px)]', {
    sm: 'auto-cols-[calc(33.333%_-_11px)]',
    lg: 'auto-cols-[calc(25%_-_12px)]',
  });
}
