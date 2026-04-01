import React, { useState, useRef, useEffect, useCallback } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';

/**
 * G7Core 다국어 헬퍼
 *
 * @param key 다국어 키
 * @return 번역된 문자열
 */
const t = (key: string): string =>
  (window as any).G7Core?.t?.(key) ?? key;

export interface ExpandableContentProps {
  /**
   * 접힌 상태의 최대 높이 (px)
   * @default 500
   */
  maxHeight?: number;

  /**
   * 펼치기 버튼 텍스트
   */
  expandText?: string;

  /**
   * 접기 버튼 텍스트
   */
  collapseText?: string;

  /**
   * 사용자 정의 클래스
   */
  className?: string;

  /**
   * children 렌더링
   */
  children?: React.ReactNode;
}

/**
 * ExpandableContent 컴포넌트
 *
 * 콘텐츠가 maxHeight를 초과하면 그라데이션 오버레이와 펼치기/접기 버튼을 표시합니다.
 * 콘텐츠가 짧으면 버튼/그라데이션 없이 전체 콘텐츠를 그대로 표시합니다.
 *
 * @example
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "ExpandableContent",
 *   "props": { "maxHeight": 500 },
 *   "children": [
 *     {
 *       "type": "composite",
 *       "name": "HtmlContent",
 *       "props": { "content": "{{product.data?.description_localized ?? ''}}" }
 *     }
 *   ]
 * }
 */
export const ExpandableContent: React.FC<ExpandableContentProps> = ({
  maxHeight = 500,
  expandText,
  collapseText,
  className = '',
  children,
}) => {
  const [isExpanded, setIsExpanded] = useState(false);
  const [needsExpand, setNeedsExpand] = useState(false);
  const contentRef = useRef<HTMLDivElement>(null);

  /**
   * 콘텐츠 높이를 측정하여 펼치기 버튼 필요 여부를 결정합니다.
   */
  const checkHeight = useCallback(() => {
    if (contentRef.current) {
      const scrollHeight = contentRef.current.scrollHeight;
      setNeedsExpand(scrollHeight > maxHeight);
    }
  }, [maxHeight]);

  useEffect(() => {
    checkHeight();

    // 이미지 로드 후 높이 재측정
    const container = contentRef.current;
    if (!container) return;

    const images = container.querySelectorAll('img');
    images.forEach((img) => {
      if (!img.complete) {
        img.addEventListener('load', checkHeight);
      }
    });

    // ResizeObserver로 동적 콘텐츠 변경 감지
    let observer: ResizeObserver | null = null;
    if (typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver(checkHeight);
      observer.observe(container);
    }

    return () => {
      images.forEach((img) => {
        img.removeEventListener('load', checkHeight);
      });
      observer?.disconnect();
    };
  }, [checkHeight, children]);

  const toggleExpand = () => {
    setIsExpanded((prev) => !prev);
  };

  const resolvedExpandText = expandText || t('sirsoft-ecommerce.shop.product.expand_detail');
  const resolvedCollapseText = collapseText || t('sirsoft-ecommerce.shop.product.collapse_detail');

  return (
    <Div className={className}>
      {/* 콘텐츠 컨테이너 */}
      <Div className="relative">
        <Div
          ref={contentRef}
          className={
            !isExpanded && needsExpand
              ? 'overflow-hidden transition-[max-height] duration-300 ease-in-out'
              : 'transition-[max-height] duration-300 ease-in-out'
          }
          style={
            !isExpanded && needsExpand
              ? { maxHeight: `${maxHeight}px` }
              : undefined
          }
        >
          {children}
        </Div>

        {/* 그라데이션 오버레이 */}
        {!isExpanded && needsExpand && (
          <Div className="absolute bottom-0 left-0 right-0 h-24 bg-gradient-to-t from-white dark:from-gray-900 to-transparent pointer-events-none" />
        )}
      </Div>

      {/* 펼치기/접기 버튼 (전체 너비 바 스타일) */}
      {needsExpand && (
        <Button
          type="button"
          onClick={toggleExpand}
          className="flex items-center justify-center w-full py-3.5 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer gap-1.5"
        >
          <Span className="text-sm font-semibold text-gray-600 dark:text-gray-400">
            {isExpanded ? resolvedCollapseText : resolvedExpandText}
          </Span>
          <Icon
            name={isExpanded ? 'chevron-up' : 'chevron-down'}
            size="sm"
          />
        </Button>
      )}
    </Div>
  );
};

export default ExpandableContent;
