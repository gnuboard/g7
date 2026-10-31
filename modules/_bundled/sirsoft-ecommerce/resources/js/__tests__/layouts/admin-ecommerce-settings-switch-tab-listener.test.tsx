// e2e:allow 이커머스 환경설정 탭 전환 이벤트 받기 — 실제 렌더 실행 흐름(탭 클릭·창 [이동] → emit → 이벤트 받기). 브라우저 흐름은 이력의 Chrome MCP 매트릭스 R1 행으로 확인한다.
/**
 * 이커머스 환경설정 — 탭 전환을 탭 본문 틀의 이벤트 받기 한 곳이 맡는다 (#753 X1)
 *
 * 종전에는 `<on listen>` 안의 연속 실행이 엔진에서 버려져(키 4개만 옮겨 담음) 탭 줄(변경 없음 갈래)과 확인 창
 * [이동] 두 곳에 정리 목록을 복제했고, 확인 창은 `$parent._local`·전역 `_local` 두 자리에 같은 값을 썼다.
 * engine-v1.80.0 부터 이벤트 받기가 통째로 실행되므로 처음 설계대로 탭 본문 틀이 정리·전역 탭·주소 갱신을 전담하고
 * 두 진입점은 같은 이벤트만 보낸다.
 *
 * 실제 레이아웃 노드(탭 줄·탭 본문 틀·확인 창 [이동] 버튼)를 실제 DynamicRenderer·ActionDispatcher 로 렌더하고
 * 클릭에서 정리·이동까지의 실행 흐름을 잰다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import layout from '../../../layouts/admin/admin_ecommerce_settings.json';
import modal from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_unsaved_tab_switch.json';

type Node = Record<string, any>;

/** 조건에 맞는 첫 노드를 찾는다. */
function find(node: unknown, match: (n: Node) => boolean): Node | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = find(child, match);
      if (found) return found;
    }
    return undefined;
  }
  if (!node || typeof node !== 'object') return undefined;
  if (match(node as Node)) return node as Node;
  for (const value of Object.values(node as Node)) {
    const found = find(value, match);
    if (found) return found;
  }
  return undefined;
}

const tabNavigation = find(layout, (n) => n.id === 'tab_navigation') as Node;
const tabContent = find(layout, (n) => n.id === 'ecommerce_settings_tab_content') as Node;
const leaveButton = find(modal, (n) => n.props?.['data-testid'] === 'ecommerce-settings-unsaved-tab-leave') as Node;

/** 탭 줄 대역 — 탭 버튼을 누르면 운영 TabNavigation 처럼 onTabChange(id) 를 부른다 */
const TabNavigationStub: React.FC<any> = ({ tabs, onTabChange }) =>
  React.createElement('div', null, (tabs ?? []).map((tab: Node) =>
    React.createElement('button', { key: tab.id, type: 'button', 'data-testid': `tab-${tab.id}`, onClick: () => onTabChange?.(tab.id) }, tab.id)));

let registry: MockComponentRegistry;

beforeEach(() => {
  registry = createMockComponentRegistryWithBasics();
  registry.register('composite', 'TabNavigation', TabNavigationStub);
});

/**
 * 탭 줄·탭 본문 틀(이벤트 받기만 남김)·확인 창 [이동] 버튼을 렌더한다.
 *
 * @param global 초기 _global
 * @param local 초기 _local
 * @returns 테스트 유틸
 */
async function renderSettings(global: Node, local: Node) {
  const utils = createLayoutTest({
    version: '1.0.0',
    layout_name: 'test/admin-ecommerce-settings-switch-tab',
    components: [
      tabNavigation,
      { ...tabContent, children: [] },
      { ...leaveButton, props: { ...(leaveButton.props ?? {}), 'data-testid': 'leave' } },
    ],
  } as any, {
    componentRegistry: registry as any,
    locale: 'ko',
    initialState: { _global: { ...global, _local: local }, _local: local },
  });
  await utils.render();

  // 운영 계약에 맞춘다 — `G7Core.state.get()` 은 곧 `_global` 이고 그 `_local` 이 저장소 B 다(TemplateApp.getGlobalState).
  // 하네스 기본값은 별도 `_local` 을 돌려줘, 이벤트 받기의 로컬 정리 뒤 이어지는 전역 setState 가 B 를 옛 값으로 되돌린다.
  (window as any).G7Core.state.get = () => ({ ...utils.getState()._global });

  return utils;
}

/** 이벤트 받기·연속 실행이 끝날 때까지 흘려보낸다 */
async function flush() {
  for (let i = 0; i < 10; i++) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

const DIRTY_LOCAL = { hasChanges: false, isAddingExchangeReason: true, exchangeReasonForm: { name: 'x' }, errors: { name: ['err'] }, claimReasonTab: 'refund' };

describe('이커머스 환경설정 — 탭 전환 이벤트 받기 실행 흐름 (#753)', () => {
  /**
   * @scenario case=clean_other_tab_switches
   * @effects tab_switch_without_confirm
   */
  it('변경 없이 탭을 누르면 이벤트 받기가 정리하고 전역 탭·주소를 옮긴다', async () => {
    const utils = await renderSettings({ activeEcommerceSettingsTab: 'basic_info' }, { ...DIRTY_LOCAL });

    fireEvent.click(screen.getByTestId('tab-shipping'));
    await flush();

    const global = utils.getState()._global;
    expect(global.activeEcommerceSettingsTab).toBe('shipping');
    expect(global.pendingEcommerceSettingsTab).toBeNull();
    expect(global._local).toMatchObject({ hasChanges: false, isAddingExchangeReason: false, exchangeReasonForm: null, errors: null, claimReasonTab: null });
    expect(utils.mockNavigate).toHaveBeenCalledTimes(1);
    expect(String(utils.mockNavigate.mock.calls[0][0])).toBe('/admin/ecommerce/settings?tab=shipping');
    utils.cleanup();
  });

  /**
   * @scenario case=leave_switches_tab
   * @effects unsaved_tab_confirm_leave_switches_tab
   */
  it('확인 창 [이동] 은 창을 닫고 맡겨 둔 탭으로 같은 정리·이동을 한다', async () => {
    const modalId = 'modal_ecommerce_settings_unsaved_tab';
    const utils = await renderSettings(
      { activeEcommerceSettingsTab: 'basic_info', pendingEcommerceSettingsTab: 'claim', modalStack: [modalId], activeModal: modalId },
      { ...DIRTY_LOCAL, hasChanges: true },
    );

    fireEvent.click(screen.getByTestId('leave'));
    await flush();

    const global = utils.getState()._global;
    // closeModal 은 엔진 내장 핸들러로 `_global.modalStack` 을 내린다
    expect(global.modalStack ?? []).not.toContain(modalId);
    expect(global.activeEcommerceSettingsTab).toBe('claim');
    expect(global.pendingEcommerceSettingsTab).toBeNull();
    expect(global._local).toMatchObject({ hasChanges: false, isAddingExchangeReason: false, errors: null });
    expect(utils.mockNavigate).toHaveBeenCalledTimes(1);
    expect(String(utils.mockNavigate.mock.calls[0][0])).toBe('/admin/ecommerce/settings?tab=claim');
    utils.cleanup();
  });
});
