import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
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
 * NotificationCenter Props
 */
export interface NotificationCenterProps {
    /** 알림 목록 */
    notifications?: NotificationItem[];
    /** 알림 제목 텍스트 */
    titleText?: string;
    /** 알림 없음 텍스트 */
    emptyText?: string;
    /** 알림 클릭 핸들러 */
    onNotificationClick?: (id: string | number) => void;
    className?: string;
}
/**
 * NotificationCenter 컴포넌트
 *
 * 알림 센터 - 알림 목록 표시 및 관리
 *
 * @example
 * ```tsx
 * <NotificationCenter
 *   notifications={[
 *     { id: 1, title: '새 댓글', message: '게시물에 댓글이 달렸습니다', time: '5분 전' }
 *   ]}
 *   titleText="Notifications"
 *   emptyText="No notifications"
 * />
 * ```
 */
export declare const NotificationCenter: React.FC<NotificationCenterProps>;
