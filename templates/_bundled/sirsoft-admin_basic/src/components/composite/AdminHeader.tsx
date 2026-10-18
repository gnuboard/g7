import React, { useState, useRef, useEffect } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Img } from '../basic/Img';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * 사용자 정보 인터페이스
 */
export interface AdminUser {
  name: string;
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
export const AdminHeader: React.FC<AdminHeaderProps> = ({
  user = { name: '', email: '' },
  notifications = [],
  onNotificationClick,
  onProfileClick,
  onLogoutClick,
  className = '',
}) => {
  const [showNotifications, setShowNotifications] = useState(false);
  const [showProfileMenu, setShowProfileMenu] = useState(false);
  const notificationRef = useRef<HTMLDivElement>(null);
  const profileRef = useRef<HTMLDivElement>(null);

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
      if (
        profileRef.current &&
        !profileRef.current.contains(event.target as Node)
      ) {
        setShowProfileMenu(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <Div
      className={`
        bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex items-center justify-end gap-4
        ${className}
      `}
    >
      {/* 알림 센터 */}
      <Div ref={notificationRef} className="relative">
        <Button
          onClick={() => setShowNotifications(!showNotifications)}
          className="relative p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors"
          aria-label={t('common.notifications')}
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
              <Span className="font-semibold text-gray-900 dark:text-white">{t('common.notifications')}</Span>
            </Div>
            <Div className="max-h-96 overflow-y-auto">
              {notifications.length === 0 ? (
                <Div className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                  <Span>{t('common.no_notifications')}</Span>
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
                      ${!notification.read ? 'bg-blue-50 dark:bg-blue-900/20' : ''}
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
                        <Div className="text-gray-600 dark:text-gray-400 text-sm mt-1">
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

      {/* 사용자 프로필 */}
      <Div ref={profileRef} className="relative">
        <Button
          onClick={() => setShowProfileMenu(!showProfileMenu)}
          className="flex items-center gap-3 px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
        >
          {user.avatar ? (
            <Img
              src={user.avatar}
              alt={user.name}
              className="w-8 h-8 rounded-full object-cover"
            />
          ) : (
            <Div className="w-8 h-8 rounded-full bg-gray-300 dark:bg-gray-600 flex items-center justify-center">
              <Icon name={IconName.User} className="w-5 h-5 text-gray-600 dark:text-gray-400" />
            </Div>
          )}
          <Div className="hidden md:block text-left">
            <Div className="font-semibold text-gray-900 dark:text-white text-sm">{user.name}</Div>
            {user.role && (
              <Div className="text-gray-500 dark:text-gray-400 text-xs">{user.role}</Div>
            )}
          </Div>
          <Icon name={IconName.ChevronDown} className="w-4 h-4 text-gray-600 dark:text-gray-400" />
        </Button>

        {/* 프로필 드롭다운 */}
        {showProfileMenu && (
          <Div className="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50">
            <Div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
              <Div className="font-semibold text-gray-900 dark:text-white">{user.name}</Div>
              <Div className="text-gray-500 dark:text-gray-400 text-sm">{user.email}</Div>
            </Div>
            <Div className="py-2">
              <Button
                onClick={() => {
                  onProfileClick?.();
                  setShowProfileMenu(false);
                }}
                className="w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 transition-colors text-gray-900 dark:text-white"
              >
                <Icon name={IconName.User} className="w-5 h-5 text-gray-600 dark:text-gray-400" />
                <Span>{t('common.profile_settings')}</Span>
              </Button>
              <Button
                onClick={() => {
                  onLogoutClick?.();
                  setShowProfileMenu(false);
                }}
                className="w-full px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-3 text-red-600 dark:text-red-400 transition-colors"
              >
                <Icon name={IconName.ArrowRight} className="w-5 h-5" />
                <Span>{t('common.logout')}</Span>
              </Button>
            </Div>
          </Div>
        )}
      </Div>
    </Div>
  );
};
