/**
 * DynamicRenderer 렌더 환경 ref (이슈 #141)
 *
 * 셀 렌더 함수는 React Context 를 읽지 못하므로, DynamicRenderer 가 자기 렌더 환경
 * (미리보기 폭·번역 컨텍스트·엔진·dispatcher·레지스트리·편집 모드)을 안정 ref 에 기록해
 * `__componentContext.renderEnvRef` 로 노출한다. ref 는 identity 가 불변이라
 * `__componentContext` 를 deps 로 쓰는 곳(resolvedProps·슬롯 등록·DataGrid useCallback)을
 * 폭 변화로 다시 돌리지 않는다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { ResponsiveProvider } from '../ResponsiveContext';

const seen: any[] = [];

/** 재렌더 사이에 같은 참조를 유지하는 데이터 컨텍스트 (identity 비교의 전제) */
const DATA_CONTEXT = {};

const Probe: React.FC<Record<string, any>> = ({ __componentContext, children }) => {
  seen.push({ ctx: __componentContext, env: __componentContext?.renderEnvRef?.current ? { ...__componentContext.renderEnvRef.current } : null });
  return <div data-testid="probe">{children}</div>;
};

describe('DynamicRenderer — __componentContext.renderEnvRef', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  let translationContext: TranslationContext;

  const probeDef = {
    id: 'probe',
    type: 'composite',
    name: 'Probe',
    props: {},
  } as unknown as ComponentDefinition;

  beforeEach(() => {
    seen.length = 0;
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Probe: { component: Probe, metadata: { name: 'Probe', type: 'composite' } },
    };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });
    translationContext = { templateId: 'edited-template', locale: 'en' };
  });

  afterEach(() => {
    cleanup();
    delete (window as any).__slotContextValue;
  });

  const tree = (width: number, def: ComponentDefinition = probeDef, extra: Record<string, any> = {}) => (
    <ResponsiveProvider overrideWidth={width}>
      <DynamicRenderer
        componentDef={def}
        dataContext={DATA_CONTEXT}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
        isEditMode
        {...extra}
      />
    </ResponsiveProvider>
  );

  /**
   * @scenario preview_device=mobile, render_path=composite_context
   * @effects preview_render_env_exposed_via_component_context
   */
  it('T-D1 composite 가 받은 renderEnvRef.current 가 미리보기 렌더 환경이다', () => {
    render(tree(390));
    const last = seen[seen.length - 1];
    expect(last.env).not.toBeNull();
    expect(last.env.responsiveWidth).toBe(390);
    expect(last.env.translationContext).toBe(translationContext);
    expect(last.env.translationEngine).toBe(translationEngine);
    expect(last.env.bindingEngine).toBe(bindingEngine);
    expect(last.env.actionDispatcher).toBe(actionDispatcher);
    expect(last.env.componentRegistry).toBe(registry);
    expect(last.env.isEditMode).toBe(true);
  });

  /**
   * @scenario preview_device=mobile→desktop, render_path=composite_context
   * @effects preview_render_env_ref_identity_stable_across_width_change
   */
  it('T-D2 폭 390→1280: ref 값은 1280 으로 갱신되고 __componentContext identity 는 불변', () => {
    const view = render(tree(390));
    const before = seen[seen.length - 1].ctx;
    view.rerender(tree(1280));
    const after = seen[seen.length - 1];
    expect(after.ctx.renderEnvRef.current.responsiveWidth).toBe(1280);
    expect(after.ctx).toBe(before);
  });

  /**
   * @scenario slot_registration=enabled, edit_mode=true
   * @effects preview_slot_registration_carries_render_env_and_path
   */
  it('T-D3 슬롯 등록 payload 에 getRenderEnv()·componentPath 가 들어간다', () => {
    const registerToSlot = vi.fn();
    (window as any).__slotContextValue = {
      isEnabled: true,
      registerToSlot,
      unregisterFromSlot: vi.fn(),
    };
    const slotDef = { ...probeDef, id: 'slotted', slot: 'header_currency' } as unknown as ComponentDefinition;

    render(tree(390, slotDef, { componentPath: '0.children.3' }));

    expect(registerToSlot).toHaveBeenCalled();
    const payload = registerToSlot.mock.calls[registerToSlot.mock.calls.length - 1][2];
    expect(payload.componentPath).toBe('0.children.3');
    expect(typeof payload.getRenderEnv).toBe('function');
    const env = payload.getRenderEnv();
    expect(env.responsiveWidth).toBe(390);
    expect(env.componentRegistry).toBe(registry);
    expect(env.actionDispatcher).toBe(actionDispatcher);
    expect(env.isEditMode).toBe(true);
  });
});
