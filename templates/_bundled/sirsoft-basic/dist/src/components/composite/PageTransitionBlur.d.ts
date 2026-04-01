import { default as React } from 'react';
export interface PageTransitionBlurProps {
    className?: string;
    style?: React.CSSProperties;
}
/**
 * PageTransitionBlur 컴포넌트
 *
 * TransitionManager의 isPending 상태를 구독하여 페이지 전환 시
 * 전체 콘텐츠에 블러 오버레이를 표시합니다.
 * PageTransitionIndicator(z-50)보다 아래 레이어(z-40)에 위치합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "PageTransitionBlur"
 * }
 */
export declare const PageTransitionBlur: React.FC<PageTransitionBlurProps>;
export default PageTransitionBlur;
