import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
/**
 * 사용자 정보 인터페이스
 */
export interface AdminUser {
    /** 실명. 이름 항목을 쓰지 않는 사이트에서는 null 이다 */
    name: string | null;
    /** 관리자 화면 영역에서 보이는 이름(표시 이름). 이 값을 먼저 쓴다 */
    display_name?: string | null;
    email: string;
    avatar?: string;
    role?: string;
}
/**
 * 알림 아이템 인터페이스
 */
export interface NotificationItem {
    id: string | number;
    title: string;
    message: string;
    time: string;
    read?: boolean;
    iconName?: IconName;
    onClick?: () => void;
}
/**
 * AdminHeader Props
 */
export interface AdminHeaderProps {
    user: AdminUser;
    notifications?: NotificationItem[];
    onNotificationClick?: (id: string | number) => void;
    onProfileClick?: () => void;
    onLogoutClick?: () => void;
    className?: string;
}
/**
 * AdminHeader 컴포넌트
 *
 * 관리자 헤더 - 사용자 프로필, 알림 센터
 *
 * @example
 * ```tsx
 * <AdminHeader
 *   user={{
 *     name: '홍길동',
 *     email: 'hong@example.com',
 *     avatar: '/avatar.png',
 *     role: '관리자'
 *   }}
 *   notifications={[
 *     { id: 1, title: '새 댓글', message: '게시물에 댓글이 달렸습니다', time: '5분 전' }
 *   ]}
 * />
 * ```
 */
export declare const AdminHeader: React.FC<AdminHeaderProps>;
