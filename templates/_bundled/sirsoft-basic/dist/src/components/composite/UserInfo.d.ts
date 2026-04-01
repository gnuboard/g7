import { default as React } from 'react';
/**
 * 메뉴 항목 설정 인터페이스
 */
export interface MenuItemConfig {
    /** 메뉴 항목 식별자 */
    key: string;
    /** 표시 텍스트 */
    label: string;
    /** 아이콘 이름 (선택) */
    icon?: string;
    /** 이동 경로 ({{userId}} 치환 지원) */
    path?: string;
    /** 커스텀 클릭 핸들러 (선택) */
    onClick?: () => void;
    /** 표시 여부 (기본: true) */
    show?: boolean;
}
/**
 * Author 객체 인터페이스
 */
export interface AuthorInfo {
    /** 사용자 ID */
    id?: string | number;
    /** 사용자 UUID */
    uuid?: string;
    /** 사용자 이름 */
    name?: string;
    /** 아바타 URL */
    avatar?: string;
    /** 사용자 상태 */
    status?: 'active' | 'inactive' | 'blocked' | 'withdrawn';
    /** 비회원 여부 */
    is_guest?: boolean;
}
export interface UserInfoProps {
    /**
     * 작성자 객체 (권장)
     * API 응답의 author 객체를 그대로 전달하면 됩니다.
     * 개별 props(name, userId 등)가 함께 전달되면 개별 props가 우선합니다.
     */
    author?: AuthorInfo;
    /**
     * 사용자 이름 (author.name보다 우선)
     */
    name?: string;
    /**
     * 사용자 ID (author.id보다 우선)
     */
    userId?: string | number;
    /**
     * 표시할 서브 텍스트 (날짜, 시간 등)
     */
    subText?: string;
    /**
     * 서브 텍스트의 title 속성 (tooltip용 원본 datetime)
     */
    subTextTitle?: string;
    /**
     * 비회원 여부 (author.is_guest보다 우선)
     * @default false
     */
    isGuest?: boolean;
    /**
     * 탈퇴한 사용자 여부 (author.status === 'withdrawn'과 OR 조건)
     * @default false
     */
    isWithdrawn?: boolean;
    /**
     * 드롭다운 표시 여부
     * @default true
     */
    showDropdown?: boolean;
    /**
     * 프로필 클릭 가능 여부 (드롭다운이 없을 때)
     * @default true
     */
    clickable?: boolean;
    /**
     * 사용자 프로필 경로 패턴
     * @default '/users/{userId}'
     */
    profilePath?: string;
    /**
     * 추가 className
     */
    className?: string;
    /**
     * 레이아웃 방향
     * @default 'vertical'
     */
    layout?: 'vertical' | 'horizontal';
    /**
     * 레이아웃 JSON에서 text 속성으로 전달되는 이름
     * name보다 우선순위가 높음
     */
    text?: string;
    /**
     * 이벤트 버블링 방지 여부
     * @default false
     */
    stopPropagation?: boolean;
    /** 커스텀 메뉴 항목 (지정 시 기본 메뉴 대체) */
    menuItems?: MenuItemConfig[];
    /** 숨길 기본 메뉴 항목 키 배열 */
    hideMenuItems?: string[];
    /** 기본 메뉴에 추가할 항목 */
    appendMenuItems?: MenuItemConfig[];
}
/**
 * UserInfo 컴포넌트
 */
export declare const UserInfo: React.FC<UserInfoProps>;
