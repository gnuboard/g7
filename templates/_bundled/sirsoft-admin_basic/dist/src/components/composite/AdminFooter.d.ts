import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
/**
 * 빠른 링크 인터페이스
 */
export interface QuickLink {
    id: string | number;
    label: string;
    url: string;
    iconName?: IconName;
}
/**
 * AdminFooter Props
 */
export interface AdminFooterProps {
    copyright?: string;
    version?: string;
    quickLinks?: QuickLink[];
    className?: string;
    copyrightModalId?: string;
    changelogModalId?: string;
}
/**
 * AdminFooter 컴포넌트
 *
 * 관리자 푸터 - 버전 정보, 빠른 링크
 *
 * @example
 * ```tsx
 * <AdminFooter
 *   copyright="© 2026 G7"
 *   version="1.0.0"
 *   quickLinks={[
 *     { id: 1, label: '문서', url: '/docs', iconName: IconName.FileText },
 *     { id: 2, label: '지원', url: '/support', iconName: IconName.HelpCircle }
 *   ]}
 * />
 * ```
 */
export declare const AdminFooter: React.FC<AdminFooterProps>;
