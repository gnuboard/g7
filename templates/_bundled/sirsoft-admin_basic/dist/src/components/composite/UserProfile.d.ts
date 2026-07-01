import { default as React } from 'react';
import { EditorAttrs } from '../../types';
/**
 * 사용자 정보 인터페이스
 */
export interface User {
    id: number | string;
    uuid?: string;
    name: string;
    email: string;
    avatar?: string;
    role?: string;
}
/**
 * UserProfile Props
 */
export interface UserProfileProps {
    user: User;
    /** 프로필 설정 텍스트 */
    profileText?: string;
    /** 로그아웃 텍스트 */
    logoutText?: string;
    /** 언어 설정 텍스트 */
    languageText?: string;
    /** 사용 가능한 언어 목록 */
    availableLocales?: string[];
    onProfileClick?: () => void;
    onLogoutClick?: () => void;
    className?: string;
    /** 로그아웃 API 엔드포인트 (기본값: /api/admin/auth/logout) */
    logoutEndpoint?: string;
    /** 로그아웃 후 리다이렉션 경로 (기본값: /admin/login) */
    redirectPath?: string;
    /** Chevron 아이콘 표시 여부 (기본값: true) */
    showChevron?: boolean;
    /** 드롭다운 열림 방향 (기본값: 'up') */
    dropdownDirection?: 'up' | 'down';
    /** 드롭다운만 표시 (버튼 영역 숨김, 외부 요소 클릭 트리거용) */
    dropdownOnly?: boolean;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * UserProfile 컴포넌트
 *
 * 사용자 프로필 표시 및 드롭다운 메뉴 제공
 *
 * @example
 * ```tsx
 * <UserProfile
 *   user={{
 *     id: 1,
 *     name: '홍길동',
 *     email: 'hong@example.com',
 *     avatar: '/avatar.png',
 *     role: '관리자'
 *   }}
 *   onProfileClick={() => console.log('프로필 클릭')}
 *   onLogoutClick={() => console.log('로그아웃')}
 * />
 * ```
 */
export declare const UserProfile: React.FC<UserProfileProps>;
