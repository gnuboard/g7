import { default as React } from 'react';
export type SpinnerSize = 'sm' | 'md' | 'lg' | 'xl';
export interface LoadingSpinnerProps {
    id?: string;
    size?: SpinnerSize;
    color?: string;
    fullscreen?: boolean;
    text?: string;
    className?: string;
}
/**
 * LoadingSpinner 집합 컴포넌트
 *
 * 로딩 상태를 시각적으로 표시하는 스피너 컴포넌트.
 * size, color, fullscreen 모드, text 라벨 옵션 제공.
 *
 * 기본 컴포넌트 조합:
 * - Div(컨테이너) > Div(스피너) + Span(텍스트, 옵션)
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "LoadingSpinner",
 *   "props": {
 *     "size": "lg",
 *     "text": "데이터를 불러오는 중...",
 *     "fullscreen": false
 *   }
 * }
 */
export declare const LoadingSpinner: React.FC<LoadingSpinnerProps>;
