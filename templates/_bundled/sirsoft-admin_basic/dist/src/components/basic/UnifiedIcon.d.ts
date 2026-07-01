import { default as React } from 'react';
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
export declare const UnifiedIcon: React.FC<UnifiedIconProps>;
