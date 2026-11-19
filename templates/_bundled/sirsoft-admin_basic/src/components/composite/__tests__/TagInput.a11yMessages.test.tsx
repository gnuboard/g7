/**
 * @file TagInput.a11yMessages.test.tsx
 * @description 태그 입력 — 화면 읽기 안내문(라이브 영역)·태그 지우기 버튼 이름이 화면 언어를 따른다
 *
 * 라이브 실측(7.1.0): ko 화면의 권한 그룹 필터·역할 일괄 추가 칸에서 react-select 기본 안내문이
 * 영어로 남았다(「1 result available…」, 태그 지우기 버튼 「Remove 매니저」). 안내문·버튼 이름을 템플릿 다국어
 * 키(`common.tag_input.*`)로 낸다.
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TagInput, TagOption } from '../TagInput';

const read = (relative: string) => JSON.parse(fs.readFileSync(path.resolve(__dirname, relative), 'utf-8'));
const commonKo = read('../../../../lang/partial/ko/common.json');
const commonEn = read('../../../../lang/partial/en/common.json');
const commonJa = read('../../../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/common.json');

const options: TagOption[] = [
  { value: '1', label: '매니저' },
  { value: '2', label: '운영자' },
];

/** ko 사전으로 G7Core.t 를 흉내 낸다 — `{{name}}` 자리표시자를 채운다. */
function koTranslate(key: string, params?: Record<string, string | number>): string {
  const value = key.split('.').reduce<any>((node, part) => node?.[part], { common: commonKo });
  if (typeof value !== 'string') return key;
  return value.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, name) => String(params?.[name] ?? ''));
}

describe('TagInput — 화면 읽기 안내문 다국어', () => {
  let original: any;

  beforeEach(() => {
    original = (window as any).G7Core;
    (window as any).G7Core = { ...(original ?? {}), t: koTranslate };
  });

  afterEach(() => {
    (window as any).G7Core = original;
  });

  // @scenario control=tag_input
  // @effects tag_input_screen_reader_text_localized
  it('태그 지우기 버튼 이름이 화면 언어 문구다 (「Remove 매니저」 아님)', () => {
    render(<TagInput value={['1']} options={options} onChange={() => {}} />);

    const remove = screen.getByRole('button', { name: koTranslate('common.tag_input.remove', { label: '매니저' }) });
    expect(remove).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Remove 매니저' })).toBeNull();
  });

  // @scenario control=tag_input
  // @effects tag_input_screen_reader_text_localized
  it('검색 결과 수 안내가 화면 언어 문구다 (「1 result available」 아님)', async () => {
    const user = userEvent.setup();
    const { container } = render(<TagInput value={[]} options={options} onChange={() => {}} />);

    await user.type(screen.getByRole('combobox'), '매니');
    await screen.findByText('매니저', { selector: '[class*="option"] *, [class*="option"]' }).catch(() => null);

    const live = container.querySelector('[role="log"]');
    expect(live?.textContent ?? '').not.toMatch(/result(s)? available/);
    expect(live?.textContent ?? '').toContain(koTranslate('common.tag_input.results', { count: 1 }));
  });

  // @scenario control=tag_input
  // @effects tag_input_screen_reader_text_localized
  it('안내문 키가 ko·en·ja 에 모두 있다', () => {
    const keys = ['remove', 'results', 'filtered', 'selected', 'deselected', 'cleared', 'disabled_option', 'focus_value', 'focus_option', 'guidance_menu', 'guidance_input', 'guidance_value'];
    for (const pack of [commonKo, commonEn, commonJa]) {
      for (const key of keys) {
        expect(pack.tag_input?.[key], key).toBeTruthy();
      }
    }
    expect(commonEn.tag_input.remove).toContain('{{label}}');
  });

  // @scenario control=tag_input
  // @effects tag_input_default_texts_localized
  it('안내 글, 결과 없음, 추가 접미사의 기본값이 화면 언어 문구다 (영어 화면에서 「검색 또는 입력...」 아님)', async () => {
    const enTranslate = (key: string, params?: Record<string, string | number>): string => {
      const value = key.split('.').reduce<any>((node, part) => node?.[part], { common: commonEn });
      if (typeof value !== 'string') return key;
      return value.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, name) => String(params?.[name] ?? ''));
    };
    (window as any).G7Core = { ...(original ?? {}), t: enTranslate };
    const user = userEvent.setup();
    render(<TagInput value={[]} options={options} onChange={() => {}} creatable />);

    expect(screen.getByText(commonEn.tag_input.placeholder)).toBeTruthy();
    await user.type(screen.getByRole('combobox'), 'zzz');
    expect(await screen.findByText('zzz', { selector: 'strong' })).toBeTruthy();
    for (const pack of [commonKo, commonEn, commonJa]) {
      for (const key of ['placeholder', 'no_options', 'create_label']) {
        expect(pack.tag_input?.[key], key).toBeTruthy();
      }
      expect(pack.tag_input.create_label, '새로 만들기 문장에 값 자리').toContain('{{value}}');
    }
    expect(commonEn.tag_input.placeholder).not.toMatch(/[가-힣]/);
  });

  /**
   * 「새로 만들기」 줄은 값 자리를 받는 문장이라 언어마다 어순을 따른다 — 영어 「Add zzz」, 한국어 「zzz 추가」.
   * 값 뒤에 접미사를 붙이던 때는 영어 화면이 「zzz add」 였다.
   */
  // @scenario control=tag_input
  // @effects tag_input_create_label_follows_locale_word_order
  it.each([
    ['en', () => commonEn, 'Add zzz'],
    ['ko', () => commonKo, 'zzz 추가'],
    ['ja', () => commonJa, 'zzzを追加'],
  ])('새로 만들기 줄의 어순이 화면 언어(%s)를 따른다', async (_locale, pack, expected) => {
    const translate = (key: string, params?: Record<string, string | number>): string => {
      const value = key.split('.').reduce<any>((node, part) => node?.[part], { common: pack() });
      if (typeof value !== 'string') return key;
      return value.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, name) => String(params?.[name] ?? ''));
    };
    (window as any).G7Core = { ...(original ?? {}), t: translate };
    const user = userEvent.setup();
    render(<TagInput value={[]} options={options} onChange={() => {}} creatable />);

    await user.type(screen.getByRole('combobox'), 'zzz');
    const value = await screen.findByText('zzz', { selector: 'strong' });
    expect(value.parentElement?.textContent?.replace(/\s+/g, ' ').trim()).toBe(expected);
  });

  // @scenario control=tag_input
  // @effects tag_input_create_label_follows_locale_word_order
  it('createLabelSuffix 를 넘기면 종전처럼 값 뒤에 붙인다', async () => {
    const user = userEvent.setup();
    render(<TagInput value={[]} options={options} onChange={() => {}} creatable createLabelSuffix="만들기" />);

    await user.type(screen.getByRole('combobox'), 'zzz');
    const value = await screen.findByText('zzz', { selector: 'strong' });
    expect(value.parentElement?.textContent?.replace(/\s+/g, ' ').trim()).toBe('zzz 만들기');
  });
});

