import { default as React } from 'react';
export interface PageTransitionIndicatorProps {
    className?: string;
    style?: React.CSSProperties;
}
/**
 * PageTransitionIndicator 컴포넌트
 *
 * TransitionManager의 isPending 상태를 구독하여 페이지 전환 시
 * 상단에 로딩 바를 표시합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "PageTransitionIndicator"
 * }
 */
export declare const PageTransitionIndicator: React.FC<PageTransitionIndicatorProps>;
export default PageTransitionIndicator;
