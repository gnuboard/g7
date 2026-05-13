import React from 'react';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { IconName } from '../basic/IconTypes';

// G7Core 전역 객체의 스타일 헬퍼 접근
const G7Core = () => (window as any).G7Core;

export interface IconButtonProps {
  iconName: IconName;
  label?: string;
  onClick?: () => void;
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  size?: 'sm' | 'md' | 'lg';
  disabled?: boolean;
  className?: string;
  style?: React.CSSProperties;
}

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
  onClick,
  variant = 'primary',
  size = 'md',
  disabled = false,
  className = '',
  style,
}) => {
  // 크기별 스타일
  const sizeClasses = {
    sm: label ? 'px-3 py-1.5 text-sm' : 'p-1.5',
    md: label ? 'px-4 py-2 text-base' : 'p-2',
    lg: label ? 'px-5 py-2.5 text-lg' : 'p-2.5',
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
    >
      <Icon name={iconName} className={iconSizeClasses[size]} />
      {label}
    </Button>
  );
};
