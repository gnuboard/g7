/**
 * overlayZIndex.test.ts — 캔버스 오버레이 z-index 계약 회귀 테스트
 *
 *
 * 회귀 배경: S5b 드래그 핸들(`20 + depth`)이 z-index 미지정(≈0)인 어포던스
 * 버튼(+/ⓘ/리사이즈/잠금/네비) 위로 올라와, 선택 후 +/ⓘ/리사이즈 핸들 클릭이
 * 이동 포인터(grab)에 가로채이고 버튼에 도달하지 못했다. 본 테스트는 밴드 불변식을
 * 강제해 어떤 트리 깊이에서도 어포던스가 핸들 위에 오도록 보장한다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import {
  DND_HANDLE_BASE,
  DND_HANDLE_MAX_DEPTH,
  EDIT_LOCK_DIM,
  OVERLAY_AFFORDANCE,
  TABLE_INPLACE,
  DND_DROP_SLOT,
  DND_DRAG_OVERLAY,
  dndHandleZIndex,
} from '../../utils/overlayZIndex';

describe('overlayZIndex — 밴드 불변식', () => {
  it('최대 깊이 드래그 핸들 < 편집 딤 < 어포던스 < 드롭 슬롯 < 드래그 고스트', () => {
    const maxHandle = DND_HANDLE_BASE + DND_HANDLE_MAX_DEPTH;
    // 편집 딤은 드래그 핸들 위(편집 중 선택 외 핸들 클릭 차단)이면서 어포던스 아래
    // (선택 요소의 ⓘ/리사이즈는 딤 위에서 조작 가능) —.
    expect(maxHandle).toBeLessThan(EDIT_LOCK_DIM);
    expect(EDIT_LOCK_DIM).toBeLessThan(OVERLAY_AFFORDANCE);
    expect(OVERLAY_AFFORDANCE).toBeLessThan(DND_DROP_SLOT);
    expect(DND_DROP_SLOT).toBeLessThan(DND_DRAG_OVERLAY);
  });

  it('표 인플레이스 오버레이는 어포던스 위·드롭 슬롯 아래', () => {
    // 거터/셀 버튼 클릭이 드래그 핸들·코어 어포던스에 가로채이지 않도록 어포던스 밴드 위.
    expect(OVERLAY_AFFORDANCE).toBeLessThan(TABLE_INPLACE);
    // 드래그 중에만 존재하는 드롭 슬롯 아래(표 편집은 드래그 중 비활성).
    expect(TABLE_INPLACE).toBeLessThan(DND_DROP_SLOT);
  });

  it('어떤 트리 깊이에서도 핸들 z-index 가 편집 딤·어포던스 밴드를 넘지 않음', () => {
    for (const depth of [0, 1, 5, 12, 49, 50, 100, 1000]) {
      expect(dndHandleZIndex(depth)).toBeLessThan(EDIT_LOCK_DIM);
      expect(dndHandleZIndex(depth)).toBeLessThan(OVERLAY_AFFORDANCE);
    }
  });

  it('dndHandleZIndex 는 깊이순 단조 증가(클램프 전까지) — 결함 2 깊이순 정렬 보존', () => {
    expect(dndHandleZIndex(0)).toBe(DND_HANDLE_BASE);
    expect(dndHandleZIndex(1)).toBe(DND_HANDLE_BASE + 1);
    expect(dndHandleZIndex(3)).toBeGreaterThan(dndHandleZIndex(2));
    // 클램프 경계
    expect(dndHandleZIndex(DND_HANDLE_MAX_DEPTH)).toBe(DND_HANDLE_BASE + DND_HANDLE_MAX_DEPTH);
    expect(dndHandleZIndex(DND_HANDLE_MAX_DEPTH + 100)).toBe(DND_HANDLE_BASE + DND_HANDLE_MAX_DEPTH);
  });

  it('음수 깊이는 0 으로 클램프', () => {
    expect(dndHandleZIndex(-5)).toBe(DND_HANDLE_BASE);
  });
});
