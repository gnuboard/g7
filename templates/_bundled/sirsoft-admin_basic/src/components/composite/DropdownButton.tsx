import React, { useState, useRef, useEffect, useCallback } from 'react';
import ReactDOM from 'react-dom';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

export interface DropdownButtonProps {
  label?: string;
  icon?: IconName | string;
  iconPosition?: 'left' | 'right';
  position?: 'left' | 'right';
  className?: string;
  style?: React.CSSProperties;
  children?: React.ReactNode;
}

/**
 * DropdownButton 집합 컴포넌트
 *
 * 드롭다운 형태의 버튼 컴포넌트입니다.
 * ActionMenu와 유사하지만 children을 통해 자유롭게 메뉴 내용을 구성할 수 있습니다.
 *
 * 기본 컴포넌트 조합: Button + Div + Span + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "DropdownButton",
 *   "props": {
 *     "label": "더보기",
 *     "icon": "chevron-down",
 *     "iconPosition": "right",
 *     "className": "btn btn-outline btn-sm"
 *   },
 *   "children": [
 *     {
 *       "name": "DropdownItem",
 *       "props": {
 *         "label": "메뉴 1"
 *       }
 *     }
 *   ]
 * }
 */
export const DropdownButton: React.FC<DropdownButtonProps> = ({
  label,
  icon,
  iconPosition = 'left',
  position = 'right',
  className = '',
  style,
  children,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0 });
  const triggerRef = useRef<HTMLButtonElement>(null);
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
          onClick={() => setIsOpen(false)}
        >
          {children}
        </div>,
        document.body
      )
    : null;

  return (
    <Div className={`relative inline-block ${className}`} style={style}>
      {/* 트리거 버튼 */}
      <Button
        ref={triggerRef}
        onClick={() => setIsOpen(!isOpen)}
        className="flex items-center gap-2"
      >
        {iconPosition === 'left' && icon && (
          <Icon name={icon} className="w-4 h-4" />
        )}
        {label && (
          <Span className="text-sm font-medium">
            {label}
          </Span>
        )}
        {iconPosition === 'right' && icon && (
          <Icon name={icon} className="w-4 h-4" />
        )}
      </Button>

      {/* Portal로 렌더링되는 드롭다운 메뉴 */}
      {dropdownMenu}
    </Div>
  );
};
