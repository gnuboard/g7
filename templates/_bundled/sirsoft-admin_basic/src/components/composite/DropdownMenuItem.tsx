import React, { useCallback, useState } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import { sitePath } from '../../support/apiUrl';
import { handleLinkClick, navigateTo, resolveLinkHref } from '../../support/navigationLink';
import { activateOnKey, FOCUS_VISIBLE_RING } from '../../support/keyboardActivation';

export interface DropdownMenuItemProps {
  label?: string;
  icon?: IconName | string;
  variant?: 'default' | 'danger';
  disabled?: boolean;
  divider?: boolean;
  className?: string;
  style?: React.CSSProperties;
  /**
   * 이동 주소 — 있으면 `<a role="menuitem" href>` 로 그려 가운데 클릭·Ctrl+클릭·우클릭 「새 탭에서 열기」가 된다.
   * 보통 클릭은 지금처럼 onClick(없으면 SPA 이동), 수정키 클릭은 브라우저 기본 동작. 바깥 주소는 새 탭으로 연다.
   */
  href?: string;
  onClick?: (event?: React.MouseEvent) => void;
}

/**
 * DropdownMenuItem 집합 컴포넌트
 *
 * DropdownButton의 자식으로 사용되는 메뉴 아이템 컴포넌트입니다.
 * ActionMenu의 아이템과 동일한 디자인을 사용합니다.
 *
 * 기본 컴포넌트 조합: Div + Icon + Span
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "DropdownButton",
 *   "props": {
 *     "label": "작업",
 *     "icon": "chevron-down"
 *   },
 *   "children": [
 *     {
 *       "name": "DropdownMenuItem",
 *       "props": {
 *         "label": "수정",
 *         "icon": "pencil"
 *       },
 *       "actions": [
 *         {
 *           "type": "click",
 *           "handler": "navigate",
 *           "params": {
 *             "path": "/edit/{{id}}"
 *           }
 *         }
 *       ]
 *     },
 *     {
 *       "name": "DropdownMenuItem",
 *       "props": {
 *         "divider": true
 *       }
 *     },
 *     {
 *       "name": "DropdownMenuItem",
 *       "props": {
 *         "label": "삭제",
 *         "icon": "trash",
 *         "variant": "danger"
 *       }
 *     }
 *   ]
 * }
 */
export const DropdownMenuItem: React.FC<DropdownMenuItemProps> = ({
  label,
  icon,
  variant = 'default',
  disabled = false,
  divider = false,
  className = '',
  style,
  href,
  onClick,
}) => {
  // 구분선인 경우
  if (divider) {
    return (
      <Div
        className="h-px bg-gray-200 dark:bg-gray-700 my-1"
        style={style}
      />
    );
  }

  // 부모 메뉴(role="menu") 안이면 로빙 포커스 — 항목은 화살표로 옮기는 자리라 Tab 순서에 들지 않는다(WAI-ARIA 메뉴 관례).
  // 번들에는 메뉴 밖 사용처가 없지만 등록된 부품이라 사이트 레이아웃이 메뉴 밖에 둘 수 있어 문맥으로 가린다(키보드 접근)
  const [inMenu, setInMenu] = useState(false);
  const detectMenu = useCallback((el: HTMLElement | null) => {
    if (el) setInMenu(el.closest('[role="menu"]') !== null);
  }, []);

  // variant에 따른 스타일
  const variantClasses =
    variant === 'danger'
      ? 'text-red-700 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20'
      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700';

  // disabled 스타일
  const disabledClasses = disabled
    ? 'opacity-50 cursor-not-allowed'
    : 'cursor-pointer';

  // 클릭 핸들러
  const handleClick = () => {
    if (!disabled && onClick) {
      onClick();
    }
  };

  const itemClassName = `flex items-center gap-3 px-4 py-3 transition-colors ${variantClasses} ${disabledClasses} ${className}`;
  const content = (
    <>
      {icon && (
        <Icon name={icon} className="w-4 h-4" />
      )}
      {label && (
        <Span className="text-sm font-medium">{label}</Span>
      )}
    </>
  );

  // 이동 주소가 있는 항목 — 메뉴 항목 링크. 비활성이면 href 를 빼고 aria-disabled.
  const link = href !== undefined ? resolveLinkHref(href) : null;
  if (link) {
    if (disabled) {
      return (
        <a
          ref={detectMenu}
          role="menuitem"
          aria-disabled="true"
          tabIndex={-1}
          className={itemClassName}
          style={style}
          onClick={(event) => {
            event.preventDefault();
            event.stopPropagation();
          }}
        >
          {content}
        </a>
      );
    }

    return (
      <a
        ref={detectMenu}
        role="menuitem"
        tabIndex={inMenu ? -1 : undefined}
        href={sitePath(link.href)}
        {...(link.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
        className={itemClassName}
        style={style}
        onClick={(event) => {
          handleLinkClick(event, () => {
            if (onClick) {
              onClick(event);
            } else {
              navigateTo(link.href);
            }
          });
        }}
      >
        {content}
      </a>
    );
  }

  return (
    <Div
      ref={detectMenu}
      onClick={handleClick}
      // 메뉴 항목 — Enter·Space 로 고른다. 메뉴 안이거나 비활성이면 Tab 에서 빠지고, 메뉴 밖이면 Tab 으로 닿는다(키보드 접근)
      role="menuitem"
      tabIndex={disabled || inMenu ? -1 : 0}
      aria-disabled={disabled || undefined}
      onKeyDown={activateOnKey(handleClick)}
      className={`${itemClassName} ${FOCUS_VISIBLE_RING}`}
      style={style}
    >
      {content}
    </Div>
  );
};
