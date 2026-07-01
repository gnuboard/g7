/**
 * LocaleSwitcher.test.tsx — 콘텐츠 로케일 전환 RTL
 *
 *  - 주입된 로케일 목록을 버튼으로 렌더, 현재 로케일 active.
 *  - 전환 클릭 → SET_LOCALE dispatch (콘텐츠 로케일만 — chrome 로케일 불변).
 *  - 로케일 1개 → 전환 토글 없이 라벨만.
 *  - readSupportedLocales — G7Config.appConfig.supportedLocales 합집합 읽기.
 */

import React from 'react';
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

// useTranslation 모킹 — 키 자체 반환(TranslationProvider 불필요).
vi.mock('../../../TranslationContext', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

import { LocaleSwitcher, readSupportedLocales, localeDisplayLabel } from '../../components/LocaleSwitcher';
import { LayoutEditorProvider } from '../../LayoutEditorContext';

function renderWith(locales: string[] | undefined, initialLocale = 'ko') {
  return render(
    <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale={initialLocale}>
      <LocaleSwitcher locales={locales} />
    </LayoutEditorProvider>,
  );
}

afterEach(() => {
  cleanup();
  delete (window as unknown as { G7Config?: unknown }).G7Config;
});

describe('LocaleSwitcher', () => {
  it('주입된 로케일 목록을 버튼으로 렌더, 현재 로케일 active', () => {
    renderWith(['ko', 'en', 'ja'], 'ko');
    expect(screen.getByTestId('g7le-locale-ko').getAttribute('aria-pressed')).toBe('true');
    expect(screen.getByTestId('g7le-locale-en').getAttribute('aria-pressed')).toBe('false');
    expect(screen.getByTestId('g7le-locale-ja')).toBeTruthy();
  });

  it('전환 클릭 → 그 로케일이 active 로 변경 (SET_LOCALE 반영)', () => {
    renderWith(['ko', 'en', 'ja'], 'ko');
    fireEvent.click(screen.getByTestId('g7le-locale-en'));
    expect(screen.getByTestId('g7le-locale-en').getAttribute('aria-pressed')).toBe('true');
    expect(screen.getByTestId('g7le-locale-ko').getAttribute('aria-pressed')).toBe('false');
  });

  it('로케일 1개 → 전환 토글 없이 라벨만', () => {
    renderWith(['ko'], 'ko');
    expect(screen.queryByTestId('g7le-locale-switcher')).toBeNull();
    expect(screen.getByTestId('g7le-locale-switcher-single')).toBeTruthy();
  });

  it('현재 로케일이 목록에 없으면 앞에 추가해 선택 가능하게', () => {
    renderWith(['en', 'ja'], 'ko');
    expect(screen.getByTestId('g7le-locale-ko')).toBeTruthy();
    expect(screen.getByTestId('g7le-locale-ko').getAttribute('aria-pressed')).toBe('true');
  });
});

describe('readSupportedLocales', () => {
  beforeEach(() => {
    delete (window as unknown as { G7Config?: unknown }).G7Config;
  });

  it('G7Config.appConfig.supportedLocales 를 읽는다', () => {
    (window as unknown as { G7Config?: unknown }).G7Config = {
      appConfig: { supportedLocales: ['ko', 'en', 'ja'] },
    };
    expect(readSupportedLocales()).toEqual(['ko', 'en', 'ja']);
  });

  it('미주입 시 빈 배열', () => {
    expect(readSupportedLocales()).toEqual([]);
  });
});

describe('localeDisplayLabel', () => {
  it('정의된 로케일 라벨 키 해석', () => {
    expect(localeDisplayLabel('ko', (k) => (k === 'layout_editor.locale.ko' ? '한국어' : k))).toBe('한국어');
  });
  it('미정 로케일은 코드 대문자 폴백', () => {
    expect(localeDisplayLabel('xx', (k) => k)).toBe('XX');
  });
});
