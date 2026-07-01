/**
 * useInsertionPoints — buildPoints DataProvider
 *
 * 부모 layout flow 에 따른 + 버튼 4방향 활성/비활성 결정.
 *
 * @since engine-v1.50.0
 */

import { describe, expect, it } from 'vitest';
import { buildPoints } from '../../hooks/useInsertionPoints';

describe('useInsertionPoints — buildPoints DataProvider', () => {
  it('block 부모 → 상/하 활성, 좌/우 비활성', () => {
    const points = buildPoints('block', [0], 2);
    const above = points.find((p) => p.direction === 'above');
    const below = points.find((p) => p.direction === 'below');
    const left = points.find((p) => p.direction === 'left');
    const right = points.find((p) => p.direction === 'right');
    expect(above?.disabled).toBe(false);
    expect(above?.insertion).toEqual({ parentPath: [0], index: 2 });
    expect(below?.disabled).toBe(false);
    expect(below?.insertion).toEqual({ parentPath: [0], index: 3 });
    expect(left?.disabled).toBe(true);
    expect(right?.disabled).toBe(true);
  });

  it('flex_row_single 부모 → 좌/우 활성, 상/하 비활성', () => {
    const points = buildPoints('flex_row_single', [], 1);
    expect(points.find((p) => p.direction === 'above')?.disabled).toBe(true);
    expect(points.find((p) => p.direction === 'below')?.disabled).toBe(true);
    expect(points.find((p) => p.direction === 'left')?.disabled).toBe(false);
    expect(points.find((p) => p.direction === 'left')?.insertion).toEqual({
      parentPath: [],
      index: 1,
    });
    expect(points.find((p) => p.direction === 'right')?.disabled).toBe(false);
    expect(points.find((p) => p.direction === 'right')?.insertion).toEqual({
      parentPath: [],
      index: 2,
    });
  });

  it('flex_column_single 부모 → 상/하 활성, 좌/우 비활성 (대칭)', () => {
    const points = buildPoints('flex_column_single', [3], 0);
    expect(points.find((p) => p.direction === 'above')?.disabled).toBe(false);
    expect(points.find((p) => p.direction === 'below')?.disabled).toBe(false);
    expect(points.find((p) => p.direction === 'left')?.disabled).toBe(true);
    expect(points.find((p) => p.direction === 'right')?.disabled).toBe(true);
  });

  it('flex_row_wrap → 4방향 모두 활성', () => {
    const points = buildPoints('flex_row_wrap', [0], 1);
    expect(points.every((p) => !p.disabled)).toBe(true);
  });

  it('flex_column_wrap → 4방향 모두 활성', () => {
    const points = buildPoints('flex_column_wrap', [], 0);
    expect(points.every((p) => !p.disabled)).toBe(true);
  });

  it('unknown 부모 → block 과 동일하게 상/하만 활성 (보수적 fallback)', () => {
    const points = buildPoints('unknown', [], 0);
    expect(points.find((p) => p.direction === 'above')?.disabled).toBe(false);
    expect(points.find((p) => p.direction === 'below')?.disabled).toBe(false);
    expect(points.find((p) => p.direction === 'left')?.disabled).toBe(true);
    expect(points.find((p) => p.direction === 'right')?.disabled).toBe(true);
  });
});
