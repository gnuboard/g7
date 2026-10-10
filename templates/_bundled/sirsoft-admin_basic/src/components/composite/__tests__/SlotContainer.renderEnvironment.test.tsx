/**
 * SlotContainer — 등록 렌더러의 렌더 환경으로 슬롯 내용을 그린다 (이슈 #141 D8)
 *
 * 슬롯 내용은 소비 위치(SlotContainer)에서 DynamicRenderer 로 다시 그려진다. 종전에는 호스트
 * 레지스트리·엔진·dispatcher 를 쓰고 `isEditMode`·편집 경로를 넘기지 않아, 레이아웃 편집기
 * 캔버스에서 슬롯 내용에 편집 마커(`data-editor-path`)가 없고 액션이 바인딩됐다.
 *
 * 우선순위: 등록 항목의 `getRenderEnv()` > SlotContainer 자신의 `__componentContext.renderEnvRef`
 * > 호스트 `G7Core.getXxx()`.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { SlotContainer } from '../SlotContainer';

describe('SlotContainer — 렌더 환경 (#141)', () => {
  const host = { id: 'host' };
  let received: any[];
  let originalG7Core: any;

  const registration = (extra: Record<string, any> = {}) => ({
    componentDef: { id: 'currency', type: 'basic', name: 'Div' },
    dataContext: {},
    order: 0,
    parentFormContext: null,
    getParentComponentContext: () => ({ state: {}, setState: () => {} }),
    translationContext: { templateId: 'host', locale: 'ko' },
    registrationKey: 'k',
    ...extra,
  });

  const installSlot = (reg: Record<string, any>) => {
    (window as any).__slotContextValue = {
      isEnabled: true,
      getSlotComponents: () => [reg],
      subscribeToSlot: () => () => {},
    };
  };

  beforeEach(() => {
    received = [];
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = {
      getDynamicRenderer: () => (props: any) => {
        received.push(props);
        return <span data-testid="slot-child" />;
      },
      getComponentRegistry: () => host,
      getDataBindingEngine: () => host,
      getTranslationEngine: () => host,
      getActionDispatcher: () => host,
    };
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    delete (window as any).__slotContextValue;
  });

  /**
   * @scenario slot_registration=with_render_env, edit_mode=true
   * @effects preview_slot_and_footer_use_render_env
   */
  it('등록 항목 getRenderEnv 의 레지스트리·엔진·dispatcher·isEditMode 와 componentPath 를 넘긴다', () => {
    const env = {
      componentRegistry: { id: 'envRegistry' },
      bindingEngine: { id: 'envBE' },
      translationEngine: { id: 'envTE' },
      actionDispatcher: { id: 'envAD' },
      isEditMode: true,
    };
    installSlot(registration({ getRenderEnv: () => env, componentPath: '0.children.2' }));

    render(<SlotContainer slotId="header_currency" id="slot_desktop" />);

    const last = received[received.length - 1];
    expect(last.registry).toBe(env.componentRegistry);
    expect(last.bindingEngine).toBe(env.bindingEngine);
    expect(last.translationEngine).toBe(env.translationEngine);
    expect(last.actionDispatcher).toBe(env.actionDispatcher);
    expect(last.isEditMode).toBe(true);
    expect(last.componentPath).toBe('0.children.2');
  });

  /**
   * @scenario slot_registration=without_render_env, container_context=present
   * @effects preview_slot_and_footer_use_render_env
   */
  it('등록 항목에 환경이 없으면 SlotContainer 자신의 renderEnvRef 를 쓴다', () => {
    const own = { actionDispatcher: { id: 'ownAD' }, isEditMode: true };
    installSlot(registration());

    render(
      <SlotContainer
        slotId="header_currency"
        {...({ __componentContext: { renderEnvRef: { current: own } } } as any)}
      />,
    );

    const last = received[received.length - 1];
    expect(last.actionDispatcher).toBe(own.actionDispatcher);
    expect(last.registry).toBe(host);
    expect(last.isEditMode).toBe(true);
  });

  /**
   * @scenario slot_registration=without_render_env, container_context=absent
   * @effects preview_render_env_falls_back_to_host_outside_preview
   */
  it('환경이 전혀 없으면 호스트 G7Core 값으로 그린다 (편집기 밖 무변화)', () => {
    installSlot(registration());

    render(<SlotContainer slotId="header_currency" />);

    const last = received[received.length - 1];
    expect(last.registry).toBe(host);
    expect(last.bindingEngine).toBe(host);
    expect(last.translationEngine).toBe(host);
    expect(last.actionDispatcher).toBe(host);
    expect(last.isEditMode).toBeFalsy();
    expect(last.componentPath).toBeUndefined();
  });
});
