import { default as React } from 'react';
import { NotificationItem } from './NotificationCenter';
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
    /**
     * 게시판 모듈이 켜져 있는가 — false 면 「인기글」 탭과 게시판 탭·더보기를 그리지 않는다.
     * 기본값 true: 레이아웃이 `_global.activeModules` 로 판정해 넘긴다. 값이 오지 않는 경우(루트 레이아웃을 keep 으로
     * 업데이트해 새 바인딩이 없는 사이트)에는 지금처럼 보인다(#822).
     */
    boardEnabled?: boolean;
    /** 쇼핑몰 모듈이 켜져 있는가 — false 면 장바구니 단추·「쇼핑」 탭·회원 메뉴의 주문내역·위시리스트·비회원 「주문조회」를 그리지 않는다. 기본값 true(위와 같은 이유) */
    shopEnabled?: boolean;
    /** 검색칸을 그리는가 — 레이아웃은 게시판·쇼핑몰·페이지 중 하나라도 켜져 있으면 true 를 넘긴다(검색 공급자가 없으면 숨김). 기본값 true */
    searchEnabled?: boolean;
    /**
     * 검색칸의 최근 검색어 범위 — 같은 범위를 쓰는 검색창(모바일 서랍·통합검색 화면)과 목록을 공유한다.
     * 기본값 `core.site`(코어 통합검색). 엔진이 그 범위를 쓸 수 없다고 답하면 펼침 목록 없이 평범한 검색칸이다
     */
    searchHistoryScope?: string;
    /**
     * 봇 화면(seo-config header_nav)의 「쇼핑」 링크 주소(예: "/shop/products") — 이 컴포넌트는 읽지 않는다(화면은 shopBase 로 만든다).
     * 옛 코어(7.1.3 이하)의 봇 렌더가 `{shopBase}/products` 같은 섞인 attrs 패턴을 치환하지 못해 레이아웃이 완성된 주소를 넘긴다.
     */
    shopProductsHref?: string;
    /** 추가 CSS 클래스 */
    className?: string;
    /** 레이아웃 편집기 식별 속성(data-editor-*) — 시각적 루트에 spread */
    editorAttrs?: Record<string, unknown>;
    /** 알림 목록 (NotificationCenter에 전달) */
    notifications?: NotificationItem[];
    /** 더 불러올 페이지 존재 여부 */
    notificationHasMore?: boolean;
    /** 알림 로딩 상태 */
    notificationLoading?: boolean;
    /** "안 읽은 알림만" 필터 상태 */
    notificationUnreadOnly?: boolean;
    /**
     * 알림 읽음 처리 권한 — false 면 [모두 읽음] 숨김·닫을 때 읽음 처리 생략.
     * 기본값 true(NotificationCenter 와 같음): 권한이 없으면 레이아웃이 abilities 를 읽어 false 를 명시한다.
     * 값이 오지 않는 경우(루트 레이아웃을 keep 으로 업데이트해 바인딩이 없는 사이트)에 기능을 숨기지 않으며, 최종 방어선은 서버 403.
     */
    notificationCanUpdate?: boolean;
    /** 알림 삭제 권한 — false 면 [모두 삭제]·개별 삭제 숨김. 기본값 true(위와 같은 이유) */
    notificationCanDelete?: boolean;
    /** 알림 드롭다운 제목 */
    notificationTitleText?: string;
    /** 알림 없음 텍스트 */
    notificationEmptyText?: string;
    /** "모두 읽음" 텍스트 */
    notificationMarkAllReadText?: string;
    /** "모두 삭제" 텍스트 */
    notificationDeleteAllText?: string;
    /** "안 읽은 알림만" 체크박스 텍스트 */
    notificationUnreadOnlyText?: string;
    /** 알림 드롭다운 닫힐 때 (뷰포트에 보인 미읽음 ID 배열 전달) */
    onNotificationClose?: (visibleUnreadIds: (string | number)[]) => void;
    /** 개별 알림 클릭 */
    onNotificationClick?: (notification: NotificationItem) => void;
    /** 무한 스크롤: 추가 로드 */
    onNotificationLoadMore?: () => void;
    /** "모두 읽음" 처리 */
    onNotificationMarkAllRead?: () => void;
    /** "모두 삭제" 요청 (모달 오픈) */
    onNotificationDeleteAll?: () => void;
    /** 개별 알림 삭제 */
    onNotificationDelete?: (notification: NotificationItem) => void;
    /** "안 읽은 알림만" 체크박스 토글 */
    onNotificationUnreadOnlyToggle?: (checked: boolean) => void;
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
