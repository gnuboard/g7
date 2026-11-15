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
 * 띠가 쓰는 `@keyframes loading` 은 `src/styles/main.css` 에 있다 — 컴포넌트가 `<style>` 요소로 실으면
 * 엄격한 스타일 정책(`style-src 'self'`)에서 막혀 띠가 움직이지 않는다 (공개 #163).
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "PageTransitionIndicator"
 * }
 */
export declare const PageTransitionIndicator: React.FC<PageTransitionIndicatorProps>;
