/**
 * getLocaleDisplayName — 사용자 헤더 언어 메뉴의 언어 이름 (#148)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import { getLocaleDisplayName } from '../localeName';

afterEach(() => {
  delete (window as any).G7Core;
  delete (window as any).G7Config;
});

describe('getLocaleDisplayName', () => {
  // @scenario case=pack_locale_label
  // @effects option_label_uses_language_pack_name
  it('언어팩의 언어 이름(appConfig.localeNames)을 먼저 쓴다', () => {
    (window as any).G7Core = {
      state: { get: () => ({ appConfig: { localeNames: { 'fil-PH': 'Filipino (Pilipinas)', 'zh-Hant': '繁體中文' } } }) },
      t: (key: string) => key,
    };

    expect(getLocaleDisplayName('fil-PH')).toBe('Filipino (Pilipinas)');
    expect(getLocaleDisplayName('zh-Hant')).toBe('繁體中文');
  });

  // @scenario case=pack_locale_label
  // @effects option_label_uses_language_pack_name
  it('부팅 전에는 G7Config.appConfig.localeNames 를 읽는다', () => {
    (window as any).G7Config = { appConfig: { localeNames: { fil: 'Filipino' } } };

    expect(getLocaleDisplayName('fil')).toBe('Filipino');
  });

  /** @effects option_label_uses_language_pack_name */
  it('언어팩 이름이 없으면 번역 → 내장 표 → 코드 그대로(대문자화 금지)', () => {
    (window as any).G7Core = {
      state: { get: () => ({}) },
      t: (key: string) => (key === 'common.language_ko' ? '한국어(번역)' : key),
    };

    expect(getLocaleDisplayName('ko')).toBe('한국어(번역)');
    expect(getLocaleDisplayName('ja')).toBe('日本語');
    expect(getLocaleDisplayName('haw')).toBe('haw');
  });
});
