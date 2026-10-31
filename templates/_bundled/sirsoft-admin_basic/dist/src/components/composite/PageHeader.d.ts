import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
import { BreadcrumbItem } from './Breadcrumb';
import { EditorAttrs } from '../../types';
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
    /**
     * 바로가기 주소 — 있으면 `<a href>` 로 그려 가운데 클릭·Ctrl+클릭 「새 탭에서 열기」가 된다(#757).
     * 보통 클릭은 onClick(없으면 SPA 이동), 수정키 클릭은 브라우저 기본 동작. 바깥 주소는 새 탭으로 연다.
     * `disabled` 면 링크로 그리지 않고 종전처럼 비활성 단추다(href 없음).
     */
    href?: string;
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
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * PageHeader 컴포넌트
 *
 * 페이지 헤더 - admin_dashboard.g7.html의 page_header 스타일과 동일
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
