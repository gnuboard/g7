/**
 * RecentSearchCombobox — 검색어 지우기(×) 단추
 *
 * 게시판 목록 검색칸은 종전에 `type="search"` 입력칸이라 브라우저가 × 단추(누르면 칸만 비움 — 검색하지 않음, 초점 유지)와
 * Esc 지우기를 붙였다. 최근 검색어 검색창(type=text)으로 바뀌며 둘 다 사라졌으므로 `showClear` 로 되살린다.
 * 칸에 글자가 있을 때만 보이고, 누르면 칸을 비우고 초점을 입력칸에 둔다. 검색·기록은 하지 않는다(종전과 같음).
 *
 * @scenario-manifest tests/scenarios/recent-search-combobox.yaml
 */

import React from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { RecentSearchCombobox } from '../RecentSearchCombobox';

const TEXT: Record<string, string> = {
  'search.clear': '검색어 지우기',
  'search.history.title': '최근 검색어',
};

let record: ReturnType<typeof vi.fn>;

beforeEach(() => {
  record = vi.fn(async () => true);
  (window as any).G7Core = {
    t: (key: string) => TEXT[key] ?? key,
    useSearchHistory: () => ({
      items: [{ id: null, keyword: '공지', searchedAt: 1 }],
      autoSave: true,
      enabled: true,
      loading: false,
      maxCount: 10,
      record,
      remove: async () => true,
      clear: async () => true,
      setAutoSave: async () => true,
      refresh: async () => undefined,
    }),
  };
});

afterEach(() => {
  delete (window as any).G7Core;
});

const input = () => screen.getByRole('combobox') as HTMLInputElement;
const clearButton = () => screen.queryByRole('button', { name: '검색어 지우기' });

describe('RecentSearchCombobox 검색어 지우기 (showClear)', () => {
  /**
   * @scenario surface=component
   * @effects clear_button_only_with_text
   */
  it('칸에 글자가 있을 때만 지우기 단추가 보인다', () => {
    render(<RecentSearchCombobox showClear onSearch={() => {}} />);
    expect(clearButton()).toBeNull();

    fireEvent.change(input(), { target: { value: '자유' } });
    expect(clearButton()).not.toBeNull();
    expect(clearButton()).toHaveAttribute('type', 'button');
  });

  /**
   * @scenario surface=component
   * @effects clear_button_only_with_text
   */
  it('주소로 들어온 검색어(value)가 있으면 처음부터 보인다', () => {
    render(<RecentSearchCombobox showClear value="자유" onSearch={() => {}} />);
    expect(clearButton()).not.toBeNull();
  });

  /**
   * @scenario surface=component
   * @effects clear_empties_without_search
   */
  it('누르면 칸을 비우고 초점을 입력칸에 두며 검색·기록하지 않는다', async () => {
    const onSearch = vi.fn();
    render(<RecentSearchCombobox showClear submitEmpty value="자유" onSearch={onSearch} />);
    await act(async () => {
      input().focus();
    });

    fireEvent.mouseDown(clearButton()!);
    fireEvent.click(clearButton()!);

    expect(input().value).toBe('');
    expect(document.activeElement).toBe(input());
    expect(onSearch).not.toHaveBeenCalled();
    expect(record).not.toHaveBeenCalled();
    expect(clearButton()).toBeNull();
  });

  /**
   * @scenario surface=component
   * @effects escape_clears_text
   */
  it('글자가 있을 때 Esc 는 칸을 비우고 검색하지 않는다, 최근 검색어 목록은 펼치지 않는다', async () => {
    const onSearch = vi.fn();
    render(<RecentSearchCombobox showClear submitEmpty onSearch={onSearch} />);
    await act(async () => {
      input().focus();
    });
    fireEvent.change(input(), { target: { value: '자유' } });

    fireEvent.keyDown(input(), { key: 'Escape' });

    expect(input().value).toBe('');
    expect(onSearch).not.toHaveBeenCalled();
    expect(screen.queryByTestId('recent-search-panel')).toBeNull();
  });

  /**
   * @scenario surface=component
   * @effects clear_button_opt_in
   */
  it('showClear 를 주지 않으면(머리글·통합검색·상품 검색칸) 단추도 Esc 지우기도 없다', () => {
    render(<RecentSearchCombobox value="자유" onSearch={() => {}} />);
    expect(clearButton()).toBeNull();

    fireEvent.keyDown(input(), { key: 'Escape' });
    expect(input().value).toBe('자유');
  });
});
