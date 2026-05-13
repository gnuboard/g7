import React from 'react';
import { Nav } from '../basic/Nav';
import { Div } from '../basic/Div';
import { A } from '../basic/A';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

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
  separator = <Icon name={IconName.ChevronRight} className="w-4 h-4 text-gray-400 dark:text-gray-500" />,
  showHome = false,
  homeHref = '/',
  maxItems,
  className = '',
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
      aria-label="Breadcrumb"
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
                  <Span className="text-gray-400 dark:text-gray-500" aria-hidden="true">
                    {separator}
                  </Span>
                  <Span className="text-gray-500 dark:text-gray-400">...</Span>
                  {displayItems.length > 1 && (
                    <Span className="text-gray-400 dark:text-gray-500" aria-hidden="true">
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
                    <Span className="text-gray-400 dark:text-gray-500" aria-hidden="true">
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

const BreadcrumbLink: React.FC<BreadcrumbLinkProps> = ({ item, isLast }) => {
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

  if (item.onClick) {
    // onClick 핸들러가 있는 경우
    return (
      <A
        href="#"
        onClick={(e) => {
          e.preventDefault();
          item.onClick?.();
        }}
        className="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
      >
        {item.label}
      </A>
    );
  }

  // 일반 링크
  return (
    <A
      href={item.href}
      className="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
    >
      {item.label}
    </A>
  );
};
