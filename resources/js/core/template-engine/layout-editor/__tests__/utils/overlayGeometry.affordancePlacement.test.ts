/**
 * resolveAffordancePlacement 회귀 테스트
 *
 * 결함: 작은 요소(44px 미만) 선택 시 ⓘ 버튼과 lock/nav 어포던스가 박스 모서리에서
 * 서로 겹쳐 클릭 불가. 본 테스트는 박스 크기(scale 반영)에 따라 'inside'/'outside'
 * 배치 전략이 올바르게 판정됨을 가드한다.
 */

import { describe, it, expect } from 'vitest';
import {
  resolveAffordancePlacement,
  resolveInsertionCrossOffsets,
  OVERLAY_SMALL_BOX_THRESHOLD,
  INSERTION_AFFORDANCE_BUTTON,
  INSERTION_AFFORDANCE_SMALL_GAP,
  type OverlayBox,
} from '../../utils/overlayGeometry';

function box(width: number, height: number, scale = 1, left = 0): OverlayBox {
  return { left, top: 0, width, height, scale };
}

/** 두 버튼(24×24) 사각형 겹침 판정 — left/top 은 박스 기준 오프셋. */
function overlaps(
  a: { left: number; top: number },
  b: { left: number; top: number }
): boolean {
  const s = INSERTION_AFFORDANCE_BUTTON;
  return !(a.left + s <= b.left || b.left + s <= a.left || a.top + s <= b.top || b.top + s <= a.top);
}

describe('resolveAffordancePlacement', () => {
  it('임계값(44px) 이상의 큰 박스는 inside 배치', () => {
    expect(resolveAffordancePlacement(box(200, 100))).toBe('inside');
    expect(resolveAffordancePlacement(box(44, 44))).toBe('inside');
  });

  it('width 가 임계값 미만이면 outside 배치 (겹침 회피)', () => {
    expect(resolveAffordancePlacement(box(20, 100))).toBe('outside');
  });

  it('height 가 임계값 미만이면 outside 배치', () => {
    expect(resolveAffordancePlacement(box(200, 20))).toBe('outside');
  });

  it('width/height 둘 다 작으면 outside 배치', () => {
    expect(resolveAffordancePlacement(box(16, 16))).toBe('outside');
  });

  it('scale 이 적용되면 시각 크기(width × scale) 기준으로 판정', () => {
    // 실제 width 100 이지만 scale 0.4 → 시각 40px < 44 → outside
    expect(resolveAffordancePlacement(box(100, 100, 0.4))).toBe('outside');
    // 실제 width 100, scale 0.5 → 시각 50px >= 44 → inside
    expect(resolveAffordancePlacement(box(100, 100, 0.5))).toBe('inside');
  });

  it('박스가 null 이면 inside (기본값)', () => {
    expect(resolveAffordancePlacement(null)).toBe('inside');
  });

  it('임계값 상수는 44px (권장 최소 터치 타깃)', () => {
    expect(OVERLAY_SMALL_BOX_THRESHOLD).toBe(44);
  });
});

describe('resolveInsertionCrossOffsets', () => {
  const half = INSERTION_AFFORDANCE_BUTTON / 2; // 12
  const gap = INSERTION_AFFORDANCE_SMALL_GAP;
  // push 는 박스 크기에 따라 동적 — base(half+gap) 와 대각 보정(2*half - min(cx,cy) + gap) 의 max.
  const pushFor = (w: number, h: number): number =>
    Math.max(half + gap, 2 * half - Math.min(w / 2, h / 2) + gap);

  it('작은 박스(6×20)에서 4방향 버튼이 박스 중심 기준 십자(위/아래/좌/우)로 배치', () => {
    const c = resolveInsertionCrossOffsets(box(6, 20));
    const push = pushFor(6, 20);
    expect(c.above.left).toBe(6 / 2 - half);
    expect(c.above.top).toBe(-push - half);
    expect(c.below.left).toBe(6 / 2 - half);
    expect(c.below.top).toBe(20 + push - half);
    expect(c.left.left).toBe(-push - half);
    expect(c.left.top).toBe(20 / 2 - half);
    expect(c.right.left).toBe(6 + push - half);
    expect(c.right.top).toBe(20 / 2 - half);
  });

  it('정사각형 박스(24×24)는 박스에 가깝게 붙는다 (push = base 18, 과도하게 멀지 않음)', () => {
    const c = resolveInsertionCrossOffsets(box(24, 24));
    const push = pushFor(24, 24);
    expect(push).toBe(half + gap); // 18 — 대각 보정이 base 이하라 base 채택
    // above 버튼 하단이 박스 상단에서 gap 만큼만 떨어짐
    expect(c.above.top).toBe(-push - half);
    expect(-push - half + INSERTION_AFFORDANCE_BUTTON).toBe(-gap); // 버튼 하단 = -gap
  });

  it('네 버튼은 서로 겹치지 않는다 (작은 6×20 박스)', () => {
    const c = resolveInsertionCrossOffsets(box(6, 20));
    const dirs = ['above', 'below', 'left', 'right'] as const;
    for (let i = 0; i < dirs.length; i++) {
      for (let j = i + 1; j < dirs.length; j++) {
        expect(overlaps(c[dirs[i]], c[dirs[j]])).toBe(false);
      }
    }
  });

  it('극단적으로 작은 박스(2×2)에서도 네 버튼이 겹치지 않는다', () => {
    const c = resolveInsertionCrossOffsets(box(2, 2));
    const dirs = ['above', 'below', 'left', 'right'] as const;
    for (let i = 0; i < dirs.length; i++) {
      for (let j = i + 1; j < dirs.length; j++) {
        expect(overlaps(c[dirs[i]], c[dirs[j]])).toBe(false);
      }
    }
  });

  it('above/below 는 가로 중앙(같은 left), left/right 는 세로 중앙(같은 top) — 십자 대칭', () => {
    const c = resolveInsertionCrossOffsets(box(6, 20));
    expect(c.above.left).toBe(c.below.left);
    expect(c.left.top).toBe(c.right.top);
  });
});
