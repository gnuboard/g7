/**
 * useResizeHandles.ts — 캔버스 모서리/변 드래그 리사이즈
 *
 * 선택 노드의 8방향 핸들 드래그 → 포인터 델타(디바이스 scale 반영) → 결과 크기를
 * `width`/`height` 컨트롤의 apply 레시피로 변환해 노드 패치. dnd-kit 이 아니라
 * 경량 포인터 핸들러로 구현한다(리사이즈는 드롭/중첩 판정이 없음).
 *
 * 드래그 결과는 속성 모달의 width/height 컨트롤 현재값과 **양방향 동기**된다 —
 * 같은 노드 속성(같은 컨트롤·레시피)을 보는 두 표면이므로, 둘 다 applyRecipe 로
 * 패치하면 reverseResolve 가 같은 값을 돌려준다.
 *
 * 제약:
 *  - 스펙에 `width`/`height` 컨트롤이 선언되지 않은 컴포넌트는 그 축 핸들 미표시.
 *  - styleProp(연속 px) / classToken·select(옵션 단계 스냅) / propValue 분기.
 *  - min/max 가 선언된 컨트롤은 그 범위로 클램프.
 *
 * @since engine-v1.50.0
 */

import { useCallback, useRef } from 'react';
import type { EditorControlSpec } from '../spec/specTypes';
import type { EditorNode } from '../utils/layoutTreeUtils';
import { applyRecipe } from '../spec/recipeEngine';

export type ResizeHandleKey = 'nw' | 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w';

/** 핸들별 영향 축 — 변(중앙)은 한 축, 모서리는 두 축 */
const HANDLE_AXES: Record<ResizeHandleKey, { width: number; height: number }> = {
  nw: { width: -1, height: -1 },
  n: { width: 0, height: -1 },
  ne: { width: 1, height: -1 },
  e: { width: 1, height: 0 },
  se: { width: 1, height: 1 },
  s: { width: 0, height: 1 },
  sw: { width: -1, height: 1 },
  w: { width: -1, height: 0 },
};

export interface UseResizeHandlesOptions {
  /** 현재 선택 노드 */
  node: EditorNode | null;
  /** width 컨트롤 (스펙) — 없으면 가로 리사이즈 비활성 */
  widthControl: EditorControlSpec | null;
  /** height 컨트롤 (스펙) — 없으면 세로 리사이즈 비활성 */
  heightControl: EditorControlSpec | null;
  /** 디바이스 미리보기 scale (9.2.3) — 포인터 델타 보정 */
  scale: number;
  /**
   * 드래그 시작 시점의 선택 요소 실제 렌더 크기(px) — 호출처(DOM 접근 가능)가 공급.
   *
   * 노드의 `style.width/height` 에 명시적 px 가 없으면(대부분의 요소) 시작 크기를
   * 0 으로 잡아 드래그가 0px 에서 시작하던 결함을 막는다. 본 콜백이
   * `getBoundingClientRect` 기반 현재 크기를 돌려주면 그 값을 시작 크기로 쓴다.
   * 미제공 시 종전대로 style px 파싱 → 0 폴백.
   */
  measureStartSize?: () => { width: number; height: number } | null;
  /** 리사이즈 결과 노드 패치 (move 마다 — 라이브 미리보기) */
  onResize: (patched: EditorNode, axis: 'width' | 'height' | 'both') => void;
  /**
   * 리사이즈 종료(pointerup) 알림 — 드래그가 실제로 노드를 바꿨을 때만 호출.
   * 호출처가 이 시점에 history 를 1회 push 한다(move 마다 push 하면
   * 이력 폭증, 미push 면 undo 스택에 안 쌓임). `patched` 는 드래그 최종 노드,
   * 미변경(델타 0)이면 호출하지 않는다.
   */
  onResizeEnd?: (patched: EditorNode, axis: 'width' | 'height' | 'both') => void;
  /** 드래그 시작/종료 알림 (devtools resizingAxis 상태) */
  onResizeStateChange?: (axis: 'width' | 'height' | 'both' | null) => void;
}

export interface UseResizeHandlesResult {
  /** 활성 핸들 축 — 선택 노드의 width/height 컨트롤 선언 여부 */
  enabledAxes: { width: boolean; height: boolean };
  /** 핸들 pointerdown 핸들러 — ElementOverlay 가 각 핸들에 부착 */
  onHandlePointerDown: (handle: ResizeHandleKey, e: { clientX: number; clientY: number }) => void;
}

/** styleProp width/height 값을 px 숫자로 파싱 (없으면 측정 DOM 으로 폴백 불가 시 0) */
function parsePx(value: unknown): number | null {
  if (typeof value === 'number') return value;
  if (typeof value === 'string') {
    const m = value.match(/^(-?\d+(?:\.\d+)?)px$/);
    if (m) return Number(m[1]);
  }
  return null;
}

/** select/classToken 옵션 스케일에서 목표 px 에 가장 가까운 옵션 value 로 스냅 */
function snapToOption(control: EditorControlSpec, targetPx: number): unknown {
  const options = Array.isArray(control.options) ? (control.options as Array<{ value: unknown }>) : [];
  const scale = Array.isArray((control as { scale?: unknown[] }).scale)
    ? ((control as { scale?: unknown[] }).scale as unknown[])
    : [];
  const candidates: unknown[] = options.length > 0 ? options.map((o) => o.value) : scale;
  if (candidates.length === 0) return undefined;
  // 후보값이 px 로 파싱되면 거리 최소, 아니면 인덱스 비례 스냅
  let best = candidates[0];
  let bestDist = Infinity;
  candidates.forEach((c, i) => {
    const px = parsePx(c) ?? i * 40; // 비-px 옵션은 인덱스 기반 가상 px
    const dist = Math.abs(px - targetPx);
    if (dist < bestDist) {
      bestDist = dist;
      best = c;
    }
  });
  return best;
}

function clamp(value: number, control: EditorControlSpec): number {
  const min = typeof (control as { min?: number }).min === 'number' ? (control as { min?: number }).min! : undefined;
  const max = typeof (control as { max?: number }).max === 'number' ? (control as { max?: number }).max! : undefined;
  let v = value;
  if (min !== undefined) v = Math.max(min, v);
  if (max !== undefined) v = Math.min(max, v);
  return v;
}

/** 컨트롤 apply 타입에 따라 목표 px 를 적용값으로 변환 */
function resolveValueForControl(control: EditorControlSpec, targetPx: number): unknown {
  const apply = control.apply as { type?: string } | undefined;
  const clamped = clamp(Math.max(0, Math.round(targetPx)), control);
  if (apply?.type === 'styleProp') {
    // 연속 px — tokenTemplate 미사용 styleProp 은 `${px}px`
    return `${clamped}px`;
  }
  if (apply?.type === 'classToken' && (apply as { tokenTemplate?: string }).tokenTemplate) {
    // 임의값 클래스 — `${px}px` 를 tokenTemplate 의 {value} 로
    return `${clamped}px`;
  }
  // select/classToken 옵션/scale — 가장 가까운 단계로 스냅
  return snapToOption(control, clamped);
}

export function useResizeHandles(options: UseResizeHandlesOptions): UseResizeHandlesResult {
  const { node, widthControl, heightControl, scale, measureStartSize, onResize, onResizeEnd, onResizeStateChange } = options;

  const enabledAxes = { width: !!widthControl, height: !!heightControl };

  // 드래그 세션 상태 — pointermove/up 핸들러가 참조
  const sessionRef = useRef<{
    handle: ResizeHandleKey;
    startX: number;
    startY: number;
    startW: number;
    startH: number;
    node: EditorNode;
  } | null>(null);

  const onHandlePointerDown = useCallback(
    (handle: ResizeHandleKey, e: { clientX: number; clientY: number }): void => {
      if (!node) return;
      const axes = HANDLE_AXES[handle];
      // 그 축 컨트롤이 없으면 무시
      if (axes.width !== 0 && !widthControl) return;
      if (axes.height !== 0 && !heightControl) return;

      // 시작 크기 — 실측 현재 크기(getBoundingClientRect, 호출처 공급) 우선, 미제공 시
      // style.width/height px, 그것도 없으면 0. style px 가 없는 요소(대부분)에서 0px
      // 부터 리사이즈가 시작되던 결함을 막는다 — 현재 렌더 크기 기준으로
      // 드래그가 시작되어야 자연스럽다.
      const style = (node.props?.style ?? {}) as Record<string, unknown>;
      const measured = measureStartSize?.() ?? null;
      const startW = parsePx(style.width) ?? (measured ? Math.round(measured.width) : 0);
      const startH = parsePx(style.height) ?? (measured ? Math.round(measured.height) : 0);

      sessionRef.current = {
        handle,
        startX: e.clientX,
        startY: e.clientY,
        startW,
        startH,
        node,
      };

      const draggedAxis: 'width' | 'height' | 'both' =
        axes.width !== 0 && axes.height !== 0 ? 'both' : axes.width !== 0 ? 'width' : 'height';
      onResizeStateChange?.(draggedAxis);

      // 드래그 동안 마지막으로 적용된 노드 — pointerup 시 onResizeEnd 로 history push.
      // null 이면 사용자가 핸들만 누르고 움직이지 않은 것(델타 0) → 이력 미기록.
      let lastPatched: EditorNode | null = null;

      const onMove = (ev: PointerEvent): void => {
        const session = sessionRef.current;
        if (!session) return;
        const s = scale || 1;
        const dx = ((ev.clientX - session.startX) / s) * HANDLE_AXES[session.handle].width;
        const dy = ((ev.clientY - session.startY) / s) * HANDLE_AXES[session.handle].height;

        let patched = session.node;
        if (HANDLE_AXES[session.handle].width !== 0 && widthControl) {
          const value = resolveValueForControl(widthControl, session.startW + dx);
          patched = applyRecipe(patched, widthControl, value);
        }
        if (HANDLE_AXES[session.handle].height !== 0 && heightControl) {
          const value = resolveValueForControl(heightControl, session.startH + dy);
          patched = applyRecipe(patched, heightControl, value);
        }
        lastPatched = patched;
        onResize(patched, draggedAxis);
      };

      const onUp = (): void => {
        sessionRef.current = null;
        onResizeStateChange?.(null);
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', onUp);
        // 드래그가 실제로 크기를 바꿨을 때만 이력 1회 push.
        if (lastPatched) onResizeEnd?.(lastPatched, draggedAxis);
      };

      window.addEventListener('pointermove', onMove);
      window.addEventListener('pointerup', onUp);
    },
    [node, widthControl, heightControl, scale, onResize, onResizeEnd, onResizeStateChange],
  );

  return { enabledAxes, onHandlePointerDown };
}
