/**
 * @file TemplateApp.overlayNoInlineStyle.test.ts
 * @description 전환 덮개가 `<style>` 요소·style 속성 문자열 없이 그려진다 (공개 #163, engine-v1.91.0)
 *
 * 버그: 엄격한 스타일 정책(`style-src 'self'; style-src-attr 'none'`)에서 덮개 3종(전환·스켈레톤·스피너)의
 *   `<style>` 주입과 기본 스피너의 innerHTML style 속성이 막혀, 화면 전환 중 덮개와 스피너가 보이지 않았다.
 * 수정: 규칙은 `RuntimeStyleSheet`(생성형 시트)로 붙이고, 기본 스피너는 요소를 만들어 코어 CSS 클래스를 단다.
 *
 * 기존 `transitionOverlay.test.ts` 는 알고리즘을 시험 안에 복제해 단언하므로 구현이 바뀌어도 통과한다.
 * 이 시험은 **실제 TemplateApp 메서드**를 부른다.
 *
 * [case:network-strict-style-csp-inline-style-blocked]
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { adoptedCssTexts, installAdoptedStub } from '../style/__tests__/adoptedStub';

const { sharedActionDispatcher, mockState } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
  mockState: {} as Record<string, any>,
}));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  releaseTemplateRoot: vi.fn(),
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn(() => mockState),
  updateTemplateData: vi.fn(),
}));

vi.mock('../routing/Router', () => ({
  Router: vi.fn(function (this: any) {
    this.loadRoutes = vi.fn().mockResolvedValue(undefined);
    this.on = vi.fn();
    this.navigateToCurrentPath = vi.fn();
    this.getRoutes = vi.fn().mockReturnValue([]);
  }),
}));

vi.mock('../template-engine/LayoutLoader', () => ({
  LayoutLoader: vi.fn(function (this: any) {
    this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
  }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance: any = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';

describe('TemplateApp 전환 덮개 — 인라인 스타일 없음', () => {
  let app: any;
  let restore: () => void;

  beforeEach(() => {
    restore = installAdoptedStub();
    document.head.innerHTML = '';
    document.body.innerHTML = '<div id="app"><div id="main_content_area"><p>이전 화면</p></div></div>';
    (window as any).G7Core = { t: () => '' };
    app = new TemplateApp({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
  });

  afterEach(() => {
    app.hideTransitionOverlay();
    restore();
    delete (window as any).G7Core;
  });

  /**
   * @scenario case=overlay_no_style_element
   * @effects overlay_rules_without_style_element, overlay_marker_survives_in_head
   */
  it.each(['opaque', 'blur', 'fade'])('target 지정 %s 덮개: <style> 0, 규칙은 생성형 시트, <head> 표식 존재 → 걷으면 없음', (style) => {
    app.showTransitionOverlay({ enabled: true, style, target: 'main_content_area' });

    expect(document.querySelectorAll('style').length).toBe(0);
    const css = adoptedCssTexts().join('');
    expect(css).toContain('#main_content_area::after{');
    expect(css).toContain('z-index:2147483647');
    if (style === 'blur') expect(css).toContain('backdrop-filter:blur(4px)');
    expect(document.head.querySelector('#g7-transition-overlay')?.tagName).toBe('META');

    app.hideTransitionOverlay();
    expect(document.getElementById('g7-transition-overlay')).toBeNull();
    expect(adoptedCssTexts()).toEqual([]);
    expect(app.transitionOverlayEl).toBeNull();
  });

  /**
   * @scenario case=overlay_no_style_element
   * @effects overlay_fullpage_div_via_cssom
   */
  it('target 미지정 덮개는 종전대로 body 의 <div>(CSSOM 대입)다', () => {
    app.showTransitionOverlay(true);

    const overlay = document.getElementById('g7-transition-overlay') as HTMLElement;
    expect(overlay.tagName).toBe('DIV');
    expect(overlay.style.position).toBe('fixed');
    expect(document.querySelectorAll('style').length).toBe(0);

    app.hideTransitionOverlay();
    expect(document.getElementById('g7-transition-overlay')).toBeNull();
  });

  /**
   * @scenario case=overlay_no_style_element
   * @effects spinner_overlay_without_inline_style
   */
  it('스피너 덮개: 대상에 position:relative 규칙만, keyframes 는 넣지 않고, 기본 스피너에 style 속성이 없다', () => {
    app.showTransitionOverlay({ enabled: true, style: 'spinner', target: 'main_content_area' });

    expect(document.querySelectorAll('style').length).toBe(0);
    expect(adoptedCssTexts()).toEqual(['#main_content_area{position:relative;}']);
    expect(document.head.querySelector('#g7-skeleton-overlay-style')?.tagName).toBe('META');

    const container = document.getElementById('g7-skeleton-overlay') as HTMLElement;
    expect(container.parentElement?.id).toBe('main_content_area');
    expect(container.innerHTML).not.toMatch(/\sstyle=/);
    expect(container.querySelector('.g7-spinner-overlay > .g7-spinner')).not.toBeNull();

    app.hideTransitionOverlay();
    expect(document.getElementById('g7-skeleton-overlay-style')).toBeNull();
    expect(document.getElementById('g7-skeleton-overlay')).toBeNull();
    expect(adoptedCssTexts()).toEqual([]);
  });

  /**
   * @scenario case=overlay_no_style_element
   * @effects skeleton_overlay_without_style_element
   */
  it('스켈레톤 덮개: <style> 0, 대상 ::after 규칙, 표식 존재 → 걷으면 없음', async () => {
    const { ComponentRegistry } = await import('../template-engine/ComponentRegistry');
    (ComponentRegistry.getInstance() as any).getComponent.mockReturnValueOnce(() => null);

    app.showTransitionOverlay(
      { enabled: true, style: 'skeleton', target: 'main_content_area', skeleton: { component: 'PageSkeleton' } },
      { components: [] }
    );

    expect(document.querySelectorAll('style').length).toBe(0);
    expect(adoptedCssTexts().join('')).toContain('#main_content_area::after{');
    expect(document.head.querySelector('#g7-skeleton-overlay-style')?.tagName).toBe('META');
    expect(document.getElementById('g7-skeleton-overlay')).not.toBeNull();

    app.hideTransitionOverlay();
    expect(document.getElementById('g7-skeleton-overlay-style')).toBeNull();
    expect(adoptedCssTexts()).toEqual([]);
  });

  /**
   * @scenario case=overlay_no_style_element
   * @effects overlay_rules_do_not_accumulate
   */
  it('덮개를 연달아 띄워도 시트·표식이 쌓이지 않는다', () => {
    app.showTransitionOverlay({ enabled: true, style: 'opaque', target: 'main_content_area' });
    app.showTransitionOverlay({ enabled: true, style: 'fade', target: 'main_content_area' });
    app.showTransitionOverlay({ enabled: true, style: 'spinner', target: 'main_content_area' });

    expect(adoptedCssTexts().length).toBe(1);
    expect(document.head.querySelectorAll('[data-g7-runtime-style]').length).toBe(1);
  });
});
