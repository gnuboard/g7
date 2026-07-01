import React from 'react';
import { Icon } from './Icon';
import { IconSize } from './IconTypes';

/**
 * UnifiedIcon 크기 타입
 */
export type UnifiedIconSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

/**
 * UnifiedIcon Props
 */
export interface UnifiedIconProps {
  /**
   * 아이콘 지정자
   *
   * @example
   * // Font Awesome (기본)
   * "fa:cart-shopping"
   * "fa-solid fa-cart-shopping"
   * "cart-shopping" // 접두사 없으면 FA 기본
   *
   * // 외부 SVG 파일
   * "svg:/modules/my-module/assets/icon.svg"
   *
   * // 이미지 파일
   * "img:/images/custom-icon.png"
   */
  icon: string;

  /**
   * 아이콘 크기
   * - xs: 12px (w-3 h-3)
   * - sm: 16px (w-4 h-4)
   * - md: 20px (w-5 h-5) - 기본값
   * - lg: 24px (w-6 h-6)
   * - xl: 32px (w-8 h-8)
   *
   * @default 'md'
   */
  size?: UnifiedIconSize;

  /**
   * 추가 CSS 클래스
   */
  className?: string;

  /**
   * 색상 (Tailwind 클래스)
   *
   * @example
   * "text-blue-500"
   * "text-slate-600 dark:text-slate-300"
   */
  color?: string;

  /**
   * 접근성 레이블
   */
  ariaLabel?: string;
}

/**
 * 크기별 Tailwind 클래스 매핑
 */
const sizeClassMap: Record<UnifiedIconSize, string> = {
  xs: 'w-3 h-3', // 12px
  sm: 'w-4 h-4', // 16px
  md: 'w-5 h-5', // 20px
  lg: 'w-6 h-6', // 24px
  xl: 'w-8 h-8', // 32px
};

/**
 * Icon 컴포넌트용 크기 매핑 (Font Awesome size prop)
 */
const iconSizeMap: Record<UnifiedIconSize, IconSize | undefined> = {
  xs: 'xs',
  sm: 'sm',
  md: undefined, // 기본값
  lg: 'lg',
  xl: '2x',
};

/**
 * UnifiedIcon 컴포넌트
 *
 * 다양한 아이콘 소스(Font Awesome, SVG, 이미지)를 통합하여 렌더링하는 컴포넌트입니다.
 *
 * @example
 * // Font Awesome 아이콘 (기본)
 * <UnifiedIcon icon="fa:cart-shopping" size="md" />
 * <UnifiedIcon icon="cart-shopping" /> // 접두사 없으면 FA 기본
 *
 * // 외부 SVG 파일
 * <UnifiedIcon icon="svg:/modules/my-module/assets/icon.svg" size="lg" />
 *
 * // 이미지 파일
 * <UnifiedIcon icon="img:/images/custom-icon.png" size="sm" />
 */
export const UnifiedIcon: React.FC<UnifiedIconProps> = ({
  icon,
  size = 'md',
  className = '',
  color,
  ariaLabel,
}) => {
  // 타입 파싱: "type:value" 형식 분리
  const colonIndex = icon.indexOf(':');

  // "fa-solid fa-xxx" 또는 "fas fa-xxx" 형식인 경우 Font Awesome으로 처리
  const isFontAwesomeClass =
    icon.startsWith('fa-') ||
    icon.startsWith('fas ') ||
    icon.startsWith('far ') ||
    icon.startsWith('fab ') ||
    icon.startsWith('fal ') ||
    icon.startsWith('fad ');

  let type: string;
  let name: string;

  if (isFontAwesomeClass) {
    // Font Awesome 클래스 형식
    type = 'fa';
    name = icon;
  } else if (colonIndex > 0) {
    // "type:name" 형식
    type = icon.substring(0, colonIndex);
    name = icon.substring(colonIndex + 1);
  } else {
    // 접두사 없음 = Font Awesome 기본
    type = 'fa';
    name = icon;
  }

  const sizeClass = sizeClassMap[size];
  const colorClass = color || '';

  switch (type) {
    case 'fa':
      // Font Awesome: Icon 컴포넌트 위임
      return (
        <Icon
          name={name}
          size={iconSizeMap[size]}
          className={className}
          color={color}
          ariaLabel={ariaLabel}
        />
      );

    case 'svg':
      // 외부 SVG 파일 (img 태그로 렌더링)
      return (
        <img
          src={name}
          className={`${sizeClass} ${colorClass} ${className}`.trim()}
          alt={ariaLabel || ''}
          aria-label={ariaLabel}
          role="img"
        />
      );

    case 'img':
      // 이미지 파일
      return (
        <img
          src={name}
          className={`${sizeClass} ${className}`.trim()}
          alt={ariaLabel || ''}
          role="img"
        />
      );

    default:
      // 기본: Font Awesome
      return (
        <Icon
          name={icon}
          size={iconSizeMap[size]}
          className={className}
          color={color}
          ariaLabel={ariaLabel}
        />
      );
  }
};
