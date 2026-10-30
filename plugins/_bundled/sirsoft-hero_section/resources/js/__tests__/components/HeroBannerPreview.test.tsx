/**
 * @file HeroBannerPreview.test.tsx
 * @description 관리자 미리보기 — 기기 틀 안에서 사용자 슬라이더를 그대로 그리고, 모바일 틀은 모바일 이미지를 강제한다.
 * (매니페스트 hero-slider.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent } from '@testing-library/react';
import { HeroBannerPreview } from '../../components/composite/HeroBannerPreview';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));
vi.mock('swiper/css', () => ({}));
vi.mock('swiper/css/effect-fade', () => ({}));
vi.mock('swiper/css/navigation', () => ({}));
vi.mock('swiper/css/pagination', () => ({}));
vi.mock('swiper/css/effect-cube', () => ({}));
vi.mock('swiper/css/effect-flip', () => ({}));
vi.mock('swiper/css/effect-coverflow', () => ({}));
vi.mock('swiper/css/effect-creative', () => ({}));

const banners = [{
  id: 1,
  title: '가을 신상품',
  image_alt: '가을',
  link_url: '/shop',
  images: { pc: { url: '/pc.jpg', width: 1920, height: 640 }, mobile: { url: '/m.jpg', width: 750, height: 1000 } },
}];

describe('HeroBannerPreview', () => {
  afterEach(() => cleanup());

  // @scenario surface=preview_renders_device_frame
  // @effects responsive_image_switch
  it('기기 틀 안에 슬라이더를 그리고, 모바일 틀은 모바일 이미지를 강제한다', () => {
    const pc = render(<HeroBannerPreview banners={banners} device="pc" />);
    const pcFrame = pc.container.querySelector('iframe')!;
    fireEvent.load(pcFrame);
    const pcBody = pcFrame.contentDocument!.body;
    expect(pcFrame.width).toBe('1366');
    expect(pcBody.querySelector('.hs')).not.toBeNull();
    expect(pcBody.querySelector('img')?.getAttribute('src')).toBe('/pc.jpg');
    expect(pcBody.querySelector('source')).toBeNull();
    pc.unmount();

    const mobile = render(<HeroBannerPreview banners={banners} device="mobile" />);
    expect(mobile.container.querySelector('.hs-preview--mobile')).not.toBeNull();
    const mobileFrame = mobile.container.querySelector('iframe')!;
    fireEvent.load(mobileFrame);
    expect(mobileFrame.width).toBe('390');
    expect(mobileFrame.contentDocument!.querySelector('img')?.getAttribute('src')).toBe('/m.jpg');
    mobile.unmount();

    const empty = render(<HeroBannerPreview banners={[]} emptyText="노출 중인 배너가 없습니다" />);
    expect(empty.container.querySelector('.hs')).toBeNull();
    expect(empty.container.textContent).toContain('노출 중인 배너가 없습니다');
  });
});
