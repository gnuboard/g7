import React from 'react';
import { HeroSlider, type HeroBanner, type HeroSliderSettings } from './HeroSlider';

export interface HeroBannerPreviewProps {
  /** 미리 볼 배너 (목록 미리보기는 노출 중인 배너들, 폼 미리보기는 저장 전 입력값 1건) */
  banners?: HeroBanner[];

  /** 위치 슬라이더 설정 */
  settings?: HeroSliderSettings;

  /** 미리 볼 기기 */
  device?: 'pc' | 'mobile';

  /** 배너가 없을 때 보일 문구 */
  emptyText?: string;

  /** 사용자 정의 클래스 */
  className?: string;
}

/**
 * 관리자 미리보기 — 사용자 슬라이더(`HeroSlider`)를 기기 틀(PC 폭·모바일 폭) 안에서 그대로 그린다.
 *
 * 관리자 화면 폭으로는 미디어 쿼리가 모바일로 바뀌지 않으므로 기기를 강제한다(`forceDevice`).
 * 배너가 없으면 빈 상태 문구를 보인다.
 */
export const HeroBannerPreview: React.FC<HeroBannerPreviewProps> = ({
  banners = [],
  settings,
  device = 'pc',
  emptyText = '',
  className = '',
}) => (
  <div className={`hs-preview hs-preview--${device} ${className}`.trim()} data-device={device}>
    {banners.length === 0
      ? <p className="hs-preview__empty">{emptyText}</p>
      : <HeroSlider banners={banners} settings={settings} forceDevice={device} />}
  </div>
);

export default HeroBannerPreview;
