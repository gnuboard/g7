// e2e:allow 이커머스 전수 실측 후속 B — 전용 Chrome(CDP) 브라우저로 화면 재실측(이력 B3 실측 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file MultilingualSiteLocaleRequired.test.tsx
 * @description 관리자 다국어 입력의 필수 언어 = 사이트 기본 언어 (보는 언어와 무관)
 *
 * 서버 다국어 필수 규칙(LocaleRequiredTranslatable)이 요청 언어 대신 사이트 기본 언어(`app.site_locale`)를
 * 필수로 보도록 바뀌었다(제품 결정 2026-10-08). 화면도 같은 언어 칸에 필수 표시(*)·required 를 붙여야
 * 영어 관리자가 EN 칸만 채우고 저장해 422 를 받는 일이 없다. 사이트 기본 언어는 프론트 `appConfig.siteLocale`
 * 로 온다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { getSiteLocale } from '../../../support/siteLocale';
import { MultilingualInput } from '../MultilingualInput';
import { HtmlEditor } from '../HtmlEditor';

/**
 * 보는 언어·사이트 기본 언어를 정해 G7Core 를 심는다.
 *
 * @param current 보는 언어
 * @param siteLocale 사이트 기본 언어(appConfig.siteLocale)
 * @param supported 지원 언어
 * @returns void
 */
function installG7Core(current: string, siteLocale: string | undefined, supported: string[] = ['ko', 'en']): void {
  (window as any).G7Core = {
    t: (key: string) => key,
    locale: { supported: () => supported, current: () => current },
    state: { get: () => ({ appConfig: siteLocale === undefined ? {} : { siteLocale } }) },
  };
}

afterEach(() => {
  delete (window as any).G7Core;
  delete (window as any).G7Config;
});

describe('getSiteLocale — 사이트 기본 언어', () => {
  /**
   * @scenario case=en_viewer_ko_site
   * @effects site_locale_resolved_from_app_config
   */
  it('보는 언어가 아니라 appConfig.siteLocale 을 돌려준다', () => {
    installG7Core('en', 'ko');

    expect(getSiteLocale()).toBe('ko');
  });

  /**
   * @scenario case=ko_viewer_en_site
   * @effects site_locale_resolved_from_app_config
   */
  it('전역 상태가 아직 없으면 서버가 주입한 G7Config.appConfig 를 읽는다', () => {
    (window as any).G7Core = { locale: { supported: () => ['ko', 'en'], current: () => 'ko' }, state: { get: () => ({}) } };
    (window as any).G7Config = { appConfig: { siteLocale: 'en' } };

    expect(getSiteLocale()).toBe('en');
  });

  /**
   * @scenario case=en_viewer_no_site
   * @effects site_locale_falls_back_to_first_supported
   */
  it('사이트 기본 언어가 없거나 지원 목록 밖이면 첫 지원 언어로 — 서버 규칙의 폴백과 같다', () => {
    installG7Core('en', undefined);
    expect(getSiteLocale()).toBe('ko');

    installG7Core('en', 'fr');
    expect(getSiteLocale()).toBe('ko');
  });
});

describe('MultilingualInput — 필수 표시는 사이트 기본 언어 칸에', () => {
  /**
   * @scenario case=en_viewer_ko_site
   * @effects multilingual_required_mark_on_site_locale
   */
  it('영어로 보는 관리자에게도 ko 칸이 필수(*·required)이고 en 칸은 아니다', () => {
    installG7Core('en', 'ko');
    const { container } = render(
      <MultilingualInput name="name" value={{ ko: '', en: '' }} onChange={() => {}} required layout="inline" />
    );

    const ko = container.querySelector('input[name="name.ko"], input[name="name[ko]"], input[data-locale="ko"]') as HTMLInputElement | null;
    const en = container.querySelector('input[name="name.en"], input[name="name[en]"], input[data-locale="en"]') as HTMLInputElement | null;
    const inputs = [...container.querySelectorAll('input')] as HTMLInputElement[];
    const required = inputs.filter((i) => i.required);

    expect(required).toHaveLength(1);
    // 첫 칸이 필수 언어 칸이다(기본 언어 칸은 늘 맨 앞)
    expect(inputs[0].required).toBe(true);
    if (ko && en) {
      expect(ko.required).toBe(true);
      expect(en.required).toBe(false);
    }
    expect(container.textContent?.indexOf('*')).toBeGreaterThan(-1);
  });

  /**
   * @scenario case=ko_viewer_en_site
   * @effects multilingual_required_mark_on_site_locale
   */
  it('사이트 기본 언어가 en 이면 한국어로 보는 관리자에게도 en 칸이 필수다', () => {
    installG7Core('ko', 'en');
    render(<MultilingualInput name="name" value={{ ko: '', en: '' }} onChange={() => {}} required layout="inline" />);

    const labels = screen.getAllByText('*');
    expect(labels).toHaveLength(1);
    // * 이 붙은 배지 안에 영어 이름(키 그대로면 common.language_en)이 있다
    const badge = labels[0].parentElement as HTMLElement;
    expect(badge.textContent).toMatch(/English|language_en|EN/i);
  });
});

describe('HtmlEditor — 필수 표시는 사이트 기본 언어 탭에', () => {
  /**
   * @scenario case=en_viewer_ko_site
   * @effects html_editor_required_mark_on_site_locale
   */
  it('영어로 보는 관리자에게도 * 는 ko 탭에 붙는다', () => {
    installG7Core('en', 'ko');
    render(<HtmlEditor name="description" multilingual value={{ ko: '', en: '' }} onChange={() => {}} />);

    const marks = screen.getAllByText('*');
    expect(marks).toHaveLength(1);
    const tab = marks[0].closest('button') as HTMLElement;
    expect(tab.textContent).toMatch(/한국어|language_ko|KO/i);
  });
});
