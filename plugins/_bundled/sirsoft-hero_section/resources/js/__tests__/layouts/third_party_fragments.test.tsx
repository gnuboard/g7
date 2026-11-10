/**
 * @file third_party_fragments.test.tsx
 * @description 다른 확장이 더한 위치의 사용자 화면 조각 — 위치 등록 필터로 위치를 더한 확장은 자기 화면 확장점에 히어로 슬라이더를 붙이는
 * 조각을 직접 둔다(히어로는 자기가 아는 자리에만 조각을 붙인다). 문서 예시와 같은 모양의 시험용 조각(정적 event_hero·동적 event_page_hero)이
 * 히어로 자기 조각과 같은 계약(공개 API·응답 읽기·슬라이더 props)을 지키는지, 엔진이 다른 확장의 조각 안 히어로 컴포넌트를 실제로 그리는지,
 * 히어로가 꺼져 있으면 아무것도 그리지 않는지 본다. (매니페스트 position-registry.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import home from '../../../extensions/home_hero.json';
import board from '../../../extensions/board_hero.json';
import staticFragment from '../fixtures/third_party_event_hero.json';
import dynamicFragment from '../fixtures/third_party_event_page_hero.json';
import generatedStatic from '../fixtures/generated_acme_event_hero.json';
import generatedDynamic from '../fixtures/generated_acme_event_page_hero.json';

/** 슬라이더 자리 표시 — 엔진이 넘긴 props 를 글자로 보인다(슬라이더 자체 동작은 HeroSlider 시험이 본다). */
const SliderProbe: React.FC<any> = ({ position, target, banners, loading }) => (
  <div data-testid="hero-slider" data-position={position} data-target={target ?? ''} data-loading={String(loading)}>
    {(banners ?? []).map((b: any) => <span key={b.id}>{b.title}</span>)}
  </div>
);

/** 히어로가 켜져 있으면 실리는 첫 화면 설정(frontend_schema) */
const heroOn = { plugins: { 'sirsoft-hero_section': { slot_widths: { event_hero: 'content' }, slot_banners: { event_hero: true } } } };

/** 조각을 레이아웃처럼 그린다. */
const renderFragment = async (fragment: any, sourceId: string, response: any, options: Record<string, any> = {}) => {
  const registry = createMockComponentRegistryWithBasics();
  registry.register('composite', 'sirsoft-hero_section.HeroSlider', SliderProbe);
  const test = createLayoutTest(fragment, { componentRegistry: registry as any, ...options });
  test.mockApi(sourceId, { response });
  const result = await test.render();
  return { ...result, test };
};

/** 조각의 data-source·슬라이더 노드 */
const partsOf = (fragment: any) => ({ source: fragment.data_sources[0], slider: fragment.components[0] });

let cleanups: Array<() => void> = [];
afterEach(() => {
  cleanups.forEach((c) => c());
  cleanups = [];
});

describe('다른 확장이 더한 위치의 조각', () => {
  // @scenario case=third_party_fragment_contract
  it('시험용 조각은 히어로 자기 조각과 같은 공개 API·응답 읽기·슬라이더 props 를 쓴다 — 히어로 조각 계약이 바뀌면 문서 예시도 고쳐야 한다', () => {
    for (const [mine, theirs, key] of [[home, staticFragment, 'event_hero'], [board, dynamicFragment, 'event_page_hero']] as const) {
      const own = partsOf(mine);
      const other = partsOf(theirs);
      expect(other.slider.name).toBe(own.slider.name);
      expect(other.slider.type).toBe('composite');
      for (const field of ['endpoint', 'method', 'auth_mode', 'loading_strategy', 'auto_fetch'] as const) {
        expect(other.source[field], field).toBe(own.source[field]);
      }
      expect(other.source.fallback).toEqual(own.source.fallback);
      expect(Object.keys(other.source.params).sort()).toEqual(Object.keys(own.source.params).sort());
      expect(other.source.params.position).toBe(key);
      expect(other.slider.props.position).toBe(key);
      // 슬라이더에 넘기는 props 이름이 히어로 조각과 같다 — 받는 값은 자기 data-source 이름·자기 위치 키로 바꿔 읽는다
      expect(Object.keys(other.slider.props).sort()).toEqual(Object.keys(own.slider.props).sort());
      const ownId = own.source.id;
      const otherId = other.source.id;
      expect(otherId.startsWith('heroSection')).toBe(false);
      for (const [prop, binding] of Object.entries<string>(own.slider.props)) {
        const expected = String(binding).split(ownId).join(otherId).split(own.slider.props.position).join(key);
        expect(other.slider.props[prop], prop).toBe(expected);
      }
      // 데스크톱 본문 상자 클래스도 같은 방식으로 넘긴다(설명 주석은 빼고 비교)
      expect(other.slider.responsive.desktop.props).toEqual(own.slider.responsive.desktop.props);
      // 히어로가 꺼지면 컴포넌트가 없다 — 켜져 있을 때만 그린다(엔진 오류 로그를 남기지 않게)
      expect(other.slider.if).toBe("{{_global.plugins?.['sirsoft-hero_section'] != null}}");
    }
  });

  // @scenario case=third_party_fragment_renders
  it('엔진은 다른 확장의 조각 안 히어로 슬라이더를 그리고, 그 위치 배너를 넘긴다 — 동적 위치는 지금 주소 값의 응답일 때만', async () => {
    const a = await renderFragment(staticFragment, 'acmeEventHero', { data: { position: 'event_hero', target: null, banners: [{ id: 1, title: '이벤트 배너' }], settings: {} } }, { initialState: { _global: heroOn } });
    cleanups.push(a.test.cleanup);
    const slider = a.container.querySelector('[data-testid="hero-slider"]')!;
    expect(slider).not.toBeNull();
    expect(slider.getAttribute('data-position')).toBe('event_hero');
    expect(slider.textContent).toContain('이벤트 배너');

    const b = await renderFragment(dynamicFragment, 'acmeEventPageHero', { data: { position: 'event_page_hero', target: 'summer', banners: [{ id: 2, title: '여름 이벤트' }], settings: {} } }, { initialState: { _global: heroOn }, routeParams: { slug: 'summer' } });
    cleanups.push(b.test.cleanup);
    const page = b.container.querySelector('[data-testid="hero-slider"]')!;
    expect(page.getAttribute('data-target')).toBe('summer');
    expect(page.textContent).toContain('여름 이벤트');

    // 앞 이벤트 응답이 남아 있으면(주소 값과 다름) 배너를 넘기지 않고 받는 중으로 둔다
    const c = await renderFragment(dynamicFragment, 'acmeEventPageHero', { data: { position: 'event_page_hero', target: 'spring', banners: [{ id: 3, title: '봄 이벤트' }], settings: {} } }, { initialState: { _global: heroOn }, routeParams: { slug: 'summer' } });
    cleanups.push(c.test.cleanup);
    const stale = c.container.querySelector('[data-testid="hero-slider"]')!;
    expect(stale.textContent).not.toContain('봄 이벤트');
    expect(stale.getAttribute('data-loading')).toBe('true');
  });

  // @scenario case=external_output_renders
  it('히어로가 자리를 채운 조각(생성 표본 — PHPUnit 이 빌더 출력과 같은지 본다)도 엔진이 그 위치 배너를 그린다 — 동적은 등록한 경로 매개변수(route.code) 값의 응답일 때만', async () => {
    const a = await renderFragment(generatedStatic, 'heroSection_acme_event_hero', { data: { position: 'acme_event_hero', target: null, banners: [{ id: 11, title: '자동 이벤트 배너' }], settings: {} } }, { initialState: { _global: heroOn } });
    cleanups.push(a.test.cleanup);
    const slider = a.container.querySelector('[data-testid="hero-slider"]')!;
    expect(slider.getAttribute('data-position')).toBe('acme_event_hero');
    expect(slider.textContent).toContain('자동 이벤트 배너');

    const b = await renderFragment(generatedDynamic, 'heroSection_acme_event_page_hero', { data: { position: 'acme_event_page_hero', target: 'summer', banners: [{ id: 12, title: '여름 페이지 배너' }], settings: {} } }, { initialState: { _global: heroOn }, routeParams: { code: 'summer' } });
    cleanups.push(b.test.cleanup);
    const page = b.container.querySelector('[data-testid="hero-slider"]')!;
    expect(page.getAttribute('data-target')).toBe('summer');
    expect(page.textContent).toContain('여름 페이지 배너');

    const c = await renderFragment(generatedDynamic, 'heroSection_acme_event_page_hero', { data: { position: 'acme_event_page_hero', target: 'spring', banners: [{ id: 13, title: '봄 페이지 배너' }], settings: {} } }, { initialState: { _global: heroOn }, routeParams: { code: 'summer' } });
    cleanups.push(c.test.cleanup);
    const stale = c.container.querySelector('[data-testid="hero-slider"]')!;
    expect(stale.textContent).not.toContain('봄 페이지 배너');
    expect(stale.getAttribute('data-loading')).toBe('true');
  });

  // @scenario case=third_party_fragment_renders
  it('히어로가 꺼져 있으면(첫 화면 설정에 히어로가 없음) 다른 확장의 조각은 아무것도 그리지 않는다', async () => {
    const off = await renderFragment(staticFragment, 'acmeEventHero', { data: { banners: [{ id: 1, title: '이벤트 배너' }] } }, { initialState: { _global: { plugins: {} } } });
    cleanups.push(off.test.cleanup);
    expect(off.container.querySelector('[data-testid="hero-slider"]')).toBeNull();
  });
});
