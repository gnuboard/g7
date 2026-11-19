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
// 렌더 중 오류를 일부러 내는 배너(id 999) — 미리보기가 스스로 회복하는지 본다
vi.mock('../../components/composite/HeroSlider', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../components/composite/HeroSlider')>();
  const HeroSlider = (props: any) => {
    if (props.banners?.some((b: any) => b.id === 999)) throw new Error('boom');
    return React.createElement(actual.HeroSlider, props);
  };
  return { ...actual, HeroSlider };
});
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

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
    expect(pcBody.querySelector('.hs-slider')).not.toBeNull();
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
    expect(empty.container.querySelector('.hs-slider')).toBeNull();
    expect(empty.container.textContent).toContain('노출 중인 배너가 없습니다');
  });

  // @scenario surface=preview_banners_text_coerced_to_string
  // @effects preview_survives_multilingual_fields
  it('넘겨받은 배너의 문구 칸이 다국어 원본(언어별 객체)이어도 화면 언어로 골라 글자로 그린다 — 객체를 그대로 그리면 화면이 깨진다', () => {
    document.documentElement.lang = 'ko';
    const raw = [{ ...banners[0], eyebrow: { ko: '머리글 한 줄', en: 'Eyebrow' }, description: { ko: '설명', en: 'Desc' } }] as any;
    const view = render(<HeroBannerPreview banners={raw} device="pc" />);
    const frame = view.container.querySelector('iframe')!;
    fireEvent.load(frame);
    const body = frame.contentDocument!.body;
    expect(body.querySelector('.hs-slider__eyebrow')?.textContent).toBe('머리글 한 줄');
    expect(body.textContent).toContain('설명');
    expect(view.container.querySelector('[data-hs-preview-error]')).toBeNull();
  });

  // @scenario surface=preview_recovers_after_render_error
  // @effects preview_survives_multilingual_fields
  it('미리보기가 렌더 중 깨지면 그 자리에만 안내를 두고, 위치·배너가 바뀌면 다시 그린다(탭을 옮겨도 깨진 채 남지 않는다)', () => {
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    const view = render(<HeroBannerPreview banners={[{ ...banners[0], id: 999 }] as any} device="pc" position="home_hero" />);
    expect(view.container.querySelector('[data-hs-preview-error]')?.textContent).toBe('sirsoft-hero_section.admin.preview.render_failed');
    view.rerender(<HeroBannerPreview banners={banners} device="pc" position="shop_hero" />);
    expect(view.container.querySelector('[data-hs-preview-error]')).toBeNull();
    const frame = view.container.querySelector('iframe')!;
    fireEvent.load(frame);
    expect(frame.contentDocument!.body.querySelector('.hs-slider')).not.toBeNull();
    errors.mockRestore();
  });
});
