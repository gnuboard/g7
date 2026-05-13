import React, { useState, useRef, useEffect, useCallback, useLayoutEffect } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { Input } from '../basic/Input';

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
export const NotificationCenter: React.FC<NotificationCenterProps> = ({
  notifications = [],
  unreadCount = 0,
  hasMore = false,
  loading = false,
  titleText = 'Notifications',
  emptyText = 'No notifications',
  markAllReadText = 'Mark all as read',
  deleteAllText = 'Delete all',
  unreadOnlyText = 'Unread only',
  unreadOnly = false,
  onNotificationClick,
  onClose,
  onLoadMore,
  onMarkAllRead,
  onDeleteAll,
  onDelete,
  onUnreadOnlyToggle,
  dropdownAlign = 'right',
  className = '',
}) => {
  const [showNotifications, setShowNotifications] = useState(false);
  const notificationRef = useRef<HTMLDivElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const sentinelRef = useRef<HTMLDivElement>(null);
  const visibleUnreadIdsRef = useRef<Set<string | number>>(new Set());

  // 무한스크롤 관련 최신 값 참조 (observer 재등록 없이 최신 prop 접근)
  const onLoadMoreRef = useRef(onLoadMore);
  const hasMoreRef = useRef(hasMore);
  const loadingRef = useRef(loading);
  const notificationsCountRef = useRef(notifications.length);
  // 마지막 onLoadMore 발행 시점의 notifications.length — 동일 길이에서 중복 발행 가드
  const lastRequestedCountRef = useRef<number>(-1);

  useEffect(() => {
    onLoadMoreRef.current = onLoadMore;
    hasMoreRef.current = hasMore;
    loadingRef.current = loading;
    notificationsCountRef.current = notifications.length;
  });

  /**
   * 무한스크롤 — IntersectionObserver
   *
   * observer는 드롭다운 열림/닫힘 전환 시에만 재등록한다.
   * 목록이 append되거나 loading/hasMore가 바뀌어도 observer를 새로 만들지 않으므로,
   * sentinel이 이미 뷰포트에 있는 상태에서 재등록 시 즉시 intersecting callback이
   * 중복 발행되던 문제를 방지한다.
   */
  useEffect(() => {
    if (!sentinelRef.current || !scrollRef.current || !showNotifications) return;

    // 드롭다운이 새로 열릴 때마다 중복 가드 초기화
    lastRequestedCountRef.current = -1;

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting) return;
        if (loadingRef.current || !hasMoreRef.current) return;
        // B안 가드: 같은 length에서 이미 한 번 발행했다면 skip
        if (lastRequestedCountRef.current === notificationsCountRef.current) return;
        lastRequestedCountRef.current = notificationsCountRef.current;
        onLoadMoreRef.current?.();
      },
      { root: scrollRef.current, threshold: 0.1 }
    );

    observer.observe(sentinelRef.current);
    return () => observer.disconnect();
  }, [showNotifications]);

  /**
   * 뷰포트 내 미읽음 알림 추적 — IntersectionObserver
   */
  useEffect(() => {
    if (!scrollRef.current || !showNotifications) return;

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          const id = entry.target.getAttribute('data-notification-id');
          const isUnread = entry.target.getAttribute('data-unread') === 'true';
          if (id && isUnread && entry.isIntersecting) {
            visibleUnreadIdsRef.current.add(id);
          }
        });
      },
      {
        root: scrollRef.current,
        threshold: 0.5,
      }
    );

    const items = scrollRef.current.querySelectorAll('[data-notification-id]');
    items.forEach((item) => observer.observe(item));

    return () => observer.disconnect();
  }, [showNotifications, notifications]);

  /**
   * 뱃지에 표시할 카운트 (handleClose 보다 먼저 선언 — deps 참조 순서)
   */
  const displayCount = unreadCount > 0 ? unreadCount : notifications.filter((n) => !n.read).length;

  /**
   * 드롭다운 닫기 — 뷰포트에 들어온 미읽음 알림 ID 전달
   *
   * 불필요한 read-batch 호출 방지:
   *  1) displayCount 가 0 이면 이미 모두 읽음/삭제 처리된 상태 → 전체 skip
   *  2) 뷰포트에 나타났던 ID 중 현재 notifications 기준 여전히 unread 인 것만 전달
   *     ("모두 읽음" onSuccess 로 notifications 가 갱신되면 필터 결과가 빈 배열이 됨)
   */
  const handleClose = useCallback(() => {
    if (showNotifications) {
      if (displayCount > 0) {
        const unreadIds = Array.from(visibleUnreadIdsRef.current).filter((id) => {
          const n = notifications.find((item) => String(item.id) === String(id));
          return n && !n.read;
        });
        if (unreadIds.length > 0) {
          onClose?.(unreadIds);
        }
      }
      visibleUnreadIdsRef.current.clear();
      setShowNotifications(false);
    }
  }, [showNotifications, onClose, displayCount, notifications]);

  /**
   * 외부 클릭 감지 — 드롭다운이 열려 있을 때만 리스너 등록
   * (stale closure 방지: showNotifications 변경 시 리스너 재등록)
   * 주의: handleClose가 useCallback으로 선언된 이후에 위치해야 TDZ 위반 방지
   */
  useEffect(() => {
    if (!showNotifications) return;

    const handleClickOutside = (event: MouseEvent) => {
      if (
        notificationRef.current &&
        !notificationRef.current.contains(event.target as Node)
      ) {
        handleClose();
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [showNotifications, handleClose]);

  /**
   * 드롭다운 토글
   */
  const handleToggle = useCallback(() => {
    if (showNotifications) {
      handleClose();
    } else {
      setShowNotifications(true);
    }
  }, [showNotifications, handleClose]);

  /**
   * 드롭다운 열릴 때 뷰포트 경계 검사 후 이탈량만큼 translateX 로 보정
   *
   * class 기반 left-0/right-0 전환은 부모 컨테이너 기준이라 부모가 뷰포트 가장자리에 있을 때
   * 여전히 이탈할 수 있다. 따라서 초기 렌더 후 드롭다운의 실제 뷰포트 좌표를 측정하여
   * 이탈한 픽셀만큼 반대 방향으로 이동시키는 방식을 사용한다.
   */
  useLayoutEffect(() => {
    const dropdown = dropdownRef.current;
    if (!showNotifications || !dropdown) return;

    // 측정 전 기존 transform 초기화 (재측정 시 누적 방지)
    dropdown.style.transform = '';
    const rect = dropdown.getBoundingClientRect();
    const vw = typeof window !== 'undefined' ? window.innerWidth : 0;

    // layout 미측정(jsdom 등)에서 rect 가 0 으로 반환되는 경우 보정 스킵
    if (rect.width === 0 && rect.height === 0) return;

    const margin = 8;
    let shift = 0;
    if (rect.right > vw - margin) {
      shift = -(rect.right - (vw - margin));
    } else if (rect.left < margin) {
      shift = margin - rect.left;
    }

    if (shift !== 0) {
      dropdown.style.transform = `translateX(${shift}px)`;
    }
  }, [showNotifications, dropdownAlign, notifications.length]);

  return (
    <Div ref={notificationRef} className={`relative ${className}`}>
      <Button
        onClick={handleToggle}
        className="relative p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-full transition-colors cursor-pointer"
        aria-label={titleText}
      >
        <Icon name={IconName.Bell} className="w-5 h-5 text-gray-600 dark:text-gray-400" />
        {displayCount > 0 && (
          <Span className="absolute top-0 right-0 flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 dark:bg-red-600 rounded-full">
            {displayCount > 99 ? '99+' : displayCount}
          </Span>
        )}
      </Button>

      {/* 알림 드롭다운 */}
      {showNotifications && (
        <Div ref={dropdownRef} className={`absolute ${dropdownAlign === 'left' ? 'left-0' : 'right-0'} mt-2 w-80 max-w-[calc(100vw-1rem)] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg z-50`}>
          <Div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between gap-2">
            <Div className="flex items-center gap-3 min-w-0">
              <Span className="font-semibold text-gray-900 dark:text-white">{titleText}</Span>
              {onUnreadOnlyToggle && (
                <Div
                  className="flex items-center gap-1.5 cursor-pointer select-none"
                  onClick={() => onUnreadOnlyToggle(!unreadOnly)}
                >
                  <Input
                    type="checkbox"
                    checked={unreadOnly}
                    onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                      e.stopPropagation();
                      onUnreadOnlyToggle(e.target.checked);
                    }}
                    onClick={(e: React.MouseEvent) => e.stopPropagation()}
                    className="w-3.5 h-3.5 rounded border-gray-300 dark:border-gray-600 text-blue-600 dark:text-blue-400 focus:ring-blue-500 dark:focus:ring-blue-400 cursor-pointer"
                  />
                  <Span className="text-xs text-gray-600 dark:text-gray-400">{unreadOnlyText}</Span>
                </Div>
              )}
            </Div>
            <Div className="flex items-center gap-3 flex-shrink-0">
              {onMarkAllRead && displayCount > 0 && (
                <Button
                  onClick={() => {
                    onMarkAllRead();
                    visibleUnreadIdsRef.current.clear();
                  }}
                  className="text-xs text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:underline cursor-pointer"
                >
                  {markAllReadText}
                </Button>
              )}
              {onDeleteAll && notifications.length > 0 && (
                <Button
                  onClick={() => {
                    // 드롭다운은 닫지 않음 — 확인 모달이 닫히면 다시 자연스럽게 노출되어야 하고,
                    // 사용자가 모달 취소 시에도 컨텍스트 유지 (요구사항)
                    onDeleteAll();
                  }}
                  className="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 hover:underline cursor-pointer"
                >
                  {deleteAllText}
                </Button>
              )}
            </Div>
          </Div>
          <Div ref={scrollRef} className="max-h-96 overflow-y-auto">
            {notifications.length === 0 && !loading ? (
              <Div className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                <Span>{emptyText}</Span>
              </Div>
            ) : (
              <>
                {notifications.map((notification) => {
                  const isUnread = !notification.read;
                  return (
                    <Div
                      key={notification.id}
                      data-notification-id={notification.id}
                      data-unread={isUnread ? 'true' : 'false'}
                      onClick={() => {
                        notification.onClick?.();
                        onNotificationClick?.(notification);
                        setShowNotifications(false);
                      }}
                      className={`group flex items-start gap-3 px-4 py-3 text-left border-b border-gray-100 dark:border-gray-700 transition-colors cursor-pointer ${
                        isUnread
                          ? 'bg-blue-50/70 dark:bg-blue-900/20 hover:bg-blue-50 dark:hover:bg-blue-900/30'
                          : 'bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700/50 opacity-70'
                      }`}
                    >
                      {notification.iconName && (
                        <Icon
                          name={notification.iconName}
                          className={`w-5 h-5 mt-0.5 flex-shrink-0 ${
                            isUnread ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-500'
                          }`}
                        />
                      )}
                      <Div className="flex-1 min-w-0">
                        <Div
                          className={`text-sm truncate ${
                            isUnread
                              ? 'font-semibold text-gray-900 dark:text-white'
                              : 'font-normal text-gray-600 dark:text-gray-400'
                          }`}
                        >
                          {notification.title}
                        </Div>
                        <Div
                          className={`text-sm mt-1 line-clamp-2 ${
                            isUnread ? 'text-gray-700 dark:text-gray-300' : 'text-gray-500 dark:text-gray-500'
                          }`}
                        >
                          {notification.message}
                        </Div>
                        <Div className="text-gray-400 dark:text-gray-500 text-xs mt-1 font-mono">
                          {notification.time}
                        </Div>
                      </Div>
                      <Div className="flex flex-col items-center gap-2 flex-shrink-0">
                        {isUnread && (
                          <Span className="w-2 h-2 bg-blue-500 dark:bg-blue-400 rounded-full mt-1.5" />
                        )}
                        {onDelete && (
                          <Button
                            onClick={(e) => {
                              e.stopPropagation();
                              onDelete(notification);
                            }}
                            className="p-1 text-gray-400 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400 rounded hover:bg-red-50 dark:hover:bg-red-900/20 cursor-pointer opacity-0 group-hover:opacity-100 transition-opacity"
                            aria-label="Delete notification"
                          >
                            <Icon name={IconName.Trash} className="w-4 h-4" />
                          </Button>
                        )}
                      </Div>
                    </Div>
                  );
                })}
                {/* 무한스크롤 sentinel */}
                {hasMore && (
                  <Div ref={sentinelRef} className="px-4 py-3 text-center">
                    {loading ? (
                      <Span className="text-sm text-gray-400 dark:text-gray-500">...</Span>
                    ) : (
                      <Span className="text-sm text-gray-400 dark:text-gray-500" />
                    )}
                  </Div>
                )}
                {loading && !hasMore && notifications.length > 0 && (
                  <Div className="px-4 py-2 text-center">
                    <Span className="text-sm text-gray-400 dark:text-gray-500">...</Span>
                  </Div>
                )}
              </>
            )}
          </Div>
        </Div>
      )}
    </Div>
  );
};
