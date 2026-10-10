// e2e:allow [동작] 탭 경고 표시 — 화면 검증은 이력 문서의 편집기 실측(활동 로그 행위자 검색 칸)으로 한다.
/**
 * [동작] 탭 디바운스 혼재 경고 (7.1.0 P13, engine-v1.78.7)
 *
 * 런타임은 같은 이벤트 prop 에서 바로 실행하는 동작을 돌리기 전에 대기 중인 디바운스를 모두 즉시 실행한다
 * (ActionDispatcher.bindActionsToProps 의 flushPendingDebounceTimers — 저장 직전 최신 입력 반영). 그래서 한
 * 칸에 디바운스 동작과 바로 실행하는 동작을 섞으면 디바운스 동작도 매 입력 실행된다. 동작은 그대로 두고
 * 칸에 경고를 띄운다(결정).
 */
import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import { ActionRecipeEditor } from '../../../components/property-controls/ActionRecipeEditor';
import { clearWidgetRegistry } from '../../../spec/widgetRegistry';
import type { EditorSpec } from '../../../spec/specTypes';
import type { EditorNode } from '../../../utils/layoutTreeUtils';

const t = (k: string) => k;
const SPEC: EditorSpec = {
  actionRecipes: {
    refetch: { label: '$t:새로고침', build: { handler: 'refetchDataSource' }, __source: { kind: 'core' } } as never,
  },
};

function renderNode(actions: Array<Record<string, unknown>>, events = ['onChange', 'onSearch']): void {
  render(
    <ActionRecipeEditor
      node={{ name: 'SearchableDropdown', actions } as unknown as EditorNode}
      spec={SPEC}
      capability={{ events }}
      t={t}
      onPatchNode={() => {}}
    />,
  );
}

describe('[동작] 탭 — 같은 칸 디바운스·비디바운스 혼재 경고', () => {
  beforeEach(() => {
    cleanup();
    clearWidgetRegistry();
  });

  // @scenario event_notation=event
  // @effects mixed_debounce_slot_warns
  it('한 칸에 디바운스 동작과 바로 실행하는 동작이 섞이면 그 칸에만 경고가 보인다', () => {
    renderNode([
      { event: 'onSearch', handler: 'refetchDataSource', debounce: 300, params: { dataSourceId: 'users' } },
      { event: 'onSearch', handler: 'setState', params: { target: 'local', q: '{{$args[0]}}' } },
      { type: 'change', handler: 'setState', params: { target: 'local', v: 1 } },
    ]);

    const warning = screen.getByTestId('g7le-action-slot-onSearch-mixed-debounce');
    expect(warning.getAttribute('role')).toBe('note');
    expect(warning.textContent).toBe('layout_editor.action.mixed_debounce_warning');
    expect(screen.queryByTestId('g7le-action-slot-onChange-mixed-debounce')).toBeNull();
  });

  // @scenario event_notation=type
  // @effects mixed_debounce_slot_warns
  it('type 과 event 로 다르게 적혀도 런타임이 같은 prop 으로 묶는 동작이면 혼재로 본다', () => {
    renderNode([
      { type: 'change', handler: 'refetchDataSource', debounce: { delay: 300 } },
      { event: 'onChange', handler: 'setState', params: { target: 'local', v: 1 } },
    ]);

    expect(screen.getByTestId('g7le-action-slot-onChange-mixed-debounce')).toBeTruthy();
  });

  // @scenario event_notation=event
  // @effects mixed_debounce_slot_warns
  it('모두 디바운스이거나 모두 바로 실행이면 경고하지 않는다', () => {
    renderNode([
      { event: 'onSearch', handler: 'refetchDataSource', debounce: 300 },
      { event: 'onSearch', handler: 'setState', debounce: 300, params: { target: 'local', q: 1 } },
      { type: 'change', handler: 'setState', params: { target: 'local', v: 1 } },
      { type: 'change', handler: 'refetchDataSource' },
    ]);

    expect(screen.queryByTestId('g7le-action-slot-onSearch-mixed-debounce')).toBeNull();
    expect(screen.queryByTestId('g7le-action-slot-onChange-mixed-debounce')).toBeNull();
  });
});
