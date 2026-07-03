/**
 * DynamicRenderer.iterationId.test.tsx — iteration 노드 id 표현식 보간
 *
 * 노드의 최상위 `id` 필드는 props 가 아니라 props 바인딩 경로를 타지 않는다. iteration
 * 안에서 `id: "item_{{$idx}}"` 처럼 표현식을 쓰면 각 row 마다 고유 DOM id 가 되어야 하지만,
 * 보간 없이 출력되면 같은 리터럴이 row 마다 반복되어 W3C HTML id 중복을 유발한다.
 *
 * engine-v1.50.0 수정: DOM 출력 지점이 effectiveComponentDef.id 를 iteration 컨텍스트로
 * 보간한 resolvedComponentId 를 쓴다. slot 등록·React key 등 내부 식별은 원본 id 유지(격리).
 */

import React from 'react';
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

// id 를 실제 DOM 속성으로 출력하는 테스트 컴포넌트.
const IdDiv: React.FC<{ id?: string; children?: React.ReactNode }> = ({ id, children }) => (
  <div id={id}>{children}</div>
);

describe('DynamicRenderer - iteration id 표현식 보간', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: IdDiv, metadata: { name: 'Div', type: 'basic' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'test-template', locale: 'ko' };
  });

  function renderNode(componentDef: ComponentDefinition, dataContext: Record<string, unknown> = {}) {
    return render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={dataContext}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />,
    );
  }

  it('index_var 를 쓴 id 가 row 별 고유 DOM id 로 보간된다', () => {
    const def: ComponentDefinition = {
      id: 'item_{{$idx}}',
      type: 'basic',
      name: 'Div',
      iteration: { source: 'rows', item_var: 'row', index_var: '$idx' },
    } as unknown as ComponentDefinition;

    const { container } = renderNode(def, { rows: [{ n: 'a' }, { n: 'b' }, { n: 'c' }] });

    const ids = Array.from(container.querySelectorAll('[id]')).map((el) => el.id);
    expect(ids).toEqual(['item_0', 'item_1', 'item_2']);
    // 중복 0
    expect(new Set(ids).size).toBe(ids.length);
  });

  it('item_var 의 필드를 쓴 id 도 row 값으로 보간된다', () => {
    const def: ComponentDefinition = {
      id: 'row_{{row.id}}',
      type: 'basic',
      name: 'Div',
      iteration: { source: 'rows', item_var: 'row' },
    } as unknown as ComponentDefinition;

    const { container } = renderNode(def, { rows: [{ id: 7 }, { id: 42 }] });

    const ids = Array.from(container.querySelectorAll('[id]')).map((el) => el.id);
    expect(ids).toEqual(['row_7', 'row_42']);
  });

  it('표현식이 없는 정적 id 는 그대로 출력된다(무영향)', () => {
    const def: ComponentDefinition = {
      id: 'static_box',
      type: 'basic',
      name: 'Div',
    } as unknown as ComponentDefinition;

    const { container } = renderNode(def);
    expect(container.querySelector('#static_box')).not.toBeNull();
  });

  it('index 0 도 빈 문자열이 아니라 정상 보간된다', () => {
    const def: ComponentDefinition = {
      id: 'cell_{{$i}}',
      type: 'basic',
      name: 'Div',
      iteration: { source: 'rows', item_var: 'r', index_var: '$i' },
    } as unknown as ComponentDefinition;

    const { container } = renderNode(def, { rows: [{}] });
    expect(container.querySelector('#cell_0')).not.toBeNull();
  });
});
