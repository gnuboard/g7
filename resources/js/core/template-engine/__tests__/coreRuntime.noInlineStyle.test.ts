/**
 * 코어가 직접 그리는 화면(오류 화면·레이아웃 로더 오류 UI·시스템 배너)에 인라인 스타일이 없다 (#163, engine-v1.91.0)
 *
 * 버그: HTML 문자열에 `style="…"` 을 넣어 그렸다. 엄격한 스타일 정책(`style-src-attr 'none'`)에서는 적용되지 않아
 *   오류 화면이 무스타일(h1 16px/400, 버튼 배경 투명)로 그려졌다.
 * 수정: 모양은 셸이 항상 싣는 코어 정적 CSS(`g7-core.css`)의 클래스로 주고, 배너마다 다른 색만 CSSOM 대입으로 준다.
 *
 * [case:network-strict-style-csp-inline-style-blocked]
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ErrorDisplay } from '../ErrorDisplay';
import { LayoutLoader, LayoutLoaderError } from '../LayoutLoader';
import { SystemBannerManager } from '../SystemBannerManager';

const CORE_CSS = readFileSync(resolve(__dirname, '../../../../../public/assets/core/css/g7-core.css'), 'utf8');

/**
 * 마크업에서 쓰인 `g7-` 클래스를 모읍니다.
 *
 * @param html 마크업
 * @returns 클래스 목록
 */
function g7Classes(html: string): string[] {
  const found = new Set<string>();
  for (const match of html.matchAll(/class="([^"]*)"/g)) {
    for (const name of match[1].split(/\s+/)) {
      if (name.startsWith('g7-')) found.add(name);
    }
  }

  return [...found];
}

/**
 * 인라인 스타일이 없고, 쓰인 g7- 클래스가 모두 코어 CSS 에 규칙으로 있는지 검사합니다.
 *
 * @param html 마크업
 * @returns 반환값 없음
 */
function expectStyledByCoreCss(html: string): void {
  expect(html).not.toMatch(/<style\b/i);
  expect(html).not.toMatch(/<[a-z][^>]*\sstyle\s*=/i);
  const classes = g7Classes(html);
  expect(classes.length).toBeGreaterThan(0);
  for (const name of classes) {
    expect(CORE_CSS, `g7-core.css 에 .${name} 규칙이 없다`).toContain(`.${name}{`);
  }
}

describe('ErrorDisplay', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
  });

  /**
   * @scenario case=error_screen_no_inline_style
   * @effects error_screen_styled_by_core_css_classes
   */
  it('오류 화면 전체(상세·스택·새로고침 포함)에 style 속성이 없고 클래스 규칙이 코어 CSS 에 있다', () => {
    ErrorDisplay.render('app', {
      title: '페이지 로딩 실패', message: '메시지', detailMessage: '상세', icon: 'fa-triangle-exclamation',
      showStack: true, stackTrace: 'Error: x\n at y', showReloadButton: true, debug: true,
    });
    const html = document.getElementById('app')!.innerHTML;

    expectStyledByCoreCss(html);
    for (const name of ['g7-error', 'g7-error__card', 'g7-error__icon-wrap', 'g7-error__icon', 'g7-error__title', 'g7-error__message', 'g7-error__detail', 'g7-error__stack', 'g7-error__stack-title', 'g7-error__stack-trace', 'g7-error__actions', 'g7-error__button', 'g7-error__button-icon']) {
      expect(document.querySelector(`.${name}`), name).not.toBeNull();
    }
    expect(document.querySelector('h1.g7-error__title')?.textContent?.trim()).toBe('페이지 로딩 실패');
    expect(document.querySelector('i.fa-solid.fa-triangle-exclamation.g7-error__icon')).not.toBeNull();
    expect(document.querySelector('button[data-g7-error-reload="1"].g7-error__button')).not.toBeNull();
  });

  /**
   * @scenario case=error_screen_no_inline_style
   * @effects error_screen_styled_by_core_css_classes
   */
  it('상세 메시지가 없으면 간격 요소를 둔다', () => {
    ErrorDisplay.render('app', { title: 't', message: 'm', icon: 'fa-x', showStack: false, showReloadButton: false, debug: false });

    expect(document.querySelector('.g7-error__spacer')).not.toBeNull();
    expect(document.querySelector('.g7-error__detail')).toBeNull();
    expectStyledByCoreCss(document.getElementById('app')!.innerHTML);
  });

  /**
   * @scenario case=error_screen_no_inline_style
   * @effects error_screen_dark_mode_by_media_query
   */
  it('다크 모드는 스크립트 판정이 아니라 CSS 미디어 쿼리가 맡는다', () => {
    const matchMedia = vi.fn();
    vi.stubGlobal('matchMedia', matchMedia);
    ErrorDisplay.render('app', { title: 't', message: 'm', icon: 'fa-x', showStack: false, showReloadButton: false, debug: false });
    vi.unstubAllGlobals();

    expect(matchMedia).not.toHaveBeenCalled();
    expect(CORE_CSS).toMatch(/@media \(prefers-color-scheme: dark\)\{\s*\.g7-error\{/);
  });
});

describe('LayoutLoader 오류 UI', () => {
  const loader: any = new LayoutLoader({} as any);

  /**
   * @scenario case=error_screen_no_inline_style
   * @effects layout_loader_error_ui_styled_by_core_css_classes
   */
  it('자리표시·컴포넌트 오류·레이아웃 오류 UI 에 style 속성이 없고 기존 계약(클래스·data 속성)을 지킨다', () => {
    const placeholder: HTMLElement = loader.createPlaceholderElement('Missing');
    expect(placeholder.className).toBe('component-placeholder');
    expect(placeholder.getAttribute('data-component-type')).toBe('Missing');
    expectStyledByCoreCss(placeholder.outerHTML);

    const componentError: HTMLElement = loader.createErrorElement('Broken', new Error('boom'));
    expect(componentError.className).toBe('component-error');
    expect(componentError.getAttribute('data-component-type')).toBe('Broken');
    expect(componentError.textContent).toContain('boom');
    expectStyledByCoreCss(componentError.outerHTML);

    const container = document.createElement('div');
    loader.renderErrorState(container, new LayoutLoaderError('레이아웃 없음', 'LAYOUT_NOT_FOUND'));
    const box = container.querySelector('.layout-error') as HTMLElement;
    expect(box.getAttribute('data-error-code')).toBe('LAYOUT_NOT_FOUND');
    expect(container.querySelector('button[data-g7-error-reload="1"]')).not.toBeNull();
    expectStyledByCoreCss(container.innerHTML);
  });
});

describe('SystemBannerManager', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    SystemBannerManager.hideAll();
  });

  afterEach(() => SystemBannerManager.hideAll());

  /**
   * @scenario case=error_screen_no_inline_style
   * @effects system_banner_colors_via_cssom
   */
  it('배너 항목은 클래스 + CSSOM 대입으로 그리고, 메시지 HTML·data-banner-id·순서 계약을 지킨다', () => {
    SystemBannerManager.show({ id: 'second', message: '<b>둘</b>', background: 'linear-gradient(90deg, rgb(1, 2, 3), rgb(4, 5, 6))', color: 'rgb(7, 8, 9)', order: 2 });
    SystemBannerManager.show({ id: 'first', message: { ko: '하나', en: 'one' }, order: 1 });

    const container = document.getElementById('g7-system-banners') as HTMLElement;
    expect(container.style.position).toBe('fixed');
    const items = Array.from(container.children) as HTMLElement[];
    expect(items.map((item) => item.getAttribute('data-banner-id'))).toEqual(['first', 'second']);
    expect(items.every((item) => item.className === 'g7-system-banner')).toBe(true);
    expect(CORE_CSS).toContain('.g7-system-banner{');

    // 기본 색(옵션 미지정)과 옵션 색이 요소 style 객체에 대입된다
    expect(items[0].style.color).toBe('white');
    expect(items[0].style.background).not.toBe('');
    expect(items[1].style.color).toBe('rgb(7, 8, 9)');
    expect(items[1].style.background).toContain('linear-gradient');
    expect(items[1].querySelector('b')?.textContent).toBe('둘');

    SystemBannerManager.hide('first');
    expect(Array.from(container.children).map((item) => item.getAttribute('data-banner-id'))).toEqual(['second']);
    SystemBannerManager.hide('second');
    expect(document.getElementById('g7-system-banners')).toBeNull();
  });
});
