// e2e:allow [동작] 탭이 적은 액션 모양을 실제 ActionDispatcher 바인딩으로 확인하는 RTL — 라이브 실측(영역 I2 후속 D-I2F-02)으로 보강.
/**
 * ActionRecipeEditor.runtimeEventForm.test.tsx — 편집기가 붙인 동작이 런타임에서 실제로 실행되는가
 *
 * 컴포넌트 콜백(`onSearch(term)` 등)은 원시값을 넘긴다. 런타임 `type` 경로는 DOM 이벤트·`{ target }`
 * 모양이 아니면 핸들러를 부르지 않으므로, 편집기가 `type: 'search'` 로 적으면 저장은 되는데
 * 화면에서는 아무 일도 일어나지 않는다. 편집기 출력 → 실제 `ActionDispatcher.bindActionsToProps`
 * → 원시값 콜백 호출 순서로 잠근다.
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ActionRecipeEditor } from '../../../components/property-controls/ActionRecipeEditor';
import { clearWidgetRegistry } from '../../../spec/widgetRegistry';
import { ActionDispatcher } from '../../../../ActionDispatcher';
import { Logger } from '../../../../../utils/Logger';
import type { EditorSpec } from '../../../spec/specTypes';
import type { EditorNode } from '../../../utils/layoutTreeUtils';

const t = (k: string) => k;

const SPEC: EditorSpec = {
  actionRecipes: {
    probe: {
      label: '$t:탐침',
      build: { handler: 'probeHandler', params: { keyword: '{{$args[0]}}' } },
      __source: { kind: 'core' },
    } as never,
  },
};

/** 편집기 슬롯에 probe 동작을 붙이고 저장될 노드 actions 를 돌려준다. */
function addProbe(node: EditorNode, events: string[], slot: string): Array<Record<string, unknown>> {
  const onPatch = vi.fn();
  render(<ActionRecipeEditor node={node} spec={SPEC} capability={{ events }} t={t} onPatchNode={onPatch} />);
  fireEvent.click(screen.getByTestId(`g7le-action-slot-${slot}-add-picker-toggle`));
  const slotEl = screen.getByTestId(`g7le-action-event-${slot}`);
  fireEvent.click(slotEl.querySelector('[data-testid="g7le-init-action-spec-probe"]') as HTMLElement);
  return (onPatch.mock.calls.at(-1)![0] as { actions: Array<Record<string, unknown>> }).actions;
}

describe('ActionRecipeEditor → 런타임 바인딩 (원시값 콜백)', () => {
  let dispatcher: ActionDispatcher;
  let calls: Array<{ args: unknown }>;

  beforeEach(() => {
    cleanup();
    clearWidgetRegistry();
    Logger.getInstance().setDebug(false);
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    calls = [];
    dispatcher.registerHandler('probeHandler', (_action, context) => {
      calls.push({ args: (context as { data?: { $args?: unknown[] } }).data?.$args });
    });
  });

  afterEach(() => {
    dispatcher.clearDebounceTimers();
  });

  // @scenario event_notation=event
  // @effects non_dom_slot_writes_event_notation, editor_output_runs_on_runtime_binding
  it('「검색어를 입력하면」에 붙인 동작이 onSearch(검색어) 원시값 호출로 실행되고 $args 를 받는다', () => {
    const actions = addProbe({ name: 'SearchableDropdown' } as EditorNode, ['onChange', 'onSearch'], 'onSearch');
    const bound = dispatcher.bindActionsToProps({ id: 'sd', actions } as never);
    expect(typeof bound.onSearch).toBe('function');
    bound.onSearch('qa736');
    expect(calls).toHaveLength(1);
    expect(calls[0].args).toEqual(['qa736']);
  });

  // @scenario event_notation=type
  // @effects dom_slot_keeps_type_notation, editor_output_runs_on_runtime_binding
  it('DOM 이벤트 슬롯(「값이 바뀌면」)은 종전 `type: change` 로 적고 DOM change 이벤트로 실행된다', () => {
    // 원시값 onChange 를 받아 주지 않는 것은 의도(troubleshooting-state-setstate 사례 26) — event 로 바꾸지 않는다.
    const actions = addProbe({ name: 'Input' } as EditorNode, ['onChange'], 'onChange');
    expect(actions[0]).toMatchObject({ type: 'change' });
    expect(actions[0]).not.toHaveProperty('event');
    const bound = dispatcher.bindActionsToProps({ id: 'in', actions } as never);
    bound.onChange({ preventDefault: vi.fn(), stopPropagation: vi.fn(), type: 'change', target: { value: 'x', name: 'q' } });
    expect(calls).toHaveLength(1);
  });

  // @scenario event_notation=event
  // @effects raw_value_slot_writes_event_notation, editor_output_runs_on_runtime_binding
  it('역량에 원시값 콜백 표식(rawValueEvents)이 있는 부품의 「값이 바뀌면」은 event 로 적어 onChange(값) 호출로 실행된다', () => {
    // SearchableDropdown·TagSelect·IconSelect·QuantitySelector 등은 onChange(값) 을 사용자 조작 때만 부른다(마운트·리렌더 호출 없음).
    const node = { name: 'SearchableDropdown' } as EditorNode;
    const onPatch = vi.fn();
    render(
      <ActionRecipeEditor
        node={node}
        spec={SPEC}
        capability={{ events: ['onChange', 'onSearch'], rawValueEvents: ['onChange'] } as never}
        t={t}
        onPatchNode={onPatch}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-action-slot-onChange-add-picker-toggle'));
    const slotEl = screen.getByTestId('g7le-action-event-onChange');
    fireEvent.click(slotEl.querySelector('[data-testid="g7le-init-action-spec-probe"]') as HTMLElement);
    const actions = (onPatch.mock.calls.at(-1)![0] as { actions: Array<Record<string, unknown>> }).actions;

    expect(actions[0]).toMatchObject({ event: 'onChange' });
    expect(actions[0]).not.toHaveProperty('type');
    const bound = dispatcher.bindActionsToProps({ id: 'sd', actions } as never);
    bound.onChange(3);
    expect(calls).toHaveLength(1);
    expect(calls[0].args).toEqual([3]);
  });

  // @scenario event_notation=type
  // @effects raw_value_slot_writes_event_notation, dom_slot_keeps_type_notation
  it('원시값 표식이 없는 칸(같은 부품의 다른 DOM 칸·표식 없는 부품)은 종전 type 그대로', () => {
    const actions = addProbe({ name: 'Input' } as EditorNode, ['onChange', 'onClick'], 'onClick');
    expect(actions[0]).toMatchObject({ type: 'click' });

    cleanup();
    const onPatch = vi.fn();
    render(
      <ActionRecipeEditor
        node={{ name: 'SearchableDropdown' } as EditorNode}
        spec={SPEC}
        capability={{ events: ['onChange', 'onFocus'], rawValueEvents: ['onChange'] } as never}
        t={t}
        onPatchNode={onPatch}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-action-slot-onFocus-add-picker-toggle'));
    const slotEl = screen.getByTestId('g7le-action-event-onFocus');
    fireEvent.click(slotEl.querySelector('[data-testid="g7le-init-action-spec-probe"]') as HTMLElement);
    const written = (onPatch.mock.calls.at(-1)![0] as { actions: Array<Record<string, unknown>> }).actions;
    expect(written[0]).toMatchObject({ type: 'focus' });
  });

  // @scenario event_notation=event
  // @effects non_dom_slot_writes_event_notation, editor_output_runs_on_runtime_binding
  it('페이지 바꾸기(onPageChange(2))도 같은 규칙으로 실행된다', () => {
    const actions = addProbe({ name: 'Pagination' } as EditorNode, ['onPageChange'], 'onPageChange');
    const bound = dispatcher.bindActionsToProps({ id: 'pg', actions } as never);
    bound.onPageChange(2);
    expect(calls).toHaveLength(1);
    expect(calls[0].args).toEqual([2]);
  });
});
