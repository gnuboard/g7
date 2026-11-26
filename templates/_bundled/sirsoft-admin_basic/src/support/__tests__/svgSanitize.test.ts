/**
 * @file svgSanitize.test.ts
 * @description 인라인 SVG 화이트리스트 정제 — 결제 브랜드 마크·관리자 메뉴 아이콘 공용
 *
 * 레이아웃·메뉴 저장 검증과 별개로 화면이 그리기 직전에 다시 거른다. 스크립트·이벤트 처리기·외부 참조
 * (foreignObject·use·href)·style 은 빠지고 글자색을 따르는 `currentColor` 도형은 남는지 잰다.
 *
 * @scenario-manifest tests/scenarios/icon-component-baseline.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { sanitizeSvgMarkup, SVG_SANITIZE_CONFIG } from '../svgSanitize';

/**
 * 정제 결과를 DOM 으로 읽는다.
 *
 * @param markup 정제된 마크업
 * @returns 감싼 div
 */
function parse(markup: string): HTMLDivElement {
  const host = document.createElement('div');
  host.innerHTML = markup;

  return host;
}

const PATH = '<path d="M12 2L2 22h20L12 2z" fill="currentColor"/>';

describe('sanitizeSvgMarkup', () => {
  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it('currentColor 도형은 남기고 svg 하나만 돌려준다', () => {
    const out = sanitizeSvgMarkup(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">${PATH}</svg>`);
    const host = parse(out);

    expect(host.querySelectorAll('svg')).toHaveLength(1);
    expect(host.querySelector('svg')?.getAttribute('viewBox')).toBe('0 0 24 24');
    expect(host.querySelector('path')?.getAttribute('fill')).toBe('currentColor');
    expect(host.querySelector('path')?.getAttribute('d')).toBe('M12 2L2 22h20L12 2z');
  });

  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it('script 요소를 지운다', () => {
    const out = sanitizeSvgMarkup(`<svg viewBox="0 0 24 24"><script>alert(1)</script>${PATH}</svg>`);

    expect(out).not.toMatch(/<script/i);
    expect(out).not.toContain('alert(1)');
    expect(parse(out).querySelector('path')).not.toBeNull();
  });

  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it('onload 같은 이벤트 처리기 속성을 지운다', () => {
    const out = sanitizeSvgMarkup(`<svg viewBox="0 0 24 24" onload="alert(1)"><path d="M0 0" onclick="alert(2)" fill="currentColor"/></svg>`);
    const host = parse(out);

    expect(out).not.toMatch(/onload|onclick|alert/i);
    expect(host.querySelector('svg')?.hasAttribute('onload')).toBe(false);
    expect(host.querySelector('path')?.hasAttribute('onclick')).toBe(false);
  });

  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it('foreignObject 를 지운다', () => {
    const out = sanitizeSvgMarkup(
      `<svg viewBox="0 0 24 24"><foreignObject width="10" height="10"><div>x</div><iframe src="//evil"></iframe></foreignObject>${PATH}</svg>`,
    );

    expect(out).not.toMatch(/foreignObject|iframe|evil/i);
    expect(parse(out).querySelector('path')).not.toBeNull();
  });

  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it('style 요소와 style 속성을 지운다', () => {
    const out = sanitizeSvgMarkup(
      `<svg viewBox="0 0 24 24"><style>path{fill:url(//evil)}</style><path d="M0 0" style="fill:red" fill="currentColor"/></svg>`,
    );

    expect(out).not.toMatch(/<style|style=|evil/i);
    expect(parse(out).querySelector('path')?.getAttribute('fill')).toBe('currentColor');
  });

  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it('use·image·a 요소와 href·xlink:href 속성을 지운다', () => {
    const out = sanitizeSvgMarkup(
      `<svg xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 24 24">`
        + `<use href="#a"/><use xlink:href="//evil/sprite.svg#b"/><image href="//evil/x.png"/>`
        + `<a href="javascript:alert(1)"><text x="0" y="10">x</text></a>`
        + `<path d="M0 0" href="//evil" fill="currentColor"/></svg>`,
    );
    const host = parse(out);

    expect(host.querySelector('use')).toBeNull();
    expect(host.querySelector('image')).toBeNull();
    expect(host.querySelector('a')).toBeNull();
    expect(out).not.toMatch(/href|javascript|evil/i);
    expect(host.querySelector('path')?.getAttribute('fill')).toBe('currentColor');
  });

  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it.each([[''], ['   '], [null], [undefined]])('빈 값(%j)은 빈 문자열을 돌려준다', (input) => {
    expect(sanitizeSvgMarkup(input as string | null | undefined)).toBe('');
  });

  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it('스크립트만 있는 입력은 빈 문자열이 된다', () => {
    expect(sanitizeSvgMarkup('<script>alert(1)</script>')).toBe('');
  });

  /**
   * @scenario component=svg_sanitize
   * @effects svg_markup_sanitized_by_whitelist
   */
  it('설정은 외부 참조·스크립트 벡터를 금지 목록에 둔다', () => {
    // DOMPurify 는 첫 정제 때 설정 배열의 이름을 소문자로 맞춘다(foreignObject → foreignobject) — 대소문자 무시로 비교한다
    const lower = (list: string[]) => list.map((name) => name.toLowerCase());

    expect(lower(SVG_SANITIZE_CONFIG.FORBID_TAGS)).toEqual(expect.arrayContaining(['script', 'foreignobject', 'image', 'use', 'a', 'style']));
    expect(lower(SVG_SANITIZE_CONFIG.FORBID_ATTR)).toEqual(expect.arrayContaining(['onload', 'href', 'xlink:href']));
    expect(lower(SVG_SANITIZE_CONFIG.ALLOWED_ATTR)).not.toContain('style');
  });
});
