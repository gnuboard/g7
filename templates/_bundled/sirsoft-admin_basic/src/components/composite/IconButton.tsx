import React from 'react';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';
import type { EditorAttrs } from '../../types';

// G7Core 전역 객체의 스타일 헬퍼 접근
const G7Core = () => (window as any).G7Core;

export interface IconButtonProps {
  iconName: IconName;
  label?: string;
  /** 글자 없는 버튼의 접근 가능한 이름(+툴팁). 비우면 아이콘별 기본 이름(새로고침·추가·닫기 등) */
  ariaLabel?: string;
  onClick?: () => void;
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost' | 'outline';
  size?: 'sm' | 'md' | 'lg';
  disabled?: boolean;
  className?: string;
  style?: React.CSSProperties;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/** 아이콘만 있는 버튼의 기본 이름 키 — 아이콘은 꾸밈으로 숨겨지므로 버튼에 이름을 단다(7.1.0 J D-J4) */
const ICON_DEFAULT_LABEL_KEYS: Record<string, string> = {
  refresh: 'common.refresh',
  'arrows-rotate': 'common.refresh',
  'rotate-right': 'common.refresh',
  plus: 'common.add',
  xmark: 'common.close',
  times: 'common.close',
  trash: 'common.delete',
  'trash-can': 'common.delete',
  pen: 'common.edit',
  pencil: 'common.edit',
  'pen-to-square': 'common.edit',
  search: 'common.search',
  download: 'common.download',
  ellipsis: 'common.more_actions',
  'ellipsis-vertical': 'common.more_actions',
  'ellipsis-h': 'common.more_actions',
  'ellipsis-v': 'common.more_actions',
};

/**
 * 글자 없는 아이콘 버튼의 이름을 정합니다.
 *
 * @param iconName 아이콘 이름
 * @param ariaLabel 직접 준 이름
 * @returns 이름(없으면 undefined)
 */
const resolveIconButtonLabel = (iconName: string | undefined, ariaLabel?: string): string | undefined => {
  if (ariaLabel) return ariaLabel;
  const key = ICON_DEFAULT_LABEL_KEYS[String(iconName ?? '').replace(/^fa-(solid|regular) /, '').replace(/^fa-/, '')];
  if (!key) return undefined;
  const translated = (window as any).G7Core?.t?.(key);
  return typeof translated === 'string' && translated !== key ? translated : undefined;
};

/**
 * IconButton 집합 컴포넌트
 *
 * 아이콘이 포함된 버튼 컴포넌트입니다.
 * 아이콘만 표시하거나 라벨과 함께 표시할 수 있습니다.
 *
 * 기본 컴포넌트 조합: Button + Icon
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "IconButton",
 *   "props": {
 *     "iconName": "plus",
 *     "label": "추가",
 *     "variant": "primary",
 *     "size": "md"
 *   }
 * }
 */
export const IconButton: React.FC<IconButtonProps> = ({
  iconName,
  label,
  ariaLabel,
  onClick,
  variant = 'primary',
  size = 'md',
  disabled = false,
  className = '',
  style,
  id,
  editorAttrs,
}) => {
  // 크기별 스타일 (라벨 없을 때는 정사각 고정 크기로 정렬을 보장)
  const sizeClasses = {
    sm: label ? 'px-3 py-1.5 text-sm' : 'w-8 h-8',
    md: label ? 'px-4 py-2 text-base' : 'w-10 h-10',
    lg: label ? 'px-5 py-2.5 text-lg' : 'w-12 h-12',
  };

  // 아이콘 크기
  const iconSizeClasses = {
    sm: 'w-4 h-4',
    md: 'w-5 h-5',
    lg: 'w-6 h-6',
  };

  // 변형별 스타일
  const variantClasses = {
    primary: 'bg-blue-600 hover:bg-blue-700 text-white border-transparent',
    secondary: 'bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600',
    danger: 'bg-red-600 hover:bg-red-700 text-white border-transparent',
    ghost: 'bg-transparent hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 border-transparent',
    outline: 'bg-transparent hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600',
  };

  const baseClasses = 'inline-flex items-center justify-center gap-2 font-medium rounded-lg border transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-blue-400';
  const disabledClasses = disabled ? 'opacity-50 cursor-not-allowed' : '';

  // 모든 내부 클래스를 결합한 후 외부 className과 병합
  const internalClasses = `${baseClasses} ${sizeClasses[size]} ${variantClasses[variant]} ${disabledClasses}`;
  const mergedClassName = G7Core()?.style?.mergeClasses?.(internalClasses, className)
    ?? `${internalClasses} ${className}`;

  return (
    <Button
      onClick={onClick}
      disabled={disabled}
      className={mergedClassName}
      style={style}
      aria-label={label ? undefined : resolveIconButtonLabel(iconName, ariaLabel)}
      title={label ? undefined : resolveIconButtonLabel(iconName, ariaLabel)}
      id={id} {...editorAttrs}
    >
      <Icon name={iconName} className={iconSizeClasses[size]} />
      {label}
    </Button>
  );
};
