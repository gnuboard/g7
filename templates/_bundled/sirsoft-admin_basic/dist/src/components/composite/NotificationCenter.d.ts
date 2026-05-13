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
    data?: Record<string, unknown>;
    onClick?: () => void;
}
/**
 * NotificationCenter Props
 */
export interface NotificationCenterProps {
    /** 알림 목록 */
    notifications?: NotificationItem[];
    /** 서버 기반 미읽음 알림 수 */
    unreadCount?: number;
    /** 추가 로드 가능 여부 */
    hasMore?: boolean;
    /** 로딩 상태 */
    loading?: boolean;
    /** 알림 제목 텍스트 */
    titleText?: string;
    /** 알림 없음 텍스트 */
    emptyText?: string;
    /** "모두 읽음" 버튼 텍스트 */
    markAllReadText?: string;
    /** "모두 삭제" 버튼 텍스트 */
    deleteAllText?: string;
    /** "미읽음만" 체크박스 텍스트 */
    unreadOnlyText?: string;
    /** "미읽음만" 체크박스 상태 */
    unreadOnly?: boolean;
    /** 알림 클릭 핸들러 (알림 전체 객체 전달) */
    onNotificationClick?: (notification: NotificationItem) => void;
    /** 드롭다운 닫힐 때 (뷰포트에 들어온 미읽음 알림 ID 목록) */
    onClose?: (visibleUnreadIds: (string | number)[]) => void;
    /** 추가 로드 요청 */
    onLoadMore?: () => void;
    /** 모두 읽음 처리 요청 */
    onMarkAllRead?: () => void;
    /** 모두 삭제 요청 */
    onDeleteAll?: () => void;
    /** 개별 알림 삭제 요청 (알림 전체 객체 전달) */
    onDelete?: (notification: NotificationItem) => void;
    /** "미읽음만" 체크박스 토글 */
    onUnreadOnlyToggle?: (checked: boolean) => void;
    /** 드롭다운 정렬 방향 — "right"(기본): 우측 정렬되어 좌측으로 확장 / "left": 좌측 정렬되어 우측으로 확장 */
    dropdownAlign?: 'left' | 'right';
    className?: string;
}
/**
 * NotificationCenter 컴포넌트
 *
 * 알림 센터 - 알림 목록 표시, 무한스크롤, 읽음 추적
 *
 * @example
 * ```tsx
 * <NotificationCenter
 *   notifications={[
 *     { id: 1, title: '새 댓글', message: '게시물에 댓글이 달렸습니다', time: '5분 전' }
 *   ]}
 *   unreadCount={5}
 *   hasMore={true}
 *   titleText="Notifications"
 *   emptyText="No notifications"
 * />
 * ```
 */
export declare const NotificationCenter: React.FC<NotificationCenterProps>;
