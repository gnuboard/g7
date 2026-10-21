/**
 * localeTag — 화면 쪽 언어 코드(BCP 47)·다국어 값 대체 순서 (engine-v1.81.0, #148)
 *
 * 백엔드 짝 `tests/Unit/Support/LocaleTagTest.php`·`LocalizedValueTest.php` 와 같은 표를 쓴다 —
 * 서버 렌더(SEO)와 SPA 화면의 글자가 갈리지 않도록.
 */

import { describe, it, expect, afterEach } from 'vitest';
import {
  isLocaleTag,
  primaryLanguage,
  resolveUsableLocale,
  valueFallbackChain,
  pickLocalized,
  getSiteLocale,
  LOCALE_TAG_BODY,
  matchLocale,
} from '../localeTag';

afterEach(() => {
  delete (window as any).__templateApp;
  delete (window as any).G7Config;
});

/**
 * 화면 언어와 사이트 기본 언어를 준비합니다.
 *
 * @param locale 화면 언어
 * @param siteLocale 사이트 기본 언어
 * @param supportedLocales 켜진 언어 목록
 */
function mockApp(locale: string, siteLocale?: string, supportedLocales?: string[]): void {
  (window as any).__templateApp = {
    getLocale: () => locale,
    globalState: { appConfig: { siteLocale, supportedLocales } },
  };
}

describe('isLocaleTag — 언어팩 설치 규칙과 같다', () => {
  // @scenario shape=two_letter
  // @effects frontend_locale_tag_mirrors_backend_rules
  it('2글자 언어 코드를 받는다', () => {
    ['ko', 'en', 'ja', 'zh-CN'].forEach((tag) => expect(isLocaleTag(tag)).toBe(true));
  });

  // @scenario shape=three_letter
  // @effects frontend_locale_tag_mirrors_backend_rules
  it('세 글자 언어 코드를 받는다', () => {
    ['fil', 'haw', 'yue'].forEach((tag) => expect(isLocaleTag(tag)).toBe(true));
  });

  // @scenario shape=region_tagged
  // @effects frontend_locale_tag_mirrors_backend_rules
  it('지역이 붙은 코드를 받는다', () => {
    ['fil-PH', 'pt-BR', 'zh-Hant-TW', 'sr-Latn-RS', 'de-DE-1996ab'].forEach((tag) => expect(isLocaleTag(tag)).toBe(true));
  });

  // @scenario shape=script_tagged
  // @effects frontend_locale_tag_mirrors_backend_rules
  it('문자 표기가 붙은 코드를 받는다', () => {
    ['zh-Hant', 'zh-Hans', 'sr-Latn'].forEach((tag) => expect(isLocaleTag(tag)).toBe(true));
  });

  // @scenario shape=numeric_region
  // @effects frontend_locale_tag_mirrors_backend_rules
  it('숫자 지역 코드를 받는다', () => {
    expect(isLocaleTag('es-419')).toBe(true);
  });

  // @scenario shape=malformed
  // @effects frontend_locale_tag_mirrors_backend_rules
  it('정규형이 아니거나 경로 문자가 든 코드는 거부한다', () => {
    ['kor1', 'KO', 'fil-ph', 'fil_PH', 'zh-hant', 'e', '../ko', 'ko.json', 'ko/en', '', 'x-klingon'].forEach((tag) =>
      expect(isLocaleTag(tag)).toBe(false),
    );
    expect(isLocaleTag(null)).toBe(false);
    expect(isLocaleTag(12)).toBe(false);
  });

  // @scenario shape=over_length
  // @effects frontend_locale_tag_mirrors_backend_rules
  it('20자를 넘으면 거부한다', () => {
    expect(isLocaleTag('fil-Latn-419-abcdefgh')).toBe(false);
    expect(isLocaleTag('fil-Latn-419-abcdefg')).toBe(true);
  });

  /** @effects frontend_locale_tag_mirrors_backend_rules */
  it('패턴 본문은 백엔드 LocaleTag::PATTERN_BODY 와 같다', () => {
    expect(LOCALE_TAG_BODY).toBe('[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?(?:-[a-zA-Z0-9]{5,8})?');
  });
});

describe('primaryLanguage', () => {
  /** @effects frontend_locale_tag_mirrors_backend_rules */
  it('주 언어 하위 태그를 소문자로 뽑는다', () => {
    expect(primaryLanguage('fil-PH')).toBe('fil');
    expect(primaryLanguage('ja_JP')).toBe('ja');
    expect(primaryLanguage('kok')).toBe('kok');
    expect(primaryLanguage('KO-kr')).toBe('ko');
  });
});

describe('matchLocale — 백엔드 LocaleTag::match 와 같은 순서', () => {
  // @scenario shape=region_tagged
  // @effects frontend_locale_tag_mirrors_backend_rules
  it('완전 일치 → 주 언어 → 주 언어가 같은 첫 지원 항목', () => {
    expect(matchLocale('fil-PH', ['ko', 'fil-PH'])).toBe('fil-PH');
    expect(matchLocale('fil_ph', ['ko', 'fil-PH'])).toBe('fil-PH');
    expect(matchLocale('fil', ['ko', 'fil-PH'])).toBe('fil-PH');
    expect(matchLocale('en-GB', ['ko', 'en'])).toBe('en');
    expect(matchLocale('ja', ['ko', 'en'])).toBeNull();
    expect(matchLocale('', ['ko'])).toBeNull();
  });
});

describe('resolveUsableLocale — 꺼진 언어 처리 (D8)', () => {
  /** @effects frontend_locale_tag_mirrors_backend_rules */
  it('지원 목록을 모르면 후보를 그대로 쓴다(종전 동작)', () => {
    expect(resolveUsableLocale('fil', {}, 'ko')).toBe('fil');
  });

  /** @effects frontend_locale_tag_mirrors_backend_rules */
  it('지원되는 후보는 그대로 쓴다', () => {
    expect(resolveUsableLocale('en', { supportedLocales: ['ko', 'en'], siteLocale: 'ko' }, 'ko')).toBe('en');
  });

  /** @effects frontend_locale_tag_mirrors_backend_rules */
  it('꺼진 후보는 사이트 기본 언어로', () => {
    expect(resolveUsableLocale('fil', { supportedLocales: ['ko', 'en'], siteLocale: 'en' }, 'ko')).toBe('en');
  });

  /** @effects frontend_locale_tag_mirrors_backend_rules */
  it('사이트 기본 언어도 꺼져 있으면 서버 언어 → 지원 목록 첫 항목', () => {
    expect(resolveUsableLocale('fil', { supportedLocales: ['ko', 'en'], siteLocale: 'ja' }, 'en')).toBe('en');
    expect(resolveUsableLocale('fil', { supportedLocales: ['ko', 'en'], siteLocale: 'ja' }, 'fr')).toBe('ko');
  });
});

describe('pickLocalized — 백엔드 LocalizedValue::pick 과 같은 표', () => {
  // @scenario shape=three_letter
  // @effects multilingual_value_falls_back_to_site_locale_then_en, frontend_locale_tag_mirrors_backend_rules
  it('대체 순서는 [현재, 사이트 기본, en] 이고 중복은 지운다', () => {
    mockApp('fil', 'ja');
    expect(valueFallbackChain()).toEqual(['fil', 'ja', 'en']);
    expect(valueFallbackChain('fil-PH')).toEqual(['fil-PH', 'ja', 'en']);
    mockApp('fil', 'en');
    expect(valueFallbackChain()).toEqual(['fil', 'en']);
    expect(getSiteLocale()).toBe('en');
  });

  /** @effects multilingual_value_falls_back_to_site_locale_then_en */
  it('빈 문자열·공백만 있는 값은 건너뛰고, 사이트 기본 언어가 영어보다 먼저다', () => {
    mockApp('fil', 'ja');
    expect(pickLocalized({ ko: '한국어', en: 'English', ja: '日本語', fil: '  ' })).toBe('日本語');
    expect(pickLocalized({ ko: '한국어', en: 'English', ja: '' })).toBe('English');
    expect(pickLocalized({ ko: '한국어', fil: 'Filipino' })).toBe('Filipino');
    expect(pickLocalized({ fil: 0 })).toBe('0');
  });

  /** @effects multilingual_value_falls_back_to_site_locale_then_en */
  it('체인에서 못 찾으면 anyAsLast 일 때만 첫 유효값', () => {
    mockApp('fil', 'ja');
    const value = { ko: '', de: 'Deutsch', fr: 'Français' };
    expect(pickLocalized(value)).toBe('Deutsch');
    expect(pickLocalized(value, undefined, false)).toBeNull();
  });

  /** @effects multilingual_value_falls_back_to_site_locale_then_en */
  it('문자열은 그대로, 객체가 아니면 null', () => {
    expect(pickLocalized('plain')).toBe('plain');
    expect(pickLocalized('')).toBe('');
    expect(pickLocalized(null)).toBeNull();
    expect(pickLocalized(12)).toBeNull();
    expect(pickLocalized({})).toBeNull();
  });

  /** @effects multilingual_value_falls_back_to_site_locale_then_en */
  it('인자로 준 언어가 화면 언어보다 먼저다', () => {
    mockApp('ko', 'ko');
    expect(pickLocalized({ ko: '한국어', en: 'English' }, 'en')).toBe('English');
  });
});
