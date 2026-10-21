import React, { useMemo } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';
import { handleLinkClick, mergeQueryIntoHref } from '../../support/navigationLink';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface PaginationProps {
  currentPage: number;
  /**
   * 마지막 페이지 번호
   *
   * 총 건수가 상한을 넘겨 정확히 세지 않은 목록은 마지막 페이지를 계산할 수 없다.
   * 그 경우 서버가 `null` 을 보내며, 마지막 페이지 점프만 사라진다. 1 부터 현재 페이지까지와
   * (`hasMorePages` 가 참이면) 다음 페이지 번호는 그대로 그린다. 0 이나 1 로 채우면 화면이 "1페이지뿐" 이라고 잘못 말하게 된다.
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
   * 지정하면 "다음" 버튼의 활성 여부를 이 값으로 정한다.
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
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
  /**
   * 페이지 주소 틀 — `{page}` 자리에 번호를 넣는다(예: `/admin/users?page={page}`). 주면 번호·이전·다음·처음·끝이
   * `<a href>` 가 되어 가운데 클릭·Ctrl+클릭으로 새 탭에서 열 수 있다. 보통 클릭은 지금처럼 onPageChange(#757).
   */
  pageHref?: string;
  /** 페이지 주소에 현재 주소의 쿼리(검색·정렬 조건)를 합친다 — 새 탭도 같은 목록 조건으로 열린다 */
  pageHrefMergeQuery?: boolean;
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
  pageHref,
  pageHrefMergeQuery = false,
}) => {
  // 총 건수를 정확히 세지 못한 목록은 마지막 페이지를 계산할 수 없다.
  // 계산 불가한 값은 마지막 페이지 번호 하나뿐이므로 그 점프만 감춘다. 1 부터 현재
  // 페이지까지는 확실히 존재하고, 다음 페이지 존재 여부는 서버가 `hasMorePages` 로 알린다.
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
      // 마지막 페이지를 모르면 아는 범위(1..현재, 다음이 있으면 현재+1)만 그린다.
      // 현재+1 너머는 존재 여부를 모르므로 번호 대신 계속됨 표시만 둔다.
      const halfVisible = Math.floor(maxVisiblePages / 2);
      const startPage = currentPage <= maxVisiblePages ? 1 : currentPage - halfVisible;
      if (startPage > 1) {
        pages.push(1);
        if (startPage > 2) pages.push('...');
      }
      for (let i = startPage; i <= currentPage; i++) {
        pages.push(i);
      }
      if (hasMorePages) {
        pages.push(currentPage + 1);
        pages.push('...');
      }
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
  }, [currentPage, resolvedTotalPages, isBounded, maxVisiblePages, hasMorePages]);

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

  /** 페이지 번호의 주소 — pageHref 가 없으면 undefined(단추로 그린다) */
  const hrefFor = (page: number): string | undefined => {
    if (!pageHref) return undefined;
    const raw = pageHref.split('{page}').join(String(page));
    return pageHrefMergeQuery ? mergeQueryIntoHref(raw) : raw;
  };

  /**
   * 페이지 조작 하나 — pageHref 가 있으면 링크(갈 수 없으면 href 없는 aria-disabled 링크), 없으면 종전 단추.
   */
  const renderControl = (opts: {
    key?: React.Key;
    page: number;
    disabled?: boolean;
    className: string;
    ariaLabel: string;
    ariaCurrent?: 'page';
    onActivate: () => void;
    children: React.ReactNode;
  }): React.ReactNode => {
    if (!pageHref) {
      return (
        <Button
          key={opts.key}
          onClick={opts.onActivate}
          disabled={opts.disabled}
          className={opts.className}
          aria-label={opts.ariaLabel}
          aria-current={opts.ariaCurrent}
        >
          {opts.children}
        </Button>
      );
    }
    if (opts.disabled) {
      return (
        <a
          key={opts.key}
          role="link"
          aria-disabled="true"
          tabIndex={-1}
          className={`button ${opts.className}`}
          aria-label={opts.ariaLabel}
          onClick={(event) => event.preventDefault()}
        >
          {opts.children}
        </a>
      );
    }
    return (
      <a
        key={opts.key}
        href={hrefFor(opts.page)}
        className={`button ${opts.className}`}
        aria-label={opts.ariaLabel}
        aria-current={opts.ariaCurrent}
        onClick={(event) => handleLinkClick(event, opts.onActivate)}
      >
        {opts.children}
      </a>
    );
  };

  return (
    <Div
      className={`pagination ${className}`}
      style={style}
      role="navigation"
      aria-label={t('common.pagination')}
      id={id} {...editorAttrs}
    >
      {/* First Button */}
      {displayFirst && renderControl({
        page: 1,
        onActivate: () => handlePageClick(1),
        disabled: currentPage === 1,
        className: 'pagination-btn',
        ariaLabel: t('common.first_page'),
        children: <>&#171;</>,
      })}

      {/* Previous Button */}
      {renderControl({
        page: currentPage - 1,
        onActivate: handlePrevious,
        disabled: currentPage === 1,
        className: 'pagination-btn',
        ariaLabel: t('common.prev_page'),
        children: prevText || <>&#8249;</>,
      })}

      {/* Page Numbers — 마지막 페이지를 모르면 아는 범위까지만 표시 */}
      <Div className="pagination">
        {pageNumbers.map((page, index) => {
          if (page === '...') {
            return (
              <Span
                key={`ellipsis-${index}`}
                className="pagination-ellipsis"
              >
                ...
              </Span>
            );
          }

          const pageNum = page as number;
          const isActive = pageNum === currentPage;

          return renderControl({
            key: pageNum,
            page: pageNum,
            onActivate: () => handlePageClick(pageNum),
            className: isActive ? 'pagination-btn-active' : 'pagination-btn',
            ariaLabel: t('common.page_n', { n: pageNum }),
            ariaCurrent: isActive ? 'page' : undefined,
            children: pageNum,
          });
        })}
      </Div>

      {/* Next Button */}
      {renderControl({
        page: currentPage + 1,
        onActivate: handleNext,
        disabled: !canGoNext,
        className: 'pagination-btn',
        ariaLabel: t('common.next_page'),
        children: nextText || <>&#8250;</>,
      })}

      {/* Last Button */}
      {displayLast && renderControl({
        page: resolvedTotalPages,
        onActivate: () => handlePageClick(resolvedTotalPages),
        disabled: currentPage === resolvedTotalPages,
        className: 'pagination-btn',
        ariaLabel: t('common.last_page'),
        children: <>&#187;</>,
      })}
    </Div>
  );
};
