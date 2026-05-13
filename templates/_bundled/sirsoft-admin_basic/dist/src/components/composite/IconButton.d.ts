import { default as React } from 'react';
import { IconName } from '../basic/IconTypes';
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
export declare const IconButton: React.FC<IconButtonProps>;
