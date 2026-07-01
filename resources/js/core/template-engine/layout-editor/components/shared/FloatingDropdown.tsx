// e2e:allow 공용 부유 드롭다운 — 위치 자동 보정(flip/clamp)은 getBoundingClientRect/뷰포트 측정 의존이라
// jsdom(좌표 0) 에서는 분기만 검증 가능. 경계 넘침 flip/clamp 실측은 Chrome MCP 매트릭스. 렌더·
// 외부클릭/ESC 닫힘·anchor 토글은 RTL(FloatingDropdown.test)로 검증.
/**
 * FloatingDropdown.tsx — 앵커 기준 부유 드롭다운(위치 자동 보정)
 *
 * 토글(앵커) 아래에 패널을 **부유**(`position:fixed`)로 띄우되, 패널이 뷰포트(또는 제한 경계)를
 * 넘치면 스스로 **뒤집고(flip)·끌어당긴다(clamp)**. 진입점은 정렬(left/right)을 지정하지 않는다 —
 * 어디에 놓이든 잘리지 않는다.
 *
 * 종전엔 각 피커가 `position:absolute; right:0`(또는 left:0)을 하드코딩해, 토글이 화면 가장자리에
 * 가까우면 반대편으로 잘렸다. 본 컴포넌트는 그 정렬 결정을 **측정 기반 자동 보정**으로 대체한다 —
 * 상용 콤보박스/팝오버 표준 동작. 표현식 편집기 데이터 검색 피커가 1차 소비자이며, 향후 다른 피커/
 * 메뉴도 이 컴포넌트로 통일한다(공용화).
 *
 * 동작:
 *  - 앵커(`anchorRef`) 아래(`placement='bottom'`) 또는 위에 패널을 띄운다(기본 bottom).
 *  - 열릴 때마다 패널 실측 → 아래 공간 부족하면 위로 flip, 오른쪽 넘치면 왼쪽 정렬로, 그래도 넘치면
 *    경계 안으로 clamp(좌우 모두). `position:fixed` 라 스크롤 컨테이너 overflow 에 잘리지 않는다.
 *  - 외부 클릭 / ESC → onClose. 앵커 자신 클릭은 토글(소비자가 처리)이라 외부 클릭에서 제외.
 *  - 리사이즈/스크롤 시 재측정(위치 추종).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(CSS 라이브러리 비종속).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';

export interface FloatingDropdownProps {
  /** 앵커(토글) 엘리먼트 ref — 패널이 이 기준으로 위치한다 */
  anchorRef: React.RefObject<HTMLElement | null>;
  /** 열림 여부 */
  open: boolean;
  /** 닫기 요청(외부 클릭/ESC) */
  onClose: () => void;
  /** 패널 본문 */
  children: React.ReactNode;
  /** 선호 배치(공간 부족 시 자동 flip). 기본 bottom */
  placement?: 'bottom' | 'top';
  /** 패널 최소 폭(px). 기본 240 */
  minWidth?: number;
  /** 패널 최대 폭(px). 기본 360 */
  maxWidth?: number;
  /** 뷰포트 가장자리 여백(clamp 시 경계로부터 띄울 간격, px). 기본 8 */
  margin?: number;
  /** data-testid */
  testid?: string;
}

interface Pos {
  left: number;
  top: number;
  maxHeight: number;
}

/**
 * 앵커 기준 부유 드롭다운(flip/clamp 자동 보정).
 *
 * @param props FloatingDropdownProps
 * @returns 열림 시 부유 패널, 닫힘 시 null
 */
export function FloatingDropdown({
  anchorRef,
  open,
  onClose,
  children,
  placement = 'bottom',
  minWidth = 240,
  maxWidth = 360,
  margin = 8,
  testid,
}: FloatingDropdownProps): React.ReactElement | null {
  const panelRef = useRef<HTMLDivElement | null>(null);
  const [pos, setPos] = useState<Pos | null>(null);

  // 위치 계산 — 앵커 rect + 패널 실측으로 flip/clamp. position:fixed 기준(뷰포트 좌표).
  const reposition = useCallback((): void => {
    const anchor = anchorRef.current;
    const panel = panelRef.current;
    if (!anchor || !panel) return;
    const a = anchor.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    // 패널 실측 폭/높이(min/max 제약은 스타일에서 적용됨).
    const pr = panel.getBoundingClientRect();
    const panelW = Math.min(Math.max(pr.width, minWidth), maxWidth);
    const panelH = pr.height;

    // 좌우 — 앵커 왼쪽 정렬 기본. 오른쪽 넘치면 앵커 오른쪽 정렬로, 그래도 넘으면 경계 clamp.
    let left = a.left;
    if (left + panelW + margin > vw) {
      // 앵커 오른쪽 끝에 정렬(패널 오른쪽을 앵커 오른쪽에 맞춤).
      left = a.right - panelW;
    }
    left = Math.min(Math.max(left, margin), vw - panelW - margin);

    // 상하 — 선호 placement 기준. 공간 부족하면 flip. maxHeight 로 가용 공간 제한(내부 스크롤).
    const spaceBelow = vh - a.bottom - margin;
    const spaceAbove = a.top - margin;
    let top: number;
    let maxHeight: number;
    const wantBelow = placement === 'bottom';
    const belowFits = spaceBelow >= Math.min(panelH, 160);
    const aboveFits = spaceAbove >= Math.min(panelH, 160);
    if ((wantBelow && belowFits) || (wantBelow && !aboveFits)) {
      top = a.bottom + 4;
      maxHeight = spaceBelow;
    } else if (!wantBelow && aboveFits) {
      top = a.top - 4 - Math.min(panelH, spaceAbove);
      maxHeight = spaceAbove;
    } else if (aboveFits) {
      // bottom 선호지만 아래 부족 + 위 가능 → flip 위로.
      top = a.top - 4 - Math.min(panelH, spaceAbove);
      maxHeight = spaceAbove;
    } else {
      // 둘 다 부족 → 더 넓은 쪽.
      if (spaceBelow >= spaceAbove) { top = a.bottom + 4; maxHeight = spaceBelow; }
      else { top = margin; maxHeight = spaceAbove; }
    }
    setPos({ left, top, maxHeight: Math.max(maxHeight, 120) });
  }, [anchorRef, placement, minWidth, maxWidth, margin]);

  // 열릴 때 + 패널 마운트 직후 1차 측정(레이아웃 페인트 전).
  useLayoutEffect(() => {
    if (!open) { setPos(null); return; }
    reposition();
  }, [open, reposition]);

  // 리사이즈/스크롤 추종 + 외부 클릭/ESC 닫힘.
  useEffect(() => {
    if (!open) return;
    const onScrollResize = (): void => reposition();
    window.addEventListener('resize', onScrollResize);
    // capture: 내부 스크롤 컨테이너 스크롤도 추종.
    window.addEventListener('scroll', onScrollResize, true);
    const onDocPointer = (e: PointerEvent): void => {
      const panel = panelRef.current;
      const anchor = anchorRef.current;
      const target = e.target as Node;
      // 패널/앵커 내부 클릭은 무시(앵커는 토글이라 소비자가 처리).
      if (panel?.contains(target) || anchor?.contains(target)) return;
      onClose();
    };
    const onKey = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') onClose();
    };
    // pointerdown(capture) — 다른 핸들러보다 먼저 외부 클릭 감지.
    document.addEventListener('pointerdown', onDocPointer, true);
    document.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('resize', onScrollResize);
      window.removeEventListener('scroll', onScrollResize, true);
      document.removeEventListener('pointerdown', onDocPointer, true);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, reposition, onClose, anchorRef]);

  if (!open) return null;
  // pos 가 아직(첫 페인트 전)이면 화면 밖(visibility:hidden)으로 그려 측정만 하고 깜빡임 방지.
  const style: React.CSSProperties = {
    ...panelBox,
    minWidth,
    maxWidth: `min(${maxWidth}px, calc(100vw - ${margin * 2}px))`,
    ...(pos
      ? { left: pos.left, top: pos.top, maxHeight: pos.maxHeight, visibility: 'visible' }
      : { left: -9999, top: -9999, visibility: 'hidden' }),
  };
  return (
    <div ref={panelRef} data-testid={testid} style={style} role="dialog">
      {children}
    </div>
  );
}

/* ── 스타일(g7le-* 인라인, CSS 라이브러리 비종속) ── */
// position:fixed — 뷰포트 좌표 기준(스크롤 컨테이너 overflow 에 안 잘림). flip/clamp 는 JS 가 계산.
const panelBox: React.CSSProperties = {
  position: 'fixed',
  zIndex: 1000,
  background: '#fff',
  border: '1px solid #cbd5e1',
  borderRadius: 8,
  boxShadow: '0 4px 16px rgba(0,0,0,0.14)',
  padding: 6,
  overflowY: 'auto',
  boxSizing: 'border-box',
};
