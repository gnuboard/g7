/**
 * overlayGeometry.ts — 오버레이 좌표 계산
 *
 * 선택/hover 박스의 절대 위치 계산 + 스크롤/리사이즈 시 박스 좌표 재계산.
 *
 * - `measureOverlay(targetEl, frameEl)`: 대상 DOM 의 viewport 기준 사각형을
 *   frame(캔버스 컨테이너) 좌표계로 변환해 반환.
 * - `subscribeOverlayTracking(callback, frameEl)`: 스크롤/리사이즈/transform/DOM 변경
 *   시 callback 을 호출하도록 구독(window resize/scroll + frame ResizeObserver +
 *   frame MutationObserver(childList/subtree, rAF debounce)). 반환 함수는 구독 해제.
 *
 * 본 모듈은 순수 DOM 계산 — 편집기 도메인 상태/devtools 호출 없음.
 *
 * @since engine-v1.50.0
 */

export interface OverlayBox {
  /** frame 컨테이너 기준 left (px) */
  left: number;
  /** frame 컨테이너 기준 top (px) */
  top: number;
  /** 너비 (px) */
  width: number;
  /** 높이 (px) */
  height: number;
  /** preview scale 이 1 이 아닐 때 외부에서 사용. 기본 1 */
  scale: number;
}

/**
 * 대상 DOM 사각형을 frame(편집 캔버스) 좌표계로 변환한다.
 *
 * frame 이 transform: scale(N) 이 적용된 경우, getBoundingClientRect 는 이미
 * 시각 사이즈를 반환하므로 추가 보정은 외부 호출자가 처리한다. 본 함수는
 * 단순히 frame 기준 상대 좌표를 반환한다.
 *
 * @param targetEl 측정 대상 DOM
 * @param frameEl 기준 frame DOM (편집 캔버스 컨테이너). null 이면 viewport 기준.
 * @returns 좌표 박스 또는 null (대상이 0 사이즈 / DOM 미연결)
 */
export function measureOverlay(targetEl: Element | null, frameEl: Element | null): OverlayBox | null {
  if (!targetEl) return null;
  const targetRect = targetEl.getBoundingClientRect();
  // 0 사이즈 노드(display:none 등) 는 측정 결과를 의미 있게 반환할 수 없다.
  if (targetRect.width === 0 && targetRect.height === 0) return null;

  if (!frameEl) {
    return {
      left: targetRect.left,
      top: targetRect.top,
      width: targetRect.width,
      height: targetRect.height,
      scale: 1,
    };
  }

  const frameRect = frameEl.getBoundingClientRect();
  return {
    left: targetRect.left - frameRect.left,
    top: targetRect.top - frameRect.top,
    width: targetRect.width,
    height: targetRect.height,
    scale: 1,
  };
}

/**
 * frame 기준 상대 박스(`measureOverlay` 결과)가 frame 의 가시 영역과 겹치는지 판정.
 *
 * frame 은 `overflow: hidden` 으로 frame 밖 자손을 시각적으로 클리핑하지만,
 * `getBoundingClientRect`(따라서 measureOverlay 결과)는 클리핑과 무관하게 노드의
 * 레이아웃 좌표를 그대로 반환한다. 그 결과 닫힌 모바일 드로어(`fixed translate-x-full`)
 * 처럼 frame 밖으로 밀려 시각적으로 가려진 노드도 박스를 가지며, 오버레이 레이어
 * (`overflow: visible`)가 그 자리에 드래그 핸들/점선 묶음/어포던스를 그려 편집기
 * 회색 배경에 노출시킨다. 본 함수로 frame 가시 영역과 겹치지 않는 박스를 걸러내
 * 가려진 노드에는 오버레이를 그리지 않도록 한다. (모바일 프리뷰 가려진
 * 드로어 항목의 dnd 핸들/점선 노출 회귀)
 *
 * 박스 좌표는 frame 기준 상대값이므로 frame 가시 영역은 `[0, frameWidth] ×
 * [0, frameHeight]`. 부분 교차도 가시로 본다(가장자리에 걸친 정상 노드 보존).
 *
 * @param box measureOverlay 결과 박스 (frame 기준 상대 좌표). null 이면 false.
 * @param frameEl 기준 frame DOM. null 이면 판정 불가로 true(보수적 — 필터 비적용).
 * @returns frame 가시 영역과 겹치면 true
 */
export function boxIntersectsFrame(box: OverlayBox | null, frameEl: Element | null): boolean {
  if (!box) return false;
  if (!frameEl) return true;
  const frameRect = frameEl.getBoundingClientRect();
  // 부동소수/서브픽셀 여유 1px. 완전히 밖(우/좌/하/상)이면 제외.
  const eps = 1;
  if (box.left >= frameRect.width - eps) return false; // 우측 밖
  if (box.left + box.width <= eps) return false; // 좌측 밖
  if (box.top >= frameRect.height - eps) return false; // 하단 밖
  if (box.top + box.height <= eps) return false; // 상단 밖
  return true;
}

/**
 * 스크롤/리사이즈/DOM 변경 시 callback 을 호출하도록 구독.
 *
 * frame 내부 스크롤도 잡기 위해 frame 의 ancestor chain 에 capture 단계 스크롤
 * 리스너를 단다. ResizeObserver 는 frame 자체에 부착해 크기 변화도 감지.
 *
 * @param callback 좌표 재계산을 트리거할 콜백
 * @param frameEl 기준 frame DOM
 * @returns 구독 해제 함수
 */
export function subscribeOverlayTracking(
  callback: () => void,
  frameEl: Element | null
): () => void {
  if (typeof window === 'undefined') {
    return () => {};
  }

  const onScrollOrResize = (): void => {
    callback();
  };

  window.addEventListener('scroll', onScrollOrResize, true);
  window.addEventListener('resize', onScrollOrResize);

  let resizeObserver: ResizeObserver | null = null;
  if (frameEl && typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => callback());
    resizeObserver.observe(frameEl);
  }

  // frame 내부 DOM 변경(childList/subtree) 감지 — 데이터소스 fetch 완료 후 DynamicRenderer 가
  // iteration 카드/리스트를 뒤늦게 렌더하면 frame 크기·components prop 이 안 바뀌어 scroll/
  // resize/ResizeObserver 만으로는 recompute 가 트리거되지 않는다. 그 결과 이전 레이아웃의
  // 드래그 핸들(stale)이 남아 새 데이터 영역 위를 z-index 로 덮고 클릭을 가로채 "카드 클릭해도
  // 선택 안 됨" 결함이 발생했다. MutationObserver 로
  // DOM 변경 시 재계산을 트리거해 stale 핸들을 제거한다.
  //
  // rAF debounce: 한 프레임의 다중 변경(자식 N개 추가 등)을 1회 callback 으로 묶고, callback
  // 이 일으킬 수 있는 후속 DOM 변경과의 동기 재발화(무한 루프)를 차단한다.
  let mutationObserver: MutationObserver | null = null;
  let rafId: number | null = null;
  const hasRaf = typeof requestAnimationFrame !== 'undefined';
  const scheduleFromMutation = (): void => {
    if (!hasRaf) {
      callback();
      return;
    }
    if (rafId !== null) return;
    rafId = requestAnimationFrame(() => {
      rafId = null;
      callback();
    });
  };
  if (frameEl && typeof MutationObserver !== 'undefined') {
    mutationObserver = new MutationObserver(scheduleFromMutation);
    mutationObserver.observe(frameEl, { childList: true, subtree: true });
  }

  return () => {
    window.removeEventListener('scroll', onScrollOrResize, true);
    window.removeEventListener('resize', onScrollOrResize);
    if (resizeObserver) {
      resizeObserver.disconnect();
      resizeObserver = null;
    }
    if (mutationObserver) {
      mutationObserver.disconnect();
      mutationObserver = null;
    }
    if (rafId !== null && hasRaf && typeof cancelAnimationFrame !== 'undefined') {
      cancelAnimationFrame(rafId);
      rafId = null;
    }
  };
}

/**
 * 작은 박스 판정 임계값(px). 박스의 width 또는 height 가 이 값 미만이면
 * 오버레이 버튼이 박스 모서리/위/아래에서 서로 겹친다.
 * 44px 은 권장 최소 터치 타깃 크기.
 */
export const OVERLAY_SMALL_BOX_THRESHOLD = 44;

/**
 * 오버레이 어포던스 버튼 배치 전략.
 *
 * - 'inside': 기존 배치 — 박스가 충분히 크면 ⓘ 는 우상단 모서리, lock/nav
 *   어포던스는 박스 위/아래 가장자리.
 * - 'outside': 작은 박스 — 버튼이 겹치므로 박스 **우측 바깥**에 세로로 분산.
 *   ⓘ 는 박스 우상단 바깥, lock 은 그 아래, nav 는 또 그 아래.
 */
export type OverlayAffordancePlacement = 'inside' | 'outside';

/**
 * 박스 크기 기준 어포던스 배치 전략 판정.
 *
 * scale 이 적용된 경우 시각 크기(width*scale, height*scale)로 판정해야 실제
 * 화면에서의 겹침을 정확히 반영한다.
 *
 * @param box 선택 박스 (null 이면 'inside')
 * @returns 배치 전략
 */
export function resolveAffordancePlacement(
  box: OverlayBox | null
): OverlayAffordancePlacement {
  if (!box) return 'inside';
  const scale = box.scale || 1;
  const visualWidth = box.width * scale;
  const visualHeight = box.height * scale;
  if (
    visualWidth < OVERLAY_SMALL_BOX_THRESHOLD ||
    visualHeight < OVERLAY_SMALL_BOX_THRESHOLD
  ) {
    return 'outside';
  }
  return 'inside';
}

/** 삽입(+) 어포던스 1버튼 크기(px) — InsertionAffordances 의 각 + 버튼은 24×24. */
export const INSERTION_AFFORDANCE_BUTTON = 24;
/**
 * 작은 박스에서 버튼 안쪽 모서리를 박스 가장자리에서 떨어뜨리는 기본 여백(px).
 * 박스에서 살짝만 띄워 시각적으로 가깝게 붙인다. 대각 버튼끼리의 비겹침은
 * resolveInsertionCrossOffsets 가 박스 직교 축 크기를 반영해 동적으로 추가 보정한다.
 */
export const INSERTION_AFFORDANCE_SMALL_GAP = 6;

/**
 * 작은 박스에서 4방향 삽입(+) 버튼이 박스 중심 기준 십자(상/하/좌/우)로
 * 충분히 벌어진 오프셋. 방향 의미(위=above, 아래=below, 좌=left, 우=right)는
 * 유지하되, 작은 박스에서 고정 -12 오프셋이라 서로/박스와 겹치는 문제를 해소.
 *
 * 각 방향 버튼을 박스 가장자리에서 (버튼크기/2 + 여백) 만큼 더 바깥으로 민다.
 * 박스 중심 기준 대칭이라 above/below 는 세로로, left/right 는 가로로 벌어져
 * 십자 형태가 되고 서로 겹치지 않는다. (중심에서
 * 간격을 띄워 겹침 제거, 세로 일렬 분산 아님)
 *
 * - `above`: 박스 위쪽 바깥, 가로 중앙
 * - `below`: 박스 아래쪽 바깥, 가로 중앙
 * - `left`: 박스 왼쪽 바깥, 세로 중앙
 * - `right`: 박스 오른쪽 바깥, 세로 중앙
 *
 * @param box 선택 박스 (frame 기준 좌표)
 * @returns 각 방향 버튼의 박스 기준 절대 좌표(left/top, px) — 버튼 24×24 중심 정렬
 */
export interface InsertionCrossOffsets {
  above: { left: number; top: number };
  below: { left: number; top: number };
  left: { left: number; top: number };
  right: { left: number; top: number };
}

export function resolveInsertionCrossOffsets(box: OverlayBox): InsertionCrossOffsets {
  const half = INSERTION_AFFORDANCE_BUTTON / 2; // 12
  const cx = box.width / 2; // 박스 가로 중심
  const cy = box.height / 2; // 박스 세로 중심

  // push = 박스 가장자리에서 버튼 중심까지의 거리.
  // (1) 기본: half + gap — 박스에서 살짝만 띄워 시각적으로 가깝게 붙인다.
  // (2) 대각 보정: above(가로 중앙)·left(세로 중앙) 같은 직교 버튼쌍이 박스 모서리
  //     근처에서 겹치지 않으려면 더 작은 축(min(cx,cy)) 기준 한 축 분리를 보장해야 한다.
  //     above 하단과 left 우단 비겹침 조건 → push >= 2*half - min(cx,cy).
  //     박스가 클수록 (1) 만으로 충분, 아주 작은 정사각형일수록 (2) 가 지배.
  const base = half + INSERTION_AFFORDANCE_SMALL_GAP;
  const diagonalSafe = 2 * half - Math.min(cx, cy) + INSERTION_AFFORDANCE_SMALL_GAP;
  const push = Math.max(base, diagonalSafe);

  return {
    // 가로 중앙 정렬(left = cx - half), 박스 위쪽으로 push 만큼 (top = -push - half)
    above: { left: cx - half, top: -push - half },
    below: { left: cx - half, top: box.height + push - half },
    left: { left: -push - half, top: cy - half },
    right: { left: box.width + push - half, top: cy - half },
  };
}

/**
 * 부모 컨테이너의 computed style 을 읽어 layout flow 종류를 판정한다.
 *
 * 본 함수는 + 버튼 4방향 결정의 1차 근거 — className
 * 토큰이 아닌 computed `display` / `flex-direction` / `flex-wrap` 만 사용해
 * Tailwind/Bootstrap/순수 CSS 환경에서 동일하게 동작한다.
 */
export interface ContainerLayoutFlow {
  /**
   * 'block' | 'flex_row_single' | 'flex_row_wrap' | 'flex_column_single' | 'flex_column_wrap'
   * | 'grid_single_row' | 'grid_single_column' | 'grid_2d' | 'unknown'
   *
   * grid_2d 는 grid-template-columns/rows 가 모두 단일 트랙이 아닌(=여러 행·열) 케이스.
   * 자식 사각형 위치로 단일 행/단일 열을 추론한다.
   */
  kind:
    | 'block'
    | 'flex_row_single'
    | 'flex_row_wrap'
    | 'flex_column_single'
    | 'flex_column_wrap'
    | 'grid_single_row'
    | 'grid_single_column'
    | 'grid_2d'
    | 'unknown';
  /** computed display 값 (block / flex / inline-flex / grid 등) */
  display: string;
  /** flex-direction (flex 일 때만 의미 있음) */
  flexDirection: string;
  /** flex-wrap (flex 일 때만 의미 있음) */
  flexWrap: string;
}

/**
 * `getComputedStyle` 기반 부모 layout flow 판정.
 *
 * SSR/테스트 환경에서 window 가 없거나 parentEl 이 null 인 경우 `unknown` 반환.
 */
export function detectContainerLayoutFlow(parentEl: Element | null): ContainerLayoutFlow {
  if (!parentEl || typeof window === 'undefined' || typeof window.getComputedStyle !== 'function') {
    return { kind: 'unknown', display: '', flexDirection: '', flexWrap: '' };
  }

  // `display: contents` wrapper 처리 — wrapper 자체는 layout 에서 사라지고 자식이
  // grandparent 의 직접 자식처럼 배치된다. 시각적 부모를 따라가기 위해 첫 비-`contents`
  // 조상의 computed style 을 본다. 본 보정 없으면 grid/flex 자식인데도 block 으로 폴백.
  let effectiveParent: Element = parentEl;
  let parentDisplay = (window.getComputedStyle(effectiveParent).display ?? '').toLowerCase();
  while (parentDisplay === 'contents' && effectiveParent.parentElement) {
    effectiveParent = effectiveParent.parentElement;
    parentDisplay = (window.getComputedStyle(effectiveParent).display ?? '').toLowerCase();
  }

  const computed = window.getComputedStyle(effectiveParent);
  const display = (computed.display ?? '').toLowerCase();
  const flexDirection = (computed.flexDirection ?? '').toLowerCase();
  const flexWrap = (computed.flexWrap ?? '').toLowerCase();

  const isFlex = display === 'flex' || display === 'inline-flex';
  const isGrid = display === 'grid' || display === 'inline-grid';

  if (isGrid) {
    // grid container — 자식 사각형 top 으로 단일 행/단일 열/2D 판정.
    // single_row: 모든 자식의 top 이 같음 → 한 줄에 가로 배치 (flex-row 와 의미 동등)
    // single_column: 모든 자식의 left 가 같음 → 한 칸에 세로 배치 (flex-column 과 의미 동등)
    // 그 외: 2D — 4방향 모두 활성
    const children = Array.from(parentEl.children).filter(
      (c) => (c as HTMLElement).offsetParent !== null || (c as HTMLElement).getClientRects().length > 0
    );
    if (children.length <= 1) {
      // 자식 1개 이하 — grid_2d (4방향) 로 보수적 분류
      return { kind: 'grid_2d', display, flexDirection, flexWrap };
    }
    const rects = children.map((c) => c.getBoundingClientRect());
    const tops = new Set(rects.map((r) => Math.round(r.top)));
    const lefts = new Set(rects.map((r) => Math.round(r.left)));
    if (tops.size === 1) {
      return { kind: 'grid_single_row', display, flexDirection, flexWrap };
    }
    if (lefts.size === 1) {
      return { kind: 'grid_single_column', display, flexDirection, flexWrap };
    }
    return { kind: 'grid_2d', display, flexDirection, flexWrap };
  }

  if (!isFlex) {
    return { kind: 'block', display, flexDirection, flexWrap };
  }

  const isWrap = flexWrap === 'wrap' || flexWrap === 'wrap-reverse';
  const isColumn = flexDirection === 'column' || flexDirection === 'column-reverse';

  if (isColumn) {
    return { kind: isWrap ? 'flex_column_wrap' : 'flex_column_single', display, flexDirection, flexWrap };
  }
  return { kind: isWrap ? 'flex_row_wrap' : 'flex_row_single', display, flexDirection, flexWrap };
}
