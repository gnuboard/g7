/**
 * DynamicRenderer.devtoolsMountId.test.tsx
 *
 * @scenario-manifest tests/scenarios/dynamic-renderer-devtools-mount-id.yaml
 *
 * 컴포넌트 id 는 선택이다(번들 레이아웃 컴포넌트 6028개 중 5845개가 id 없이 쓴다). 렌더러가 마운트·언마운트를
 * DevTools 에 알릴 때 id 없는 컴포넌트까지 `undefined` 를 키로 넘겨, DevTools 의 마운트 표가 id 없는 컴포넌트
 * 전부를 한 칸에 덮어쓰고 그중 하나만 언마운트돼도 그 칸이 지워졌다. id 가 있는 컴포넌트만 알린다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';

const TestDiv: React.FC<{ children?: React.ReactNode }> = ({ children }) => <div>{children}</div>;

/** 어떤 추적 메서드를 불러도 받아 주는 DevTools 대역 — 마운트·언마운트 호출만 따로 센다 */
function installDevTools() {
  const trackMount = vi.fn();
  const trackUnmount = vi.fn();
  const known: Record<string, unknown> = { isEnabled: () => true, trackMount, trackUnmount };
  const devTools = new Proxy(known, {
    get: (target, key: string) => (key in target ? target[key] : () => undefined),
  });
  const w = window as unknown as { G7Core?: Record<string, unknown> };
  const previous = w.G7Core;
  w.G7Core = { ...(previous ?? {}), devTools };

  return { trackMount, trackUnmount, restore: () => { w.G7Core = previous; } };
}

describe('DynamicRenderer — DevTools 마운트 추적은 id 가 있는 컴포넌트만', () => {
  let registry: ComponentRegistry;
  let translationContext: TranslationContext;
  let devTools: ReturnType<typeof installDevTools>;

  beforeEach(() => {
    registry = ComponentRegistry.getInstance();
    (registry as unknown as { registry: Record<string, unknown> }).registry = {
      Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    };
    translationContext = { templateId: 'test-template', locale: 'ko' };
    devTools = installDevTools();
  });

  afterEach(() => {
    devTools.restore();
  });

  // @scenario component_id=mixed
  // @effects devtools_mount_tracks_only_identified_components
  it('id 없는 컴포넌트는 마운트·언마운트를 알리지 않고, id 있는 컴포넌트는 그 id 로 알린다', () => {
    const componentDef: ComponentDefinition = {
      id: 'root',
      type: 'basic',
      name: 'Div',
      children: [
        { type: 'basic', name: 'Div' },
        { type: 'basic', name: 'Div' },
        { id: 'named_child', type: 'basic', name: 'Div' },
      ],
    };

    const view = render(
      <DynamicRenderer
        componentDef={componentDef}
        dataContext={{}}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={new DataBindingEngine()}
        translationEngine={new TranslationEngine()}
        actionDispatcher={new ActionDispatcher({ navigate: vi.fn() })}
      />
    );

    const mountedIds = devTools.trackMount.mock.calls.map((call) => call[0]);
    expect(mountedIds.sort()).toEqual(['named_child', 'root']);

    view.unmount();

    const unmountedIds = devTools.trackUnmount.mock.calls.map((call) => call[0]);
    expect(unmountedIds.sort()).toEqual(['named_child', 'root']);
  });
});
