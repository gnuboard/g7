/**
 * computedDevtoolsTracking.test.tsx
 *
 * @scenario-manifest tests/scenarios/engine-computed-devtools-tracking.yaml
 *
 * 렌더러가 computed 값을 DevTools 에 알릴 때 의존성 종류를 DevTools 계약(`state` | `datasource` | `computed` |
 * `expression`)에 없는 `_local` 로 넘겼다. 이 값이 의존성 트리 노드의 type 으로 그대로 나가 g7-computed 진단이 계약 밖 값을
 * 받았다. 의존성은 `_local` 상태이므로 종류는 `state`, 경로는 `_local` 이다. 전달부의 `as any` 가 이 어긋남을 가렸다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const TestSpan: React.FC<{ text?: string }> = ({ text }) => <span>{text}</span>;

/** 어떤 추적 메서드를 불러도 받아 주는 DevTools 대역 — computed 추적 호출만 따로 센다 */
function installDevTools() {
  const trackComputedProperty = vi.fn();
  const trackComputedRecalc = vi.fn();
  const known: Record<string, unknown> = { isEnabled: () => true, trackComputedProperty, trackComputedRecalc };
  const devTools = new Proxy(known, {
    get: (target, key: string) => (key in target ? target[key] : () => undefined),
  });
  const w = window as unknown as { G7Core?: Record<string, unknown> };
  const previous = w.G7Core;
  w.G7Core = { ...(previous ?? {}), devTools };

  return { trackComputedProperty, trackComputedRecalc, restore: () => { w.G7Core = previous; } };
}

describe('렌더러의 computed DevTools 추적 — 의존성 종류', () => {
  let registry: ComponentRegistry;
  let translationContext: TranslationContext;
  let devTools: ReturnType<typeof installDevTools>;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as unknown as { registry: Record<string, unknown> }).registry = {
      Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    };
    translationContext = { templateId: 'test-template', locale: 'ko' };
    devTools = installDevTools();
  });

  afterEach(() => {
    devTools.restore();
  });

  // @scenario dependency=local_state
  // @effects computed_dependency_type_follows_devtools_contract
  it('_local 에서 계산한 computed 의 의존성과 재계산 트리거를 state 종류, _local 경로로 알린다', () => {
    const componentDef: ComponentDefinition = {
      id: 'probe',
      type: 'basic',
      name: 'Span',
      props: { text: '{{_computed.doubled}}' },
    };

    render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={{ _local: { count: 2 }, _computedDefinitions: { doubled: '{{_local.count * 2}}' } }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={new DataBindingEngine()}
        translationEngine={new TranslationEngine()}
        actionDispatcher={new ActionDispatcher({ navigate: vi.fn() })}
      />
    );

    expect(devTools.trackComputedProperty).toHaveBeenCalled();
    for (const call of devTools.trackComputedProperty.mock.calls) {
      expect(call[2]).toEqual([expect.objectContaining({ type: 'state', path: '_local' })]);
    }
    expect(devTools.trackComputedRecalc).toHaveBeenCalled();
    for (const call of devTools.trackComputedRecalc.mock.calls) {
      expect(call[1]).toBe('dependency-change');
      expect(call[5]).toEqual({ type: 'state', path: '_local' });
    }
  });
});
