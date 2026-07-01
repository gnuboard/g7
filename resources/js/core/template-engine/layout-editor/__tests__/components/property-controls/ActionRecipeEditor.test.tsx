// e2e:allow 컴포넌트 [동작] 탭 패리티(RTL) — 카드/picker/요약/코드 위젯 합성, Chrome MCP 매트릭스(세션 D)로 보강.
/**
 * ActionRecipeEditor.test.tsx — 컴포넌트 [동작] 탭 패리티 RTL
 *
 *  ② 공통화 후: 슬롯 안의 카드/picker/요약/코드/편집/onSuccess 중첩은 공용 ActionListBuilder
 * 에 위임된다. 본 컴포넌트는 이벤트 슬롯 루프 + node↔slot 변환(readEventActions/writeEventActions)
 * 만 담당한다. testid 는 슬롯 빌더 prefix `g7le-action-slot-{eventName}` 기반.
 *
 * 검증:
 *  ① 추가 picker(ActionAddPicker, context=component)
 *  ② 인스턴스 카드 친화 요약 + 출처 배지 + 코드 보기
 *  ③ 드래그 재배치 + 드롭 위치 표시
 *  ④ 고급 항목 코드 열람
 * 회귀(무파괴):
 *  ⑤ 이벤트 슬롯(onClick/onHover) 유지
 *  ⑥ node.actions+type 저장 규약(props.actions/events 미생성)
 *  ⑦ legacy props.actions/events 흡수 + 최상위 일원화
 *  ⑧ 이벤트 슬롯 격리(다른 type 미혼입) + writeEventActions others 보존
 *  ⑨ onSuccess/onError 중첩 action-list 유지(공용 빌더 재귀)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ActionRecipeEditor } from '../../../components/property-controls/ActionRecipeEditor';
import { clearWidgetRegistry, registerWidget } from '../../../spec/widgetRegistry';
import type { EditorSpec } from '../../../spec/specTypes';
import type { EditorNode } from '../../../utils/layoutTreeUtils';

const t = (k: string) => k;

const SPEC: EditorSpec = {
  actionRecipes: {
    navigate: {
      label: '$t:페이지 이동',
      params: [{ key: 'path', widget: 'text' }],
      build: { handler: 'navigate', params: { path: '{{path}}' } },
      __source: { kind: 'core' },
    } as never,
    toast: {
      label: '$t:안내 메시지',
      params: [{ key: 'message', widget: 'text' }],
      build: { handler: 'toast', params: { message: '{{message}}' } },
      __source: { kind: 'core' },
    } as never,
    pay: {
      label: '$t:결제 요청',
      build: { handler: 'pay' },
      __source: { kind: 'plugin', id: 'tosspayments' },
    } as never,
    callServerThen: {
      label: '$t:서버 호출',
      params: [
        { key: 'endpoint', widget: 'text' },
        { key: 'onSuccess', widget: 'action-list' },
      ],
      build: { handler: 'apiCall', target: '{{endpoint}}', onSuccess: '{{onSuccess}}' },
      __source: { kind: 'core' },
    } as never,
  },
};

/** onClick 슬롯 빌더 prefix. */
const CLICK = 'g7le-action-slot-onClick';

beforeEach(() => {
  cleanup();
  clearWidgetRegistry();
  registerWidget('text', ({ value, onChange }) => (
    <input data-testid="w-text" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />
  ));
});

describe('ActionRecipeEditor — 패리티(공통화 위임)', () => {
  const node: EditorNode = { name: 'Button', props: {} };

  it('추가 picker(context=component) 마운트 + 코어 스펙 노출', () => {
    render(<ActionRecipeEditor node={node} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId(`${CLICK}-add-picker-toggle`)).toBeInTheDocument();
    fireEvent.click(screen.getByTestId(`${CLICK}-add-picker-toggle`));
    expect(screen.getByTestId('g7le-init-action-spec-navigate')).toBeInTheDocument();
  });

  it('인스턴스 카드 — 친화 요약 + 출처 배지 + 코드 보기', () => {
    const withAction: EditorNode = {
      name: 'Button',
      actions: [{ type: 'click', handler: 'toast', params: { message: '저장됨' } }],
    };
    render(<ActionRecipeEditor node={withAction} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId(`${CLICK}-item-0`)).toBeInTheDocument();
    expect(screen.getByTestId(`${CLICK}-source-0`).textContent).toContain('source_core');
    expect(screen.getByTestId(`${CLICK}-summary-0`)).toBeInTheDocument();
    fireEvent.click(screen.getByTestId(`${CLICK}-code-0`));
    expect(screen.getByTestId(`${CLICK}-code-view-0`).textContent).toContain('"handler": "toast"');
  });

  it('확장 제공 항목은 제공자명 출처 배지', () => {
    const withAction: EditorNode = { name: 'Button', actions: [{ type: 'click', handler: 'pay' }] };
    render(<ActionRecipeEditor node={withAction} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId(`${CLICK}-source-0`).textContent).toContain('tosspayments');
  });

  it('순서 변경 ▲▼ 버튼은 노출되지 않는다(드래그 전용)', () => {
    const withActions: EditorNode = {
      name: 'Button',
      actions: [
        { type: 'click', handler: 'navigate', params: { path: '/a' } },
        { type: 'click', handler: 'toast', params: { message: 'x' } },
      ],
    };
    render(<ActionRecipeEditor node={withActions} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.queryByTestId(`${CLICK}-up-0`)).not.toBeInTheDocument();
    expect(screen.queryByTestId(`${CLICK}-down-0`)).not.toBeInTheDocument();
  });

  it('드래그 핸들 ⠿ 가 draggable 이고 drag→drop 으로 순서 재배치된다', () => {
    const onPatch = vi.fn();
    const withActions: EditorNode = {
      name: 'Button',
      actions: [
        { type: 'click', handler: 'navigate', params: { path: '/a' } },
        { type: 'click', handler: 'toast', params: { message: 'x' } },
      ],
    };
    render(<ActionRecipeEditor node={withActions} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={onPatch} />);
    const handle0 = screen.getByTestId(`${CLICK}-drag-0`);
    expect(handle0.getAttribute('draggable')).toBe('true');
    const card1 = screen.getByTestId(`${CLICK}-item-1`);
    fireEvent.dragStart(handle0);
    fireEvent.dragOver(card1, { clientY: 10 });
    fireEvent.drop(card1);
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const acts = (patched as { actions?: Array<Record<string, unknown>> }).actions!;
    expect(acts[0]).toMatchObject({ handler: 'toast' });
    expect(acts[1]).toMatchObject({ handler: 'navigate' });
  });

  it('드래그 중 드롭 예정 지점의 삽입선이 활성화된다(드롭 위치 표시)', () => {
    const withActions: EditorNode = {
      name: 'Button',
      actions: [
        { type: 'click', handler: 'navigate', params: { path: '/a' } },
        { type: 'click', handler: 'toast', params: { message: 'x' } },
      ],
    };
    render(<ActionRecipeEditor node={withActions} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId(`${CLICK}-dropline-0`).getAttribute('data-active')).toBe('false');
    expect(screen.getByTestId(`${CLICK}-dropline-end`).getAttribute('data-active')).toBe('false');
    fireEvent.dragStart(screen.getByTestId(`${CLICK}-drag-0`));
    fireEvent.dragOver(screen.getByTestId(`${CLICK}-item-1`), { clientY: 10 });
    expect(screen.getByTestId(`${CLICK}-dropline-end`).getAttribute('data-active')).toBe('true');
    fireEvent.dragEnd(screen.getByTestId(`${CLICK}-drag-0`));
    expect(screen.getByTestId(`${CLICK}-dropline-end`).getAttribute('data-active')).toBe('false');
  });

  it('고급 보존(matchAction null) 항목 — [편집] 부재 + 코드 열람', () => {
    const withAdvanced: EditorNode = {
      name: 'Button',
      actions: [{ type: 'click', handler: 'unknownHandler', weird: true }],
    };
    render(<ActionRecipeEditor node={withAdvanced} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId(`${CLICK}-advanced-0`)).toBeInTheDocument();
    expect(screen.queryByTestId(`${CLICK}-edit-0`)).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId(`${CLICK}-code-0`));
    expect(screen.getByTestId(`${CLICK}-code-view-0`).textContent).toContain('unknownHandler');
  });
});

describe('ActionRecipeEditor — 회귀(무파괴)', () => {
  const node: EditorNode = { name: 'Button', props: {} };

  it('이벤트 슬롯 유지(onClick/onHover)', () => {
    render(<ActionRecipeEditor node={node} spec={SPEC} capability={{ events: ['onClick', 'onHover'] }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-action-event-onClick')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-action-event-onHover')).toBeInTheDocument();
  });

  it('node.actions+type 저장 규약 무변경(props.actions/events 미생성)', () => {
    const onPatch = vi.fn();
    render(<ActionRecipeEditor node={node} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId(`${CLICK}-add-picker-toggle`));
    fireEvent.click(screen.getByTestId('g7le-init-action-spec-toast'));
    const patched = onPatch.mock.calls[0][0] as EditorNode;
    expect((patched as { props?: { actions?: unknown; events?: unknown } }).props?.actions).toBeUndefined();
    expect((patched as { actions?: Array<Record<string, unknown>> }).actions?.[0]).toMatchObject({ type: 'click', handler: 'toast' });
  });

  it('onHover → type:mouseenter 저장', () => {
    const onPatch = vi.fn();
    render(<ActionRecipeEditor node={node} spec={SPEC} capability={{ events: ['onHover'] }} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-action-slot-onHover-add-picker-toggle'));
    fireEvent.click(screen.getByTestId('g7le-init-action-spec-navigate'));
    expect((onPatch.mock.calls[0][0] as { actions?: Array<Record<string, unknown>> }).actions?.[0]).toMatchObject({
      type: 'mouseenter',
      handler: 'navigate',
    });
  });

  it('onSuccess 중첩 action-list 유지(공용 빌더 재귀 — 내부도 카드 일관)', () => {
    const node2: EditorNode = { name: 'Button', actions: [{ type: 'click', handler: 'apiCall', target: 'x', onSuccess: [{ handler: 'toast', params: { message: 'ok' } }] }] };
    render(<ActionRecipeEditor node={node2} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    fireEvent.click(screen.getByTestId(`${CLICK}-edit-0`));
    // 편집 폼의 onSuccess param + 중첩 빌더.
    expect(screen.getByTestId(`${CLICK}-edit-param-onSuccess`)).toBeInTheDocument();
    const nestedPrefix = `${CLICK}-edit-onSuccess`;
    expect(screen.getByTestId(nestedPrefix)).toBeInTheDocument();
    expect(screen.getByTestId(`${nestedPrefix}-item-0`)).toBeInTheDocument();
  });

  it('중첩 onSuccess add picker 가 코어 recipe 를 노출(빈 상태 금지)', () => {
    const node2: EditorNode = { name: 'Button', actions: [{ type: 'click', handler: 'apiCall', target: 'x' }] };
    render(<ActionRecipeEditor node={node2} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    fireEvent.click(screen.getByTestId(`${CLICK}-edit-0`));
    const nestedPrefix = `${CLICK}-edit-onSuccess`;
    const nested = screen.getByTestId(nestedPrefix);
    const toggle = nested.querySelector(`[data-testid="${nestedPrefix}-add-picker-toggle"]`) as HTMLElement;
    expect(toggle).toBeTruthy();
    fireEvent.click(toggle);
    expect(nested.querySelector('[data-testid="g7le-init-action-spec-toast"]')).toBeTruthy();
  });

  it('중첩 onSuccess add picker 로 동작 추가 → apiCall.onSuccess 에 toast 들어감', () => {
    const onPatch = vi.fn();
    const node2: EditorNode = { name: 'Button', actions: [{ type: 'click', handler: 'apiCall', target: 'x' }] };
    render(<ActionRecipeEditor node={node2} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId(`${CLICK}-edit-0`));
    const nestedPrefix = `${CLICK}-edit-onSuccess`;
    const nested = screen.getByTestId(nestedPrefix);
    fireEvent.click(nested.querySelector(`[data-testid="${nestedPrefix}-add-picker-toggle"]`) as HTMLElement);
    fireEvent.click(nested.querySelector('[data-testid="g7le-init-action-spec-toast"]') as HTMLElement);
    const patched = onPatch.mock.calls.at(-1)![0] as { actions?: Array<Record<string, unknown>> };
    const api = patched.actions![0] as { onSuccess?: unknown };
    expect(Array.isArray(api.onSuccess)).toBe(true);
    expect((api.onSuccess as Array<Record<string, unknown>>)[0]).toMatchObject({ handler: 'toast' });
  });

  it('bindingCandidates 가 동작 입력칸(key-value 값)까지 흘러 데이터칩 피커가 노출된다 (회귀: 컴포넌트 [동작] 탭 candidates 누락)', () => {
    // PropertyEditorModal 이 ActionRecipeEditor 에 bindingCandidates 를 전달하지 않아
    // 동작 입력칸에 데이터칩 추가(🔍 InlineBindingScalarPicker)가 안 떴던 회귀를 잠근다.
    const specWithBody: EditorSpec = {
      actionRecipes: {
        callServerKv: {
          label: '$t:서버 호출',
          params: [{ key: 'body', widget: 'key-value' }],
          build: { handler: 'apiCall', params: { body: '{{body}}' } },
          __source: { kind: 'core' },
        } as never,
      },
    };
    const node2: EditorNode = { name: 'Button', actions: [{ type: 'click', handler: 'apiCall', params: { body: { foo: '' } } }] };
    const candidates = [
      { expression: '{{checkoutData.data.x}}', source: '_local', sourceId: 'checkoutData', path: 'data.x', shape: 'scalar', preview: 'x' },
    ] as never;
    render(
      <ActionRecipeEditor
        node={node2}
        spec={specWithBody}
        capability={{ events: ['onClick'] }}
        t={t}
        onPatchNode={vi.fn()}
        bindingCandidates={candidates}
      />,
    );
    fireEvent.click(screen.getByTestId(`${CLICK}-edit-0`));
    // body 의 값 입력칸(KeyValueChipEditor)에 데이터칩 피커(🔍, -pick) 가 존재.
    const pickers = document.querySelectorAll('[data-testid*="kv-body"][data-testid*="-pick"]');
    expect(pickers.length).toBeGreaterThan(0);
  });

  it('한 이벤트(onClick) 슬롯만 onClick 액션을 읽는다 — 다른 type(change) 미혼입', () => {
    const multi: EditorNode = {
      name: 'Input',
      actions: [
        { type: 'click', handler: 'toast', params: { message: 'c' } },
        { type: 'change', handler: 'navigate', params: { path: '/x' } },
      ],
    };
    render(<ActionRecipeEditor node={multi} spec={SPEC} capability={{ events: ['onClick', 'onChange'] }} t={t} onPatchNode={vi.fn()} />);
    const clickSlot = screen.getByTestId('g7le-action-event-onClick');
    const changeSlot = screen.getByTestId('g7le-action-event-onChange');
    expect(clickSlot.querySelectorAll('[data-testid^="g7le-action-slot-onClick-item-"]').length).toBe(1);
    expect(changeSlot.querySelectorAll('[data-testid^="g7le-action-slot-onChange-item-"]').length).toBe(1);
    expect(clickSlot.textContent).toContain('안내 메시지');
    expect(changeSlot.textContent).toContain('페이지 이동');
  });

  it('한 이벤트(onChange) 슬롯에 추가 시 다른 이벤트(onClick) 기존 액션 보존 — writeEventActions others', () => {
    const onPatch = vi.fn();
    const multi: EditorNode = {
      name: 'Input',
      actions: [{ type: 'click', handler: 'toast', params: { message: 'keep' } }],
    };
    render(<ActionRecipeEditor node={multi} spec={SPEC} capability={{ events: ['onClick', 'onChange'] }} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-action-slot-onChange-add-picker-toggle'));
    const changeSlot = screen.getByTestId('g7le-action-event-onChange');
    fireEvent.click(changeSlot.querySelector('[data-testid="g7le-init-action-spec-navigate"]') as HTMLElement);
    const patched = onPatch.mock.calls.at(-1)![0] as { actions?: Array<Record<string, unknown>> };
    const acts = patched.actions!;
    expect(acts.some((a) => a.type === 'click' && a.handler === 'toast')).toBe(true);
    expect(acts.some((a) => a.type === 'change' && a.handler === 'navigate')).toBe(true);
  });

  it('legacy props.actions 를 읽고 편집 저장 시 최상위 node.actions 로 일원화(props.actions 제거)', () => {
    const onPatch = vi.fn();
    const legacy: EditorNode = {
      name: 'Button',
      props: { actions: [{ type: 'click', handler: 'toast', params: { message: 'legacy' } }] },
    } as never;
    render(<ActionRecipeEditor node={legacy} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={onPatch} />);
    expect(screen.getByTestId(`${CLICK}-item-0`)).toBeInTheDocument();
    fireEvent.click(screen.getByTestId(`${CLICK}-remove-0`));
    const patched = onPatch.mock.calls.at(-1)![0] as { props?: { actions?: unknown }; actions?: unknown };
    expect(patched.props?.actions).toBeUndefined();
  });

  it('legacy props.events 객체를 type 부여해 평탄화 읽기(onClick 배열 → click 슬롯)', () => {
    const legacyEvents: EditorNode = {
      name: 'Button',
      props: { events: { onClick: [{ handler: 'toast', params: { message: 'ev' } }] } },
    } as never;
    render(<ActionRecipeEditor node={legacyEvents} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    const clickSlot = screen.getByTestId('g7le-action-event-onClick');
    expect(clickSlot.querySelector(`[data-testid="${CLICK}-item-0"]`)).toBeTruthy();
    expect(clickSlot.textContent).toContain('안내 메시지');
  });
});
