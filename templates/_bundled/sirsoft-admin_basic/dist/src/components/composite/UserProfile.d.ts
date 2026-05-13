import { default as React } from 'react';
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
