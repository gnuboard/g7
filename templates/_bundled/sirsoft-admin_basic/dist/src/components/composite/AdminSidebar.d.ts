import { default as React } from 'react';
/**
 * 메뉴 아이템 인터페이스 (API 응답 구조)
 */
export interface MenuItem {
    id: string | number;
    name: string | {
        ko: string;
        en: string;
    };
    slug: string;
    url?: string | null;
    icon?: string;
    children?: MenuItem[];
    is_active?: boolean;
    module_id?: number | null;
}
/**
 * AdminSidebar Props
 */
export interface AdminSidebarProps {
    logo?: string;
    logoAlt?: string;
    menu: MenuItem[];
    collapsed?: boolean;
    onToggleCollapse?: () => void;
    className?: string;
    /** 현재 로케일 (다국어 메뉴 이름 표시용). 미지정 시 G7Core.locale.current() 자동 사용 */
    currentLocale?: string;
}
/**
 * AdminSidebar 컴포넌트
 *
 * 관리자 사이드바 - 계층형 메뉴, 접기/펼치기 기능 제공
 *
 * @example
 * ```tsx
 * <AdminSidebar
 *   logo="/logo.png"
 *   menu={[
 *     { id: 1, name: { ko: '대시보드', en: 'Dashboard' }, slug: 'dashboard', url: '/admin', icon: 'fas fa-tachometer-alt' },
 *     {
 *       id: 2,
 *       name: { ko: '콘텐츠', en: 'Content' },
 *       slug: 'content',
 *       icon: 'fas fa-file-text',
 *       children: [
 *         { id: 21, name: { ko: '게시물', en: 'Posts' }, slug: 'posts', url: '/admin/posts', icon: 'fas fa-list' },
 *         { id: 22, name: { ko: '페이지', en: 'Pages' }, slug: 'pages', url: '/admin/pages', icon: 'fas fa-file' }
 *       ]
 *     }
 *   ]}
 *   collapsed={false}
 * />
 * ```
 */
export declare const AdminSidebar: React.FC<AdminSidebarProps>;
