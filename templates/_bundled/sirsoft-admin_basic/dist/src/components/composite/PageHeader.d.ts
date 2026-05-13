import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { BreadcrumbItem } from './Breadcrumb';
/**
 * 탭 아이템 인터페이스
 */
export interface TabItem {
    id: string | number;
    label: string;
    value: string;
    active?: boolean;
    badge?: string | number;
}
/**
 * 액션 버튼 인터페이스
 */
export interface ActionButton {
    id: string | number;
    label: string;
    onClick?: () => void;
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
    iconName?: IconName;
    disabled?: boolean;
}
/**
 * PageHeader Props
 */
export interface PageHeaderProps {
    title: string;
    description?: string;
    breadcrumbItems?: BreadcrumbItem[];
    tabs?: TabItem[];
    onTabChange?: (value: string) => void;
    actions?: ActionButton[];
    className?: string;
    children?: React.ReactNode;
}
/**
 * PageHeader 컴포넌트
 *
 * 페이지 헤더 - admin_dashboard.json의 page_header 스타일과 동일
 * 배경색 없음, flex items-center justify-between mb-6 레이아웃
 *
 * @example
 * ```tsx
 * <PageHeader
 *   title="사용자 관리"
 *   description="시스템에 등록된 사용자 목록을 조회하고 관리합니다."
 *   actions={[
 *     { id: 1, label: '사용자 추가', variant: 'primary', iconName: 'plus' }
 *   ]}
 * />
 * ```
 */
export declare const PageHeader: React.FC<PageHeaderProps>;
