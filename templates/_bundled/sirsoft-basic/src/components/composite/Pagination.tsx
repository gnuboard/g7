import React, { useMemo } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface PaginationProps {
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  maxVisiblePages?: number;
  showFirstLast?: boolean;
  className?: string;
  style?: React.CSSProperties;
  prevText?: string;
  nextText?: string;
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
  className = '',
  style,
  prevText,
  nextText,
}) => {

  // 페이지 번호 생성 알고리즘
  const pageNumbers = useMemo(() => {
    const pages: (number | string)[] = [];

    if (totalPages <= maxVisiblePages + 2) {
      // 페이지가 적으면 모두 표시
      for (let i = 1; i <= totalPages; i++) {
        pages.push(i);
      }
    } else {
      // 페이지가 많으면 축약 표시
      const halfVisible = Math.floor(maxVisiblePages / 2);

      let startPage = Math.max(2, currentPage - halfVisible);
      let endPage = Math.min(totalPages - 1, currentPage + halfVisible);

      // 시작 또는 끝 근처일 때 조정
      if (currentPage <= halfVisible + 1) {
        endPage = maxVisiblePages;
      } else if (currentPage >= totalPages - halfVisible) {
        startPage = totalPages - maxVisiblePages + 1;
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
      if (endPage < totalPages - 1) {
        pages.push('...');
      }

      // 마지막 페이지
      if (totalPages > 1) {
        pages.push(totalPages);
      }
    }

    return pages;
  }, [currentPage, totalPages, maxVisiblePages]);

  const handlePageClick = (page: number) => {
    if (page < 1 || page > totalPages || page === currentPage) return;
    onPageChange(page);
  };

  const handlePrevious = () => {
    if (currentPage > 1) {
      onPageChange(currentPage - 1);
    }
  };

  const handleNext = () => {
    if (currentPage < totalPages) {
      onPageChange(currentPage + 1);
    }
  };

  return (
    <Div
      className={`flex items-center gap-2 ${className}`}
      style={style}
      role="navigation"
      aria-label={t('common.pagination')}
    >
      {/* First Button */}
      {showFirstLast && (
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

      {/* Page Numbers */}
      <Div className="flex items-center gap-1">
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
        disabled={currentPage === totalPages}
        className="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800"
        aria-label={t('common.next_page')}
      >
        {nextText || <>&#8250;</>}
      </Button>

      {/* Last Button */}
      {showFirstLast && (
        <Button
          onClick={() => handlePageClick(totalPages)}
          disabled={currentPage === totalPages}
          className="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800"
          aria-label={t('common.last_page')}
        >
          &#187;
        </Button>
      )}
    </Div>
  );
};
