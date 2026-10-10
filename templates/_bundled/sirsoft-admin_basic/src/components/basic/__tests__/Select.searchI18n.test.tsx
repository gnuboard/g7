/**
 * @file Select.searchI18n.test.tsx
 * @description 선택 상자 검색칸 안내·이름 다국어 (7.1.0 영역 J D-J11 후속)
 *
 * 검색칸의 기본 안내(placeholder)만 번역 키로 바꾸고 이름(aria-label)은 「Search」 로 남아,
 * 한국어 관리자 화면에서도 화면 읽기 프로그램이 검색칸을 영어로 읽었다. 안내와 이름 모두 화면 언어를 따른다.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import React from 'react';
import { Select } from '../Select';

const DICT: Record<string, string> = {
  'common.search': '검색',
  'common.search_placeholder': '검색...',
  'common.no_results': '검색 결과가 없습니다',
};

const OPTIONS = [
  { value: 'Asia/Seoul', label: '(UTC+09:00) Asia/Seoul' },
  { value: 'Asia/Tokyo', label: '(UTC+09:00) Asia/Tokyo' },
];

describe('Select 검색칸 다국어', () => {
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario control=select_search_box
   * @effects select_search_box_name_translated
   */
  it('searchPlaceholder 가 없으면 검색칸 안내·이름이 화면 언어(common.*)를 따른다', () => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };

    render(<Select options={OPTIONS} value="Asia/Seoul" onChange={() => {}} searchable />);
    fireEvent.click(screen.getByRole('button'));

    const input = screen.getByRole('searchbox') as HTMLInputElement;
    expect(input.placeholder).toBe('검색...');
    expect(input.getAttribute('aria-label')).toBe('검색');
  });

  /**
   * @scenario control=select_search_box
   * @effects select_search_box_name_translated
   */
  it('searchPlaceholder 를 주면 그 문구가 안내이자 이름이다', () => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };

    render(<Select options={OPTIONS} value="Asia/Seoul" onChange={() => {}} searchable searchPlaceholder="타임존 검색" />);
    fireEvent.click(screen.getByRole('button'));

    const input = screen.getByRole('searchbox') as HTMLInputElement;
    expect(input.placeholder).toBe('타임존 검색');
    expect(input.getAttribute('aria-label')).toBe('타임존 검색');
  });

  /**
   * @scenario control=select_search_box
   * @effects select_search_no_results_translated
   */
  it('검색 결과가 없으면 화면 언어의 「결과 없음」 문구를 보인다 (validate R4 동류)', () => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };

    render(<Select options={OPTIONS} value="Asia/Seoul" onChange={() => {}} searchable />);
    fireEvent.click(screen.getByRole('button'));
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'zzz' } });

    expect(screen.getByText('검색 결과가 없습니다')).toBeTruthy();
    expect(screen.queryByText('No results')).toBeNull();
  });
});
