import { default as React } from 'react';
export interface HeroSlide {
    image: string;
    alt?: string;
}
export interface HeroCtaCard {
    label: string;
    href: string;
    /**
     * variant 옵션:
     * - 'building': .main-visual__button-card--building CSS 배경 사용 (테마 둘러보기)
     * - 'lab':      .main-visual__button-card--lab CSS 배경 사용 (제작의뢰)
     * - 'intro':    .main-visual__button-card--intro 브랜드 그라데이션 (오프셋 테마 소개)
     */
    variant: 'building' | 'lab' | 'intro';
}
export interface HeroCarouselProps {
    slides: HeroSlide[];
    intervalMs?: number;
    title?: string;
    subtitle?: string;
    /** description은 <br> 태그 허용 (정적 콘텐츠) */
    description?: string;
    ctaCards?: HeroCtaCard[];
    className?: string;
    initialPaused?: boolean;
    ariaLabel?: string;
}
/**
 * 메인 비주얼 자동 슬라이더
 * - 7초 자동 전환 (intervalMs)
 * - prev/next/pause/play 컨트롤
 * - 카운터 + 진행 바 (.bar는 CSS의 hero-bar keyframes로 채워짐)
 * - CTA 카드 3개 (building/lab/intro variant — CSS 정의 클래스 그대로 매핑)
 */
declare const HeroCarousel: React.FC<HeroCarouselProps>;
export default HeroCarousel;
