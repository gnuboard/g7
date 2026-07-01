/**
 * SourceLockDimLayer.tsx — 출처 기반 역 스포트라이트 잠금 음영 레이어
 *
 * 별도 편집 모드(extension/base/modal/iteration_item)에서 **편집 가능한 영역만 밝게 두고
 * 나머지 전체를 한 장의 음영으로 덮는다**(역 스포트라이트). 속성 편집 모달의 딤
 * (`EditorCanvasOverlay.renderEditLock`)이 선택 요소만 "구멍"으로 남기고 나머지를 통째
 * 어둡게 하는 것과 동일한 시각 효과를, 출처 기준(편집 모드별 편집 가능 노드)으로 적용한다.
 *
 * 확장 편집 모드 예: 편집 중 확장 조각 노드(`__source.extensionId === 현재 확장`)만 밝은
 * 구멍으로 노출되고, 호스트 본체(base/route/partial)·타 확장은 한 장의 음영 아래로 어두워진다.
 * base 편집 모드: base/partial 본체가 구멍, route 콘텐츠·확장이 음영. modal: 모달 children 이
 * 구멍, 확장이 음영.
 *
 * 음영은 `pointerEvents:none` 인 순수 시각 레이어다(잠금 강제는 useElementSelection 의 잠금
 * 매트릭스가 담당). 구멍(편집 가능 영역)도 음영을 덮지 않으므로 그 안의 어포던스·드래그
 * 핸들·삽입 버튼이 정상 동작한다.
 *
 * 구멍 계산 — "편집 가능 영역에 구멍" 정책:
 *   편집 가능(미잠금) 노드 중 **가장 얕은(=가장 바깥) 편집 가능 노드**의 박스를 구멍으로
 *   삼는다. 편집 가능 노드의 자손은 그 구멍 안에 포함되므로 따로 구멍을 뚫지 않는다. 호스트
 *   깊은 곳에 흩어져 주입된 확장도 각 진입점 박스가 구멍이 되어 정확히 그 조각만 밝게 노출된다.
 *
 * 음영 합성 — 다중 구멍 4사각형:
 *   구멍이 1개면 renderEditLock 과 동일한 상/하/좌/우 4사각형으로 프레임을 덮는다. 구멍이
 *   여럿이면 프레임을 세로 슬라이스로 나눠 각 구멍 행을 비우는 사각형 집합으로 덮는다. 정확한
 *   구멍 윤곽 대신 "구멍 바운딩 박스 외부"를 덮는 보수적 근사 — 편집 가능 영역은 항상 밝게
 *   유지되고(절대 덮지 않음) 그 외는 어두워진다.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useState } from 'react';
import { SOURCE_LOCK_DIM } from '../utils/overlayZIndex';
import {
  type EditorNode,
  type ComponentPath,
  isNodeLocked,
  serializeEditorPath,
} from '../utils/layoutTreeUtils';

export type DimEditMode = 'route' | 'base' | 'modal' | 'extension' | 'iteration_item';

export interface SourceLockDimLayerProps {
  /** 캔버스 프레임 DOM (data-editor-path 노드 측정 대상) */
  frameEl: HTMLElement | null;
  /** 현재 문서 components 트리 (잠금 판정용) */
  components: EditorNode[];
  /** 현재 편집 모드 */
  editMode: DimEditMode;
  /** 확장 편집 모드일 때 편집 중 확장 PK */
  currentExtensionId?: number;
  /**
   * path 기반 편집 대상 모드(modal / iteration_item)에서 편집 가능 노드의 트리
   * 인덱스 경로. 이 노드 박스 하나만 구멍으로 노출하고 나머지 호스트 전체를 음영으로 덮는다.
   * extension/base 모드에서는 무시(출처 기반 walk 사용).
   */
  editableRootPath?: ComponentPath | null;
}

interface Rect {
  left: number;
  top: number;
  width: number;
  height: number;
}

/**
 * components 트리에서 "편집 가능 구멍 path" 집합을 계산한다 — 미잠금(편집 가능) 중 가장
 * 얕은 노드. 잠긴 노드는 자손에 편집 가능 노드가 있으면 내려가고, 없으면 무시(음영 대상).
 *
 * route 모드는 전체가 편집 대상이라(확장만 블랙박스 잠금) 역 스포트라이트가 부적합 → 빈
 * 배열을 반환해 음영을 끄고, 확장 블랙박스는 어포던스/개별 처리에 맡긴다.
 */
export function computeEditableHolePaths(
  components: EditorNode[],
  editMode: DimEditMode,
  currentExtensionId?: number,
  editableRootPath?: ComponentPath | null,
): ComponentPath[] {
  // route 모드: 호스트(라우트 콘텐츠) 전체가 편집 대상 — 역 스포트라이트 미적용.
  if (editMode === 'route') return [];

  // path 기반 편집 대상 모드 (modal / iteration_item). 편집 단위가 노드 출처가
  // 아니라 **호스트 트리의 특정 위치**(모달 노드 / iteration 원본 노드)다. 그 한 위치만 구멍으로
  // 노출하고 나머지 호스트 전체를 음영으로 덮는다(확장 편집과 동형 인플레이스). editableRootPath
  // 가 주어지면 출처 기반 walk 대신 그 박스 하나만 구멍으로 삼는다.
  if ((editMode === 'modal' || editMode === 'iteration_item')) {
    return editableRootPath && editableRootPath.length > 0 ? [editableRootPath] : [];
  }

  const holes: ComponentPath[] = [];
  const walk = (nodes: EditorNode[], prefix: ComponentPath): void => {
    nodes.forEach((node, i) => {
      const path = [...prefix, i];
      const locked = isNodeLocked(node, editMode, currentExtensionId);
      if (!locked) {
        // 가장 얕은 편집 가능 노드 — 구멍. 자손은 구멍 안이므로 미하강.
        holes.push(path);
        return;
      }
      // 잠금 노드 — 편집 가능 자손이 있으면 내려가 그 구멍을 찾는다.
      const children = Array.isArray(node.children) ? (node.children as EditorNode[]) : [];
      if (children.length > 0) walk(children, path);
    });
  };
  walk(components, []);
  return holes;
}

/** path(인덱스 경로)를 DynamicRenderer 의 data-editor-path 문자열(`0.children.2`)로 직렬화. */
function toDotPath(path: ComponentPath): string {
  return serializeEditorPath(path);
}

/** 속성 선택자용 안전 이스케이프 (CSS.escape 폴백). */
function cssEscapeAttr(value: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(value);
  }
  return value.replace(/["\\]/g, '\\$&');
}

/**
 * 출처 잠금 역 스포트라이트 음영 레이어. frameEl 의 data-editor-path 노드를 측정해 편집 가능
 * 영역을 구멍으로 남기고 나머지 전체를 한 장의 음영으로 덮는다. 편집 가능 영역이 없거나 route
 * 모드면 아무것도 렌더하지 않는다.
 */
interface DimGeometry {
  frame: Rect;
  /** 편집 가능 구멍 박스 (frame-local). 각 박스만 정밀하게 비운다. */
  holes: Rect[];
}

export function SourceLockDimLayer({
  frameEl,
  components,
  editMode,
  currentExtensionId,
  editableRootPath,
}: SourceLockDimLayerProps): React.ReactElement | null {
  const [geo, setGeo] = useState<DimGeometry | null>(null);
  // 고유 mask id — 동시 마운트(여러 캔버스) 충돌 방지. 렌더 1회 고정.
  const [maskId] = useState(() => `g7le-dim-mask-${Math.floor(Math.random() * 1e9)}`);

  useEffect(() => {
    if (!frameEl) {
      setGeo(null);
      return;
    }

    let raf = 0;
    const measure = (): void => {
      const holePaths = computeEditableHolePaths(components, editMode, currentExtensionId, editableRootPath);
      // route 모드/구멍 없음 → 음영 끔(전체 잠금 화면을 캄캄하게 덮지 않는다. 잠금 강제는
      // 선택 매트릭스가 담당).
      if (holePaths.length === 0) {
        setGeo(null);
        return;
      }
      const frameRect = frameEl.getBoundingClientRect();
      const frame: Rect = { left: 0, top: 0, width: frameRect.width, height: frameRect.height };
      const holes: Rect[] = [];
      for (const path of holePaths) {
        const dot = toDotPath(path);
        // 1차: 순수 path DOM. iteration 원본 노드(반복 항목 편집 대상)는 DynamicRenderer 가
        // 인스턴스(`{path}.iteration.N`)로만 렌더하고 순수 path 노드는 DOM 에 없다 → 2차로
        // 그 노드의 모든 인스턴스 박스를 합집합(union)해 구멍으로 삼는다(iteration 음영
        // 구멍이 안 잡혀 딤이 통째로 꺼지던 결함). sortable 인스턴스도 동일.
        const direct = frameEl.querySelector<HTMLElement>(`[data-editor-path="${cssEscapeAttr(dot)}"]`);
        let box: Rect | null = null;
        if (direct) {
          const r = direct.getBoundingClientRect();
          if (r.width > 0 && r.height > 0) {
            box = { left: r.left, top: r.top, width: r.width, height: r.height };
          }
        }
        if (!box) {
          // 노드 루트에 data-editor-path 가 없을 때 합집합(union) 박스로 폴백:
          //  - iteration/sortable 인스턴스(`.iteration.N` / `.sortable.N`)
          //  - 직속/하위 자손(`.children.*`) — 모달 노드(Modal composite)는 루트에 editor-path
          //  가 안 붙고 children 만 붙는다.
          //    자손 박스 union 으로 모달 영역을 구멍으로 삼는다.
          const insts = Array.from(
            frameEl.querySelectorAll<HTMLElement>(
              `[data-editor-path^="${cssEscapeAttr(dot)}.iteration."], [data-editor-path^="${cssEscapeAttr(dot)}.sortable."], [data-editor-path^="${cssEscapeAttr(dot)}.children."]`,
            ),
          );
          let minL = Infinity, minT = Infinity, maxR = -Infinity, maxB = -Infinity;
          for (const inst of insts) {
            const r = inst.getBoundingClientRect();
            if (r.width <= 0 || r.height <= 0) continue;
            minL = Math.min(minL, r.left); minT = Math.min(minT, r.top);
            maxR = Math.max(maxR, r.right); maxB = Math.max(maxB, r.bottom);
          }
          if (maxR > minL && maxB > minT) {
            box = { left: minL, top: minT, width: maxR - minL, height: maxB - minT };
          }
          // 모달 패널 확장 — Modal composite 는 루트에 editor-path 가 없고 children 만 있어
          // 위 union 이 패널 안쪽 콘텐츠 행만 잡는다(제목/X버튼/패딩 누락). 자손이 속한
          // 모달 패널([role="dialog"] 또는 fixed 패널)까지 구멍을 넓혀 패널 전체를 편집 가능
          // 영역으로 노출한다.
          if (box && insts.length > 0) {
            const panel = insts[0].closest<HTMLElement>('[role="dialog"]');
            if (panel && frameEl.contains(panel)) {
              const pr = panel.getBoundingClientRect();
              if (pr.width > 0 && pr.height > 0) {
                box = { left: pr.left, top: pr.top, width: pr.width, height: pr.height };
              }
            }
          }
        }
        if (!box) continue;
        holes.push({
          left: box.left - frameRect.left,
          top: box.top - frameRect.top,
          width: box.width,
          height: box.height,
        });
      }
      // 구멍을 하나도 못 측정했으면(DOM 미부착 등) 음영을 켜지 않는다(전체 캄캄 방지).
      if (holes.length === 0) {
        setGeo(null);
        return;
      }
      setGeo({ frame, holes });
    };

    const schedule = (): void => {
      if (raf) cancelAnimationFrame(raf);
      raf = requestAnimationFrame(measure);
    };

    schedule();
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(schedule) : null;
    ro?.observe(frameEl);
    const mo = typeof MutationObserver !== 'undefined' ? new MutationObserver(schedule) : null;
    mo?.observe(frameEl, { childList: true, subtree: true, attributes: true });
    window.addEventListener('scroll', schedule, true);
    window.addEventListener('resize', schedule);

    return () => {
      if (raf) cancelAnimationFrame(raf);
      ro?.disconnect();
      mo?.disconnect();
      window.removeEventListener('scroll', schedule, true);
      window.removeEventListener('resize', schedule);
    };
    // editableRootPath 는 배열 — 매 렌더 새 참조 가능성. dot string 으로 안정화해 deps 에 반영
    // (modal/iteration 모드에서 편집 대상 위치 변경 시 음영 구멍 재측정).
  }, [frameEl, components, editMode, currentExtensionId, editableRootPath?.join('.') ?? '']);

  if (!geo || geo.holes.length === 0) return null;

  const { frame, holes } = geo;

  // 상호작용 차단 레이어 — modal / iteration_item 처럼 **편집 대상이 단일
  // 영역**인 모드에서는 딤(잠긴 호스트) 위 클릭/드래그/포커스를 물리적으로 삼켜 호스트의 라이브
  // 버튼·입력·드래그가 동작하지 않게 한다.
  // 구멍(편집 대상) 바운딩 박스 바깥을 상/하/좌/우 4사각형으로 덮는다(단일 구멍이라 정확).
  // extension/base 모드는 편집 가능 조각이 여러 곳에 흩어질 수 있어(구멍 사이 호스트 콘텐츠가
  // 정당한 표시 영역) 이 물리 차단을 적용하지 않는다 — 그 모드는 선택 매트릭스만으로 잠근다.
  const blocksInteraction = editMode === 'modal' || editMode === 'iteration_item';
  let blockerRects: Rect[] = [];
  if (blocksInteraction) {
    // 모든 구멍의 union 바운딩 박스.
    let minL = Infinity, minT = Infinity, maxR = -Infinity, maxB = -Infinity;
    for (const h of holes) {
      minL = Math.min(minL, h.left); minT = Math.min(minT, h.top);
      maxR = Math.max(maxR, h.left + h.width); maxB = Math.max(maxB, h.top + h.height);
    }
    const clamp = (v: number, lo: number, hi: number) => Math.max(lo, Math.min(hi, v));
    const hl = clamp(minL, 0, frame.width);
    const ht = clamp(minT, 0, frame.height);
    const hr = clamp(maxR, 0, frame.width);
    const hb = clamp(maxB, 0, frame.height);
    // 상/하/좌/우 4밴드 (구멍 union 바깥 전체) — 폭/높이 0 인 밴드는 제외.
    blockerRects = [
      { left: 0, top: 0, width: frame.width, height: ht }, // 상
      { left: 0, top: hb, width: frame.width, height: frame.height - hb }, // 하
      { left: 0, top: ht, width: hl, height: hb - ht }, // 좌
      { left: hr, top: ht, width: frame.width - hr, height: hb - ht }, // 우
    ].filter((r) => r.width > 0 && r.height > 0);
  }

  const swallow = (e: React.SyntheticEvent): void => {
    e.preventDefault();
    e.stopPropagation();
  };

  // SVG mask 역 스포트라이트 — 흰색(불투명) 사각형 = 음영 표시, 검은색 구멍 사각형 = 도려냄.
  // 한 장의 dim 사각형에 mask 를 적용해 어느 위치·개수의 구멍이든 정확히 그 박스만 밝게
  // 비운다(4사각형 band 의 구멍 침범 결함 제거 — "조각마다 개별 정밀").
  return (
    <>
    {/* 상호작용 차단 밴드 — 시각 딤(SVG)과 별개의 투명 레이어. 딤 위 모든 포인터/드래그를 삼킨다. */}
    {blockerRects.map((r, i) => (
      <div
        key={i}
        className="g7le-source-lock-block"
        data-testid="g7le-source-lock-block"
        onClickCapture={swallow}
        onPointerDownCapture={swallow}
        onMouseDownCapture={swallow}
        onDragStartCapture={swallow}
        style={{
          position: 'absolute',
          left: r.left,
          top: r.top,
          width: r.width,
          height: r.height,
          zIndex: SOURCE_LOCK_DIM,
          pointerEvents: 'auto',
          cursor: 'not-allowed',
          background: 'transparent',
        }}
      />
    ))}
    <svg
      className="g7le-source-lock-dim"
      data-testid="g7le-source-lock-dim"
      width={frame.width}
      height={frame.height}
      style={{
        position: 'absolute',
        left: 0,
        top: 0,
        width: frame.width,
        height: frame.height,
        zIndex: SOURCE_LOCK_DIM,
        pointerEvents: 'none',
      }}
    >
      <defs>
        <mask id={maskId}>
          {/* 전체 = 음영 표시(흰색) */}
          <rect x={0} y={0} width={frame.width} height={frame.height} fill="white" />
          {/* 편집 가능 구멍 = 도려냄(검은색) — 각 조각 박스만 정밀하게 비운다. */}
          {holes.map((h, i) => (
            <rect
              key={i}
              data-testid="g7le-source-lock-dim-hole"
              x={h.left}
              y={h.top}
              width={h.width}
              height={h.height}
              rx={4}
              fill="black"
            />
          ))}
        </mask>
      </defs>
      {/* 속성 모달 딤과 동일 톤(rgba(15,23,42,0.45)) — mask 로 구멍만 비워 편집 조각만 밝게. */}
      <rect
        data-testid="g7le-source-lock-dim-box"
        x={0}
        y={0}
        width={frame.width}
        height={frame.height}
        fill="rgba(15, 23, 42, 0.45)"
        mask={`url(#${maskId})`}
      />
    </svg>
    </>
  );
}
