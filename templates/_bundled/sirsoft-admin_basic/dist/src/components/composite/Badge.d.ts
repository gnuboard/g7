import { default as React } from 'react';
export interface BadgeProps {
    color?: string;
    text?: string;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
    style?: React.CSSProperties;
}
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
export declare const Badge: React.FC<React.PropsWithChildren<BadgeProps>>;
