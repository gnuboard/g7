/**
 * 컴포넌트 기본 문구가 화면 언어를 따른다 — 레이아웃이 문구를 넘기지 않아도 영문이 보이지 않는다.
 *
 * 7.1.0 전수 실측(O-C3b-4): 관리자 컴포넌트의 화면 문구·접근 이름 기본값이 영문 고정이었다(LoginForm 「Email」·「Sign In」,
 * NotificationCenter 「Notifications」, UserProfile 「Logout」·「Logging out...」, SearchableDropdown 「Select」·
 * 「Type to search」, LanguageSelector 「Language」, EmptyState·AdminSidebar 이미지 대체 글, TagSelect 「No items selected」,
 * ThemeToggle 「System/Light/Dark」, FileInput 「Browse」·「No file selected」·「Clear file」, Select 검색칸, Alert 아이콘 이름).
 * 번들 레이아웃은 모두 다국어 키를 넘겨 화면 영향은 없었지만, 레이아웃 편집기로 새로 놓으면 영문이 보였다.
 * 기본값을 다국어 키로 바꾸고, 번역이 없거나 엔진 밖(단위 테스트)이면 종전 영문을 쓴다.
 *
 * 번역 함수는 실제 언어 파일(템플릿 ko/en, 일본어 언어팩 ja)로 만든다.
 *
 * @scenario-manifest tests/scenarios/icon-only-control-names.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, afterEach, beforeEach } from 'vitest';
import { LoginForm } from '../LoginForm';
import { NotificationCenter } from '../NotificationCenter';
import { UserProfile } from '../UserProfile';
import { SearchableDropdown } from '../SearchableDropdown';
import { LanguageSelector } from '../LanguageSelector';
import { EmptyState } from '../EmptyState';
import { AdminSidebar } from '../AdminSidebar';
import { TagSelect } from '../TagSelect';
import { ThemeToggle } from '../ThemeToggle';
import { Alert } from '../Alert';
import { ActionMenu } from '../ActionMenu';
import { FileInput } from '../../basic/FileInput';
import { Select } from '../../basic/Select';

type Locale = 'ko' | 'en' | 'ja';

const ROOT = path.resolve(__dirname, '../../../..');
const JA_ROOT = path.resolve(ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial');

const loadPartial = (locale: Locale, file: string): Record<string, unknown> => {
  const dir = locale === 'ja' ? JA_ROOT : path.join(ROOT, 'lang/partial', locale);
  return JSON.parse(fs.readFileSync(path.join(dir, `${file}.json`), 'utf8'));
};

/** 엔진 G7Core.t 와 같은 규칙 — 없는 키는 키 그대로, {{name}} 치환 */
const installT = (locale: Locale) => {
  const files: Record<string, Record<string, unknown>> = {};
  for (const f of ['common', 'admin', 'auth']) files[f] = loadPartial(locale, f);
  const t = (key: string, params?: Record<string, string | number>) => {
    const [file, ...rest] = key.split('.');
    let node: unknown = files[file];
    for (const part of rest) node = (node as Record<string, unknown> | undefined)?.[part];
    if (typeof node !== 'string') return key;
    return params ? node.replace(/\{\{(\w+)\}\}/g, (_, k) => String(params[k] ?? '')) : node;
  };
  (window as any).G7Core = { t, locale: { supported: () => ['ko', 'en', 'ja'], current: () => locale } };
  return t;
};

beforeEach(() => {
  (window as any).matchMedia = (query: string) => ({
    matches: false, media: query, onchange: null,
    addEventListener: () => {}, removeEventListener: () => {}, addListener: () => {}, removeListener: () => {}, dispatchEvent: () => false,
  });
});

afterEach(() => {
  delete (window as any).G7Core;
});

describe.each(['ko', 'en', 'ja'] as Locale[])('기본 문구 다국어 (%s)', (locale) => {
  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('LoginForm 기본 라벨·단추·안내가 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<LoginForm />);
    expect(screen.getAllByText(t('auth.login.email')).length).toBeGreaterThan(0);
    expect(screen.getAllByText(t('auth.login.password')).length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: t('auth.login.submit') })).toBeTruthy();
    expect(screen.getByText(t('auth.login.forgot'))).toBeTruthy();
    expect(screen.getByPlaceholderText(t('auth.login.email'))).toBeTruthy();
    // 빈 칸으로 제출하면 오류 문구도 화면 언어다
    fireEvent.click(screen.getByRole('button', { name: t('auth.login.submit') }));
    expect(screen.getByText(t('auth.login.error.email_required'))).toBeTruthy();
    expect(screen.getByText(t('auth.login.error.password_required'))).toBeTruthy();
    if (locale !== 'en') expect(screen.queryByText('Sign In')).toBeNull();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('NotificationCenter 기본 제목·빈 목록 문구가 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<NotificationCenter notifications={[]} />);
    fireEvent.click(screen.getByRole('button', { name: t('admin.notifications') }));
    expect(screen.getByText(t('admin.no_notifications'))).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('UserProfile 기본 메뉴 문구가 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<UserProfile user={{ name: 'U', email: 'u@example.com' } as any} />);
    fireEvent.click(screen.getByText('U'));
    expect(screen.getByText(t('admin.profile_settings'))).toBeTruthy();
    expect(screen.getByText(t('admin.logout'))).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('SearchableDropdown 기본 단추·검색 안내가 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<SearchableDropdown options={[{ value: 'a', label: 'A' }]} />);
    const trigger = screen.getByText(t('common.select_placeholder'));
    fireEvent.click(trigger);
    expect(screen.getByPlaceholderText(t('common.search_placeholder'))).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('LanguageSelector 기본 이름이 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<LanguageSelector availableLocales={['ko', 'en']} />);
    expect(screen.getByRole('button', { name: t('admin.language') })).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('EmptyState·AdminSidebar 이미지 대체 글과 TagSelect 빈 안내가 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<EmptyState title="x" illustrationSrc="/a.png" />);
    expect(screen.getByAltText(t('common.empty_illustration'))).toBeTruthy();
    render(<AdminSidebar menu={[]} logo="/logo.png" />);
    expect(screen.getByAltText(t('common.logo'))).toBeTruthy();
    render(<TagSelect options={[]} value={[]} />);
    expect(screen.getByText(t('common.no_items_selected'))).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('ThemeToggle 기본 메뉴 항목이 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<ThemeToggle />);
    fireEvent.click(screen.getByRole('button', { name: t('common.theme_select') }));
    expect(screen.getByText(t('admin.theme.auto'))).toBeTruthy();
    expect(screen.getByText(t('admin.theme.light'))).toBeTruthy();
    expect(screen.getByText(t('admin.theme.dark'))).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('FileInput 기본 단추·안내·지우기 이름과 Select 검색칸이 화면 언어를 따른다', () => {
    const t = installT(locale);
    const { container } = render(<FileInput />);
    expect(screen.getByText(t('common.file_input.browse'))).toBeTruthy();
    expect(screen.getByText(t('common.file_input.no_file'))).toBeTruthy();
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['x'], 'a.txt', { type: 'text/plain' })] } });
    expect(screen.getByRole('button', { name: t('common.file_input.clear') })).toBeTruthy();
    render(<Select searchable options={[{ value: 'a', label: 'A' }]} value="a" onChange={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /A/ }));
    expect(screen.getByRole('searchbox', { name: t('common.search') })).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects decorative_icon_hidden_from_a11y_tree
   */
  it('Alert 유형 아이콘과 닫기 아이콘은 꾸밈으로 숨고 영문 이름을 내지 않는다', () => {
    installT(locale);
    const { container } = render(<Alert type="warning" message="m" dismissible onDismiss={() => {}} />);
    expect(container.querySelectorAll('i[role="img"]').length).toBe(0);
    expect(container.querySelector('[aria-label="warning icon"]')).toBeNull();
    expect(container.querySelector('[aria-label="Close"]')).toBeNull();
  });

  /**
   * ActionMenu 트리거 글자 기본값이 한국어 「작업」 고정이라 영어·일본어 화면에도 「작업」이 보였다(validate C 묶음).
   *
   * @scenario control=default_text
   * @effects default_text_follows_locale
   */
  it('ActionMenu 기본 트리거 글자가 화면 언어를 따른다', () => {
    const t = installT(locale);
    render(<ActionMenu items={[{ id: 'a', label: 'A' }]} />);
    expect(screen.getByRole('button', { name: new RegExp(t('common.actions')) })).toBeTruthy();
  });
});

describe('엔진 밖(번역 함수 없음) 대체 문구', () => {
  /**
   * @scenario control=default_text
   * @effects fallback_without_engine
   */
  it('번역 함수가 없으면 종전 영문 기본값을 쓴다', () => {
    render(<LoginForm />);
    expect(screen.getByRole('button', { name: 'Sign In' })).toBeTruthy();
    render(<SearchableDropdown options={[]} />);
    expect(screen.getByText('Select')).toBeTruthy();
    render(<TagSelect options={[]} value={[]} />);
    expect(screen.getByText('No items selected')).toBeTruthy();
    // 대체 문구는 영어다(docs/frontend/data-binding-i18n.md 「영어 기본값」) — ActionMenu 의 종전 한국어 기본값 「작업」도 영어로
    render(<ActionMenu items={[{ id: 'a', label: 'A' }]} />);
    expect(screen.getByText('Actions')).toBeTruthy();
  });

  /**
   * @scenario control=default_text
   * @effects fallback_without_engine
   */
  it('번역 함수가 없으면 아이콘 단추 접근 이름도 영어 대체 문구를 쓴다', () => {
    render(<ThemeToggle />);
    expect(screen.getByRole('button', { name: 'Theme' })).toBeTruthy();
    render(<UserProfile user={{ name: 'U', email: 'u@example.com' } as any} dropdownOnly />);
    expect(screen.getByRole('button', { name: 'User menu' })).toBeTruthy();
  });
});
