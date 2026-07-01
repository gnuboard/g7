/**
 * PageStateSwitcher.test.tsx — 캔버스 툴바 페이지 상태 토글 RTL
 *
 *  - availableStates 2개 이상 → 드롭다운 렌더, 기본 상태 선택.
 *  - 선택 변경 → SET_ACTIVE_STATE 반영(select value 변경).
 *  - availableStates 1개 이하 → 토글 미표시(디그레이드).
 *  - 라벨 `$t:` 해석, description 보조 표시.
 */

import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, screen, fireEvent, cleanup, act } from '@testing-library/react';

// useTranslation 모킹 — 키 자체 반환.
vi.mock('../../../TranslationContext', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

import { PageStateSwitcher } from '../../components/PageStateSwitcher';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import type { EditorStateItemSpec } from '../../spec/specTypes';

/** availableStates 를 dispatch 로 셋팅하는 테스트 하네스 */
function Harness({ states, activeId }: { states: EditorStateItemSpec[]; activeId: string | null }) {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    // route 선택 후 상태 셋팅 — reducer 의 scope-reset 가 비우지 않도록 SELECT_ROUTE 후 셋팅
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/login', layoutName: 'auth/login' } });
    dispatch({ type: 'SET_AVAILABLE_STATES', states, activeStateId: activeId });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  return <PageStateSwitcher />;
}

function renderWith(states: EditorStateItemSpec[], activeId: string | null) {
  return render(
    <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
      <Harness states={states} activeId={activeId} />
    </LayoutEditorProvider>,
  );
}

afterEach(() => {
  cleanup();
  delete (window as unknown as { G7Core?: unknown }).G7Core;
});

const TWO_STATES: EditorStateItemSpec[] = [
  { id: 'default', label: 'Default', default: true },
  { id: 'login_failed', label: 'Login failed', description: 'desc-key' },
];

describe('PageStateSwitcher', () => {
  it('availableStates 2개 이상 → 드롭다운 렌더, 기본 상태 선택', () => {
    act(() => {
      renderWith(TWO_STATES, 'default');
    });
    const select = screen.getByTestId('g7le-state-switcher-select') as HTMLSelectElement;
    expect(select).toBeTruthy();
    expect(select.value).toBe('default');
    // 두 옵션 모두 렌더
    expect(screen.getByText('Default')).toBeTruthy();
    expect(screen.getByText('Login failed')).toBeTruthy();
  });

  it('선택 변경 → SET_ACTIVE_STATE 반영 (select value 변경)', () => {
    act(() => {
      renderWith(TWO_STATES, 'default');
    });
    const select = screen.getByTestId('g7le-state-switcher-select') as HTMLSelectElement;
    act(() => {
      fireEvent.change(select, { target: { value: 'login_failed' } });
    });
    expect((screen.getByTestId('g7le-state-switcher-select') as HTMLSelectElement).value).toBe('login_failed');
    expect(screen.getByTestId('g7le-state-switcher').getAttribute('data-active-state')).toBe('login_failed');
  });

  it('availableStates 1개 → 토글 미표시 (디그레이드)', () => {
    act(() => {
      renderWith([{ id: 'only', label: 'Only', default: true }], 'only');
    });
    expect(screen.queryByTestId('g7le-state-switcher')).toBeNull();
  });

  it('availableStates 0개 → 토글 미표시', () => {
    act(() => {
      renderWith([], null);
    });
    expect(screen.queryByTestId('g7le-state-switcher')).toBeNull();
  });

  it('description 보조 표시 (활성 상태가 description 보유 시)', () => {
    act(() => {
      renderWith(TWO_STATES, 'login_failed');
    });
    // description 평문 'desc-key' 가 회색 보조로 표시 ($t: 미접두 → 평문 그대로)
    expect(screen.getByTestId('g7le-state-switcher-description').textContent).toBe('desc-key');
  });

  it('label 이 `$t:` 키면 G7Core.t 로 해석', () => {
    (window as unknown as { G7Core?: { t: (k: string) => string } }).G7Core = {
      t: (k: string) => (k === 'editor.state.x' ? '비회원' : k),
    };
    act(() => {
      renderWith(
        [
          { id: 'a', label: '$t:editor.state.x', default: true },
          { id: 'b', label: 'B' },
        ],
        'a',
      );
    });
    expect(screen.getByText('비회원')).toBeTruthy();
  });
});
