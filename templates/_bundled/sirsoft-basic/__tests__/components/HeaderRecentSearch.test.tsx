// e2e:allow Vitest 컴포넌트 렌더 테스트(Header 데스크톱 검색칸 ↔ 최근 검색어 검색창). 실제 화면은 최근 검색어 2단계 브라우저 실측 표에서 측정한다.
/**
 * @file HeaderRecentSearch.test.tsx
 * @description 데스크톱 Header 검색칸이 RecentSearchCombobox 로 그려지고, 범위 prop 을 넘기며, 제출하면 기록을 기다리지 않고
 * 통합검색 화면으로 이동하는지 검증한다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { act, fireEvent, render, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../src/components/composite/NotificationCenter', () => ({
  NotificationCenter: () => <div data-testid="notification-center-mock" />,
}));

import Header from '../../src/components/composite/Header';

const record = vi.fn(() => new Promise<boolean>(() => {})); // 회원 POST 처럼 끝나지 않는 기록
const scopesSeen: string[] = [];

beforeEach(() => {
  scopesSeen.length = 0;
  (window as any).G7Core = {
    t: (key: string) => key,
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    dispatch: vi.fn(),
    useSearchHistory: (scope: string) => {
      scopesSeen.push(scope);

      return {
        items: [{ id: null, keyword: '등산화 끈', searchedAt: 1 }],
        autoSave: true,
        enabled: true,
        loading: false,
        maxCount: 10,
        record,
        remove: vi.fn(),
        clear: vi.fn(),
        setAutoSave: vi.fn(),
        refresh: vi.fn(),
      };
    },
    state: { getLocal: () => ({}), getGlobal: () => ({}), get: () => ({}) },
    getSlotContext: () => ({ getSlotComponents: () => [], subscribeToSlot: () => () => {}, isEnabled: true }),
  };
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
});

/** dispatch 로 나간 navigate 경로 */
const navigatedPaths = () =>
  (window as any).G7Core.dispatch.mock.calls
    .map(([action]: [any]) => action)
    .filter((action: any) => action?.handler === 'navigate')
    .map((action: any) => action.params.path);

describe('Header 데스크톱 검색칸 — 최근 검색어', () => {
  /**
   * @scenario surface=header_desktop
   * @effects empty_focus_opens_recent_list, combobox_listbox_option_aria
   */
  it('검색칸은 combobox 이고 기본 범위 core.site 로 최근 검색어를 펼친다', async () => {
    const { getByTestId } = render(<Header />);
    const search = getByTestId('header-search');
    const input = within(search).getByRole('combobox');

    expect(scopesSeen).toContain('core.site');
    await act(async () => input.focus());
    expect(input.getAttribute('aria-expanded')).toBe('true');
    expect(within(search).getAllByRole('option').map((o) => o.getAttribute('aria-label'))).toEqual(['등산화 끈']);
  });

  /**
   * @scenario surface=header_desktop
   * @effects header_scope_prop_selects_list
   */
  it('searchHistoryScope 를 넘기면 그 범위를 쓴다', () => {
    render(<Header searchHistoryScope="sirsoft-board.posts" />);
    expect(scopesSeen).toContain('sirsoft-board.posts');
    expect(scopesSeen).not.toContain('core.site');
  });

  /**
   * @scenario surface=header_desktop
   * @effects submit_records_without_waiting
   */
  it('제출하면 기록이 끝나기를 기다리지 않고 /search?q= 로 이동한다', () => {
    const { getByTestId } = render(<Header />);
    const input = within(getByTestId('header-search')).getByRole('combobox');

    fireEvent.change(input, { target: { value: '침낭 & 버너' } });
    fireEvent.submit(input.closest('form')!);

    expect(navigatedPaths()).toEqual([`/search?q=${encodeURIComponent('침낭 & 버너')}`]);
  });

  /**
   * @scenario surface=header_desktop
   * @effects item_select_searches_and_records
   */
  it('최근 검색어 항목을 고르면 그 검색어로 이동한다', async () => {
    const { getByTestId } = render(<Header />);
    const search = getByTestId('header-search');
    const input = within(search).getByRole('combobox');
    await act(async () => input.focus());

    fireEvent.click(within(search).getByRole('option'));
    expect(navigatedPaths()).toEqual([`/search?q=${encodeURIComponent('등산화 끈')}`]);
  });

  /**
   * @scenario surface=header_desktop
   * @effects blank_keyword_ignored
   */
  it('빈 검색어는 이동하지 않는다', () => {
    const { getByTestId } = render(<Header />);
    const input = within(getByTestId('header-search')).getByRole('combobox');
    fireEvent.change(input, { target: { value: '   ' } });
    fireEvent.submit(input.closest('form')!);
    expect(navigatedPaths()).toEqual([]);
  });

  /**
   * @scenario surface=header_desktop
   * @effects search_box_follows_result_url
   */
  it('검색칸은 주소를 따라간다 — 통합검색 화면이면 주소의 q, 첫 화면으로 가면 빈칸 (설계 합의 2026-11-04)', async () => {
    window.history.pushState({}, '', '/search?q=%EB%93%B1%EC%82%B0%ED%99%94');
    try {
      const { getByTestId } = render(<Header />);
      const input = within(getByTestId('header-search')).getByRole('combobox') as HTMLInputElement;
      expect(input.value).toBe('등산화');

      await act(async () => {
        window.history.pushState({}, '', '/');
        window.dispatchEvent(new PopStateEvent('popstate'));
      });
      expect(input.value).toBe('');
    } finally {
      window.history.pushState({}, '', '/');
    }
  });
});
