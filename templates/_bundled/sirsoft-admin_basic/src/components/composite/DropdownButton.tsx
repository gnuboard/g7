import React, { useState, useRef, useEffect, useCallback, useId } from 'react';
import ReactDOM from 'react-dom';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { focusMenuEdge, menuKeyDown, menuTriggerKeyDown } from '../../support/keyboardActivation';

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
  /** 메뉴를 띄운 때의 트리거 위치 — 스크롤로 트리거가 실제로 움직였는지 가르는 기준 */
  const anchorRectRef = useRef<{ top: number; left: number } | null>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  // 키보드로 연 메뉴가 열린 뒤 포커스를 옮길 끝 — 마우스로 열면 비어 있어 포커스를 옮기지 않는다(8차 8부)
  const pendingEdgeRef = useRef<'first' | 'last' | null>(null);
  const menuId = `dropdown-button-menu-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;

  // 메뉴는 포털로 문서 끝에 붙는다 — 키보드로 열면 열린 직후 첫 항목(ArrowUp 이면 마지막)으로 옮긴다
  useEffect(() => {
    if (!isOpen || !pendingEdgeRef.current) return;
    const edge = pendingEdgeRef.current;
    pendingEdgeRef.current = null;
    focusMenuEdge(menuRef.current, edge);
  }, [isOpen]);

  // 메뉴 위치 계산
  const updateMenuPosition = useCallback(() => {
    if (!triggerRef.current) return;

    const rect = triggerRef.current.getBoundingClientRect();
    anchorRectRef.current = { top: rect.top, left: rect.left };
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

  // 스크롤/리사이즈 시 메뉴 닫기 — 스크롤은 트리거가 실제로 움직였을 때만(ActionMenu 와 같은 규칙).
  // 단추를 보이게 한 스크롤의 scroll 이벤트는 메뉴를 연 뒤에 늦게 와 첫 클릭에 곧바로 닫히게 했다.
  useEffect(() => {
    if (!isOpen) return;

    const handleScroll = () => {
      const anchor = anchorRectRef.current;
      const rect = triggerRef.current?.getBoundingClientRect();
      if (anchor && rect && Math.abs(rect.top - anchor.top) < 1 && Math.abs(rect.left - anchor.left) < 1) {
        return;
      }
      setIsOpen(false);
    };
    const handleResize = () => {
      setIsOpen(false);
    };

    window.addEventListener('scroll', handleScroll, true);
    window.addEventListener('resize', handleResize);

    return () => {
      window.removeEventListener('scroll', handleScroll, true);
      window.removeEventListener('resize', handleResize);
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
          // 항목을 고르거나 Esc 로 닫으면 여는 단추로 포커스를 돌린다 — 메뉴가 사라져 body 로 빠지지 않게(키보드 접근).
          // 항목이 창을 열면 창이 이 단추를 「연 자리」로 기억했다가 닫힐 때 돌려준다
          onClick={() => {
            setIsOpen(false);
            triggerRef.current?.focus();
          }}
          id={menuId}
          role="menu"
          // 화살표, Home, End, Enter, Space, Esc, Tab — ActionMenu 와 같은 메뉴 단추 규칙(8차 8부)
          onKeyDown={menuKeyDown(menuRef, triggerRef, () => setIsOpen(false))}
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
        // Modal 이 펼친 메뉴로 알아보게 한다 — 메뉴 안 Esc 는 메뉴 몫이다
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-controls={isOpen ? menuId : undefined}
        onKeyDown={menuTriggerKeyDown(isOpen, setIsOpen, pendingEdgeRef, menuRef)}
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
