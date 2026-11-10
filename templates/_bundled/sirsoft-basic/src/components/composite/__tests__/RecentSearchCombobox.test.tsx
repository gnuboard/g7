/**
 * RecentSearchCombobox — 최근 검색어 펼침 목록을 가진 검색창
 *
 * 코어 엔진의 실제 런타임(`installSearchHistoryRuntime` — 정규화·보관 개수·localStorage `g7_search_history`·변경 알림)을
 * 그대로 쓴다. 엔진 훅 `useSearchHistory` 는 저장소 루트의 React 를 import 해 이 템플릿의 React 와 인스턴스가 달라
 * (시험 환경에서만 — 사이트는 엔진이 React 를 하나로 묶는다) 같은 계약(스냅숏 + `searchHistory:changed` 구독 + list)의
 * 얇은 연결 훅을 이 템플릿 React 로 세운다. 엔진 훅 자체는 엔진 시험과 브라우저 실측이 맡는다.
 * 기록을 기다리지 않는 것·옛 엔진은 대역 훅을 쓴다.
 *
 * 검증: 빈 입력 초점에 열림, 주소 검색어(value) 미기록, ↑/↓·Enter·Esc·Delete, 항목 삭제·전체 삭제,
 * 자동 저장 끄기/켜기(끄면 목록 감춤 + 안내), combobox/listbox/option 의 aria, 같은 범위 공유,
 * 꺼진 범위·편집 모드에서 펼치지 않음, 누름 영역 44px 클래스.
 */

import React from 'react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@core/api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn(() => null), get: vi.fn() })),
}));

vi.mock('@core/auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({ on: vi.fn(() => () => {}), getState: vi.fn(() => ({ isAuthenticated: false })) })),
  },
}));

import {
  SEARCH_HISTORY_CHANGED_EVENT,
  SEARCH_HISTORY_STORAGE_KEY,
  getSearchHistoryRuntime,
  installSearchHistoryRuntime,
  resetSearchHistoryRuntime,
} from '@core/searchHistory';

import { RecentSearchCombobox } from '../RecentSearchCombobox';

const TEXT: Record<string, string> = {
  'search.history.title': '최근 검색어',
  'search.history.empty': '최근 검색어가 없습니다.',
  'search.history.loading': '불러오는 중',
  'search.history.delete': '검색어 삭제: {{keyword}}',
  'search.history.clear_all': '전체 삭제',
  'search.history.auto_save_off': '자동 저장 끄기',
  'search.history.auto_save_on': '자동 저장 켜기',
  'search.history.auto_save_off_notice': '검색어 자동 저장이 꺼져 있습니다.',
  'search.button': '검색',
};

/** 아주 작은 컴포넌트 이벤트 버스 (엔진 G7Core.componentEvent 와 같은 on/emit) */
function createComponentEvent() {
  const listeners = new Map<string, Set<(data: unknown) => void>>();

  return {
    on(event: string, fn: (data: unknown) => void) {
      if (!listeners.has(event)) listeners.set(event, new Set());
      listeners.get(event)!.add(fn);

      return () => listeners.get(event)?.delete(fn);
    },
    emit(event: string, data: unknown) {
      listeners.get(event)?.forEach((fn) => fn(data));
    },
  };
}

/**
 * 엔진 `G7Core.useSearchHistory` 와 같은 계약의 연결 훅 — 실제 런타임의 스냅숏을 읽고 변경 알림에 다시 읽는다.
 *
 * @param scope 범위
 * @returns 훅 반환 모양
 */
function useRuntimeSearchHistory(scope: string) {
  const runtime = getSearchHistoryRuntime()!;
  const [state, setState] = React.useState(() => runtime.getSnapshot(scope));
  React.useEffect(() => {
    let alive = true;
    void runtime.list(scope).then((next: ReturnType<typeof runtime.getSnapshot>) => alive && setState(next));
    const off = (window as any).G7Core.componentEvent.on(SEARCH_HISTORY_CHANGED_EVENT, (change: { scope: string | null }) => {
      if (change?.scope && change.scope !== scope) return;
      if (alive) setState(runtime.getSnapshot(scope));
    });

    return () => {
      alive = false;
      off();
    };
  }, [scope]);

  return {
    ...state,
    record: (keyword: string) => runtime.record(scope, keyword),
    remove: (target: any) => runtime.remove(scope, target),
    clear: () => runtime.clear(scope),
    setAutoSave: (on: boolean) => runtime.setAutoSave(on),
    refresh: async () => undefined,
  };
}

/** 비회원 저장소에 미리 넣는다 (최신순) */
function seed(keywords: string[], autoSave = true) {
  const now = 1_793_740_000_000;
  window.localStorage.setItem(
    SEARCH_HISTORY_STORAGE_KEY,
    JSON.stringify({ v: 1, autoSave, items: { 'core.site': keywords.map((k, i) => ({ k, t: now - i * 1000 })) } }),
  );
}

/** 저장된 core.site 검색어 (최신순) */
function stored(): { autoSave: boolean; keywords: string[] } {
  const raw = window.localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY);
  const parsed = raw ? JSON.parse(raw) : { autoSave: true, items: {} };

  return { autoSave: parsed.autoSave, keywords: (parsed.items?.['core.site'] ?? []).map((i: { k: string }) => i.k) };
}

/** 비동기 기록·변경 알림이 반영될 때까지 */
async function flush() {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
}

function input(): HTMLInputElement {
  return screen.getByRole('combobox') as HTMLInputElement;
}

async function focusInput(el: HTMLInputElement = input()) {
  await act(async () => {
    el.focus();
  });
}

describe('RecentSearchCombobox (실제 엔진 런타임 · 비회원)', () => {
  beforeEach(() => {
    window.localStorage.clear();
    const componentEvent = createComponentEvent();
    (window as any).G7Core = {
      t: (key: string, params?: Record<string, string>) =>
        Object.entries(params ?? {}).reduce((s, [k, v]) => s.split(`{{${k}}}`).join(v), TEXT[key] ?? key),
      componentEvent,
      useSearchHistory: useRuntimeSearchHistory,
    };
    installSearchHistoryRuntime({
      getScopes: () => ({ 'core.site': { max_count: 10 } }),
      api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } as any,
      storage: window.localStorage,
      emit: (change: unknown) => componentEvent.emit(SEARCH_HISTORY_CHANGED_EVENT, change),
      onAuthChange: () => () => {},
      getAuthState: () => ({ isAuthenticated: false }),
    });
  });

  afterEach(() => {
    resetSearchHistoryRuntime();
    window.localStorage.clear();
    delete (window as any).G7Core;
  });

  /**
   * @scenario surface=component
   * @effects empty_focus_opens_recent_list, combobox_listbox_option_aria, touch_targets_44px_and_dark_pairs
   */
  it('빈 입력에 초점을 두면 펼쳐지고 combobox/listbox/option aria 가 맞다', async () => {
    seed(['등산화 끈', '침낭']);
    render(<RecentSearchCombobox placeholder="검색" />);

    const box = input();
    expect(box.getAttribute('aria-expanded')).toBe('false');
    expect(box.hasAttribute('aria-controls')).toBe(false);

    await focusInput();

    expect(box.getAttribute('aria-expanded')).toBe('true');
    const listbox = screen.getByRole('listbox');
    expect(box.getAttribute('aria-controls')).toBe(listbox.id);
    expect(listbox.getAttribute('aria-label')).toBe('최근 검색어');
    const options = within(listbox).getAllByRole('option');
    expect(options.map((o) => o.getAttribute('aria-label'))).toEqual(['등산화 끈', '침낭']);
    expect(options.every((o) => o.getAttribute('aria-selected') === 'false')).toBe(true);
    // 누름 영역 44px — 항목·삭제 단추
    expect(options[0].className).toContain('min-h-[44px]');
    expect(within(options[0]).getByRole('button').className).toContain('min-w-[44px]');
    // 라이트/다크 짝
    expect(screen.getByTestId('recent-search-panel').className).toMatch(/bg-white.*dark:bg-gray-800/);
  });

  /**
   * @scenario surface=component
   * @effects url_query_value_not_recorded
   */
  it('주소로 들어온 검색어(value)는 기록하지 않고, 칸이 차 있으면 펼치지 않는다', async () => {
    seed(['침낭']);
    render(<RecentSearchCombobox value="코펠" />);
    await flush();

    expect(input().value).toBe('코펠');
    expect(stored().keywords).toEqual(['침낭']);

    await focusInput();
    expect(input().getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByRole('listbox')).toBeNull();

    // 칸을 비우면 펼쳐진다
    fireEvent.change(input(), { target: { value: '' } });
    expect(input().getAttribute('aria-expanded')).toBe('true');
  });

  /**
   * @scenario surface=component
   * @effects keyboard_arrow_enter_escape_delete, item_select_searches_and_records
   */
  it('↑/↓ 로 항목을 고르고 Enter 로 그 검색어를 검색·기록한다', async () => {
    seed(['등산화 끈', '침낭', '캠핑']);
    const onSearch = vi.fn();
    render(<RecentSearchCombobox onSearch={onSearch} />);
    await focusInput();

    const box = input();
    fireEvent.keyDown(box, { key: 'ArrowDown' });
    const options = screen.getAllByRole('option');
    expect(box.getAttribute('aria-activedescendant')).toBe(options[0].id);
    expect(options[0].getAttribute('aria-selected')).toBe('true');

    fireEvent.keyDown(box, { key: 'ArrowDown' });
    fireEvent.keyDown(box, { key: 'ArrowDown' });
    expect(box.getAttribute('aria-activedescendant')).toBe(options[2].id);
    fireEvent.keyDown(box, { key: 'ArrowDown' }); // 끝에서 처음으로
    expect(box.getAttribute('aria-activedescendant')).toBe(options[0].id);
    fireEvent.keyDown(box, { key: 'ArrowUp' }); // 처음에서 끝으로
    expect(box.getAttribute('aria-activedescendant')).toBe(options[2].id);
    fireEvent.keyDown(box, { key: 'ArrowUp' });

    fireEvent.keyDown(box, { key: 'Enter' });
    expect(onSearch).toHaveBeenCalledWith('침낭');
    expect(box.value).toBe('침낭');
    await flush();
    expect(stored().keywords).toEqual(['침낭', '등산화 끈', '캠핑']);
  });

  /**
   * @scenario surface=component
   * @effects keyboard_arrow_enter_escape_delete
   */
  it('Esc 는 닫고 초점을 입력칸에 둔다, ↓ 로 다시 연다', async () => {
    seed(['침낭']);
    render(<RecentSearchCombobox />);
    await focusInput();
    expect(screen.getByRole('listbox')).toBeTruthy();

    fireEvent.keyDown(input(), { key: 'Escape' });
    expect(screen.queryByRole('listbox')).toBeNull();
    expect(input().getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(input());

    fireEvent.keyDown(input(), { key: 'ArrowDown' });
    expect(screen.getByRole('listbox')).toBeTruthy();
  });

  /**
   * @scenario surface=component
   * @effects submit_records_without_waiting
   */
  it('입력한 검색어를 제출하면 다듬어 검색하고 맨 앞에 기록한다', async () => {
    seed(['침낭']);
    const onSearch = vi.fn();
    render(<RecentSearchCombobox onSearch={onSearch} />);
    await focusInput();

    fireEvent.change(input(), { target: { value: '  등산화   끈 ' } });
    fireEvent.submit(input().closest('form')!);

    expect(onSearch).toHaveBeenCalledWith('등산화 끈');
    await flush();
    expect(stored().keywords).toEqual(['등산화 끈', '침낭']);
  });

  /**
   * @scenario surface=component
   * @effects item_delete_and_clear_all
   */
  it('× 단추(aria-label)와 Delete 키로 항목을 지우고, 전체 삭제는 범위를 비운다', async () => {
    seed(['등산화 끈', '침낭', '캠핑']);
    render(<RecentSearchCombobox />);
    await focusInput();

    fireEvent.click(screen.getByRole('button', { name: '검색어 삭제: 침낭' }));
    await flush();
    expect(stored().keywords).toEqual(['등산화 끈', '캠핑']);
    expect(screen.getAllByRole('option')).toHaveLength(2);
    expect(document.activeElement).toBe(input());

    fireEvent.keyDown(input(), { key: 'ArrowDown' });
    fireEvent.keyDown(input(), { key: 'Delete' });
    await flush();
    expect(stored().keywords).toEqual(['캠핑']);

    fireEvent.click(screen.getByRole('button', { name: '전체 삭제' }));
    await flush();
    expect(stored().keywords).toEqual([]);
    expect(screen.queryByRole('listbox')).toBeNull();
    expect(screen.getByText('최근 검색어가 없습니다.')).toBeTruthy();
  });

  /**
   * @scenario surface=component
   * @effects auto_save_off_hides_list_with_notice
   */
  it('자동 저장을 끄면 목록을 감추고 안내를 보이며 기록하지 않는다, 켜면 돌아온다', async () => {
    seed(['침낭']);
    const onSearch = vi.fn();
    render(<RecentSearchCombobox onSearch={onSearch} />);
    await focusInput();

    fireEvent.click(screen.getByRole('button', { name: '자동 저장 끄기' }));
    await flush();
    expect(stored().autoSave).toBe(false);
    expect(screen.queryByRole('listbox')).toBeNull();
    expect(screen.getByText('검색어 자동 저장이 꺼져 있습니다.')).toBeTruthy();

    fireEvent.change(input(), { target: { value: '코펠' } });
    fireEvent.submit(input().closest('form')!);
    expect(onSearch).toHaveBeenCalledWith('코펠');
    await flush();
    expect(stored().keywords).toEqual(['침낭']);

    fireEvent.change(input(), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: '자동 저장 켜기' }));
    await flush();
    expect(stored().autoSave).toBe(true);
    expect(screen.getAllByRole('option').map((o) => o.getAttribute('aria-label'))).toEqual(['침낭']);
  });

  /**
   * @scenario surface=component
   * @effects same_scope_boxes_share_list
   */
  it('같은 범위의 검색창끼리 목록을 공유한다 (한 곳에서 기록하면 다른 곳에도)', async () => {
    render(
      <>
        <RecentSearchCombobox data-testid="a" onSearch={() => undefined} />
        <RecentSearchCombobox data-testid="b" />
      </>,
    );
    const [first, second] = screen.getAllByRole('combobox') as HTMLInputElement[];
    fireEvent.change(first, { target: { value: '버너' } });
    fireEvent.submit(first.closest('form')!);
    await flush();

    await focusInput(second);
    const listbox = within(screen.getByTestId('b')).getByRole('listbox');
    expect(within(listbox).getAllByRole('option').map((o) => o.getAttribute('aria-label'))).toEqual(['버너']);
  });

  /**
   * @scenario surface=component
   * @effects empty_focus_opens_recent_list
   */
  it('초점이 검색창 밖으로 나가면 닫히고, 펼침 목록 단추로 옮기면 열린 채다', async () => {
    seed(['침낭']);
    render(
      <>
        <RecentSearchCombobox />
        <button type="button">밖</button>
      </>,
    );
    await focusInput();
    await act(async () => {
      screen.getByRole('button', { name: '전체 삭제' }).focus();
    });
    expect(screen.getByRole('listbox')).toBeTruthy();

    await act(async () => {
      screen.getByRole('button', { name: '밖' }).focus();
    });
    expect(screen.queryByTestId('recent-search-panel')).toBeNull();
  });

  /**
   * @scenario surface=component
   * @effects unavailable_scope_falls_back_to_plain_search
   */
  it('선언되지 않은 범위는 펼치지 않고 평범한 검색창으로 검색한다', async () => {
    const onSearch = vi.fn();
    render(<RecentSearchCombobox scope="acme.unknown" onSearch={onSearch} />);
    await focusInput();
    expect(input().getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByTestId('recent-search-panel')).toBeNull();

    fireEvent.change(input(), { target: { value: '침낭' } });
    fireEvent.submit(input().closest('form')!);
    expect(onSearch).toHaveBeenCalledWith('침낭');
    await flush();
    expect(window.localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY)).toBeNull();
  });

  /**
   * @scenario surface=component
   * @effects editor_mode_and_preview_never_open_or_record
   */
  it('레이아웃 편집 모드(editorAttrs)에서는 펼침 목록이 편집 대상을 가리지 않는다', async () => {
    seed(['침낭']);
    render(<RecentSearchCombobox editorAttrs={{ 'data-editor-id': 'n1' } as any} />);
    await focusInput();
    expect(screen.queryByTestId('recent-search-panel')).toBeNull();
    expect(screen.getByTestId('recent-search-combobox').getAttribute('data-editor-id')).toBe('n1');
  });

  /**
   * WAI-ARIA combobox 패턴 — `aria-expanded` 는 combobox 의 팝업(여기서는 listbox)이 보이는지를 알리고, `aria-controls` 는 그 팝업을
   * 가리킨다. 최근 검색어가 0건이거나 자동 저장이 꺼져 목록 대신 안내만 보일 때는 listbox 가 없으므로 접혀 있다고 알리고
   * 없는 id 를 가리키지 않는다(안내 문구는 role=status 로 따로 읽힌다).
   *
   * @scenario surface=component
   * @effects empty_history_panel_not_announced_as_expanded_listbox, combobox_listbox_option_aria
   */
  it('최근 검색어가 0건이면 안내 상자는 보이되 aria-expanded=false 이고 없는 목록을 aria-controls 로 가리키지 않는다', async () => {
    render(<RecentSearchCombobox placeholder="검색" />);
    await focusInput();

    expect(screen.getByTestId('recent-search-panel')).toBeTruthy();
    expect(screen.getByRole('status').textContent).toBe('최근 검색어가 없습니다.');
    expect(screen.queryByRole('listbox')).toBeNull();
    expect(input().getAttribute('aria-expanded')).toBe('false');
    expect(input().hasAttribute('aria-controls')).toBe(false);
    expect(input().hasAttribute('aria-activedescendant')).toBe(false);
  });

  /**
   * @scenario surface=component
   * @effects empty_history_panel_not_announced_as_expanded_listbox, auto_save_off_hides_list_with_notice
   */
  it('자동 저장이 꺼져 안내만 보일 때도 aria-expanded=false 이고, 전체 삭제로 목록이 사라지면 접힘으로 바뀐다', async () => {
    seed(['침낭'], false);
    const { unmount } = render(<RecentSearchCombobox />);
    await focusInput();
    expect(screen.getByText('검색어 자동 저장이 꺼져 있습니다.')).toBeTruthy();
    expect(input().getAttribute('aria-expanded')).toBe('false');
    expect(input().hasAttribute('aria-controls')).toBe(false);
    unmount();

    seed(['침낭']);
    render(<RecentSearchCombobox />);
    await focusInput();
    const box = input();
    expect(box.getAttribute('aria-expanded')).toBe('true');
    // aria-controls 는 문서에 실제로 있는 요소를 가리킨다
    expect(document.getElementById(box.getAttribute('aria-controls')!)?.getAttribute('role')).toBe('listbox');

    fireEvent.click(screen.getByRole('button', { name: '전체 삭제' }));
    await flush();
    expect(screen.queryByRole('listbox')).toBeNull();
    expect(box.getAttribute('aria-expanded')).toBe('false');
    expect(box.hasAttribute('aria-controls')).toBe(false);
  });

  /**
   * @scenario surface=component
   * @effects blank_keyword_ignored
   */
  it('빈 검색어 제출은 기본으로 무시한다(머리글·통합검색)', async () => {
    const onSearch = vi.fn();
    render(<RecentSearchCombobox onSearch={onSearch} />);
    fireEvent.change(input(), { target: { value: '   ' } });
    fireEvent.submit(input().closest('form')!);
    expect(onSearch).not.toHaveBeenCalled();
  });

  /**
   * 목록 화면의 검색칸(상품 목록·분류·게시판 목록)은 칸을 비우고 제출하면 검색 조건을 푸는 것이 종전 동작이다.
   *
   * @scenario surface=component
   * @effects empty_submit_clears_list_filter
   */
  it('submitEmpty 면 빈 제출도 onSearch("") 로 넘기고(검색 조건 풀기) 기록하지 않는다', async () => {
    seed(['침낭']);
    const onSearch = vi.fn();
    render(<RecentSearchCombobox submitEmpty value="코펠" onSearch={onSearch} />);
    fireEvent.change(input(), { target: { value: '   ' } });
    fireEvent.submit(input().closest('form')!);
    expect(onSearch).toHaveBeenCalledWith('');
    await flush();
    expect(stored().keywords).toEqual(['침낭']);
  });

  /**
   * 확장 범위를 선언한 모듈이 꺼지면 셸의 검색 범위 목록에서 빠진다 — 그 범위의 검색칸은 조용히 평범한 검색창이다.
   *
   * @scenario surface=component
   * @effects unavailable_scope_falls_back_to_plain_search
   */
  it.each(['sirsoft-ecommerce.products', 'sirsoft-board.posts'])(
    '범위를 선언한 확장이 꺼져 있으면(%s 미선언) 펼치지 않고 검색만 한다',
    async (scope) => {
      const onSearch = vi.fn();
      render(<RecentSearchCombobox scope={scope} onSearch={onSearch} />);
      await focusInput();
      expect(screen.queryByTestId('recent-search-panel')).toBeNull();
      expect(input().getAttribute('aria-expanded')).toBe('false');
      fireEvent.change(input(), { target: { value: '후드' } });
      fireEvent.submit(input().closest('form')!);
      expect(onSearch).toHaveBeenCalledWith('후드');
      await flush();
      expect(window.localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY)).toBeNull();
    },
  );

  /**
   * @scenario surface=component
   * @effects submit_records_without_waiting
   */
  it('검색 단추(showButton)로도 제출한다', async () => {
    const onSearch = vi.fn();
    render(<RecentSearchCombobox showButton onSearch={onSearch} />);
    fireEvent.change(input(), { target: { value: '랜턴' } });
    fireEvent.click(screen.getByRole('button', { name: '검색' }));
    expect(onSearch).toHaveBeenCalledWith('랜턴');
  });
});

describe('RecentSearchCombobox (대역 훅)', () => {
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario surface=component
   * @effects submit_records_without_waiting
   */
  it('기록이 끝나기를 기다리지 않고 바로 onSearch 를 부른다 (회원 POST 대기 중에도)', async () => {
    const record = vi.fn(() => new Promise<boolean>(() => {})); // 끝나지 않는 기록
    (window as any).G7Core = {
      t: (k: string) => k,
      useSearchHistory: () => ({
        items: [], autoSave: true, enabled: true, loading: false, maxCount: 10,
        record, remove: vi.fn(), clear: vi.fn(), setAutoSave: vi.fn(), refresh: vi.fn(),
      }),
    };
    const onSearch = vi.fn();
    render(<RecentSearchCombobox onSearch={onSearch} />);
    fireEvent.change(input(), { target: { value: '침낭' } });
    fireEvent.submit(input().closest('form')!);

    expect(onSearch).toHaveBeenCalledWith('침낭');
    await flush();
    expect(record).toHaveBeenCalledWith('침낭');
  });

  /**
   * @scenario surface=component
   * @effects editor_mode_and_preview_never_open_or_record
   */
  it('레이아웃 편집기 미리보기 화면(_global.__isPreview)에서는 펼치지 않고 기록하지 않는다 — 검색 이동만', async () => {
    const record = vi.fn(async () => true);
    (window as any).G7Core = {
      t: (k: string) => k,
      state: { get: () => ({ __isPreview: true }) },
      useSearchHistory: () => ({
        items: [{ id: null, keyword: '침낭', searchedAt: 1 }], autoSave: true, enabled: true, loading: false, maxCount: 10,
        record, remove: vi.fn(), clear: vi.fn(), setAutoSave: vi.fn(), refresh: vi.fn(),
      }),
    };
    const onSearch = vi.fn();
    render(<RecentSearchCombobox onSearch={onSearch} />);
    await focusInput();
    expect(screen.queryByTestId('recent-search-panel')).toBeNull();
    fireEvent.change(input(), { target: { value: '코펠' } });
    fireEvent.submit(input().closest('form')!);
    await flush();
    expect(onSearch).toHaveBeenCalledWith('코펠');
    expect(record).not.toHaveBeenCalled();
  });

  /**
   * @scenario surface=component
   * @effects unavailable_scope_falls_back_to_plain_search
   */
  it('엔진에 훅이 없으면(옛 엔진) 펼침 목록 없이 검색만 한다', async () => {
    (window as any).G7Core = { t: (k: string) => k };
    const onSearch = vi.fn();
    render(<RecentSearchCombobox onSearch={onSearch} />);
    await focusInput();
    expect(screen.queryByTestId('recent-search-panel')).toBeNull();
    fireEvent.change(input(), { target: { value: '침낭' } });
    fireEvent.submit(input().closest('form')!);
    expect(onSearch).toHaveBeenCalledWith('침낭');
  });
});

describe('RecentSearchCombobox (주소 따라가기 — resultPath, 설계 합의 2026-11-04)', () => {
  /** SPA 이동 흉내 — 엔진 알림이 없는 대체 구독은 뒤로/앞으로(popstate)를 본다 */
  async function go(url: string) {
    await act(async () => {
      window.history.pushState({}, '', url);
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
  }

  beforeEach(() => {
    (window as any).G7Core = {
      t: (k: string) => k,
      useSearchHistory: () => ({
        items: [], autoSave: true, enabled: true, loading: false, maxCount: 10,
        record: vi.fn(async () => true), remove: vi.fn(), clear: vi.fn(), setAutoSave: vi.fn(), refresh: vi.fn(),
      }),
    };
  });

  afterEach(() => {
    window.history.pushState({}, '', '/');
    delete (window as any).G7Core;
  });

  /**
   * @scenario surface=component
   * @effects search_box_follows_result_url
   */
  it('결과 화면(resultPath)이면 주소의 name 검색어를, 다른 화면이면 빈칸을 보인다', async () => {
    window.history.pushState({}, '', '/search?q=%EB%93%B1%EC%82%B0%ED%99%94');
    render(<RecentSearchCombobox resultPath="/search" />);
    expect(input().value).toBe('등산화');

    await go('/');
    expect(input().value).toBe('');

    await go('/search?q=%EC%B9%A8%EB%82%AD&page=2');
    expect(input().value).toBe('침낭');
  });

  /**
   * @scenario surface=component
   * @effects search_box_follows_result_url
   */
  it('입력만 하고 검색하지 않은 글자도 다른 화면으로 가면 지우고, 주소가 그대로면 입력 중 글자를 건드리지 않는다', async () => {
    window.history.pushState({}, '', '/search?q=a');
    const { rerender } = render(<RecentSearchCombobox resultPath="/search" />);
    fireEvent.change(input(), { target: { value: 'ab' } });
    rerender(<RecentSearchCombobox resultPath="/search" />);
    expect(input().value).toBe('ab');

    await go('/board/free');
    expect(input().value).toBe('');
  });

  /**
   * @scenario surface=component
   * @effects search_box_follows_result_url
   */
  it('resultPath 가 없으면 주소를 따라가지 않는다 — 화면이 다시 그리는 검색창(value)은 종전대로', async () => {
    render(<RecentSearchCombobox />);
    fireEvent.change(input(), { target: { value: '코펠' } });
    await go('/board/free');
    expect(input().value).toBe('코펠');
  });
});
