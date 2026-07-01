import React, { useState, useRef, useEffect } from 'react';
import { Div } from '../basic/Div';
import { Button } from '../basic/Button';
import type { EditorAttrs } from '../../types';

export interface DropdownItem {
  label: string;
  value: string;
  onClick?: () => void;
  disabled?: boolean;
}

export interface DropdownProps {
  label: string;
  items: DropdownItem[];
  /** 아이템 클릭 시 호출되는 콜백. value 문자열을 전달합니다. */
  onItemClick?: (value: string, item: DropdownItem) => void;
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right';
  /** 드롭다운 스타일 변형 */
  variant?: 'default' | 'text';
  className?: string;
  style?: React.CSSProperties;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * Dropdown 집합 컴포넌트
 *
 * Button + Div 기본 컴포넌트를 조합하여 드롭다운 메뉴 UI를 구성합니다.
 * 키보드 네비게이션 (Arrow Up/Down, Enter, Escape) 지원, 외부 클릭 감지 기능을 포함합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Dropdown",
 *   "props": {
 *     "label": "작업",
 *     "items": [
 *       {"label": "수정", "value": "edit"},
 *       {"label": "삭제", "value": "delete"}
 *     ]
 *   }
 * }
 */
export const Dropdown: React.FC<DropdownProps> = ({
  label,
  items,
  onItemClick,
  position = 'bottom-left',
  variant = 'default',
  className = '',
  style,
  id,
  editorAttrs,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [focusedIndex, setFocusedIndex] = useState(-1);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const [adjustedPosition, setAdjustedPosition] = useState(position);

  // 외부 클릭 감지
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsOpen(false);
        setFocusedIndex(-1);
      }
    };

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
      return () => document.removeEventListener('mousedown', handleClickOutside);
    }
  }, [isOpen]);

  // 키보드 네비게이션
  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (!isOpen) return;

      const enabledIndexes = items
        .map((item, index) => (!item.disabled ? index : -1))
        .filter((index) => index !== -1);

      switch (event.key) {
        case 'ArrowDown':
          event.preventDefault();
          setFocusedIndex((prev) => {
            const currentEnabledIndex = enabledIndexes.indexOf(prev);
            const nextEnabledIndex = (currentEnabledIndex + 1) % enabledIndexes.length;
            return enabledIndexes[nextEnabledIndex];
          });
          break;

        case 'ArrowUp':
          event.preventDefault();
          setFocusedIndex((prev) => {
            const currentEnabledIndex = enabledIndexes.indexOf(prev);
            const prevEnabledIndex =
              currentEnabledIndex <= 0 ? enabledIndexes.length - 1 : currentEnabledIndex - 1;
            return enabledIndexes[prevEnabledIndex];
          });
          break;

        case 'Enter':
          event.preventDefault();
          if (focusedIndex >= 0 && !items[focusedIndex].disabled) {
            handleItemClick(items[focusedIndex]);
          }
          break;

        case 'Escape':
          event.preventDefault();
          setIsOpen(false);
          setFocusedIndex(-1);
          break;
      }
    };

    if (isOpen) {
      document.addEventListener('keydown', handleKeyDown);
      return () => document.removeEventListener('keydown', handleKeyDown);
    }
  }, [isOpen, focusedIndex, items]);

  // 드롭다운 메뉴가 화면을 벗어나는지 감지하고 위치 조정
  useEffect(() => {
    if (isOpen && menuRef.current && dropdownRef.current) {
      const menuRect = menuRef.current.getBoundingClientRect();
      const triggerRect = dropdownRef.current.getBoundingClientRect();
      const viewportWidth = window.innerWidth;
      const viewportHeight = window.innerHeight;

      // 메뉴 크기 (w-56 = 14rem = 224px)
      const menuWidth = menuRect.width || 224;
      const menuHeight = menuRect.height || 200;

      let newPosition = position;

      // 수평 위치 조정
      const isLeftAligned = position.includes('left');
      if (isLeftAligned) {
        // left 정렬: 메뉴가 버튼 왼쪽 끝에서 오른쪽으로 펼쳐짐
        const menuRight = triggerRect.left + menuWidth;
        if (menuRight > viewportWidth) {
          newPosition = newPosition.replace('left', 'right') as typeof position;
        }
      } else {
        // right 정렬: 메뉴가 버튼 오른쪽 끝에서 왼쪽으로 펼쳐짐
        const menuLeft = triggerRect.right - menuWidth;
        if (menuLeft < 0) {
          newPosition = newPosition.replace('right', 'left') as typeof position;
        }
      }

      // 수직 위치 조정
      const isTopAligned = position.includes('top');
      if (isTopAligned) {
        // top 정렬: 메뉴가 버튼 위로 펼쳐짐
        if (triggerRect.top - menuHeight < 0) {
          newPosition = newPosition.replace('top', 'bottom') as typeof position;
        }
      } else {
        // bottom 정렬: 메뉴가 버튼 아래로 펼쳐짐
        if (triggerRect.bottom + menuHeight > viewportHeight) {
          newPosition = newPosition.replace('bottom', 'top') as typeof position;
        }
      }

      setAdjustedPosition(newPosition);
    }
  }, [isOpen, position]);

  const handleItemClick = (item: DropdownItem) => {
    if (item.disabled) return;

    item.onClick?.();
    // value를 첫 번째 인자로 전달하여 switch 핸들러와 호환
    onItemClick?.(item.value, item);
    setIsOpen(false);
    setFocusedIndex(-1);
  };

  const positionClasses = {
    'bottom-left': 'dropdown-menu-bottom-left',
    'bottom-right': 'dropdown-menu-bottom-right',
    'top-left': 'dropdown-menu-top-left',
    'top-right': 'dropdown-menu-top-right',
  };

  return (
    <Div
      ref={dropdownRef}
      className={`dropdown ${className}`}
      style={style}
      id={id} {...editorAttrs}
    >
      {/* Trigger Button */}
      <Button
        onClick={() => setIsOpen(!isOpen)}
        className={variant === 'text' ? 'dropdown-trigger-text' : 'dropdown-trigger'}
        aria-haspopup="true"
        aria-expanded={isOpen}
      >
        {label}
        {variant !== 'text' && (
          <svg
            className={`inline-block ml-2 w-4 h-4 transition-transform ${
              isOpen ? 'rotate-180' : ''
            }`}
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M19 9l-7 7-7-7"
            />
          </svg>
        )}
      </Button>

      {/* Dropdown Menu */}
      {isOpen && (
        <Div
          ref={menuRef}
          className={`dropdown-menu ${positionClasses[adjustedPosition]}`}
          role="menu"
          aria-orientation="vertical"
        >
          <Div className="py-1">
            {items.map((item, index) => (
              <Div
                key={item.value}
                className={`${
                  item.disabled ? 'dropdown-item-disabled' : 'dropdown-item'
                } ${focusedIndex === index ? 'dropdown-item-focused' : ''}`}
                onClick={() => handleItemClick(item)}
                onMouseEnter={() => !item.disabled && setFocusedIndex(index)}
                role="menuitem"
                aria-disabled={item.disabled}
                tabIndex={item.disabled ? -1 : 0}
              >
                {item.label}
              </Div>
            ))}
          </Div>
        </Div>
      )}
    </Div>
  );
};
