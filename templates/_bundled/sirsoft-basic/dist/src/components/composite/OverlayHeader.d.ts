import { default as React } from 'react';
export interface OverlayMenuItem {
    label: string;
    href: string;
    active?: boolean;
}
export interface OverlayUser {
    uuid?: string;
    name?: string;
    avatar?: string;
}
export interface OverlayHeaderProps {
    logoLabel?: string;
    logoHref?: string;
    menu: OverlayMenuItem[];
    /** 비로그인 시 노출되는 로그인 링크 */
    loginHref?: string;
    loginLabel?: string;
    /** 마이페이지 링크 (로그인 시 노출) */
    myHref?: string;
    myLabel?: string;
    logoutLabel?: string;
    menuButtonAriaLabel?: string;
    thresholdPx?: number;
    variant?: 'overlay' | 'subpage';
    className?: string;
    /** 로그인된 사용자 정보 — 없으면 비로그인 상태로 간주 (보통 _global.currentUser 바인딩) */
    currentUser?: OverlayUser | null;
}
declare const OverlayHeader: React.FC<OverlayHeaderProps>;
export default OverlayHeader;
