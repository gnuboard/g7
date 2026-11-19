import React, { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { H2 } from '../basic/H2';
import { Icon } from '../basic/Icon';
import { Section } from '../basic/Section';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';
import { productRailColumnClass, useViewportWidth } from '../layout/viewportWidth';

export interface ProductRailProps {
  /** 줄 제목 (레이아웃이 `$t:` 로 번역해 넘긴다) */
  title?: string;
  /** 제목 앞 아이콘 이름 */
  icon?: string;
  /** 아이콘 클래스 (색) */
  iconClassName?: string;
  /** 이전 단추 이름 (없으면 영문 기본값) */
  prevLabel?: string;
  /** 다음 단추 이름 (없으면 영문 기본값) */
  nextLabel?: string;
  /** 스크린리더가 읽는 영역 종류 (예: 「상품 줄」) */
  roleDescription?: string;
  /** 추가 CSS 클래스 */
  className?: string;
  /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
  /** 카드들 — 레이아웃이 넘긴 자식을 그대로 그린다 */
  children?: React.ReactNode;
}

const ACTIVE_BUTTON =
  'p-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700';
const LOCKED_BUTTON =
  'p-2 border border-gray-200 dark:border-gray-700 rounded-lg text-gray-300 dark:text-gray-600 cursor-not-allowed';

/**
 * ProductRail 컴포넌트
 *
 * 상품 카드를 가로 한 줄로 늘어놓고 손가락 넘김(브라우저 스크롤 + 스냅)과 이전·다음 단추로
 * 넘기는 컨테이너. 상품 배열을 받지 않고 **레이아웃이 넘긴 자식 카드**를 감싼다 — 검색 로봇용
 * HTML 에서도 안의 상품 링크가 각각 그려진다. 한 화면에 보이는 카드 수는 2(모바일)·3·4(데스크톱)이며,
 * 엔진 폭(편집기 미리보기에서는 기기 폭)으로 고른다(`productRailColumnClass`).
 *
 * @example
 * // 레이아웃 마크업 사용 예시
 * <product-rail data-type="composite" title="$t:shop.popular_products" icon="flame"
 *               prevLabel="$t:common.prev" nextLabel="$t:common.next">
 *   <div data-each="product in {{popularProducts.data ?? []}}">
 *     <product-card data-type="composite" product="{{product}}"/>
 *   </div>
 * </product-rail>
 */
export const ProductRail: React.FC<ProductRailProps> = ({
  title,
  icon,
  iconClassName = 'text-xl text-orange-600 dark:text-orange-400',
  prevLabel = 'Previous',
  nextLabel = 'Next',
  roleDescription,
  className = '',
  id,
  editorAttrs,
  children,
}) => {
  const uid = useId();
  const headingId = `${uid}-heading`;
  const trackId = `${uid}-track`;
  const trackRef = useRef<HTMLDivElement | null>(null);
  const viewportWidth = useViewportWidth();
  const [atStart, setAtStart] = useState(true);
  const [atEnd, setAtEnd] = useState(false);

  /** 줄의 처음·끝에 닿았는지 다시 잰다 (1px 오차는 닿은 것으로 본다). */
  const measure = useCallback(() => {
    const track = trackRef.current;
    if (!track) return;
    const max = track.scrollWidth - track.clientWidth;
    setAtStart(track.scrollLeft <= 1);
    setAtEnd(max <= 1 || track.scrollLeft >= max - 1);
  }, []);

  useEffect(() => {
    measure();
    const track = trackRef.current;
    if (!track || typeof ResizeObserver === 'undefined') return undefined;
    // 화면 폭이 바뀌거나 카드가 뒤늦게 채워지면 단추 잠금을 다시 정한다
    const observer = new ResizeObserver(() => measure());
    observer.observe(track);
    return () => observer.disconnect();
  }, [measure, children]);

  /**
   * 카드 한 칸(첫 카드 폭 + 간격)만큼 넘긴다.
   *
   * @param direction 방향 (-1 이전, 1 다음)
   */
  const step = (direction: 1 | -1) => {
    const track = trackRef.current;
    if (!track) return;
    const first = track.firstElementChild as HTMLElement | null;
    const width = first?.getBoundingClientRect().width ?? track.clientWidth;
    const gap = parseFloat(window.getComputedStyle(track).columnGap || '0') || 0;
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    track.scrollBy({ left: direction * (width + gap), behavior: reduced ? 'auto' : 'smooth' });
  };

  return (
    <Section
      id={id}
      className={className}
      aria-roledescription={roleDescription}
      aria-labelledby={title ? headingId : undefined}
      {...editorAttrs}
    >
      <Div className="flex justify-between items-center mb-4">
        <H2 id={headingId} className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
          {icon ? <Icon name={icon} className={iconClassName} /> : null}
          <Span>{title}</Span>
        </H2>
        <Div className="flex gap-2">
          <Button
            type="button"
            aria-label={prevLabel}
            aria-controls={trackId}
            disabled={atStart}
            className={atStart ? LOCKED_BUTTON : ACTIVE_BUTTON}
            onClick={() => step(-1)}
          >
            <Icon name="chevron-left" className="text-base" />
          </Button>
          <Button
            type="button"
            aria-label={nextLabel}
            aria-controls={trackId}
            disabled={atEnd}
            className={atEnd ? LOCKED_BUTTON : ACTIVE_BUTTON}
            onClick={() => step(1)}
          >
            <Icon name="chevron-right" className="text-base" />
          </Button>
        </Div>
      </Div>
      <Div
        ref={trackRef}
        id={trackId}
        data-product-rail-track=""
        onScroll={measure}
        className={`grid grid-flow-col ${productRailColumnClass(viewportWidth)} gap-4 overflow-x-auto snap-x snap-mandatory overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden [&>*]:snap-start`}
      >
        {children}
      </Div>
    </Section>
  );
};

export default ProductRail;
