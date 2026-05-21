import { default as React } from 'react';
export interface RevealOnScrollProps {
    delay?: 1 | 2 | 3 | 4 | 5 | 6;
    className?: string;
    rootMargin?: string;
    threshold?: number;
    children?: React.ReactNode;
}
/**
 * 스크롤 진입 시 페이드인 (.reveal + .is-in-view 토글)
 * IntersectionObserver 기반, 1회 발화 후 unobserve.
 * 미지원 환경에서는 즉시 is-in-view 적용 (fallback).
 */
declare const RevealOnScroll: React.FC<RevealOnScrollProps>;
export default RevealOnScroll;
