import { default as React } from 'react';
export interface RevealScrollManagerProps {
    /** 관찰 대상 셀렉터 — 기본 `.aict-home .reveal` */
    selector?: string;
    rootMargin?: string;
    threshold?: number;
}
/**
 * 페이지 내 모든 `.reveal` 요소에 IntersectionObserver를 부착해
 * viewport 진입 시 `.is-in-view` 클래스를 토글한다.
 * 정적 마크업의 .reveal 요소 (RevealOnScroll wrapper 미사용)에도 페이드인 적용.
 */
declare const RevealScrollManager: React.FC<RevealScrollManagerProps>;
export default RevealScrollManager;
