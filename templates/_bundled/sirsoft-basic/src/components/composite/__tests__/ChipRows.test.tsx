/**
 * ChipRows 컴포넌트 테스트
 *
 * @description 분류 칩 줄 컨테이너가 칩 폭을 재서 한 줄·두 줄을 정하고, 넘칠 때 줄 폭과 줄이기 값을
 *   CSS 변수로만 거는지(인라인 스타일 속성 없이) 잠근다.
 *
 * @scenario-manifest tests/scenarios/shop-category-chip-rows.yaml
 */

import React from 'react';
import { render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChipRows } from '../ChipRows';

/** 칩 폭과 보이는 폭을 정해 준다 (jsdom 은 배치를 계산하지 않는다) */
function mockLayout(containerWidth: number, chipWidth: number) {
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockImplementation(function (this: HTMLElement) {
    return this.hasAttribute('data-chip-rows-scroll') ? containerWidth : 0;
  });
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
    const width = this.parentElement?.hasAttribute('data-chip-rows-inner') ? chipWidth : 0;
    return { width, height: 32, top: 0, left: 0, right: width, bottom: 32, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
  });
}

const chips = (count: number) =>
  Array.from({ length: count }, (_, i) => (
    <a key={i} href={`/shop/category/c${i}`}>
      분류 {i}
    </a>
  ));

describe('ChipRows', () => {
  let originalG7Core: any;

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = { ...originalG7Core, t: (k: string) => k };
  });

  afterEach(() => {
    vi.restoreAllMocks();
    (window as any).G7Core = originalG7Core;
  });

  const parts = (container: HTMLElement) => ({
    root: container.querySelector('nav') as HTMLElement,
    scroll: container.querySelector('[data-chip-rows-scroll]') as HTMLElement,
    inner: container.querySelector('[data-chip-rows-inner]') as HTMLElement,
    fade: container.querySelector('[data-chip-rows-fade]') as HTMLElement | null,
  });

  // @scenario case=c08
  // @effects chip_rows_single_row_has_no_variables
  it('다 들어가면 한 줄(줄바꿈 없음)이고 폭·줄이기 변수를 걸지 않는다', () => {
    mockLayout(600, 80);
    const { container } = render(<ChipRows ariaLabel="상품 분류">{chips(4)}</ChipRows>);
    const { root, inner, fade } = parts(container);

    expect(root.getAttribute('aria-label')).toBe('상품 분류');
    expect(inner.className).toContain('flex-nowrap');
    expect(root.style.getPropertyValue('--g7-chip-rows-w')).toBe('');
    expect(root.style.getPropertyValue('--g7-chip-px')).toBe('');
    expect(fade).toBeNull();
    // 자식(분류 링크)은 그대로다
    expect(inner.querySelectorAll('a')).toHaveLength(4);
  });

  // @scenario case=c09
  // @effects chip_rows_two_rows_width_is_longer_half
  it('넘치면 두 줄 — 안쪽 폭을 더 긴 절반에 맞추고 줄바꿈으로 나눈다', () => {
    // 칩 6개 × 100 → 한 줄 640 > 400, 절반 316
    mockLayout(400, 100);
    const { container } = render(<ChipRows>{chips(6)}</ChipRows>);
    const { root, inner, scroll } = parts(container);

    expect(inner.className).toContain('flex-wrap');
    expect(inner.className).toContain('w-[var(--g7-chip-rows-w)]');
    // 소수점 올림 여유 1px 를 더한다(세 줄로 넘어가지 않게)
    expect(root.style.getPropertyValue('--g7-chip-rows-w')).toBe('317px');
    expect(root.style.getPropertyValue('--g7-chip-px')).toBe('');
    expect(scroll.className).toContain('overflow-x-auto');
  });

  // @scenario case=c10
  // @effects chip_rows_shrink_variables_applied
  it('두 줄로도 넘치면 줄이기 값을 변수로 걸고 끝 흐림을 보인다', () => {
    // 줄이지 않으면 셋째 칩이 28% 만 걸친다 → 반쯤 보이는 단계까지 줄인다
    mockLayout(290, 120);
    const { container } = render(<ChipRows>{chips(12)}</ChipRows>);
    const { root, fade } = parts(container);

    expect(root.style.getPropertyValue('--g7-chip-rows-w')).toMatch(/px$/);
    const px = parseFloat(root.style.getPropertyValue('--g7-chip-px'));
    const fs = parseFloat(root.style.getPropertyValue('--g7-chip-fs'));
    const gap = parseFloat(root.style.getPropertyValue('--g7-chip-gap'));
    expect(px).toBeGreaterThanOrEqual(6);
    expect(px).toBeLessThanOrEqual(12);
    expect(fs).toBeGreaterThanOrEqual(13);
    expect(gap).toBeGreaterThanOrEqual(4);
    // 변수는 style 속성 문자열이 아니라 setProperty 로 건다(엄격한 스타일 정책) — 속성 값에는 변수만 있다
    expect(root.getAttribute('style') ?? '').not.toMatch(/(^|;)\s*(width|padding|font-size)\s*:/);
    expect(fade).not.toBeNull();
    expect(fade!.getAttribute('aria-hidden')).toBe('true');
  });

  // @scenario case=c11
  // @effects chip_rows_dark_pairs
  it('끝 흐림에 다크 모드 짝이 있고 누름을 가로채지 않는다', () => {
    mockLayout(300, 120);
    const { container } = render(<ChipRows>{chips(12)}</ChipRows>);
    const { fade } = parts(container);

    expect(fade!.className).toMatch(/from-white dark:from-gray-900/);
    expect(fade!.className).toContain('pointer-events-none');
  });

  // @scenario case=c12
  // @effects chip_rows_passes_editor_attributes
  it('id·편집기 속성·클래스를 겉 nav 에 싣는다', () => {
    mockLayout(600, 80);
    const { container } = render(
      <ChipRows id="chips" className="mb-4" editorAttrs={{ 'data-editor-id': 'n1' } as any}>
        {chips(2)}
      </ChipRows>,
    );
    const { root } = parts(container);

    expect(root.id).toBe('chips');
    expect(root.className).toContain('mb-4');
    expect(root.getAttribute('data-editor-id')).toBe('n1');
  });
});
