import React from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

export interface DropdownMenuItemProps {
  label?: string;
  icon?: IconName | string;
  variant?: 'default' | 'danger';
  disabled?: boolean;
  divider?: boolean;
  className?: string;
  style?: React.CSSProperties;
  onClick?: () => void;
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

  // variant에 따른 스타일
  const variantClasses =
    variant === 'danger'
      ? 'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20'
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

  return (
    <Div
      onClick={handleClick}
      className={`flex items-center gap-3 px-4 py-3 transition-colors ${variantClasses} ${disabledClasses} ${className}`}
      style={style}
    >
      {icon && (
        <Icon name={icon} className="w-4 h-4" />
      )}
      {label && (
        <Span className="text-sm font-medium">{label}</Span>
      )}
    </Div>
  );
};
