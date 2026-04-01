import React from 'react';
import { Span } from '../basic/Span';

export interface BadgeProps {
  color?: string;
  text?: string;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
  style?: React.CSSProperties;
}

const colorStyles: Record<string, string> = {
  blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
  green: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
  red: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
  gray: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
  yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
  purple: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
  orange: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
  teal: 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
  cyan: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300',
};

const sizeStyles: Record<string, string> = {
  sm: 'px-1.5 py-0.5 text-[10px]',
  md: 'px-2 py-0.5 text-xs',
  lg: 'px-2.5 py-1 text-sm',
};

/**
 * Badge 집합 컴포넌트
 *
 * 색상 기반의 라벨 뱃지입니다. 상태, 타입 등의 분류를 시각적으로 표현합니다.
 *
 * 기본 컴포넌트 조합: Span
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Badge",
 *   "props": {
 *     "color": "blue",
 *     "text": "활성"
 *   }
 * }
 */
export const Badge: React.FC<React.PropsWithChildren<BadgeProps>> = ({
  color = 'gray',
  text,
  size = 'md',
  className = '',
  style,
  children,
}) => {
  const colorClass = colorStyles[color] || colorStyles.gray;
  const sizeClass = sizeStyles[size] || sizeStyles.md;

  return (
    <Span
      className={`inline-flex items-center rounded-full font-medium ${sizeClass} ${colorClass} ${className}`}
      style={style}
    >
      {text}
      {children}
    </Span>
  );
};
