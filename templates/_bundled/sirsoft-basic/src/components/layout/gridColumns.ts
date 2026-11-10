/**
 * Grid 열 수 폭 판정 — Grid 부품과 PageSkeleton 이 함께 쓴다.
 *
 * `responsive` prop({sm, md, lg, xl, 2xl})을 Tailwind 반응형 접두(`md:grid-cols-3`)로 내면 브라우저
 * 창 폭으로 판정되어 레이아웃 편집기 미리보기 폭을 따르지 못하고, 엔진 `responsive` 와 경계가 갈라진다.
 * 그래서 엔진 폭(`G7Core.useResponsive().width`)으로 열 수를 골라 접두 없는 `grid-cols-N` 하나만 낸다.
 * 열 수가 아닌 폭 분기(여백·보이기 등)는 같은 폴더의 `viewportWidth.ts` 를 쓴다.
 */

import { BREAKPOINTS } from './viewportWidth';

/** Grid 반응형 열 수 (구간 이름 → 열 수) */
export interface GridResponsiveCols {
  sm?: number;
  md?: number;
  lg?: number;
  xl?: number;
  '2xl'?: number;
}

/** Tailwind 기본 경계(px, `viewportWidth.ts` 의 BREAKPOINTS) — 넓은 쪽부터 판정한다 */
const GRID_BREAKPOINTS: ReadonlyArray<readonly [keyof GridResponsiveCols, number]> = [
  ['2xl', BREAKPOINTS['2xl']],
  ['xl', BREAKPOINTS.xl],
  ['lg', BREAKPOINTS.lg],
  ['md', BREAKPOINTS.md],
  ['sm', BREAKPOINTS.sm],
];

/**
 * 열 수별 클래스 — 런타임에 조립하면 CSS 스캔이 찾지 못하므로 전체 이름을 적어 둔다.
 */
const GRID_COLS_CLASSES: Record<number, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-2',
  3: 'grid-cols-3',
  4: 'grid-cols-4',
  5: 'grid-cols-5',
  6: 'grid-cols-6',
  7: 'grid-cols-7',
  8: 'grid-cols-8',
  9: 'grid-cols-9',
  10: 'grid-cols-10',
  11: 'grid-cols-11',
  12: 'grid-cols-12',
};

/**
 * 현재 폭에 맞는 열 수를 고른다.
 *
 * Tailwind 와 같은 cascade 다: 폭 이상인 구간 중 가장 넓은 구간의 값을 쓰고, 그 구간에 값이 없으면
 * 아래(좁은) 구간 값을 이어받으며, 맞는 구간이 하나도 없으면 기본 `cols` 를 쓴다.
 *
 * @param cols 기본 열 수
 * @param responsive 구간별 열 수
 * @param width 판정 폭(px) — 편집기 미리보기에서는 기기 폭
 * @returns 열 수 (기본값도 없으면 undefined)
 */
export function resolveGridCols(
  cols: number | undefined,
  responsive: GridResponsiveCols | undefined,
  width: number
): number | undefined {
  if (responsive && typeof responsive === 'object') {
    for (const [key, min] of GRID_BREAKPOINTS) {
      const value = responsive[key];
      if (width >= min && value) {
        return value;
      }
    }
  }

  return cols || undefined;
}

/**
 * 열 수에 해당하는 접두 없는 클래스.
 *
 * @param cols 열 수
 * @returns `grid-cols-N` (열 수가 없으면 빈 문자열)
 */
export function gridColsClass(cols: number | undefined): string {
  if (!cols) {
    return '';
  }

  return GRID_COLS_CLASSES[cols] ?? `grid-cols-${cols}`;
}
