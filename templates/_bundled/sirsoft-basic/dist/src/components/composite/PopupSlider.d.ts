import { default as React } from 'react';
export interface PopupSlideItem {
    image: string;
    alt?: string;
    href?: string;
}
export interface PopupSliderProps {
    posters: PopupSlideItem[];
    intervalMs?: number;
    title?: string;
    className?: string;
    initialPaused?: boolean;
    /** prev/next/pause 아이콘 경로 — 코어에서 주입 */
    prevIcon?: string;
    nextIcon?: string;
    pauseIcon?: string;
}
/**
 * S4 알림마당 영역의 POPUP ZONE 포스터 슬라이더.
 * 5.5초 자동 전환 (intervalMs 기본값), prev/pause/next 컨트롤.
 */
declare const PopupSlider: React.FC<PopupSliderProps>;
export default PopupSlider;
