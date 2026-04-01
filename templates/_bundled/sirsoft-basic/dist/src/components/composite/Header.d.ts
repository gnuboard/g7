import { default as React } from 'react';
interface Board {
    id: number;
    name: string;
    slug: string;
    icon?: string;
}
interface User {
    uuid: string;
    name: string;
    avatar?: string;
    is_admin?: boolean;
}
interface HeaderProps {
    /** 사이트 로고 URL */
    logo?: string;
    /** 사이트 이름 */
    siteName?: string;
    /** 현재 로그인된 사용자 */
    user?: User | null;
    /** 장바구니 아이템 수 */
    cartCount?: number;
    /** 읽지 않은 알림 수 */
    notificationCount?: number;
    /** 게시판 목록 */
    boards?: Board[];
    /** 탭에 표시할 최대 게시판 수 */
    maxVisibleBoards?: number;
    /** 모바일 메뉴 열기 콜백 */
    onMobileMenuOpen?: () => void;
    /** 사용 가능한 언어 목록 */
    availableLocales?: string[];
    /** 현재 언어 */
    currentLocale?: string;
    /** 쇼핑몰 기본 경로 (예: "/shop", "/") */
    shopBase?: string;
    /** 추가 CSS 클래스 */
    className?: string;
}
/**
 * 사이트 헤더 컴포넌트
 *
 * @example
 * ```json
 * // 레이아웃 JSON에서 사용
 * {
 *   "type": "composite",
 *   "name": "Header",
 *   "props": {
 *     "logo": "{{_global.settings.site_logo}}",
 *     "siteName": "{{_global.settings.site_name}}",
 *     "user": "{{_global.currentUser}}",
 *     "cartCount": "{{_global.cartCount}}",
 *     "notificationCount": "{{_global.notificationCount}}",
 *     "boards": "{{boards.data}}",
 *     "maxVisibleBoards": 5
 *   }
 * }
 * ```
 */
declare const Header: React.FC<HeaderProps>;
export default Header;
