/**
 * @file HeroSwiperStyles.test.tsx
 * @description Swiper 라이브러리 CSS 를 확장 CSS 밖 동봉 자산으로 싣는 계약 — 코어 자산 API 로 한 번만 싣고(실린 `<link>` 는
 * 옮기지 않는다 — 옮기면 시트를 다시 적용하는 사이 막 생긴 Swiper 가 끌기를 놓친다), 실릴 때까지 슬라이더는 같은 자리의 스켈레톤을 두고, 끝내 못 실으면 실패 알림을 걸고 슬라이더를 그린다.
 * 미리보기(iframe)는 실린 CSS 와 다크 표식을 함께 옮긴다. 동봉 판은 package.json 의 swiper 와 같다.
 * (매니페스트 hero-slider.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, cleanup, act, fireEvent } from '@testing-library/react';
import type { HeroBanner } from '../../components/composite/HeroSlider';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

const ROOT = path.resolve(__dirname, '../../../..');

const banner = (id: number): HeroBanner => ({
  id,
  title: `제목 ${id}`,
  description: null,
  button_text: null,
  link_url: null,
  link_target: '_self',
  link_type: 'none',
  image_alt: `대체 ${id}`,
  images: { pc: { url: `/pc-${id}.jpg`, width: 1920, height: 640 }, mobile: null },
} as unknown as HeroBanner);

/** 코어 자산 API 대역 — 싣기 약속을 바깥에서 풀고 실패시킨다 */
function installCore() {
  let resolveLoad: () => void = () => {};
  let rejectLoad: (error: Error) => void = () => {};
  const loadStylesheet = vi.fn((url: string, attrs: Record<string, string>) => {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = url;
    link.id = attrs.id;
    document.head.appendChild(link);
    return new Promise<void>((resolve, reject) => {
      resolveLoad = resolve;
      // 코어 로더는 끝내 실패하면 그 link 를 지운다(networkResilience.loadStylesheetWithRetry)
      rejectLoad = (error) => {
        link.remove();
        reject(error);
      };
    });
  });
  const notifyFailure = vi.fn();
  (window as any).G7Core = {
    asset: { plugin: (id: string, p: string) => `/api/plugins/assets/${id}/${p}`, loadStylesheet },
    assets: { notifyFailure },
  };
  return { loadStylesheet, notifyFailure, resolve: () => resolveLoad(), reject: (e: Error) => rejectLoad(e) };
}

/** 모듈 상태(한 번만 싣기)를 시험마다 새로 시작한다 */
async function fresh() {
  vi.resetModules();
  const styles = await import('../../support/swiperStyles');
  const { HeroSlider } = await import('../../components/composite/HeroSlider');
  return { styles, HeroSlider };
}

describe('Swiper CSS 동봉 자산', () => {
  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
    document.head.innerHTML = '';
  });

  // @scenario surface=swiper_styles_vendor_asset
  it('코어 자산 API 가 없으면(시험·코어 밖) 싣지 않고 바로 슬라이더를 그린다', async () => {
    const { HeroSlider } = await fresh();
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} />);
    expect(container.querySelector('section.hs-slider--skeleton')).toBeNull();
    expect(container.querySelector('section.hs-slider[role="region"]')).not.toBeNull();
    expect(document.getElementById('hs-swiper-css')).toBeNull();
  });

  // @scenario surface=swiper_styles_vendor_asset
  it('동봉 Swiper CSS 를 한 번만 싣고, 실릴 때까지 같은 폭의 스켈레톤을 둔 뒤 실린 자리 그대로 슬라이더를 그린다', async () => {
    const core = installCore();
    const first = document.createElement('link');
    first.id = 'template-css';
    document.head.appendChild(first);
    const { HeroSlider } = await fresh();
    const { container } = render(<><HeroSlider banners={[banner(1), banner(2)]} settings={{ width: 'full' }} /><HeroSlider banners={[banner(3)]} /></>);

    expect(core.loadStylesheet).toHaveBeenCalledTimes(1);
    expect(core.loadStylesheet.mock.calls[0][0]).toBe('/api/plugins/assets/sirsoft-hero_section/dist/vendor/swiper/14.3.0/swiper-bundle.min.css');
    expect(core.loadStylesheet.mock.calls[0][1]).toEqual({ id: 'hs-swiper-css' });
    const skeletons = container.querySelectorAll('section.hs-slider--skeleton');
    expect(skeletons).toHaveLength(2);
    expect(skeletons[0].className).toContain('hs-slider--full');
    expect(container.querySelector('section[role="region"]')).toBeNull();

    await act(async () => {
      core.resolve();
    });
    // 실린 link 를 옮기지 않는다(코어가 붙인 head 끝 자리 그대로)
    expect(document.head.lastChild).toBe(document.getElementById('hs-swiper-css'));
    expect(container.querySelector('section.hs-slider--skeleton')).toBeNull();
    expect(container.querySelectorAll('section.hs-slider[role="region"]')).toHaveLength(2);
  });

  // @scenario surface=swiper_styles_vendor_asset
  it('데이터가 CSS 보다 먼저 와도 키보드 복귀를 감지한다 — 슬라이더를 누른 뒤 Tab 으로 돌아오면 자동 넘김이 멈춘다', async () => {
    const core = installCore();
    const { HeroSlider } = await fresh();
    // fresh() 가 모듈을 새로 불러와도 슬라이더가 쓴 대역은 swiper/react 쪽이다
    const { swiperState } = (await import('swiper/react')) as unknown as typeof import('../swiperMock');
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    expect(container.querySelector('section[role="region"]')).toBeNull();
    await act(async () => {
      core.resolve();
    });
    const region = container.querySelector('section[role="region"]') as HTMLElement;
    const autoplay = swiperState.instance!.autoplay;

    fireEvent.pointerDown(region, { pointerType: 'mouse' });
    fireEvent.focus(region);
    expect(autoplay.running).toBe(true);
    fireEvent.blur(region);
    fireEvent.keyDown(document, { key: 'Tab' });
    fireEvent.focus(region);
    expect(autoplay.running).toBe(false);
  });

  // @scenario surface=swiper_styles_vendor_asset
  it('끝내 못 실으면 실패 알림에 다시 시도를 걸고, 슬라이더는 전환 효과 없이라도 그린다', async () => {
    const core = installCore();
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const { HeroSlider } = await fresh();
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} />);

    await act(async () => {
      core.reject(new Error('404'));
    });
    expect(core.notifyFailure).toHaveBeenCalledTimes(1);
    expect(core.notifyFailure.mock.calls[0][0]).toMatchObject({ id: 'sirsoft-hero_section-swiper-css', label: '배너 슬라이더 모양' });
    expect(container.querySelector('section.hs-slider[role="region"]')).not.toBeNull();

    core.notifyFailure.mock.calls[0][0].retry();
    expect(core.loadStylesheet).toHaveBeenCalledTimes(2);
    warn.mockRestore();
  });

  // @scenario surface=swiper_styles_vendor_asset
  it('미리보기 iframe 에는 실린 Swiper CSS 를 한 번만 복사한다', async () => {
    const core = installCore();
    const { styles } = await fresh();
    void styles.loadSwiperStyles();
    await act(async () => {
      core.resolve();
    });
    const frame = document.createElement('iframe');
    document.body.appendChild(frame);
    const doc = frame.contentDocument!;
    doc.head.appendChild(doc.createElement('style'));

    styles.copySwiperStyles(doc);
    styles.copySwiperStyles(doc);
    const copies = doc.head.querySelectorAll('#hs-swiper-css');
    expect(copies).toHaveLength(1);
    expect((copies[0] as HTMLLinkElement).href).toContain('/dist/vendor/swiper/14.3.0/swiper-bundle.min.css');
    frame.remove();
  });

  // @scenario surface=swiper_styles_vendor_asset
  it('동봉 판은 package.json 의 swiper 와 같고, 그 판의 CSS·라이선스 파일이 dist/vendor 에 있다', async () => {
    const { styles } = await fresh();
    const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
    expect(pkg.dependencies.swiper).toBe(styles.SWIPER_VERSION);
    const dir = path.join(ROOT, 'dist/vendor/swiper', styles.SWIPER_VERSION);
    const css = fs.readFileSync(path.join(dir, 'swiper-bundle.min.css'), 'utf8');
    expect(css).toContain(`Swiper ${styles.SWIPER_VERSION}`);
    expect(fs.existsSync(path.join(dir, 'LICENSE'))).toBe(true);
  });

  // @scenario surface=swiper_styles_vendor_asset
  it('확장 CSS 와 컴포넌트는 Swiper 클래스를 걸지 않는다 — 화살표·점은 자기 클래스로 그린다', () => {
    const css = fs.readFileSync(path.join(ROOT, 'resources/css/main.css'), 'utf8');
    expect(css).not.toMatch(/\.swiper-[a-z]/);
    const slider = fs.readFileSync(path.join(ROOT, 'resources/js/components/composite/HeroSlider.tsx'), 'utf8');
    expect(slider).not.toMatch(/className=\{?[`'"][^`'"]*\bswiper-/);
    expect(slider).not.toContain("import 'swiper/css");
  });
});
