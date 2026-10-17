import React, { useState, useRef, useEffect, useCallback } from 'react';
import ReactDOM from 'react-dom';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import type { EditorAttrs } from '../../types';
import { handleLinkClick, mergeQueryIntoHref, resolveLinkHref } from '../../support/navigationLink';

// G7Core.t() 번역 함수 참조 — 번역이 없거나 엔진 밖(단위 테스트)이면 대체 문구를 쓴다(컴포넌트 기본값은 영어 — data-binding-i18n.md)
const tOr = (key: string, fallback: string): string => {
  const translated = (window as any).G7Core?.t?.(key);
  return typeof translated === 'string' && translated !== key ? translated : fallback;
};

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
  /** 잠긴 항목에 보일 안내 문구 — 항목 안 보조 줄과 title 로 「왜 잠겼는지」를 알린다(잠기지 않으면 숨김) */
  disabledReason?: string;
  /** 조건부 표시 (false면 아이템 숨김, 엔진이 표현식 평가 후 boolean 전달) */
  if?: boolean;
  /**
   * 화면을 옮기는 항목의 주소 — 주면 `<a role="menuitem" href>` 로 그려 가운데 클릭·Ctrl+클릭으로 새 탭에서 열 수 있다.
   * 보통 클릭은 지금처럼 onClick·onItemClick(#757). 삭제처럼 옮기지 않는 항목은 비워 둔다.
   */
  href?: string;
  /**
   * 참이면 현재 주소의 쿼리를 href 에 합쳐 그린다(같은 키는 href 쪽이 이긴다) — 보통 클릭이 목록 조건을 넘기는 항목은
   * 새 탭에도 같은 조건이 가도록 켠다(#757, A 의 mergeQuery 와 같은 규칙).
   */
  mergeQuery?: boolean;
  /**
   * 링크 항목을 여는 창 — `_blank` 면 새 창(`rel="noopener noreferrer"`)으로 연다(#757). 보통 클릭도 브라우저가 새 창으로
   * 열고 항목 동작(onClick·onItemClick)은 부르지 않는다 — 종전 `openWindow` 항목을 링크로 바꿀 때 쓴다. 바깥 주소는 늘 새 창.
   */
  target?: string;
}

export interface ActionMenuProps {
  items: ActionMenuItem[];
  /** 트리거 글자. 생략하면 공용 문구 `common.actions`(화면 언어), 빈 문자열이면 아이콘만 그린다 */
  triggerLabel?: string;
  triggerIconName?: IconName;
  /**
   * 글자 없는 아이콘 트리거의 접근 가능한 이름 (aria-label)
   *
   * `triggerLabel` 이 비어 아이콘만 그려질 때 쓴다. 주지 않으면 공용 문구 `common.more_actions`(엔진 밖 `More actions`)로
   * 채워 이름 없는 버튼을 남기지 않는다. 보이는 글자가 있으면 그 글자가 이름이므로 쓰지 않는다.
   */
  triggerAriaLabel?: string;
  /** `triggerAriaLabel` 과 같은 뜻(DataGrid 행 메뉴가 열 머리글 이름을 넘긴다). 둘 다 있으면 `triggerAriaLabel` 이 앞선다 */
  ariaLabel?: string;
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
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
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
  triggerLabel: triggerLabelProp,
  triggerIconName = IconName.EllipsisVertical,
  triggerAriaLabel,
  ariaLabel,
  position = 'right',
  className = '',
  style,
  children,
  onItemClick,
  id,
  editorAttrs,
}) => {
  // 기본 트리거 글자는 화면 언어를 따른다 — 종전 기본값이 한국어 「작업」 고정이라 영어·일본어 화면에도 그대로 보였다.
  // 번역이 없거나 엔진 밖(단위 테스트)이면 영어 대체 문구를 쓴다(컴포넌트 기본값은 영어 — data-binding-i18n.md).
  // 빈 문자열("")은 아이콘만 그리는 뜻이라 그대로 둔다.
  const triggerLabel = triggerLabelProp ?? tOr('common.actions', 'Actions');
  // 아이콘만 있는 트리거는 글리프 이름(「ellipsis h」)으로 읽히거나 이름 없는 버튼이 되므로 「더보기」 를 이름으로 단다
  const iconOnlyName = triggerAriaLabel || ariaLabel || tOr('common.more_actions', 'More actions');
  const [isOpen, setIsOpen] = useState(false);
  const [menuPosition, setMenuPosition] = useState({ top: 0, left: 0 });
  const triggerRef = useRef<HTMLElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  /** 키보드로 연 메뉴인지 — 그때만 첫 항목으로 초점을 옮긴다(마우스 동작은 종전과 같다). */
  const openedByKeyboardRef = useRef(false);
  /** 메뉴를 띄운 때의 트리거 위치 — 스크롤로 트리거가 실제로 움직였는지 가르는 기준 */
  const anchorRectRef = useRef<{ top: number; left: number } | null>(null);

  // 행 값으로 풀어야 하는 disabledField 가 그대로 남아 있으면(행 해석기 밖에서 쓰인 경우) 잠근다 —
  // 무시하고 열어 두면 권한 게이트가 조용히 사라진다(DataGrid 는 해석한 뒤 이 키를 지워서 넘긴다).
  const visibleItems = items
    .filter((item) => item.if !== false)
    .map((item) => (item.disabledField ? { ...item, disabled: true } : item));

  /**
   * 메뉴 안에서 고를 수 있는 항목 요소 목록을 돌려줍니다(구분선·잠긴 항목 제외).
   *
   * @returns 초점을 받을 수 있는 항목 요소
   */
  const getFocusableItems = (): HTMLElement[] =>
    Array.from(
      menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([aria-disabled="true"])') ?? []
    );

  /**
   * 요소에 초점을 옮깁니다. 메뉴는 스크롤하면 닫히므로 초점 이동이 화면을 굴리지 않게 한다.
   *
   * @param element 초점을 줄 요소
   */
  const focusWithoutScroll = (element: HTMLElement | null | undefined) => {
    element?.focus({ preventScroll: true });
  };

  /**
   * 메뉴를 닫고 트리거로 초점을 되돌립니다(키보드 경로).
   */
  const closeAndRestoreFocus = () => {
    setIsOpen(false);
    focusWithoutScroll(triggerRef.current);
  };

  // 메뉴 위치 계산
  const updateMenuPosition = useCallback(() => {
    if (!triggerRef.current) return;

    const rect = triggerRef.current.getBoundingClientRect();
    anchorRectRef.current = { top: rect.top, left: rect.left };
    const menuWidth = 224; // w-56 = 14rem = 224px

    let left = position === 'right' ? rect.right - menuWidth : rect.left;
    let top = rect.bottom + 8; // mt-2 = 8px

    // 화면 아래 경계 체크 — 메뉴는 fixed 라 스크롤로 닿을 수 없고 스크롤하면 닫힌다.
    // 아래로 열어 잘리고 위에는 들어가면 버튼 위로 연다.
    const menuHeight = menuRef.current?.getBoundingClientRect().height ?? 0;
    if (top + menuHeight > window.innerHeight - 8 && rect.top - 8 - menuHeight >= 8) {
      top = rect.top - 8 - menuHeight;
    }

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

  // 키보드로 열었으면 첫 항목으로 초점을 옮긴다(WAI-ARIA 메뉴 버튼).
  useEffect(() => {
    if (isOpen && openedByKeyboardRef.current) {
      openedByKeyboardRef.current = false;
      focusWithoutScroll(getFocusableItems()[0]);
    }
  }, [isOpen]);

  // 스크롤/리사이즈 시 메뉴 닫기 — 메뉴는 fixed 라 트리거가 움직이면 엉뚱한 자리에 떠 있게 된다.
  // 스크롤은 **트리거가 실제로 움직였을 때만** 닫는다. 브라우저는 스크롤 위치를 곧바로 바꾸고 scroll 이벤트는 다음
  // 프레임에 늦게 보낸다 — 표 오른쪽 열처럼 가로 스크롤 안쪽의 단추를 누르면 단추를 보이게 한 스크롤의 이벤트가 메뉴를
  // 연 뒤에 도착해 첫 클릭에 곧바로 닫혔다(실측 2026-10-15: mousedown 66ms · click 67ms · scroll 72ms). 그 이벤트가 올 때
  // 트리거 위치는 메뉴를 띄운 때와 같으므로 위치로 가른다. 메뉴 자체의 스크롤도 트리거를 움직이지 않아 닫지 않는다.
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

  /**
   * 트리거 클릭 — 키보드가 일으킨 클릭(detail 0, 기본 버튼의 Enter·Space)이면 첫 항목으로 초점을 옮기게 표시한다.
   *
   * @param event 마우스 이벤트
   */
  const handleTriggerClick = (event: React.MouseEvent<HTMLElement>) => {
    if (!isOpen && event.detail === 0) {
      openedByKeyboardRef.current = true;
    }
    setIsOpen(!isOpen);
  };

  /**
   * 트리거의 키 입력 — 아래 화살표로 열고, 열린 상태의 Escape 로 닫는다. children 트리거(Div)는
   * 버튼처럼 Enter·Space 도 받는다(기본 버튼은 브라우저가 이 두 키를 클릭으로 바꿔 준다).
   *
   * @param event 키보드 이벤트
   * @param handleActivationKeys Enter·Space 를 직접 처리할지(children 트리거)
   */
  const handleTriggerKeyDown = (event: React.KeyboardEvent<HTMLElement>, handleActivationKeys: boolean) => {
    const isActivationKey = event.key === 'Enter' || event.key === ' ';
    if ((isActivationKey && handleActivationKeys) || event.key === 'ArrowDown') {
      event.preventDefault();
      if (isOpen) {
        focusWithoutScroll(getFocusableItems()[0]);
        return;
      }
      openedByKeyboardRef.current = true;
      setIsOpen(true);
      return;
    }

    if (event.key === 'Escape' && isOpen) {
      event.preventDefault();
      setIsOpen(false);
    }
  };

  /**
   * 메뉴의 키 입력 — 화살표·Home·End 로 잠기지 않은 항목 사이를 옮기고 Enter·Space 로 고르며 Escape·Tab 으로 닫는다.
   * 항목에서 올라온 키와 메뉴 자체가 받은 키를 한곳에서 다룬다.
   *
   * @param event 키보드 이벤트
   */
  const handleMenuKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    const focusable = getFocusableItems();
    const current = document.activeElement as HTMLElement | null;
    const index = current ? focusable.indexOf(current) : -1;

    switch (event.key) {
      case 'ArrowDown':
      case 'ArrowUp': {
        event.preventDefault();
        if (focusable.length === 0) return;
        const step = event.key === 'ArrowDown' ? 1 : -1;
        focusWithoutScroll(focusable[(index + step + focusable.length) % focusable.length]);
        return;
      }
      case 'Home':
        event.preventDefault();
        focusWithoutScroll(focusable[0]);
        return;
      case 'End':
        event.preventDefault();
        focusWithoutScroll(focusable[focusable.length - 1]);
        return;
      case 'Enter':
      case ' ':
        // 링크 항목에서 Ctrl·Meta·Shift+Enter 는 브라우저가 새 탭·새 창으로 연다 — 막지 않는다(#757)
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey || event.shiftKey) && focusable[index]?.tagName === 'A') {
          return;
        }
        event.preventDefault();
        if (index < 0) return;
        focusable[index].click();
        focusWithoutScroll(triggerRef.current);
        return;
      case 'Escape':
        event.preventDefault();
        event.stopPropagation();
        closeAndRestoreFocus();
        return;
      case 'Tab':
        // 메뉴는 문서 끝에 그려지므로 트리거로 초점을 돌려 둔 뒤 기본 Tab 이동을 맡긴다.
        closeAndRestoreFocus();
        return;
      default:
    }
  };

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
          role="menu"
          aria-label={triggerLabel || iconOnlyName}
          onKeyDown={handleMenuKeyDown}
          className="fixed z-[9999] w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg overflow-hidden"
          style={{
            top: menuPosition.top,
            left: menuPosition.left,
          }}
        >
          {visibleItems.map((item) => {
            // 구분선인 경우
            if (item.divider) {
              return (
                <div
                  key={item.id}
                  role="separator"
                  className="border-t border-gray-200 dark:border-gray-600 my-1"
                />
              );
            }

            // 잠긴 항목은 위험(빨강) 색·hover 를 빼고 회색으로 흐리게 — 빨강이 남으면 눌릴 것처럼 보였다(O-F11).
            const stateClasses = item.disabled
              ? 'text-gray-400 dark:text-gray-500 opacity-60 cursor-not-allowed'
              : item.variant === 'danger'
                ? 'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 focus:bg-red-50 dark:focus:bg-red-900/20 cursor-pointer'
                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus:bg-gray-50 dark:focus:bg-gray-700 cursor-pointer';

            const link = item.disabled ? null : resolveLinkHref(item.mergeQuery && typeof item.href === 'string' ? mergeQueryIntoHref(item.href) : item.href);
            if (link) {
              return (
                <a
                  key={item.id}
                  href={link.href}
                  {...(link.external || item.target === '_blank'
                    ? { target: '_blank', rel: 'noopener noreferrer' }
                    : item.target ? { target: item.target } : {})}
                  role="menuitem"
                  tabIndex={-1}
                  onClick={(event) => {
                    // 보통 클릭만 지금처럼 항목 동작을 돌린다 — Ctrl·Shift·가운데 클릭은 브라우저가 새 탭·새 창으로 연다
                    if (!handleLinkClick(event, () => handleItemClick(item))) setIsOpen(false);
                  }}
                  className={`w-full text-left flex items-center gap-3 px-4 py-3 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 ${stateClasses}`}
                >
                  {item.iconName && (
                    <Icon name={item.iconName} className="w-4 h-4" />
                  )}
                  <span className="flex flex-col">
                    <span className="text-sm font-medium">{item.label}</span>
                    {item.disabled && item.disabledReason && (
                      <span className="text-xs font-normal text-gray-500 dark:text-gray-400">{item.disabledReason}</span>
                    )}
                  </span>
                </a>
              );
            }

            return (
              <button
                key={item.id}
                type="button"
                role="menuitem"
                tabIndex={-1}
                disabled={item.disabled}
                aria-disabled={item.disabled || undefined}
                title={item.disabled && item.disabledReason ? item.disabledReason : undefined}
                onClick={() => handleItemClick(item)}
                className={`w-full text-left flex items-center gap-3 px-4 py-3 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 ${stateClasses}`}
              >
                {item.iconName && (
                  <Icon name={item.iconName} className="w-4 h-4" />
                )}
                <span className="flex flex-col">
                  <span className="text-sm font-medium">{item.label}</span>
                  {item.disabled && item.disabledReason && (
                    <span className="text-xs font-normal text-gray-500 dark:text-gray-400">{item.disabledReason}</span>
                  )}
                </span>
              </button>
            );
          })}
        </div>,
        document.body
      )
    : null;

  return (
    <Div className={`relative inline-block ${className}`} style={style} id={id} {...editorAttrs}>
      {/* 트리거: children이 있으면 커스텀 트리거, 없으면 기본 Button */}
      {children ? (
        <Div
          ref={triggerRef as React.RefObject<HTMLDivElement>}
          role="button"
          tabIndex={0}
          aria-haspopup="menu"
          aria-expanded={isOpen}
          aria-label={triggerAriaLabel}
          onClick={handleTriggerClick}
          onKeyDown={(event) => handleTriggerKeyDown(event, true)}
          className="cursor-pointer"
        >
          {children}
        </Div>
      ) : (
        <Button
          ref={triggerRef as React.RefObject<HTMLButtonElement>}
          aria-haspopup="menu"
          aria-expanded={isOpen}
          // 아이콘만 있는 트리거(DataGrid 행 메뉴 … 등)는 글자가 없어 이름 없는 버튼으로 읽혔다(7.1.0 실측 O-KF-1)
          aria-label={triggerLabel ? undefined : iconOnlyName}
          onClick={handleTriggerClick}
          onKeyDown={(event) => handleTriggerKeyDown(event, false)}
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
