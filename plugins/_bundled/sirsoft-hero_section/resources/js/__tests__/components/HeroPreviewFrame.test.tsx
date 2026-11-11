/**
 * @file HeroPreviewFrame.test.tsx
 * @description 관리자 미리보기의 구좌 틀 — 기기 viewport(PC 1366·모바일 390)는 그대로 두고, 그 안에 활성 사용자 템플릿의
 * 본문 상자 사슬(클래스·반응형 덮어쓰기)과 템플릿 CSS 를 실어 실제 사이트와 같은 구좌 폭에서 슬라이더를 그린다.
 * 반응형 키 선택은 코어 ResponsiveManager 와 같은 규칙이어야 한다. (매니페스트 preview-frame-and-conflict.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { responsiveManager } from '@core/template-engine/ResponsiveManager';
import { HeroBannerPreview, PREVIEW_STYLES_TIMEOUT_MS } from '../../components/composite/HeroBannerPreview';
import { frameClass, matchResponsiveKey } from '../../support/editor';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

const banners = [{
  id: 1,
  title: '가을 신상품',
  image_alt: '가을',
  images: { pc: { url: '/pc.jpg', width: 1920, height: 640 }, mobile: null },
}];

const frame = {
  template: 'demo-user',
  stylesheets: ['/assets/demo-user/css/components.css?v=3'],
  positions: {
    home_hero: {
      layout: 'home',
      chain: [
        { className: 'min-h-screen flex flex-col', responsive: {} },
        { className: 'flex-1', responsive: {} },
        { className: 'min-h-fit px-4', responsive: { desktop: 'min-h-fit max-w-7xl mx-auto px-8' } },
      ],
    },
    shop_hero: null,
  },
};

/** iframe 을 띄우고 그 문서를 돌려준다. */
const mountFrame = (ui: React.ReactElement) => {
  const view = render(ui);
  const iframe = view.container.querySelector('iframe')!;
  fireEvent.load(iframe);
  return { view, iframe, doc: iframe.contentDocument! };
};

/**
 * 틀을 떼었다 다시 붙인다 — 브라우저는 about:blank 문서를 새로 만들고 load 를 다시 보낸다. 옛 문서의 노드는 그대로 남는다.
 * jsdom 은 떼는 순간 옛 창을 닫으며 문서를 비워(jsdom 소스의 「not really correct behavior」 주석) React 가 옛 포털을 지우지
 * 못하므로, 옛 창의 닫기만 막아 브라우저와 같게 둔다.
 */
const reattach = (iframe: HTMLIFrameElement) => {
  const parent = iframe.parentNode!;
  (iframe.contentWindow as unknown as { close: () => void }).close = () => {};
  iframe.remove();
  parent.insertBefore(iframe, parent.firstChild);
};

describe('미리보기 구좌 틀', () => {
  afterEach(() => cleanup());

  // @scenario surface=preview_frame_responsive_rule_matches_core
  // @effects preview_matches_site_slot
  it('반응형 키 선택이 코어 ResponsiveManager 와 같다(프리셋·사용자 범위·열린 범위, 사용자 범위 우선·좁은 범위 우선)', () => {
    const sets: Array<Record<string, string>> = [
      { desktop: 'a' },
      { mobile: 'a', tablet: 'b', desktop: 'c' },
      { portable: 'a', desktop: 'b' },
      { portable: 'a', mobile: 'b' },
      { '768-1023': 'a', tablet: 'b', desktop: 'c' },
      { '-599': 'a', '1200-': 'b', portable: 'c' },
      { 'bogus': 'a', desktop: 'b' },
    ];
    for (const responsive of sets) {
      for (const width of [0, 390, 599, 600, 767, 768, 800, 1023, 1024, 1199, 1200, 1366, 1920]) {
        expect(matchResponsiveKey(responsive, width), `${JSON.stringify(responsive)} @ ${width}`).toBe(responsiveManager.getMatchingKey(responsive, width));
      }
    }
    expect(frameClass({ className: 'min-h-fit px-4', responsive: { desktop: 'max-w-7xl px-8' } }, 1366)).toBe('max-w-7xl px-8');
    expect(frameClass({ className: 'min-h-fit px-4', responsive: { desktop: 'max-w-7xl px-8' } }, 390)).toBe('min-h-fit px-4');
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  // @effects preview_matches_site_slot
  it('PC 는 viewport 1366 을 유지하고 안쪽에 데스크톱 클래스의 본문 상자를 두어 그 안에 슬라이더를 그린다', () => {
    const { iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);

    expect(iframe.width).toBe('1366');
    const boxes = [...doc.body.querySelectorAll('[data-hs-frame]')];
    expect(boxes.map((b) => b.className)).toEqual(['min-h-screen flex flex-col', 'flex-1', 'min-h-fit max-w-7xl mx-auto px-8']);
    expect(boxes[2].querySelector('.hs-slider')).not.toBeNull();
    expect(doc.body.querySelector(':scope > .hs-slider')).toBeNull();
    const links = [...doc.head.querySelectorAll('link[data-hs-template-css]')].map((l) => l.getAttribute('href'));
    expect(links).toEqual(['/assets/demo-user/css/components.css?v=3']);
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  // @effects preview_matches_site_slot
  it('모바일은 viewport 390 에 기본(모바일) 클래스를 쓴다', () => {
    const { iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="mobile" frame={frame} position="home_hero" />);

    expect(iframe.width).toBe('390');
    const boxes = [...doc.body.querySelectorAll('[data-hs-frame]')];
    expect(boxes[2].className).toBe('min-h-fit px-4');
    expect(boxes[2].querySelector('.hs-slider')).not.toBeNull();
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  // @effects preview_matches_site_slot
  it('틀(위치 설정 응답)이 미리보기보다 늦게 와도 템플릿 CSS 를 싣고 상자 사슬로 옮겨 그린다', () => {
    const { view, iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" position="home_hero" />);
    expect(doc.head.querySelectorAll('link[data-hs-template-css]').length).toBe(0);

    view.rerender(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);

    expect(iframe.contentDocument).toBe(doc);
    expect([...doc.head.querySelectorAll('link[data-hs-template-css]')].map((l) => l.getAttribute('href'))).toEqual(['/assets/demo-user/css/components.css?v=3']);
    expect(doc.body.querySelectorAll('[data-hs-frame]').length).toBe(3);
    expect(doc.body.querySelectorAll('.hs-slider').length).toBe(1);
  });

  // @scenario surface=preview_frame_missing_slot_falls_back
  // @effects preview_without_frame_uses_device_width
  it('틀이 없거나 그 위치에 확장점이 없으면 종전처럼 기기 폭 전체에 그린다(오버라이드·기존 호출 호환)', () => {
    for (const props of [{}, { frame, position: 'shop_hero' }, { frame: null, position: 'home_hero' }]) {
      const { doc, view } = mountFrame(<HeroBannerPreview banners={banners} device="pc" {...props} />);
      expect(doc.body.querySelectorAll('[data-hs-frame]').length).toBe(0);
      expect(doc.body.querySelector('.hs-slider')).not.toBeNull();
      expect(doc.head.querySelectorAll('link[data-hs-template-css]').length).toBe(0);
      view.unmount();
    }
  });

  // @scenario surface=admin_max_height_setting
  // @effects preview_follows_max_height_before_save
  it('틀이 없으면 미리보기 높이는 비율대로 재다가 그 기기 최대 높이(비웠으면 화면 전체 폭만 640px)에서 멈춘다', () => {
    const height = (device: 'pc' | 'mobile', settings?: Record<string, unknown>) => {
      const { iframe, view } = mountFrame(<HeroBannerPreview banners={banners} device={device} settings={settings as any} />);
      const value = Number(iframe.getAttribute('height'));
      view.unmount();
      return value;
    };
    // 비율대로 — PC 1366/3, 휴대폰 390/0.75
    expect(height('pc')).toBe(456);
    expect(height('mobile')).toBe(520);
    expect(height('pc', { max_height_pc: 300 })).toBe(300);
    expect(height('mobile', { max_height_pc: 300 })).toBe(520);
    expect(height('mobile', { max_height_mobile: 400 })).toBe(400);
    // 최대 높이가 비율 높이보다 크면 비율대로
    expect(height('mobile', { max_height_mobile: 1600 })).toBe(520);
    // 화면 전체 폭은 비웠을 때 640px — 이 두 기기 폭에서는 비율 높이가 더 낮다
    expect(height('mobile', { width: 'full' })).toBe(520);
  });

  // @scenario surface=preview_frame_content_box
  // @effects preview_follows_width_before_save
  it('본문 폭이면 구좌 틀의 본문 상자(기기 폭에 맞는 클래스)로 슬라이더를 감싼다', () => {
    const withContent = { ...frame, positions: { ...frame.positions, home_hero: { ...frame.positions.home_hero, content: { className: 'px-4', responsive: { desktop: 'max-w-7xl mx-auto px-8' } } } } };
    const pc = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={withContent} position="home_hero" />);
    expect(pc.doc.querySelector('[data-hs-width="content"]')?.className).toBe('max-w-7xl mx-auto px-8');
    cleanup();
    const mobile = mountFrame(<HeroBannerPreview banners={banners} device="mobile" frame={withContent} position="home_hero" />);
    expect(mobile.doc.querySelector('[data-hs-width="content"]')?.className).toBe('px-4');
  });

  // @scenario surface=preview_frame_content_box
  // @effects preview_follows_width_before_save
  it('화면 전체 폭이면 감싸지 않고 문구 층에 본문 상자를 쓴다', () => {
    const withContent = { ...frame, positions: { ...frame.positions, home_hero: { ...frame.positions.home_hero, content: { className: 'px-4', responsive: { desktop: 'max-w-7xl mx-auto px-8' } } } } };
    const { doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={withContent} position="home_hero" settings={{ width: 'full' }} />);
    expect(doc.querySelector('[data-hs-width]')).toBeNull();
    expect(doc.querySelector('section.hs-slider')?.className).toContain('hs-slider--full');
    expect(doc.querySelector('.hs-slider__box')?.className).toBe('hs-slider__box max-w-7xl mx-auto px-8');
  });

  // @scenario surface=preview_frame_styles_ready
  // @effects preview_hidden_until_styles_ready
  it('iframe 안 stylesheet(복사한 바깥 CSS·템플릿 CSS)가 모두 load·error 를 받기 전에는 미리보기를 가리고 표식을 달지 않는다', () => {
    const outer = document.createElement('link');
    outer.rel = 'stylesheet';
    outer.href = '/admin/components.css';
    document.head.appendChild(outer);
    try {
      const { view, iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);
      const host = view.container.querySelector('.hs-preview__viewport')!;
      const [copied, template] = [doc.head.querySelector('link:not([data-hs-template-css])')!, doc.head.querySelector('link[data-hs-template-css]')!];
      expect(copied.getAttribute('href')).toContain('/admin/components.css');
      expect(host.hasAttribute('data-hs-styles-ready')).toBe(false);
      expect(iframe.style.visibility).toBe('hidden');

      fireEvent.load(template);
      expect(host.hasAttribute('data-hs-styles-ready'), '복사한 CSS 가 아직이면 그대로 가린다').toBe(false);
      expect(iframe.style.visibility).toBe('hidden');

      // 실패한 링크도 기다림을 끝낸다 — 받지 못한 CSS 때문에 미리보기가 계속 가려지지 않게
      fireEvent.error(copied);
      expect(host.hasAttribute('data-hs-styles-ready')).toBe(true);
      expect(iframe.style.visibility).not.toBe('hidden');
    } finally {
      outer.remove();
    }
  });

  // @scenario surface=preview_frame_styles_ready
  // @effects preview_hidden_until_styles_ready
  it('틀이 늦게 와 템플릿 CSS 가 새로 붙으면 그 CSS 를 받을 때까지 다시 가리고 표식을 뗀다', () => {
    const { view, iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" position="home_hero" />);
    const host = () => view.container.querySelector('.hs-preview__viewport')!;
    expect(host().hasAttribute('data-hs-styles-ready'), '받을 CSS 가 없으면 바로 준비됨').toBe(true);

    view.rerender(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);
    expect(host().hasAttribute('data-hs-styles-ready')).toBe(false);
    expect(iframe.style.visibility).toBe('hidden');

    fireEvent.load(doc.head.querySelector('link[data-hs-template-css]')!);
    expect(host().hasAttribute('data-hs-styles-ready')).toBe(true);
    expect(iframe.style.visibility).not.toBe('hidden');
  });

  // @scenario surface=preview_frame_styles_ready
  // @effects preview_hidden_until_styles_ready
  it('CSS 가 끝내 응답하지 않아도 일정 시간 뒤에는 미리보기를 보인다 — 표식은 달지 않는다', () => {
    vi.useFakeTimers();
    try {
      const { view, iframe } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);
      expect(iframe.style.visibility).toBe('hidden');
      act(() => { vi.advanceTimersByTime(PREVIEW_STYLES_TIMEOUT_MS); });
      expect(iframe.style.visibility).not.toBe('hidden');
      expect(view.container.querySelector('.hs-preview__viewport')!.hasAttribute('data-hs-styles-ready')).toBe(false);
    } finally {
      vi.useRealTimers();
    }
  });

  // @scenario surface=preview_frame_styles_ready
  // @effects preview_hidden_until_styles_ready
  it('iframe 문서가 새로 만들어지면 새 문서의 stylesheet 를 새로 지켜보고, 옛 문서의 링크가 끝나도 새 문서가 끝날 때까지 가린다', () => {
    const outer = document.createElement('link');
    outer.rel = 'stylesheet';
    outer.href = '/admin/components.css';
    document.head.appendChild(outer);
    try {
      const { view, iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);
      const host = () => view.container.querySelector('.hs-preview__viewport')!;
      const oldLinks = [...doc.head.querySelectorAll('link')];
      oldLinks.forEach((link) => fireEvent.load(link));
      expect(host().hasAttribute('data-hs-styles-ready')).toBe(true);

      // 틀을 옮겨 붙이면 브라우저는 about:blank 문서를 새로 만들고 load 를 다시 보낸다

      act(() => { reattach(iframe); });
      const next = iframe.contentDocument!;
      expect(next, '새 문서').not.toBe(doc);
      act(() => { fireEvent.load(iframe); });

      const copied = next.head.querySelector('link:not([data-hs-template-css])');
      const template = next.head.querySelector('link[data-hs-template-css]');
      expect(copied?.getAttribute('href')).toContain('/admin/components.css');
      expect(template?.getAttribute('href')).toBe('/assets/demo-user/css/components.css?v=3');
      expect(next.body.querySelector('.hs-slider'), '새 문서에 다시 그린다').not.toBeNull();
      expect(host().hasAttribute('data-hs-styles-ready')).toBe(false);
      expect(iframe.style.visibility).toBe('hidden');

      // 옛 문서 링크가 늦게 끝나도 새 문서 판정을 바꾸지 않는다
      oldLinks.forEach((link) => fireEvent.error(link));
      expect(host().hasAttribute('data-hs-styles-ready')).toBe(false);

      fireEvent.load(copied!);
      expect(host().hasAttribute('data-hs-styles-ready')).toBe(false);
      fireEvent.load(template!);
      expect(host().hasAttribute('data-hs-styles-ready')).toBe(true);
      expect(iframe.style.visibility).not.toBe('hidden');
    } finally {
      outer.remove();
    }
  });

  // @scenario surface=preview_frame_styles_ready
  // @effects preview_hidden_until_styles_ready
  it('옛 문서에서 기다림 시간이 지났어도 새 문서는 다시 기다림 시간만큼 가린다', () => {
    vi.useFakeTimers();
    // 응답하지 않는 바깥 CSS — 새 문서에도 복사돼 새 문서도 처음부터 받지 못한 채다
    const outer = document.createElement('link');
    outer.rel = 'stylesheet';
    outer.href = '/admin/slow.css';
    document.head.appendChild(outer);
    try {
      const { view, iframe } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);
      act(() => { vi.advanceTimersByTime(PREVIEW_STYLES_TIMEOUT_MS); });
      expect(iframe.style.visibility).not.toBe('hidden');

      act(() => { reattach(iframe); });
      act(() => { fireEvent.load(iframe); });
      expect(iframe.style.visibility, '새 문서는 처음부터 다시 기다린다').toBe('hidden');

      act(() => { vi.advanceTimersByTime(PREVIEW_STYLES_TIMEOUT_MS); });
      expect(iframe.style.visibility).not.toBe('hidden');
      expect(view.container.querySelector('.hs-preview__viewport')!.hasAttribute('data-hs-styles-ready')).toBe(false);
    } finally {
      outer.remove();
      vi.useRealTimers();
    }
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  // @effects preview_matches_site_slot
  it('바깥 문서의 색상 테마 중립 표식을 미리보기 문서에도 옮긴다 — 확장 CSS 다크 규칙은 템플릿 .dark 대신 이 표식만 본다', () => {
    document.documentElement.setAttribute('data-g7-color-scheme', 'dark');
    try {
      const { doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);
      expect(doc.documentElement.getAttribute('data-g7-color-scheme')).toBe('dark');
    } finally {
      document.documentElement.removeAttribute('data-g7-color-scheme');
    }
  });
});

/**
 * 코어 자산 API 대역 — 코어 로더(networkResilience.loadStylesheetWithRetry)처럼 시도마다 바깥 문서에 새 `<link>` 를 붙이고,
 * 실패한 시도의 link 는 지운다. 시험이 시도의 성공·실패를 바깥에서 정한다.
 */
function installSwiperCore() {
  const url = '/api/plugins/assets/sirsoft-hero_section/dist/vendor/swiper/14.3.0/swiper-bundle.min.css';
  let current: HTMLLinkElement | null = null;
  let settle: { resolve: () => void; reject: (error: Error) => void } | null = null;
  const attach = () => {
    current = document.createElement('link');
    current.rel = 'stylesheet';
    current.href = url;
    current.id = 'hs-swiper-css';
    document.head.appendChild(current);
  };
  const loadStylesheet = vi.fn(() => {
    attach();
    return new Promise<void>((resolve, reject) => { settle = { resolve, reject }; });
  });
  const notifyFailure = vi.fn();
  (window as any).G7Core = {
    asset: { plugin: (id: string, p: string) => `/api/plugins/assets/${id}/${p}`, loadStylesheet },
    assets: { notifyFailure },
  };
  return {
    notifyFailure,
    /** 이번 시도가 실패해 link 를 지우고, 같은 약속 안에서 다음 시도의 link 를 붙인다 */
    retryAttempt: () => { current?.remove(); attach(); },
    succeed: () => settle?.resolve(),
    fail: () => { current?.remove(); current = null; settle?.reject(new Error('404')); },
  };
}

describe('미리보기 Swiper CSS 가 늦게 실리는 경로', () => {
  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
    document.head.querySelectorAll('#hs-swiper-css').forEach((link) => link.remove());
    vi.restoreAllMocks();
  });

  /** Swiper CSS 「한 번만 싣기」 모듈 상태를 시험마다 새로 시작한 미리보기 */
  const freshPreview = async () => {
    vi.resetModules();
    return (await import('../../components/composite/HeroBannerPreview')).HeroBannerPreview;
  };

  // @scenario surface=preview_frame_styles_ready
  // @effects preview_hidden_until_styles_ready
  it('바깥 첫 시도가 실패하고 다음 시도의 link 가 미리보기를 띄운 뒤에 실리면, 실패한 사본을 새 사본으로 갈고 그 사본을 받을 때까지 가린다', async () => {
    const core = installSwiperCore();
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const Preview = await freshPreview();
    const { view, iframe, doc } = mountFrame(<Preview banners={banners} device="pc" />);
    const host = () => view.container.querySelector('.hs-preview__viewport')!;

    const first = doc.getElementById('hs-swiper-css')!;
    expect(first, '첫 시도 link 를 사본으로 둔다').not.toBeNull();
    expect(iframe.style.visibility).toBe('hidden');
    fireEvent.error(first);
    expect(host().hasAttribute('data-hs-styles-ready'), '받지 못한 사본도 기다림을 끝낸다').toBe(true);

    core.retryAttempt();
    await act(async () => { core.succeed(); });

    const copies = doc.head.querySelectorAll('#hs-swiper-css');
    expect(copies).toHaveLength(1);
    expect(copies[0], '실패한 사본을 그대로 두지 않는다').not.toBe(first);
    expect(host().hasAttribute('data-hs-styles-ready')).toBe(false);
    expect(iframe.style.visibility).toBe('hidden');

    fireEvent.load(copies[0]);
    expect(host().hasAttribute('data-hs-styles-ready')).toBe(true);
    expect(iframe.style.visibility).not.toBe('hidden');
  });

  // @scenario surface=preview_frame_styles_ready
  // @effects preview_hidden_until_styles_ready
  it('끝내 못 실은 뒤 실패 알림의 다시 시도로 실리면 그때 미리보기에도 복사하고, 받을 때까지 가린다', async () => {
    const core = installSwiperCore();
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const Preview = await freshPreview();
    const { view, iframe, doc } = mountFrame(<Preview banners={banners} device="pc" />);
    const host = () => view.container.querySelector('.hs-preview__viewport')!;

    fireEvent.error(doc.getElementById('hs-swiper-css')!);
    await act(async () => { core.fail(); });
    expect(core.notifyFailure).toHaveBeenCalledTimes(1);
    expect(host().hasAttribute('data-hs-styles-ready')).toBe(true);

    act(() => { core.notifyFailure.mock.calls[0][0].retry(); });
    await act(async () => { core.succeed(); });

    const copy = doc.getElementById('hs-swiper-css');
    expect(copy, '다시 시도로 실린 CSS 를 미리보기에도 복사한다').not.toBeNull();
    expect(host().hasAttribute('data-hs-styles-ready')).toBe(false);
    expect(iframe.style.visibility).toBe('hidden');

    fireEvent.load(copy!);
    expect(host().hasAttribute('data-hs-styles-ready')).toBe(true);
  });

  // @scenario surface=preview_frame_styles_ready
  // @effects preview_hidden_until_styles_ready
  it('바깥 첫 시도가 그대로 성공하면 이미 둔 사본을 다시 만들지 않는다(다시 받느라 가리지 않는다)', async () => {
    const core = installSwiperCore();
    const Preview = await freshPreview();
    const { view, doc } = mountFrame(<Preview banners={banners} device="pc" />);
    const first = doc.getElementById('hs-swiper-css')!;
    fireEvent.load(first);
    await act(async () => { core.succeed(); });

    expect(doc.getElementById('hs-swiper-css')).toBe(first);
    expect(view.container.querySelector('.hs-preview__viewport')!.hasAttribute('data-hs-styles-ready')).toBe(true);
  });
});
