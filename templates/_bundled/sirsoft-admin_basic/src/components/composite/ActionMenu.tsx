import React, { useState, useRef, useEffect, useCallback } from 'react';
import ReactDOM from 'react-dom';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

export interface ActionMenuItem {
  id: string | number;
  label?: string;
  iconName?: IconName;
  onClick?: () => void;
  disabled?: boolean;
  variant?: 'default' | 'danger';
  /** 구분선 여부 (true면 구분선으로 렌더링) */
  divider?: boolean;
  /** 행 데이터의 필드 경로로 disabled 여부를 결정 (값이 falsy면 disabled) */
  disabledField?: string;
  /** 조건부 표시 (false면 아이템 숨김, 엔진이 표현식 평가 후 boolean 전달) */
  if?: boolean;
}

export interface ActionMenuProps {
  items: ActionMenuItem[];
  triggerLabel?: string;
  triggerIconName?: IconName;
  position?: 'left' | 'right';
  className?: string;
  style?: React.CSSProperties;
  /**
   * 커스텀 트리거 콘텐츠
   *
   * children이 제공되면 기본 Button 트리거 대신 children을 클릭 트리거로 사용합니다.
   * 레이아웃 JSON에서 ActionMenu의 children으로 정의한 컴포넌트가 트리거가 됩니다.
   */
  children?: React.ReactNode;
  /**
   * 메뉴 아이템 클릭 시 호출되는 콜백
   * JSON 레이아웃의 actions에서 onItemClick 이벤트로 바인딩됨
   *
   * @param itemId - 클릭된 아이템의 id
   * @param item - 클릭된 아이템 전체 객체
   */
  onItemClick?: (itemId: string | number, item: ActionMenuItem) => void;
}

/**
 * ActionMenu 집합 컴포넌트
 *
 * 드롭다운 형태의 액션 메뉴 컴포넌트입니다.
 * 트리거 버튼과 메뉴 아이템 목록을 제공합니다.
 *
 * 기본 컴포넌트 조합: Button + Div + Span + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "ActionMenu",
 *   "props": {
 *     "triggerLabel": "작업",
 *     "items": [
 *       {"id": 1, "label": "수정", "iconName": "pencil"},
 *       {"id": 2, "label": "삭제", "iconName": "trash", "variant": "danger"}
 *     ]
 *   }
 * }
 */
export const ActionMenu: React.FC<ActionMenuProps> = ({
  items,
  triggerLabel = '작업',
  triggerIconName = IconName.EllipsisVertical,
  position = 'right',
  className = '',
  style,
  children,
  onItemClick,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0 });
  const triggerRef = useRef<HTMLElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  // 메뉴 위치 계산
  const updateMenuPosition = useCallback(() => {
    if (!triggerRef.current) return;

    const rect = triggerRef.current.getBoundingClientRect();
    const menuWidth = 224; // w-56 = 14rem = 224px

    let left = position === 'right' ? rect.right - menuWidth : rect.left;
    const top = rect.bottom + 8; // mt-2 = 8px

    // 화면 왼쪽 경계 체크
    if (left < 8) {
      left = 8;
    }

    // 화면 오른쪽 경계 체크
    if (left + menuWidth > window.innerWidth - 8) {
      left = window.innerWidth - menuWidth - 8;
    }

    setMenuPosition({ top, left });
  }, [position]);

  // 메뉴 열릴 때 위치 계산
  useEffect(() => {
    if (isOpen) {
      updateMenuPosition();
    }
  }, [isOpen, updateMenuPosition]);

  // 스크롤/리사이즈 시 메뉴 닫기
  useEffect(() => {
    if (!isOpen) return;

    const handleScrollOrResize = () => {
      setIsOpen(false);
    };

    window.addEventListener('scroll', handleScrollOrResize, true);
    window.addEventListener('resize', handleScrollOrResize);

    return () => {
      window.removeEventListener('scroll', handleScrollOrResize, true);
      window.removeEventListener('resize', handleScrollOrResize);
    };
  }, [isOpen]);

  // 외부 클릭 감지
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node;
      const isClickInsideTrigger = triggerRef.current?.contains(target);
      const isClickInsideMenu = menuRef.current?.contains(target);

      if (!isClickInsideTrigger && !isClickInsideMenu) {
        setIsOpen(false);
      }
    };

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
    }

    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [isOpen]);

  const handleItemClick = (item: ActionMenuItem) => {
    if (item.disabled) return;

    // 1. 개별 아이템의 onClick 콜백 호출 (우선)
    if (item.onClick) {
      item.onClick();
    }

    // 2. 통합 onItemClick 콜백 호출 (JSON 레이아웃 actions 바인딩)
    if (onItemClick) {
      onItemClick(item.id, item);
    }

    setIsOpen(false);
  };

  // Portal로 렌더링할 드롭다운 메뉴
  const dropdownMenu = isOpen
    ? ReactDOM.createPortal(
        <div
          ref={menuRef}
          className="fixed z-[9999] w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg overflow-hidden"
          style={{
            top: menuPosition.top,
            left: menuPosition.left,
          }}
        >
          {items.filter((item) => item.if !== false).map((item) => {
            // 구분선인 경우
            if (item.divider) {
              return (
                <div
                  key={item.id}
                  className="border-t border-gray-200 dark:border-gray-600 my-1"
                />
              );
            }

            const variantClasses =
              item.variant === 'danger'
                ? 'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20'
                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700';

            const disabledClasses = item.disabled
              ? 'opacity-50 cursor-not-allowed'
              : 'cursor-pointer';

            return (
              <div
                key={item.id}
                onClick={() => handleItemClick(item)}
                className={`flex items-center gap-3 px-4 py-3 transition-colors ${variantClasses} ${disabledClasses}`}
              >
                {item.iconName && (
                  <Icon name={item.iconName} className="w-4 h-4" />
                )}
                <span className="text-sm font-medium">{item.label}</span>
              </div>
            );
          })}
        </div>,
        document.body
      )
    : null;

  return (
    <Div className={`relative inline-block ${className}`} style={style}>
      {/* 트리거: children이 있으면 커스텀 트리거, 없으면 기본 Button */}
      {children ? (
        <Div
          ref={triggerRef as React.RefObject<HTMLDivElement>}
          onClick={() => setIsOpen(!isOpen)}
          className="cursor-pointer"
        >
          {children}
        </Div>
      ) : (
        <Button
          ref={triggerRef as React.RefObject<HTMLButtonElement>}
          onClick={() => setIsOpen(!isOpen)}
          className="flex items-center gap-2 px-2 py-1 bg-transparent hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
        >
          {triggerLabel && (
            <Span className="text-sm font-medium text-gray-700 dark:text-gray-300">
              {triggerLabel}
            </Span>
          )}
          {triggerIconName && (
            <Icon name={triggerIconName} className="w-4 h-4 text-gray-500 dark:text-gray-400" />
          )}
        </Button>
      )}

      {/* Portal로 렌더링되는 드롭다운 메뉴 */}
      {dropdownMenu}
    </Div>
  );
};
