import React, { useState, useRef, useEffect } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
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
export const NotificationCenter: React.FC<NotificationCenterProps> = ({
  notifications = [],
  titleText = 'Notifications',
  emptyText = 'No notifications',
  onNotificationClick,
  className = '',
}) => {
  const [showNotifications, setShowNotifications] = useState(false);
  const notificationRef = useRef<HTMLDivElement>(null);

  const unreadCount = notifications.filter((n) => !n.read).length;

  /**
   * 외부 클릭 감지
   */
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (
        notificationRef.current &&
        !notificationRef.current.contains(event.target as Node)
      ) {
        setShowNotifications(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <Div ref={notificationRef} className={`relative ${className}`}>
      <Button
        onClick={() => setShowNotifications(!showNotifications)}
        className="relative p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition-colors"
        aria-label={titleText}
      >
        <Icon name={IconName.Bell} className="w-5 h-5 text-gray-600 dark:text-gray-400" />
        {unreadCount > 0 && (
          <Span className="absolute top-0 right-0 flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full">
            {unreadCount > 9 ? '9+' : unreadCount}
          </Span>
        )}
      </Button>

      {/* 알림 드롭다운 */}
      {showNotifications && (
        <Div className="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50">
          <Div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <Span className="font-semibold text-gray-900 dark:text-white">{titleText}</Span>
          </Div>
          <Div className="max-h-96 overflow-y-auto">
            {notifications.length === 0 ? (
              <Div className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                <Span>{emptyText}</Span>
              </Div>
            ) : (
              notifications.map((notification) => (
                <Button
                  key={notification.id}
                  onClick={() => {
                    notification.onClick?.();
                    onNotificationClick?.(notification.id);
                    setShowNotifications(false);
                  }}
                  className={`
                    w-full px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700 transition-colors
                    ${!notification.read ? 'bg-blue-50 dark:bg-gray-700' : ''}
                  `}
                >
                  <Div className="flex items-start gap-3">
                    {notification.iconName && (
                      <Icon
                        name={notification.iconName}
                        className="w-5 h-5 text-gray-600 dark:text-gray-400 mt-0.5"
                      />
                    )}
                    <Div className="flex-1">
                      <Div className="font-semibold text-gray-900 dark:text-white text-sm">
                        {notification.title}
                      </Div>
                      <Div className="text-gray-600 dark:text-gray-300 text-sm mt-1">
                        {notification.message}
                      </Div>
                      <Div className="text-gray-400 dark:text-gray-500 text-xs mt-1">
                        {notification.time}
                      </Div>
                    </Div>
                    {!notification.read && (
                      <Span className="w-2 h-2 bg-blue-500 rounded-full mt-1.5" />
                    )}
                  </Div>
                </Button>
              ))
            )}
          </Div>
        </Div>
      )}
    </Div>
  );
};
