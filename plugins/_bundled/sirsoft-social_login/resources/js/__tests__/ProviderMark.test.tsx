/**
 * @file ProviderMark.test.tsx
 * @description 연결 목록 줄의 제공자 표시 — 계열별 공식 심볼을 버튼 색 원 안에 그리고, 장식으로 숨긴다.
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { ProviderMark } from '../components/basic/ProviderMark';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** 버튼 스타일시트에서 선택자 하나의 선언을 꺼낸다(정확히 그 선택자만 가진 규칙). */
const cssRule = (css: string, selector: string): Record<string, string> | null => {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = css.match(new RegExp(`(?:^|\\})\\s*${escaped}\\s*\\{([^}]*)\\}`));
  if (!match) return null;
  return Object.fromEntries(
    match[1].split(';').map((d) => d.trim()).filter(Boolean).map((d) => {
      const [k, ...v] = d.split(':');
      return [k.trim(), v.join(':').trim().toLowerCase()];
    }),
  );
};

describe('ProviderMark', () => {
  afterEach(() => {
    cleanup();
  });

  /**
   * @scenario brand=all
   * @effects mypage_row_shows_provider_symbol
   */
  it('내장 4종은 버튼과 같은 공식 심볼(SVG)을 버튼 색 원 안에 그리고, 모르는 계열은 이름 첫 글자 원형으로 그린다', () => {
    for (const brand of ['kakao', 'naver', 'google', 'facebook']) {
      const { container } = render(<ProviderMark brand={brand} label={brand} />);
      const mark = container.querySelector('.sl-mark');

      expect(mark?.className).toContain(`sl-mark--${brand}`);
      expect(mark?.getAttribute('aria-hidden')).toBe('true');
      expect(mark?.querySelector('svg.sl-symbol')).not.toBeNull();
      cleanup();
    }

    const { container } = render(<ProviderMark brand="mock_oidc" label="사내 계정" />);
    const mark = container.querySelector('.sl-mark');

    expect(mark?.className).toContain('sl-mark--custom');
    expect(mark?.querySelector('svg')).toBeNull();
    expect(mark?.textContent).toBe('사');
  });

  /**
   * @scenario brand=all, theme=light|dark
   * @effects mypage_row_shows_provider_symbol
   */
  it('원 색은 제공자 버튼 색과 같고, 구글은 사이트 다크에서만 어두워진다', () => {
    const css = readFileSync(resolve(__dirname, '../../css/buttons.css'), 'utf8');

    expect(cssRule(css, '.sl-mark--kakao')).toEqual({ 'background-color': '#fee500' });
    expect(cssRule(css, '.sl-mark--naver')).toEqual({ 'background-color': '#03a94d' });
    expect(cssRule(css, '.sl-mark--google')).toEqual({ 'background-color': '#ffffff', 'border-color': '#747775' });
    expect(cssRule(css, '.dark .sl-mark--google')).toEqual({ 'background-color': '#131314', 'border-color': '#8e918f' });
    expect(cssRule(css, '.sl-mark--facebook')).toEqual({ 'background-color': '#1877f2' });
    expect(css).not.toMatch(/\.sl-badge/);
  });
});
