/**
 * @file external_position_output.test.tsx
 * @description 다른 확장이 더한 위치의 자동 출력 — 히어로가 자리를 채울 때 붙이는 조각(생성 표본, PHPUnit 이 빌더 출력과 같은지 본다)을
 * 레이아웃 엔진으로 그리고, 그 안의 **실제 `HeroSlider`** 가 공개 API 응답으로 배너(제목·이미지·수정 단추)를 그리는지 본다.
 * 슬라이더 넘김 라이브러리(Swiper)만 시험용으로 바꾼다. (매니페스트 position-registry.yaml)
 *
 * @vitest-environment jsdom
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { HeroSlider } from '../../components/composite/HeroSlider';
import generatedStatic from '../fixtures/generated_acme_event_hero.json';
import generatedDynamic from '../fixtures/generated_acme_event_page_hero.json';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

/** 공개 API 가 내주는 배너 한 장 */
const banner = (id: number, title: string) => ({
  id,
  title,
  description: '',
  button_text: '',
  image_alt: title,
  title_visible: true,
  title_color: '#FFFFFF',
  title_align: 'left',
  description_visible: false,
  description_color: '#E5E7EB',
  description_align: 'left',
  link_url: '',
  link_new_window: false,
  images: { pc: { url: `/api/plugins/sirsoft-hero_section/images/pc-${id}`, width: 1920, height: 640 }, mobile: null },
});

/** 히어로가 켜져 있으면 실리는 첫 화면 설정 */
const heroOn = { plugins: { 'sirsoft-hero_section': { slot_widths: { acme_event_hero: 'content' }, slot_banners: { acme_event_hero: true } } } };

let cleanups: Array<() => void> = [];

/** 생성 조각을 실제 HeroSlider 와 함께 그린다. */
const renderOutput = async (fragment: any, sourceId: string, data: any, options: Record<string, any> = {}) => {
  const registry = createMockComponentRegistryWithBasics();
  registry.register('composite', 'sirsoft-hero_section.HeroSlider', HeroSlider as any);
  const test = createLayoutTest(fragment, { componentRegistry: registry as any, initialState: { _global: heroOn }, ...options });
  test.mockApi(sourceId, { response: { data } });
  const result = await test.render();
  cleanups.push(test.cleanup);

  return result.container as HTMLElement;
};

beforeEach(() => {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({ matches: false, media: query, addEventListener: vi.fn(), removeEventListener: vi.fn() })) as unknown as typeof window.matchMedia;
  (window as any).G7Core = { t: (key: string) => key };
});

afterEach(() => {
  cleanups.forEach((c) => c());
  cleanups = [];
});

describe('다른 확장이 더한 위치의 자동 출력 — 실제 HeroSlider', () => {
  // @scenario case=external_output_renders
  it('정적 위치: 그 위치의 배너 제목·이미지를 그리고, 수정 권한이 있으면 「관리자에서 수정」 단추를 단다', async () => {
    const container = await renderOutput(generatedStatic, 'heroSection_acme_event_hero', {
      position: 'acme_event_hero', target: null, banners: [banner(21, '이벤트 메인 배너')], settings: { autoplay: false, interval_seconds: 5, effect: 'slide', width: 'content' }, abilities: { can_update: true },
    });

    const slider = container.querySelector('section.hs-slider[data-position="acme_event_hero"]');
    expect(slider).not.toBeNull();
    expect(slider!.textContent).toContain('이벤트 메인 배너');
    expect(slider!.querySelector('img')?.getAttribute('src')).toContain('/api/plugins/sirsoft-hero_section/images/pc-21');
    expect(container.querySelector('a[href*="/admin/plugins/sirsoft-hero_section/banners/21/edit"]')).not.toBeNull();
  });

  // @scenario case=external_output_renders
  it('배너가 0건이면 아무것도 그리지 않는다', async () => {
    const container = await renderOutput(generatedStatic, 'heroSection_acme_event_hero', { position: 'acme_event_hero', target: null, banners: [], settings: {} });

    expect(container.querySelector('section.hs-slider')).toBeNull();
  });

  // @scenario case=external_output_renders
  it('동적 위치: 등록한 경로 매개변수(route.code) 값의 응답이면 그 대상 배너를 그리고, 다른 대상 응답이면 그리지 않는다', async () => {
    const page = await renderOutput(generatedDynamic, 'heroSection_acme_event_page_hero', {
      position: 'acme_event_page_hero', target: 'summer', banners: [banner(22, '여름 이벤트 배너')], settings: {},
    }, { routeParams: { code: 'summer' } });
    expect(page.querySelector('section.hs-slider[data-position="acme_event_page_hero"]')?.textContent).toContain('여름 이벤트 배너');

    const stale = await renderOutput(generatedDynamic, 'heroSection_acme_event_page_hero', {
      position: 'acme_event_page_hero', target: 'spring', banners: [banner(23, '봄 이벤트 배너')], settings: {},
    }, { routeParams: { code: 'summer' } });
    expect(stale.textContent).not.toContain('봄 이벤트 배너');
  });
});
