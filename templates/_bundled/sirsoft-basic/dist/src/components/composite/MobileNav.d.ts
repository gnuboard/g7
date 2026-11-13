import { default as React } from 'react';
interface Board {
    id: number;
    name: string;
    slug: string;
    icon?: string;
}
interface User {
    id: number;
    /** 실명. 이름 항목을 쓰지 않는 사이트에서는 null 이다 */
    name: string | null;
    /** 사이트 기본 영역에서 보이는 이름(표시 이름). 드로어는 이 값을 먼저 쓴다 */
    display_name?: string | null;
    avatar?: string;
}
interface MobileNavProps {
    /** 드로어 열림 상태 */
    isOpen: boolean;
    /** 닫기 콜백 */
    onClose: (event?: Event | React.SyntheticEvent) => void;
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
    /** 일반 회원가입을 받는지 — false 면 회원가입 버튼을 숨긴다 (기본 true, 7.2.0) */
    registrationEnabled?: boolean;
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
