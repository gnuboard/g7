import React from 'react';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';

export interface BadgeProps {
  /** 색상명 직접 지정 (blue/green/red/gray/yellow/... ). variant 보다 우선 적용 */
  color?: string;
  /** 의미 기반 변형 (success/warning/danger/info/primary/secondary/text). 백엔드 Enum variant() 출력과 매핑 */
  variant?: string;
  text?: string;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
  style?: React.CSSProperties;
  /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
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

/**
 * 의미 기반 variant → 색상명 매핑.
 *
 * 백엔드 Enum 의 variant() 메서드(success/warning/danger/info/primary/secondary 등)가
 * 반환하는 semantic 값을 Badge 색상으로 해석한다. StatusBadge 의 색상 규약과 일관.
 */
const variantToColor: Record<string, string> = {
  success: 'green',
  warning: 'yellow',
  danger: 'red',
  error: 'red',
  info: 'blue',
  primary: 'blue',
  secondary: 'gray',
  text: 'gray',
  default: 'gray',
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
 * // 레이아웃 JSON 사용 예시 — 색상 직접 지정
 * {
 *   "name": "Badge",
 *   "props": { "color": "blue", "text": "활성" }
 * }
 * @example
 * // 의미 기반 variant (백엔드 Enum variant() 출력 바인딩)
 * {
 *   "name": "Badge",
 *   "props": { "variant": "{{row.option_status_variant}}", "text": "{{row.option_status_label}}" }
 * }
 */
export const Badge: React.FC<React.PropsWithChildren<BadgeProps>> = ({
  color,
  variant,
  text,
  size = 'md',
  className = '',
  style,
  children,
  id,
  editorAttrs,
}) => {
  // 우선순위: color(명시적 색상명) > variant(의미 기반) > gray(기본)
  const resolvedColor = color || (variant ? variantToColor[variant] : undefined) || 'gray';
  const colorClass = colorStyles[resolvedColor] || colorStyles.gray;
  const sizeClass = sizeStyles[size] || sizeStyles.md;

  return (
    <Span
      className={`inline-flex items-center rounded-full font-medium ${sizeClass} ${colorClass} ${className}`}
      style={style}
      id={id} {...editorAttrs}
    >
      {text}
      {children}
    </Span>
  );
};
