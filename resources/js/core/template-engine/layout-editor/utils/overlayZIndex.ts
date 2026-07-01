/**
 * overlayZIndex.ts — 편집기 캔버스 오버레이 레이어 z-index 계약
 *
 * 캔버스 위에는 서로 다른 레이어가 겹쳐 떠 있다. 이들이 같은 stacking context
 * (PreviewCanvas 의 g7le-overlay-layer) 안에서 paint 순서/z-index 로 충돌하지
 * 않도록 **밴드(band)** 를 명시한다. 종전에는 어포던스 버튼(+/ⓘ/리사이즈/잠금/네비)이
 * z-index 미지정(≈0)이라, S5b 에서 도입된 드래그 핸들(`20 + depth`)이 항상 위로
 * 올라와 버튼 클릭을 가로채는 회귀가 있었다.
 *
 * 밴드 배치(낮음 → 높음):
 *   - DND_HANDLE_BASE(20) + depth        : 드래그 핸들. 깊은(구체적) 핸들이 위로 와
 *                                          클릭/드래그 시작이 가장 안쪽 요소에 도달
 *                                          (결함 2 "선택 기준 드래그"의 깊이순 정렬).
 *                                          depth 는 DND_HANDLE_MAX_DEPTH 로 클램프해
 *                                          어떤 트리 깊이에서도 어포던스 밴드를 넘지 못함.
 *   - EDIT_LOCK_DIM(90)                   : 속성 편집 중 캔버스 딤/잠금 레이어. 모든
 *                                          드래그 핸들(≤70) 위에 둬, 편집 중 다른 요소의
 *                                          드래그 핸들 클릭으로 선택/이동되던 결함을 막는다.
 *                                          선택 요소는 딤의 "구멍"으로 노출되고 그 어포던스
 *                                          (120)는 딤 위라 계속 조작 가능.
 *   - OVERLAY_AFFORDANCE(120)             : +/ⓘ/리사이즈/잠금/네비 어포던스 버튼.
 *                                          항상 드래그 핸들·딤 위 → 클릭이 버튼에 도달.
 *   - DND_DROP_SLOT(9000)                 : 드래그 중에만 존재하는 드롭 슬롯(hover 판정).
 *   - DND_DRAG_OVERLAY(10000)             : 드래그 고스트(최상위, body 포털).
 *
 * 계약 불변식(테스트로 강제):
 *   DND_HANDLE_BASE + DND_HANDLE_MAX_DEPTH  <  EDIT_LOCK_DIM  <  OVERLAY_AFFORDANCE  <  DND_DROP_SLOT
 *
 * @since engine-v1.50.0
 */

/** 드래그 핸들 base z-index. depth 를 더해 깊은 핸들이 위로 온다(결함 2). */
export const DND_HANDLE_BASE = 20;

/**
 * 드래그 핸들 depth 가산 상한. 핸들 z-index = BASE + min(depth, MAX_DEPTH).
 * 어떤 깊은 트리에서도 핸들 z 가 어포던스 밴드(120)를 넘지 않도록 클램프한다.
 * (BASE 20 + 50 = 70 < 120 — 50단계 중첩까지 깊이순 정렬 보존하면서도 밴드 유지.)
 */
export const DND_HANDLE_MAX_DEPTH = 50;

/**
 * 속성 편집 중 캔버스 딤/잠금 레이어. 모든 드래그 핸들(BASE+MAX_DEPTH=70) 위, 어포던스
 * 밴드(120) 아래. 편집 중 선택 외 요소의 드래그 핸들 클릭으로 선택/이동되던 결함을
 * 막으려면 핸들 위여야 하고, 선택 요소의 어포던스(120)는 딤 위에 유지되어야 한다.
 */
export const EDIT_LOCK_DIM = 90;

/**
 * 어포던스 버튼 밴드(+/ⓘ/리사이즈 핸들/잠금/네비). 모든 드래그 핸들·편집 딤 위.
 * 비-드래그 시점 UI 라 드롭 슬롯(9000) 아래에 둬도 무방(드롭 슬롯은 드래그 중에만 존재).
 */
export const OVERLAY_AFFORDANCE = 120;

/**
 * 표 캔버스 인플레이스 오버레이(셀 선택/거터 레일/병합 도구). 어포던스
 * 밴드(120) 바로 위에 둬, 코어 선택 오버레이(ⓘ/칩/리사이즈)·드래그 핸들이 거터 버튼
 * 클릭을 가로채지 못하게 한다. 드롭
 * 슬롯(9000) 아래 — 드래그 중에는 표 편집 비활성이라 무방.
 */
export const TABLE_INPLACE = 130;

/**
 * 출처 기반 상시 잠금 음영. 확장/base/modal/iteration 편집 모드에서
 * 편집 불가(잠금) 출처 노드(호스트 본체·타 확장)를 상시 어둡게 표시한다. 선택/모달과 무관하게
 * "어디가 편집 가능한 영역인지"를 시각적으로 분리한다(편집 가능 조각만 밝게 노출).
 *
 * pointerEvents:none 인 순수 시각 레이어 — 잠금 강제는 useElementSelection 의 잠금 매트릭스가
 * 담당하므로 클릭을 가로채지 않는다(어포던스/삽입 버튼은 음영 위에서 정상 동작). 드래그 핸들
 * (≤70) 위에 둬 잠긴 노드가 어둡게 덮이되, 어포던스 밴드(120)·자물쇠 배지는 음영 위로 노출.
 */
export const SOURCE_LOCK_DIM = 80;

/** 드롭 슬롯(드래그 중 hover 판정). 모든 핸들/어포던스 위. */
export const DND_DROP_SLOT = 9000;

/** 드래그 고스트(최상위, document.body 포털). */
export const DND_DRAG_OVERLAY = 10000;

/**
 * 트리 깊이순 드래그 핸들 z-index 계산. depth 는 DND_HANDLE_MAX_DEPTH 로 클램프해
 * 어포던스 밴드 침범을 방지한다.
 *
 * @param depth 핸들 노드의 트리 깊이(`.children.` 세그먼트 수)
 * @return 핸들에 적용할 z-index
 */
export function dndHandleZIndex(depth: number): number {
  const clamped = depth < 0 ? 0 : depth > DND_HANDLE_MAX_DEPTH ? DND_HANDLE_MAX_DEPTH : depth;
  return DND_HANDLE_BASE + clamped;
}
