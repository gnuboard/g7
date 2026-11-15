/**
 * ChipRows 컴포넌트
 *
 * 분류 칩 줄 컨테이너. 자식(칩 링크)은 레이아웃이 그대로 넘기고, 이 컴포넌트는 칩 폭을 재서 줄 배치만 정한다.
 *
 * - 한 줄에 다 들어가면 한 줄.
 * - 넘치면 두 줄로 나눠 가로로 넘긴다 — 분류가 많을수록 줄이 늘어나 상품 목록을 아래로 밀어내지 않게.
 * - 두 줄로도 넘치면 칩을 조금씩 줄이고(여백·간격·글자), 그래도 넘치면 끝에 걸친 칩이 반쯤 보이게 해
 *   옆에 더 있음을 드러낸다. 오른쪽 끝에는 흐림을 둔다.
 *
 * 줄 폭과 줄이기 값은 CSS 변수로만 건다(`style.setProperty`). 칩의 여백·글자 크기는 레이아웃의 칩 클래스가
 * 그 변수를 읽는다(`px-[var(--g7-chip-px,0.75rem)]` 등) — 변수가 없으면 원래 크기다.
 */

import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Div } from '../basic/Div';
import { Nav } from '../basic/Nav';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';
import { planChipRows } from '../../support/chipRowsPlan';

export interface ChipRowsProps {
  /** 칩 줄의 이름 (스크린리더가 읽는다) */
  ariaLabel?: string;
  /** 추가 CSS 클래스 */
  className?: string;
  /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
  /** 칩 (레이아웃이 넘기는 자식) */
  children?: React.ReactNode;
}

const VARIABLES = ['--g7-chip-rows-w', '--g7-chip-px', '--g7-chip-gap', '--g7-chip-fs'] as const;

type Mode = 'initial' | 'single' | 'double';

/**
 * 분류 칩 줄 컨테이너
 *
 * @example
 * ```html
 * <chip-rows data-type="composite" ariaLabel="$t:shop.category.chips_label">
 *   <a href="…">전체</a>
 *   <div data-each="cat in {{categories.data}}"><a href="…">{{cat.name_localized}}</a></div>
 * </chip-rows>
 * ```
 */
export const ChipRows: React.FC<ChipRowsProps> = ({ ariaLabel, className = '', id, editorAttrs, children }) => {
  const rootRef = useRef<HTMLElement>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const innerRef = useRef<HTMLDivElement>(null);
  const [mode, setMode] = useState<Mode>('initial');
  const [overflow, setOverflow] = useState(false);
  const [atEnd, setAtEnd] = useState(false);

  const measure = useCallback(() => {
    const root = rootRef.current;
    const scroll = scrollRef.current;
    const inner = innerRef.current;
    if (!root || !scroll || !inner) return;

    // 줄이기 값을 걷고 원래 크기로 잰다 — 같은 틱 안이라 화면에는 보이지 않는다
    for (const name of VARIABLES.slice(1)) root.style.removeProperty(name);
    const widths = Array.from(inner.children).map((chip) => (chip as HTMLElement).getBoundingClientRect().width);
    const plan = planChipRows(widths, scroll.clientWidth);

    if (plan.rows === 2 && plan.rowWidth !== null) {
      // 소수점 폭이 올림돼 세 줄로 넘어가지 않게 1px 여유를 둔다
      root.style.setProperty('--g7-chip-rows-w', `${Math.ceil(plan.rowWidth) + 1}px`);
    } else {
      root.style.removeProperty('--g7-chip-rows-w');
    }
    if (plan.step > 0) {
      root.style.setProperty('--g7-chip-px', `${plan.paddingX}px`);
      root.style.setProperty('--g7-chip-gap', `${plan.gap}px`);
      root.style.setProperty('--g7-chip-fs', `${plan.fontSize}px`);
    }

    const nextMode: Mode = widths.length === 0 || !(scroll.clientWidth > 0) ? 'initial' : plan.rows === 2 ? 'double' : 'single';
    setMode((current) => (current === nextMode ? current : nextMode));
    setOverflow((current) => (current === plan.overflow ? current : plan.overflow));
  }, []);

  // 그릴 때마다 다시 잰다 — 칩 수·글자가 바뀌면 부모가 다시 그린다
  useLayoutEffect(() => {
    measure();
  });

  useEffect(() => {
    const scroll = scrollRef.current;
    if (!scroll) return;

    let observer: ResizeObserver | undefined;
    if (typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver(() => measure());
      observer.observe(scroll);
    }
    // 웹 글꼴이 늦게 오면 칩 폭이 달라진다
    let cancelled = false;
    (document as any).fonts?.ready?.then?.(() => {
      if (!cancelled) measure();
    });

    const onScroll = () => {
      const end = scroll.scrollLeft + scroll.clientWidth >= scroll.scrollWidth - 1;
      setAtEnd((current) => (current === end ? current : end));
    };
    scroll.addEventListener('scroll', onScroll, { passive: true });

    return () => {
      cancelled = true;
      observer?.disconnect();
      scroll.removeEventListener('scroll', onScroll);
    };
  }, [measure]);

  // 재기 전(첫 그림)에는 종전과 같은 줄바꿈 — 한 줄로 그렸다가 바뀌며 화면이 흔들리지 않게
  const innerClassName =
    mode === 'double'
      ? 'flex flex-wrap gap-[var(--g7-chip-gap,0.5rem)] w-[var(--g7-chip-rows-w)]'
      : mode === 'single'
        ? 'flex flex-nowrap gap-[var(--g7-chip-gap,0.5rem)]'
        : 'flex flex-wrap gap-[var(--g7-chip-gap,0.5rem)]';

  return (
    <Nav
      ref={rootRef}
      aria-label={ariaLabel}
      id={id}
      className={`relative ${className}`.trim()}
      {...(editorAttrs as Record<string, unknown>)}
    >
      <Div
        ref={scrollRef}
        data-chip-rows-scroll=""
        className="overflow-x-auto overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        <Div ref={innerRef} data-chip-rows-inner="" className={innerClassName}>
          {children}
        </Div>
      </Div>
      {/* 오른쪽 끝 흐림 — 넘칠 때만, 끝까지 넘기면 걷는다. 장식이라 누름을 가로채지 않는다 */}
      {overflow && !atEnd && (
        <Span
          data-chip-rows-fade=""
          aria-hidden="true"
          className="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-white dark:from-gray-900 to-transparent"
        />
      )}
    </Nav>
  );
};

export default ChipRows;
