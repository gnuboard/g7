import { default as React } from 'react';
interface Board {
    id: number;
    name: string;
    slug: string;
    icon?: string;
}
interface User {
    id: number;
    name: string;
    avatar?: string;
}
interface MobileNavProps {
    /** 드로어 열림 상태 */
    isOpen: boolean;
    /** 닫기 콜백 */
    onClose: () => void;
    /** 사이트 로고 URL */
    logo?: string;
    /** 사이트 이름 */
    siteName?: string;
    /** 현재 로그인된 사용자 */
    user?: User | null;
    /** 게시판 목록 */
    boards?: Board[];
    /** 장바구니 아이템 수 */
    cartCount?: number;
}
/**
 * 모바일 네비게이션 드로어
 *
 * @example
 * ```tsx
 * const [isOpen, setIsOpen] = useState(false);
 *
 * <MobileNav
 *   isOpen={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   user={currentUser}
 *   boards={boards}
 * />
 * ```
 */
declare const MobileNav: React.FC<MobileNavProps>;
export default MobileNav;
