/**
 * iteration + `if` 항목별 평가 회귀 테스트 (engine-v1.56.0)
 *
 * `if` 가 항목 변수를 참조하면 부모 시점 컨텍스트에는 그 변수가 없어 조건이 항상 false 가
 * 되고, 조건 평가가 iteration 분기보다 먼저 걸려 **목록 전체가 렌더되지 않았다**.
 * 반복 렌더 경로(renderItemChildren)는 항목별로 평가하므로 같은 작성이 거기서는 정상
 * 동작했고, 그 비대칭 때문에 재현 위치를 특정하기 어려웠다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const Row: React.FC<{ label?: string; children?: React.ReactNode }> = ({ label, children }) => (
  <div data-testid="row">{label ?? children}</div>
);

describe('DynamicRenderer — iteration + if', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Row: { component: Row, metadata: { name: 'Row', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  const renderDef = (componentDef: ComponentDefinition, dataContext: any) =>
    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={dataContext}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

  const context = {
    users: [
      { name: '가', is_active: true },
      { name: '나', is_active: false },
      { name: '다', is_active: true },
    ],
  };

  it('항목 변수를 참조하는 if 가 항목별로 평가된다 (종전에는 목록 전체 미렌더)', () => {
    renderDef(
      {
        id: 'list',
        type: 'basic',
        name: 'Row',
        props: { label: '{{user.name}}' },
        if: '{{user.is_active}}',
        iteration: { source: '{{users}}', item_var: 'user' },
      } as ComponentDefinition,
      context
    );

    const rows = screen.getAllByTestId('row');
    expect(rows.map((r) => r.textContent)).toEqual(['가', '다']);
  });

  it('외곽 변수만 참조하는 if 는 결과가 종전과 같다 (false → 전체 미렌더)', () => {
    const view = renderDef(
      {
        id: 'list-off',
        type: 'basic',
        name: 'Row',
        props: { label: '{{user.name}}' },
        if: '{{flags.visible}}',
        iteration: { source: '{{users}}', item_var: 'user' },
      } as ComponentDefinition,
      { ...context, flags: { visible: false } }
    );

    expect(screen.queryAllByTestId('row')).toHaveLength(0);
    view.unmount();
  });

  it('외곽 변수 if 가 true 면 전 항목이 렌더된다 (회귀 보호)', () => {
    const view = renderDef(
      {
        id: 'list-on',
        type: 'basic',
        name: 'Row',
        props: { label: '{{user.name}}' },
        if: '{{flags.visible}}',
        iteration: { source: '{{users}}', item_var: 'user' },
      } as ComponentDefinition,
      { ...context, flags: { visible: true } }
    );

    expect(screen.getAllByTestId('row')).toHaveLength(3);
    view.unmount();
  });

  it('iteration 없는 컴포넌트의 if 는 종전대로 즉시 차단한다 (회귀 보호)', () => {
    const view = renderDef(
      {
        id: 'single',
        type: 'basic',
        name: 'Row',
        props: { label: 'x' },
        if: '{{flags.visible}}',
      } as ComponentDefinition,
      { flags: { visible: false } }
    );

    expect(screen.queryAllByTestId('row')).toHaveLength(0);
    view.unmount();
  });

  it('{item_var}_index 자동 변수가 주입된다 (반복 렌더 경로와 동일)', () => {
    const view = renderDef(
      {
        id: 'idx',
        type: 'basic',
        name: 'Row',
        props: { label: '{{user_index}}' },
        iteration: { source: '{{users}}', item_var: 'user' },
      } as ComponentDefinition,
      context
    );

    expect(screen.getAllByTestId('row').map((r) => r.textContent)).toEqual(['0', '1', '2']);
    view.unmount();
  });
});
