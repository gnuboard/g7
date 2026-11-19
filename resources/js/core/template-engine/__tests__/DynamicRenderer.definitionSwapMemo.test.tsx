/**
 * @file DynamicRenderer.definitionSwapMemo.test.tsx
 * @description 편집기가 노드 정의를 바꿔 끼웠을 때(같은 props 참조, 다른 필드만 바뀜) 조건과 props 해석이 다시 계산되는지
 *
 * 편집기는 노드를 불변 갱신으로 고친다 — `{ ...node, children: [...] }` 처럼 props 객체는 그대로 두고 다른 필드만 바꾼다.
 * 그런데 조건 메모(`shouldRender`)는 `condition` 을, props 메모(`resolvedProps`)는 type·slot·component_layout·
 * children 을 의존성에 두지 않아, 그 필드만 바뀐 정의를 받아도 이전 결과를 그대로 썼다. name·expandChildren 은 컴포넌트
 * 문맥 메모를 거쳐 이미 다시 계산됐고(그 잠금으로 남긴다), children 은 basic·layout 이 `__` props 를 DOM 앞에서 걸러내고
 * composite 는 늘 문맥을 받으므로 화면으로 관찰되는 차이가 없다(의존성만 맞췄다).
 *
 * 하네스: 실물 `DynamicRenderer`. 데이터 컨텍스트는 같은 참조로 고정해, 다시 계산의 계기가 정의 교체 하나뿐이게 한다.
 *
 * @scenario-manifest tests/scenarios/dynamic-renderer-definition-swap-memo.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

/** 마지막으로 받은 props (컴포넌트 이름별) */
const lastProps: Record<string, Record<string, any>> = {};

const Box: React.FC<{ children?: React.ReactNode; 'data-testid'?: string }> = ({ children, 'data-testid': testId }) => (
  <div data-testid={testId}>{children}</div>
);

/** 받은 props 를 기록하는 복합 컴포넌트 */
const Probe: React.FC<Record<string, any>> = (props) => {
  lastProps.Probe = props;

  return <div data-testid={props['data-testid']}>{props.children}</div>;
};

/** skipBindingKeys 를 선언한 복합 컴포넌트 — 그 키는 바인딩 전 원문을 받는다 */
const RawProbe: React.FC<Record<string, any>> = (props) => {
  lastProps.RawProbe = props;

  return <div data-testid={props['data-testid']} />;
};

describe('정의 교체 — 조건·props 메모가 바뀐 필드를 지켜본다', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  const translationContext: TranslationContext = { templateId: 'test-template', locale: 'ko' };
  /** 다시 그리기마다 같은 참조 — 정의 교체 말고 다른 계기를 만들지 않는다 */
  const dataContext = { flag: false, label: 'bound' };

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Box: { component: Box, metadata: { name: 'Box', type: 'basic' } },
      Probe: { component: Probe, metadata: { name: 'Probe', type: 'composite' } },
      RawProbe: { component: RawProbe, metadata: { name: 'RawProbe', type: 'composite', skipBindingKeys: ['template'] } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    for (const key of Object.keys(lastProps)) delete lastProps[key];
  });

  afterEach(() => {
    cleanup();
  });

  const rendererOf = (def: ComponentDefinition): React.ReactElement => (
    <DynamicRenderer
      componentDef={def}
      dataContext={dataContext}
      translationContext={translationContext}
      registry={registry}
      bindingEngine={bindingEngine}
      translationEngine={translationEngine}
      actionDispatcher={actionDispatcher}
    />
  );

  // @scenario field=condition
  // @effects definition_swap_recomputes_condition
  it('condition 만 바꾼 정의를 받으면 표시 여부를 다시 판정한다', () => {
    const props = { 'data-testid': 'cond' };
    const before: ComponentDefinition = { id: 'cond', type: 'basic', name: 'Box', props };
    const { rerender, queryByTestId } = render(rendererOf(before));
    expect(queryByTestId('cond')).not.toBeNull();

    rerender(rendererOf({ ...before, condition: '{{flag}}' }));
    expect(queryByTestId('cond')).toBeNull();

    rerender(rendererOf({ ...before, condition: '{{!flag}}' }));
    expect(queryByTestId('cond')).not.toBeNull();
  });

  // @scenario field=type
  // @effects definition_swap_recomputes_props
  it('type 만 composite 로 바꾼 정의를 받으면 컴포넌트 문맥을 넘긴다', () => {
    const props = { 'data-testid': 'probe' };
    const before: ComponentDefinition = { id: 'probe', type: 'layout', name: 'Probe', props };
    const { rerender } = render(rendererOf(before));
    expect(lastProps.Probe.__componentContext).toBeUndefined();

    rerender(rendererOf({ ...before, type: 'composite' }));
    expect(lastProps.Probe.__componentContext).toBeDefined();
  });

  // @scenario field=component_layout
  // @effects definition_swap_recomputes_props
  it('component_layout 만 바꾼 정의를 받으면 새 레이아웃 정의를 넘긴다', () => {
    const props = { 'data-testid': 'probe' };
    const layoutA = { header: [{ id: 'h', type: 'basic', name: 'Box' }] };
    const layoutB = { header: [{ id: 'h2', type: 'basic', name: 'Box' }] };
    const before: ComponentDefinition = { id: 'probe', type: 'composite', name: 'Probe', props, component_layout: layoutA } as any;
    const { rerender } = render(rendererOf(before));
    expect(lastProps.Probe.__componentLayoutDefs).toBe(layoutA);

    rerender(rendererOf({ ...before, component_layout: layoutB } as any));
    expect(lastProps.Probe.__componentLayoutDefs).toBe(layoutB);
  });

  // @scenario field=name
  // @effects definition_swap_recomputes_props
  it('name 만 바꾼 정의를 받으면 새 컴포넌트의 skipBindingKeys 로 다시 해석한다', () => {
    const props = { 'data-testid': 'probe', template: '{{label}}' };
    const before: ComponentDefinition = { id: 'probe', type: 'composite', name: 'Probe', props };
    const { rerender } = render(rendererOf(before));
    expect(lastProps.Probe.template).toBe('bound');

    rerender(rendererOf({ ...before, name: 'RawProbe' }));
    expect(lastProps.RawProbe.template).toBe('{{label}}');
  });
});
