import React from 'react';
import { Nav } from '../basic/Nav';
import { Div } from '../basic/Div';
import { A } from '../basic/A';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import type { EditorAttrs } from '../../types';
import { handleLinkClick, resolveLinkHref } from '../../support/navigationLink';

// G7Core.t() 번역 함수 참조 — 번역이 없거나 엔진 밖(단위 테스트)이면 대체 문구를 쓴다
const tOr = (key: string, fallback: string, params?: Record<string, string | number>): string => {
  const translated = (window as any).G7Core?.t?.(key, params);
  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

export interface BreadcrumbItem {
  label: string;
  href?: string;
  onClick?: () => void;
}

export interface BreadcrumbProps {
  items: BreadcrumbItem[];
  separator?: React.ReactNode;
  showHome?: boolean;
  homeHref?: string;
  maxItems?: number;
  className?: string;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * Breadcrumb 집합 컴포넌트
 *
 * 현재 페이지의 경로를 계층적으로 표시하는 내비게이션 컴포넌트.
 * separator 커스터마이징, showHome 옵션, maxItems로 중간 생략 기능 제공.
 *
 * 기본 컴포넌트 조합:
 * - Nav > Div > A(링크) + Span(구분자)
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Breadcrumb",
 *   "props": {
 *     "items": [
 *       {"label": "대시보드", "href": "/admin"},
 *       {"label": "사용자 관리", "href": "/admin/users"},
 *       {"label": "사용자 목록"}
 *     ],
 *     "showHome": true,
 *     "maxItems": 4
 *   }
 * }
 */
export const Breadcrumb: React.FC<BreadcrumbProps> = ({
  items,
  separator = <Icon name={IconName.ChevronRight} className="w-4 h-4 text-gray-500 dark:text-gray-500" />,
  showHome = false,
  homeHref = '/',
  maxItems,
  className = '',
  id,
  editorAttrs,
}) => {
  // Home 아이템 생성
  const homeItem: BreadcrumbItem = {
    label: 'Home',
    href: homeHref,
  };

  // 전체 아이템 배열 생성
  let allItems = showHome ? [homeItem, ...items] : items;

  // maxItems 적용 (중간 생략)
  let displayItems = allItems;
  let hasEllipsis = false;

  if (maxItems && allItems.length > maxItems) {
    hasEllipsis = true;
    const firstItems = allItems.slice(0, 1);
    const lastItems = allItems.slice(-(maxItems - 2));
    displayItems = [...firstItems, ...lastItems];
  }

  return (
    <Nav
      className={`flex items-center space-x-2 text-sm ${className}`}
      aria-label={tOr('common.breadcrumb', 'Breadcrumb')}
      id={id} {...editorAttrs}
    >
      <Div className="flex items-center space-x-2">
        {displayItems.map((item, index) => {
          const isLast = index === displayItems.length - 1;
          const isFirst = index === 0;

          return (
            <React.Fragment key={index}>
              {/* 중간 생략 표시 (첫 번째 아이템 다음) */}
              {hasEllipsis && isFirst && (
                <>
                  <BreadcrumbLink item={item} isLast={false} />
                  <Span className="text-gray-500 dark:text-gray-400" aria-hidden="true">
                    {separator}
                  </Span>
                  <Span className="text-gray-500 dark:text-gray-400">...</Span>
                  {displayItems.length > 1 && (
                    <Span className="text-gray-500 dark:text-gray-400" aria-hidden="true">
                      {separator}
                    </Span>
                  )}
                </>
              )}

              {/* 일반 아이템 */}
              {(!hasEllipsis || !isFirst) && (
                <>
                  <BreadcrumbLink item={item} isLast={isLast} />
                  {!isLast && (
                    <Span className="text-gray-500 dark:text-gray-400" aria-hidden="true">
                      {separator}
                    </Span>
                  )}
                </>
              )}
            </React.Fragment>
          );
        })}
      </Div>
    </Nav>
  );
};

interface BreadcrumbLinkProps {
  item: BreadcrumbItem;
  isLast: boolean;
}

/**
 * 경로 항목 하나 — 주소가 있으면 링크(가운데 클릭·Ctrl+클릭 새 탭), 주소 없이 동작만 있으면 단추, 둘 다 없거나
 * 마지막 항목이면 글자. 주소 없는 `<a href="#">` 는 그리지 않는다(#757).
 */
const BreadcrumbLink: React.FC<BreadcrumbLinkProps> = ({ item, isLast }) => {
  const link = resolveLinkHref(item.href);
  const linkClassName = 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 transition-colors';

  if (isLast || (!item.href && !item.onClick)) {
    // 마지막 아이템이거나 링크가 없는 경우
    return (
      <Span
        className="font-medium text-gray-900 dark:text-white"
        aria-current={isLast ? 'page' : undefined}
      >
        {item.label}
      </Span>
    );
  }

  if (item.onClick && !link) {
    // 주소 없이 동작만 있는 항목 — 단추
    return (
      <Button type="button" onClick={() => item.onClick?.()} className={`${linkClassName} cursor-pointer`}>
        {item.label}
      </Button>
    );
  }

  if (item.onClick && link) {
    // 주소와 동작이 함께 있는 항목 — 보통 클릭만 동작을 부르고, 수정키 클릭은 브라우저가 새 탭으로 연다
    return (
      <A
        href={link.href}
        {...(link.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        onClick={(event: React.MouseEvent) => {
          handleLinkClick(event, () => item.onClick?.());
        }}
        className={linkClassName}
      >
        {item.label}
      </A>
    );
  }

  // 일반 링크
  return (
    <A
      href={item.href}
      className={linkClassName}
    >
      {item.label}
    </A>
  );
};
