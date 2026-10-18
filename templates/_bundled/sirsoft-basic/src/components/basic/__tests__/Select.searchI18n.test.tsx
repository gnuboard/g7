// e2e:allow 문구 다국어만 바뀜 — 사용자·관리자 화면 ko/en/ja 브라우저 실측은 Playwright MCP 매트릭스로 수행(7.1.0 Q-G3 세션 2 이력)
/**
 * @file Select.searchI18n.test.tsx
 * @description 사용자 선택 상자 검색칸 안내·이름·결과 없음 문구 다국어 (7.1.0 validate R4, admin_basic 대칭)
 *
 * 관리자 Select 는 검색칸 안내·이름을 화면 언어로 바꿨지만(D-J11) 사용자 Select 는 「Search...」·「Search」·
 * 「No results」 가 영어로 고정돼 한국어·일본어 화면에서도 영어로 보이고 읽혔다. 세 문구 모두 화면 언어를 따른다.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { Select } from '../Select';

const DICT: Record<string, string> = {
  'common.search': '검색',
  'common.search_placeholder': '검색어를 입력하세요...',
  'common.no_results': '검색 결과가 없습니다',
};

const OPTIONS = [
  { value: 'KR', label: '대한민국' },
  { value: 'JP', label: '일본' },
];

describe('사용자 Select 검색칸 다국어', () => {
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario control=select_search_box
   * @effects select_search_box_name_translated
   */
  it('searchPlaceholder 가 없으면 검색칸 안내·이름이 화면 언어(common.*)를 따른다', () => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };

    render(<Select options={OPTIONS} value="KR" onChange={() => {}} searchable />);
    fireEvent.click(screen.getByRole('button'));

    const input = screen.getByRole('searchbox') as HTMLInputElement;
    expect(input.placeholder).toBe('검색어를 입력하세요...');
    expect(input.getAttribute('aria-label')).toBe('검색');
  });

  /**
   * @scenario control=select_search_box
   * @effects select_search_box_name_translated
   */
  it('searchPlaceholder 를 주면 그 문구가 안내이자 이름이다', () => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };

    render(<Select options={OPTIONS} value="KR" onChange={() => {}} searchable searchPlaceholder="국가 검색" />);
    fireEvent.click(screen.getByRole('button'));

    const input = screen.getByRole('searchbox') as HTMLInputElement;
    expect(input.placeholder).toBe('국가 검색');
    expect(input.getAttribute('aria-label')).toBe('국가 검색');
  });

  /**
   * @scenario control=select_search_box
   * @effects select_search_no_results_translated
   */
  it('검색 결과가 없으면 화면 언어의 「결과 없음」 문구를 보인다', () => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };

    render(<Select options={OPTIONS} value="KR" onChange={() => {}} searchable />);
    fireEvent.click(screen.getByRole('button'));
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'zzz' } });

    expect(screen.getByText('검색 결과가 없습니다')).toBeTruthy();
    expect(screen.queryByText('No results')).toBeNull();
  });

  /**
   * @scenario control=select_search_box
   * @effects select_search_box_name_translated
   */
  it('번역기가 없으면 영어 기본 문구로 떨어진다', () => {
    render(<Select options={OPTIONS} value="KR" onChange={() => {}} searchable />);
    fireEvent.click(screen.getByRole('button'));

    const input = screen.getByRole('searchbox') as HTMLInputElement;
    expect(input.placeholder).toBe('Search...');
    expect(input.getAttribute('aria-label')).toBe('Search');
  });
});
