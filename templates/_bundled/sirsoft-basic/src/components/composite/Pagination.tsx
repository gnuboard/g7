import React, { useMemo } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface PaginationProps {
  currentPage: number;
  /**
   * 마지막 페이지 번호
   *
   * 총 건수가 상한을 넘겨 정확히 세지 않은 목록은 마지막 페이지를 계산할 수 없다.
   * 그 경우 서버가 `null` 을 보내며, 페이지 번호 목록과 마지막 페이지 점프가 사라지고
   * 이전/다음 이동만 남는다. 0 이나 1 로 채우면 화면이 "1페이지뿐" 이라고 잘못 말하게 된다.
   */
  totalPages: number | null;
  onPageChange: (page: number) => void;
  maxVisiblePages?: number;
  /**
   * 첫/마지막 페이지 버튼 표시 여부 (두 버튼의 기본값)
   *
   * 마지막 페이지만 따로 감춰야 하는 경우가 있으므로 `showLast` 로 덮어쓸 수 있다.
   */
  showFirstLast?: boolean;
  /**
   * 첫 페이지 버튼 표시 여부 (미지정 시 showFirstLast 를 따른다)
   */
  showFirst?: boolean;
  /**
   * 마지막 페이지 버튼 표시 여부 (미지정 시 showFirstLast 를 따른다)
   *
   * 총 건수가 부정확한 목록에서는 마지막 페이지를 계산할 수 없으므로,
   * `totalPages` 가 null 이면 이 값과 무관하게 감춰진다.
   */
  showLast?: boolean;
  /**
   * 다음 페이지 존재 여부 (총 건수를 모르는 목록에서 사용)
   *
   * 총 건수를 몰라도 다음 페이지 존재 여부는 서버가 정확히 판정한다.
   * `totalPages` 가 null 일 때만 쓰인다 — 아래 `canGoNext` 주석 참조.
   */
  hasMorePages?: boolean;
  className?: string;
  style?: React.CSSProperties;
  prevText?: string;
  nextText?: string;
    /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
/**
   * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
   */
  editorAttrs?: EditorAttrs;
}

/**
 * Pagination 집합 컴포넌트
 *
 * Button + Span 기본 컴포넌트를 조합하여 페이지네이션 UI를 구성합니다.
 * 페이지 번호 생성 알고리즘을 포함하며, 많은 페이지 수에 대해 효율적으로 렌더링합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Pagination",
 *   "props": {
 *     "currentPage": "{{pagination.current}}",
 *     "totalPages": "{{pagination.total}}",
 *     "maxVisiblePages": 5,
 *     "showFirstLast": true
 *   }
 * }
 */
export const Pagination: React.FC<PaginationProps> = ({
  currentPage,
  totalPages,
  onPageChange,
  maxVisiblePages = 5,
  showFirstLast = true,
  showFirst,
  showLast,
  hasMorePages,
  className = '',
  style,
  prevText,
  nextText,
  id,
  editorAttrs,
}) => {
  // 총 건수를 정확히 세지 못한 목록은 마지막 페이지를 계산할 수 없다.
  // 이때는 페이지 번호 목록과 마지막 페이지 점프를 감추고 이전/다음 이동만 남긴다 —
  // 끝까지 넘겨 보는 것은 그대로 가능하다.
  const isBounded = totalPages !== null && totalPages !== undefined;
  const resolvedTotalPages = isBounded ? (totalPages as number) : 0;

  // 마지막 페이지를 알면 페이지 산술이 언제나 옳으므로 그쪽을 먼저 본다.
  // `hasMorePages` 를 우선하면, 응답에 `has_more_pages` 가 없어 레이아웃이 `?? false` 로
  // 채운 값이 정상 페이저의 "다음" 을 막아버린다 (실측: 1/2 페이지인데 다음 비활성).
  // 서버 판정이 필요한 자리는 마지막 페이지를 모르는 목록뿐이다.
  const canGoNext = isBounded
    ? currentPage < resolvedTotalPages
    : (hasMorePages ?? false);

  const displayFirst = (showFirst ?? showFirstLast) && isBounded;
  const displayLast = (showLast ?? showFirstLast) && isBounded;

  // 페이지 번호 생성 알고리즘
  const pageNumbers = useMemo(() => {
    const pages: (number | string)[] = [];

    if (!isBounded) {
      // 마지막 페이지를 모르면 번호 목록을 만들 수 없다 (현재 페이지만 별도로 표시)
      return pages;
    }

    if (resolvedTotalPages <= maxVisiblePages + 2) {
      // 페이지가 적으면 모두 표시
      for (let i = 1; i <= resolvedTotalPages; i++) {
        pages.push(i);
      }
    } else {
      // 페이지가 많으면 축약 표시
      const halfVisible = Math.floor(maxVisiblePages / 2);

      let startPage = Math.max(2, currentPage - halfVisible);
      let endPage = Math.min(resolvedTotalPages - 1, currentPage + halfVisible);

      // 시작 또는 끝 근처일 때 조정
      if (currentPage <= halfVisible + 1) {
        endPage = maxVisiblePages;
      } else if (currentPage >= resolvedTotalPages - halfVisible) {
        startPage = resolvedTotalPages - maxVisiblePages + 1;
      }

      // 첫 페이지
      pages.push(1);

      // 시작 생략 부호
      if (startPage > 2) {
        pages.push('...');
      }

      // 중간 페이지들
      for (let i = startPage; i <= endPage; i++) {
        pages.push(i);
      }

      // 끝 생략 부호
      if (endPage < resolvedTotalPages - 1) {
        pages.push('...');
      }

      // 마지막 페이지
      if (resolvedTotalPages > 1) {
        pages.push(resolvedTotalPages);
      }
    }

    return pages;
  }, [currentPage, resolvedTotalPages, isBounded, maxVisiblePages]);

  const handlePageClick = (page: number) => {
    if (page < 1 || page === currentPage) return;
    if (isBounded && page > resolvedTotalPages) return;
    onPageChange(page);
  };

  const handlePrevious = () => {
    if (currentPage > 1) {
      onPageChange(currentPage - 1);
    }
  };

  const handleNext = () => {
    if (canGoNext) {
      onPageChange(currentPage + 1);
    }
  };

  return (
    <Div
      className={`flex items-center gap-2 ${className}`}
      style={style}
      role="navigation"
      aria-label={t('common.pagination')}
      id={id} {...editorAttrs}
    >
      {/* First Button */}
      {displayFirst && (
        <Button
          onClick={() => handlePageClick(1)}
          disabled={currentPage === 1}
          className="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800"
          aria-label={t('common.first_page')}
        >
          &#171;
        </Button>
      )}

      {/* Previous Button */}
      <Button
        onClick={handlePrevious}
        disabled={currentPage === 1}
        className="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800"
        aria-label={t('common.prev_page')}
      >
        {prevText || <>&#8249;</>}
      </Button>

      {/* Page Numbers — 마지막 페이지를 모르면 현재 페이지만 표시 */}
      <Div className="flex items-center gap-1">
        {!isBounded && (
          <Span className="px-3 py-1 text-sm text-gray-700 dark:text-gray-300">
            {currentPage}
          </Span>
        )}
        {pageNumbers.map((page, index) => {
          if (page === '...') {
            return (
              <Span
                key={`ellipsis-${index}`}
                className="px-3 py-1 text-sm text-gray-500 dark:text-gray-400"
              >
                ...
              </Span>
            );
          }

          const pageNum = page as number;
          const isActive = pageNum === currentPage;

          return (
            <Button
              key={pageNum}
              onClick={() => handlePageClick(pageNum)}
              className={`px-3 py-1 text-sm border rounded ${
                isActive
                  ? 'bg-blue-500 dark:bg-blue-600 text-white border-blue-500 dark:border-blue-600'
                  : 'border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800'
              }`}
              aria-label={t('common.page_n', { n: pageNum })}
              aria-current={isActive ? 'page' : undefined}
            >
              {pageNum}
            </Button>
          );
        })}
      </Div>

      {/* Next Button */}
      <Button
        onClick={handleNext}
        disabled={!canGoNext}
        className="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800"
        aria-label={t('common.next_page')}
      >
        {nextText || <>&#8250;</>}
      </Button>

      {/* Last Button */}
      {displayLast && (
        <Button
          onClick={() => handlePageClick(resolvedTotalPages)}
          disabled={currentPage === resolvedTotalPages}
          className="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800"
          aria-label={t('common.last_page')}
        >
          &#187;
        </Button>
      )}
    </Div>
  );
};
