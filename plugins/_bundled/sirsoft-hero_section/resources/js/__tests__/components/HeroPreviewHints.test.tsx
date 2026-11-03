/**
 * @file HeroPreviewHints.test.tsx
 * @description 편집 미리보기의 안내 두 가지 — (1) 지금 기기에서 문구가 배너 높이를 넘어 문구 영역 안 스크롤이 생기면 알린다,
 * (2) 사진 평균색과 문구 배경으로 어림한 글자 대비가 낮으면 추정값과 권장 조치를 알린다. 둘 다 안내일 뿐 문구·글자색을 바꾸거나
 * 저장을 막지 않는다. (매니페스트 preview-frame-and-conflict.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { render, cleanup, fireEvent, waitFor } from '@testing-library/react';
import { HeroBannerPreview } from '../../components/composite/HeroBannerPreview';
import fs from 'node:fs';
import path from 'node:path';
import { contrastRatio, estimateContrast, OVERLAYS } from '../../support/editor';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

const sample = vi.hoisted(() => ({ rgb: [255, 255, 255] as [number, number, number] | null }));
vi.mock('../../support/editor', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../support/editor')>()),
  sampleImage: vi.fn(async () => sample.rgb),
}));

const draft = (over: Record<string, unknown> = {}) => ({
  id: 5,
  title: { ko: '가을 신상품' },
  description: { ko: '새로 들어온 상품을 만나 보세요' },
  button_text: {},
  image_alt: {},
  title_color: '#FFFFFF',
  description_color: '#E5E7EB',
  presentation: { pc_x: 50, pc_y: 50, mobile_x: 50, mobile_y: 50, overlay: 'none' },
  images: { pc: { id: 1, url: '/pc.png', width: 1920, height: 640 }, mobile: null },
  ...over,
});

/** 미리보기를 띄우고 iframe 을 불러 슬라이더가 그려지게 한다. */
const mount = (props: Record<string, unknown>) => {
  const view = render(<HeroBannerPreview locale="ko" defaultLocale="ko" device="pc" {...props} />);
  fireEvent.load(view.container.querySelector('iframe')!);
  return view;
};

describe('미리보기 안내', () => {
  beforeEach(() => {
    sample.rgb = [255, 255, 255];
    (window as any).G7Core = { t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}` };
  });
  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  // @scenario surface=contrast_estimate_hint
  // @scenario surface=contrast_estimate_hint, case=estimate
  it('대비 추정은 사진 평균색 위에 문구 배경을 절반 세기로 겹친 색과 글자색의 WCAG 대비율이다', () => {
    expect(contrastRatio('#FFFFFF', [0, 0, 0])).toBeCloseTo(21, 1);
    // 흰 사진 + 흰 글자: 배경 없음 1:1, 어두운 배경도 3:1 미만(실측 2.55)
    expect(estimateContrast('#FFFFFF', [255, 255, 255], 'none')).toBeCloseTo(1, 2);
    expect(estimateContrast('#FFFFFF', [255, 255, 255], 'dark')!).toBeLessThan(3);
    // 회색 사진 + 흰 글자 + 밝은 배경은 낮고(실측 1.64), 어두운 배경은 충분하다(실측 5.68)
    expect(estimateContrast('#FFFFFF', [156, 163, 175], 'light')!).toBeLessThan(2);
    expect(estimateContrast('#FFFFFF', [156, 163, 175], 'dark')!).toBeGreaterThan(4.5);
    // 검은 사진 + 어두운 글자는 낮다(실측 1.18), 잘못된 색은 추정하지 않는다
    expect(estimateContrast('#111827', [0, 0, 0], 'none')!).toBeLessThan(1.5);
    expect(estimateContrast('red', [0, 0, 0], 'none')).toBeNull();
  });

  // @scenario surface=contrast_estimate_hint, case=estimate
  it('추정에 쓰는 문구 배경 색·불투명도는 main.css 의 그라데이션 끝값과 같다', () => {
    const css = fs.readFileSync(path.resolve(__dirname, '../../../css/main.css'), 'utf8');
    for (const [overlay, { rgb, alpha }] of Object.entries(OVERLAYS)) {
      const match = new RegExp(`\\.hs-slider__text--${overlay}\\s*\\{[^}]*rgba\\(([^)]+)\\)`).exec(css);
      expect(match, overlay).not.toBeNull();
      expect(match![1].split(',').map((v) => parseFloat(v))).toEqual([...rgb, alpha]);
    }
  });

  // @scenario surface=contrast_estimate_hint, case=low
  it('대비가 낮으면 제목·설명의 추정값과 권장 조치를 안내하고 글자색은 바꾸지 않는다', async () => {
    const value = draft();
    const view = mount({ draft: value });
    const hint = await waitFor(() => {
      const el = view.container.querySelector('[data-hint="contrast"]');
      expect(el).not.toBeNull();
      return el!;
    });
    expect(hint.textContent).toContain('admin.form.preview.contrast_low');
    expect(hint.textContent).toContain('admin.form.preview.contrast_title{\\"ratio\\":\\"1\\"}');
    expect(hint.textContent).toContain('admin.form.preview.contrast_description');
    expect(hint.textContent).toContain('admin.form.preview.contrast_tip_light{"option":"sirsoft-hero_section.admin.form.presentation.dark"}');
    expect(value.title_color).toBe('#FFFFFF');
    const title = view.container.querySelector('iframe')!.contentDocument!.querySelector<HTMLElement>('.hs-slider__title')!;
    expect(title.style.color).toBe('rgb(255, 255, 255)');
  });

  // @scenario surface=contrast_estimate_hint, case=ok
  it('대비가 충분하거나 사진을 읽지 못하면 대비 안내를 띄우지 않는다', async () => {
    sample.rgb = [0, 0, 0];
    const dark = mount({ draft: draft() });
    await waitFor(() => expect(dark.container.querySelector('.hs-slider__title')).toBeNull());
    await new Promise((r) => setTimeout(r, 0));
    expect(dark.container.querySelector('[data-hint="contrast"]')).toBeNull();
    dark.unmount();

    sample.rgb = null;
    const unknown = mount({ draft: draft() });
    await new Promise((r) => setTimeout(r, 0));
    expect(unknown.container.querySelector('[data-hint="contrast"]')).toBeNull();
  });

  // @scenario surface=long_text_overflow_hint
  it('지금 기기에서 문구 영역에 스크롤이 생기면 줄이기를 권하는 안내를 띄우고, 넘치지 않으면 띄우지 않는다', async () => {
    sample.rgb = null;
    const view = mount({ draft: draft() });
    expect(view.container.querySelector('[data-hint="text-overflow"]')).toBeNull();
    // 슬라이더는 iframe 문서에 그려지므로 그 창의 원형에서 높이를 흉내 낸다
    const frameWindow = view.container.querySelector('iframe')!.contentWindow as unknown as typeof window;
    const height = { scroll: 500, client: 300, lines: 500 };
    vi.spyOn(frameWindow.HTMLElement.prototype, 'scrollHeight', 'get').mockImplementation(function (this: HTMLElement) {
      return this.classList.contains('hs-slider__scroll') ? height.scroll : 0;
    });
    // 넘침은 줄의 배치 높이(등장 효과의 이동 제외)로도 확인한다
    vi.spyOn(frameWindow.HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function (this: HTMLElement) {
      return this.classList.contains('hs-slider__line') ? height.lines : 0;
    });
    vi.spyOn(frameWindow.HTMLElement.prototype, 'clientHeight', 'get').mockImplementation(function (this: HTMLElement) {
      return this.classList.contains('hs-slider__scroll') ? height.client : 0;
    });
    view.rerender(<HeroBannerPreview locale="ko" defaultLocale="ko" device="pc" draft={draft({ description: { ko: '아주 긴 설명' } })} />);
    const hint = await waitFor(() => {
      const el = view.container.querySelector('[data-hint="text-overflow"]');
      expect(el).not.toBeNull();
      return el!;
    });
    expect(hint.textContent).toContain('admin.form.preview.text_overflow');
    expect(hint.textContent).toContain('"device":"PC"');
    // 문구는 줄이지 않는다
    expect(view.container.querySelector('iframe')!.contentDocument!.querySelector('.hs-slider__title')!.textContent).toBe('가을 신상품');
    expect(view.container.querySelector('iframe')!.contentDocument!.querySelector('.hs-slider__description')!.textContent).toBe('아주 긴 설명');

    height.scroll = 300;
    view.rerender(<HeroBannerPreview locale="ko" defaultLocale="ko" device="pc" draft={draft({ title: { ko: '짧게' } })} />);
    await waitFor(() => expect(view.container.querySelector('[data-hint="text-overflow"]')).toBeNull());

    // 「효과 다시 보기」 중 줄이 아래에서 올라오며 스크롤 높이만 늘어난 경우는 넘침으로 안내하지 않는다
    height.scroll = 320;
    height.lines = 280;
    view.rerender(<HeroBannerPreview locale="ko" defaultLocale="ko" device="pc" draft={draft({ title: { ko: '효과 재생 중' } })} />);
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(view.container.querySelector('[data-hint="text-overflow"]')).toBeNull();
  });

  // @scenario surface=long_text_overflow_hint, case=with_controls
  it('혼자일 때는 들어가도 배너가 2장 이상이라 넘김 단추 자리를 비우면 넘치는 경우를 따로 알리고, 미리보기 모양은 그대로 둔다', async () => {
    sample.rgb = null;
    const view = mount({ draft: draft() });
    const frameWindow = view.container.querySelector('iframe')!.contentWindow as unknown as typeof window;
    // 넘김 단추 자리(.hs-slider--controlled)를 비우면 문구 영역이 50px 줄어든다
    vi.spyOn(frameWindow.HTMLElement.prototype, 'scrollHeight', 'get').mockImplementation(function (this: HTMLElement) {
      return this.classList.contains('hs-slider__scroll') ? 280 : 0;
    });
    vi.spyOn(frameWindow.HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function (this: HTMLElement) {
      return this.classList.contains('hs-slider__line') ? 280 : 0;
    });
    vi.spyOn(frameWindow.HTMLElement.prototype, 'clientHeight', 'get').mockImplementation(function (this: HTMLElement) {
      if (!this.classList.contains('hs-slider__scroll')) return 0;
      return this.closest('.hs-slider')?.classList.contains('hs-slider--controlled') ? 250 : 300;
    });
    view.rerender(<HeroBannerPreview locale="ko" defaultLocale="ko" device="mobile" draft={draft({ description: { ko: '조금 긴 설명' } })} />);
    const hint = await waitFor(() => {
      const el = view.container.querySelector('[data-hint="text-overflow"]');
      expect(el).not.toBeNull();
      return el!;
    });
    expect(hint.textContent).toContain('admin.form.preview.text_overflow_controls');
    expect(hint.textContent).toContain('"device":"MOBILE"');
    expect(view.container.querySelector('iframe')!.contentDocument!.querySelector('.hs-slider')!.classList.contains('hs-slider--controlled')).toBe(false);
  });
});
