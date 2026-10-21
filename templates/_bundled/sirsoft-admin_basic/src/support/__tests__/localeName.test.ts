/**
 * getLocaleDisplayName — 언어팩 언어 이름을 칸 라벨로 (#148 F5)
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
  // @scenario shape=region_tagged
  // @effects admin_locale_label_uses_language_pack_name
  it('언어팩의 언어 이름(appConfig.localeNames)을 먼저 쓴다', () => {
    (window as any).G7Core = {
      state: { get: () => ({ appConfig: { localeNames: { 'fil-PH': 'Filipino (Pilipinas)', 'zh-Hant': '繁體中文' } } }) },
      t: (key: string) => key,
    };

    expect(getLocaleDisplayName('fil-PH')).toBe('Filipino (Pilipinas)');
    expect(getLocaleDisplayName('zh-Hant')).toBe('繁體中文');
  });

  // @scenario shape=three_letter
  // @effects admin_locale_label_uses_language_pack_name
  it('부팅 전에는 G7Config.appConfig.localeNames 를 읽는다', () => {
    (window as any).G7Config = { appConfig: { localeNames: { fil: 'Filipino' } } };

    expect(getLocaleDisplayName('fil')).toBe('Filipino');
  });

  /** @effects admin_locale_label_uses_language_pack_name */
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
