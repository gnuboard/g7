/**
 * localeLabel — CKEditor 다국어 탭 라벨 (#148 F5·F5x)
 *
 * 언어팩으로 켠 `fil-PH` 탭이 `FIL-PH` 로만 보이던 것을 언어팩 이름으로 보이게 하고, 그 이름(언어팩 manifest
 * 문자열)이 `innerHTML` 로 들어가 스크립트가 실행될 수 있던 자리를 `textContent` 로 바꾼다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import { getLocaleLabel, buildLocaleTabContent } from '../handlers/localeLabel';

afterEach(() => {
  delete (window as any).G7Core;
  delete (window as any).G7Config;
});

describe('getLocaleLabel', () => {
  // @scenario shape=region_tagged
  // @effects admin_locale_label_uses_language_pack_name
  it('언어팩 이름(appConfig.localeNames)을 먼저 쓴다', () => {
    (window as any).G7Config = { appConfig: { localeNames: { 'fil-PH': 'Filipino (Pilipinas)' } } };

    expect(getLocaleLabel('fil-PH')).toBe('Filipino (Pilipinas)');
  });

  /** @effects admin_locale_label_uses_language_pack_name */
  it('언어팩 이름이 없으면 내장 표 → 코드 그대로', () => {
    expect(getLocaleLabel('ko')).toBe('한국어');
    expect(getLocaleLabel('haw')).toBe('haw');
  });
});

describe('buildLocaleTabContent', () => {
  // @scenario shape=malformed
  // @effects ckeditor_locale_tab_label_is_text_not_html
  it('언어 이름에 든 마크업은 글자로 넣고 요소로 만들지 않는다', () => {
    (window as any).G7Config = { appConfig: { localeNames: { xx: '<img src=x onerror=alert(1)>' } } };
    const tab = document.createElement('button');

    buildLocaleTabContent(tab, 'xx', false);

    expect(tab.querySelectorAll('img')).toHaveLength(0);
    expect(tab.textContent).toContain('<img src=x onerror=alert(1)>');
  });

  // @scenario shape=region_tagged
  // @effects ckeditor_locale_tab_label_is_text_not_html, admin_locale_label_uses_language_pack_name
  it('아이콘·이름·기본 언어 표시(*)를 만든다', () => {
    (window as any).G7Config = { appConfig: { localeNames: { 'fil-PH': 'Filipino (Pilipinas)' } } };
    const tab = document.createElement('button');

    buildLocaleTabContent(tab, 'fil-PH', true);

    expect(tab.querySelector('i.fas.fa-globe.ck5-lang-icon')).not.toBeNull();
    expect(tab.querySelector('span')?.textContent).toBe('Filipino (Pilipinas)');
    expect(tab.querySelector('.ck5-required')?.textContent).toBe('*');
  });

  /** @effects ckeditor_locale_tab_label_is_text_not_html */
  it('기본 언어가 아니면 * 를 붙이지 않는다', () => {
    const tab = document.createElement('button');

    buildLocaleTabContent(tab, 'en', false);

    expect(tab.querySelector('.ck5-required')).toBeNull();
    expect(tab.textContent).toBe('English');
  });
});
