import { default as React } from 'react';
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
export declare const Breadcrumb: React.FC<BreadcrumbProps>;
